# Open Data Repository Data Publisher
# ODR OAuth Manager
# (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
# (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
# Released under the GPLv2

"""
Defines a Tornado web application that manages OAuth tokens as a JupyterHub service.
Will (hopefully) be rendered obsolete by https://github.com/jupyterhub/jupyterhub/pull/938 , whenever that is done.

This service is necessary because getting a new OAuth access_token through the 'refresh_token' grant type also
requires the 'client_secret' value...something which shouldn't be revealed to users running JupyterHub.
"""

import json
import os
import requests

from tornado.escape import json_decode
from tornado.gen import coroutine
#from tornado.httpclient import HTTPClient, HTTPRequest
from tornado.httputil import url_concat
from tornado.ioloop import IOLoop
from tornado.log import app_log
from tornado.web import Application, HTTPError, RequestHandler


class OAuthRequestHandler(RequestHandler):

    def load_tokens(self):
        """
        Returns a dict containing the OAuth tokens for all users.

        Currently, they're stored in a simple text file in the same directory.
        Ideally, this file should be readable/writable only by whichever user is running JupyterHub.
        """

        # Don't want to create file if it doesn't already exist
        if not os.path.isfile('oauth_token_storage.txt'):
            raise FileNotFoundError

        with open('oauth_token_storage.txt', 'r') as handle:
            data = json.load(handle)
            return data


    def store_tokens(self, tokens_dict):
        """
        Persists a dict of username:tokens for later retrieval.

        Currently, they're stored in a simple text file in the same directory.
        Ideally, this file should be readable/writable only by whichever user is running JupyterHub.
        """

        # Don't want to create file if it doesn't already exist
        if not os.path.isfile('oauth_token_storage.txt'):
            raise FileNotFoundError

        with open('oauth_token_storage.txt', 'w') as handle:
            json.dump(tokens_dict, handle)


class CreateOAuthUser(OAuthRequestHandler):
    """
    The JupyterHub application itself should be the only 'user' that calls this.

    After the OAuthenticator that JupyterHub is currently using finishes the OAuth login process, it should
    call this function by using pre_spawn_start() so the OAuth manager knows which tokens to store for the
    user that just logged in.

    http://jupyterhub.readthedocs.io/en/latest/api/auth.html#jupyterhub.auth.Authenticator.pre_spawn_start
    """

    def post(self):
        # Ensure POST has all required data
        data = json_decode(self.request.body)
        if 'api_auth_token' not in data:
            raise HTTPError(400, 'api_auth_token not provided')
        if 'access_token' not in data:
            raise HTTPError(400, 'access_token not provided')
        if 'refresh_token' not in data:
            raise HTTPError(400, 'refresh_token not provided')
        if 'user_session_token' not in data:
            raise HTTPError(400, 'user_session_token not provided')
        if 'username' not in data:
            raise HTTPError(400, 'username not provided')

        # Extract data from http request
        self.api_auth_token = data['api_auth_token']
        self.access_token = data['access_token']
        self.refresh_token = data['refresh_token']
        self.user_session_token = data['user_session_token']
        self.username = data['username']

        # Ensure that only JupyterHub can access this method...
        if not ( self.api_auth_token == os.environ['oauth_manager_token'] ):
            raise HTTPError(403)

        # Store the provided parameters for later
        __all_tokens = self.load_tokens()
        __all_tokens[ self.username ] = {
            'access_token': self.access_token,
            'refresh_token': self.refresh_token,
            'user_session_token': self.user_session_token,
        }
        self.store_tokens(__all_tokens)


class GetAccessToken(OAuthRequestHandler):
    """
    Returns a JSON response containing the user's access token
    """

    def get(self, user_session_token):
        # Locate the user this session_token is referring to, if possible
        __username = ''
        __all_tokens = self.load_tokens()
        for user, data in __all_tokens.items():
            if data['user_session_token'] == user_session_token:
                __username = user

        # If user not found, return error
        if __username == '':
            raise HTTPError(404)

        # Otherwise, return the user's access token to the requesting application
        self.write( {'access_token': __all_tokens[__username]['access_token']} )


class RequestNewAccessToken(OAuthRequestHandler):
    """
    Contacts the OAuth provider and attempts to request a new access/refresh_token pair.

    Returns a JSON response containing the new access/refresh_token pair if successful
    """

    def get(self, user_session_token):
        # Locate the user this session_token is referring to, if possible
        __username = ''
        __all_tokens = self.load_tokens()

        for user, data in __all_tokens.items():
            if data['user_session_token'] == user_session_token:
                __username = user

        # If user not found, return error
        if __username == '':
            raise HTTPError(404)


        __user_tokens = __all_tokens[ __username ]
        self.refresh_token = __user_tokens['refresh_token']

        # Request a new access/refresh_token pair from the OAuth provider
        params = dict(
            client_id=os.environ['oauth_client_id'],
            client_secret=os.environ['oauth_client_secret'],
            grant_type='refresh_token',
            refresh_token=self.refresh_token
        )
        url = url_concat(os.environ['oauth_token_url'], params)

        # Using the requests library instead of tornado because tornado seems to throw an uncatchable error if it receives a 400 response
        headers = {
            "Accept": "application/json",
            "User-Agent": "JupyterHub",
        }
        r = requests.get(url, headers=headers)
        resp_json = r.json()

        # Ensure the access_token exists
        if 'access_token' not in resp_json and 'refresh_token' not in resp_json:
            self.write( resp_json )
        else:
            # Extract the new access/refresh_token pair from the OAuth provider
            self.access_token = resp_json['access_token']
            self.refresh_token = resp_json['refresh_token']

            # Store the provided parameters for later
            __user_tokens['access_token'] = self.access_token
            __user_tokens['refresh_token'] = self.refresh_token

            __all_tokens[ __username ] = __user_tokens
            self.store_tokens(__all_tokens)

            # Return the new access/refresh_token pair to the requesting application
            self.write( {'access_token': self.access_token, 'refresh_token': self.refresh_token} )


def make_app():
    # All registered urls MUST be prefixed with os.environ['JUPYTERHUB_SERVICE_PREFIX']
    return Application([
        ( os.environ['JUPYTERHUB_SERVICE_PREFIX'] + "/create_user", CreateOAuthUser),
        ( os.environ['JUPYTERHUB_SERVICE_PREFIX'] + "/get_access_token/([a-f0-9]{64,64})", GetAccessToken),     # take a 64 character hex string identifying which user this is
        ( os.environ['JUPYTERHUB_SERVICE_PREFIX'] + "/get_new_access_token/([a-f0-9]{64,64})", RequestNewAccessToken),
    ])

if __name__ == '__main__':
#    print( os.environ )
#    r = requests.get( os.environ['JUPYTERHUB_API_URL'] + '/proxy', headers={'Authorization': 'token %s' % os.environ['JUPYTERHUB_API_TOKEN']} )
#    print( r.text )

    app = make_app()
    app.listen(8094)    # port number needs to match config in jupyterhub_config.py file

    # Apparently need these lines so the service exits cleanly when the hub is shut down
    try:
        IOLoop.current().start()
    except KeyboardInterrupt:
        pass
