"""
Custom Authenticator to use OAuth2 with ODR
"""

import base64
import json
import os
import secrets
import shutil

from tornado.auth import OAuth2Mixin
from tornado import gen, web

from tornado.httputil import url_concat
from tornado.httpclient import HTTPRequest, HTTPClient, AsyncHTTPClient

from jupyterhub.auth import LocalAuthenticator
from jupyterhub.handlers import BaseHandler

from traitlets import Unicode, Dict

from .oauth2 import OAuthLoginHandler, OAuthenticator


class ODREnvMixin(OAuth2Mixin):
    # TODO - figure out how to get this from config file?
    odr_base_url = 'http://delta.odr.io'

#    _OAUTH_ACCESS_TOKEN_URL = os.environ.get('OAUTH2_TOKEN_URL', '')
    _OAUTH_ACCESS_TOKEN_URL = odr_base_url + "/oauth/v2/token"

#    _OAUTH_AUTHORIZE_URL = os.environ.get('OAUTH2_AUTHORIZE_URL', '')
    _OAUTH_AUTHORIZE_URL = odr_base_url + "/oauth/v2/auth"


class ODRLoginHandler(OAuthLoginHandler, ODREnvMixin):
    pass

class ODROAuthenticator(OAuthenticator):
    pass

class LocalODROAuthenticator(LocalAuthenticator, ODROAuthenticator):

    """Uses local system user creation so that ODR is able to define the user list"""

    login_service = "ODR OAuth"
    login_handler = ODRLoginHandler

    # Currently defined in the jupyterhub_config.py file
    manager_token = Unicode(
        os.environ.get('OAUTH2_MANAGER_TOKEN', ''),
        config=True,
        help="TODO"
    )
    token_url = Unicode(
        os.environ.get('OAUTH2_TOKEN_URL', ''),
        config=True,
        help="Userdata method to get user data login information"
    )
    userdata_url = Unicode(
        os.environ.get('OAUTH2_USERDATA_URL', ''),
        config=True,
        help="Userdata url to get user data login information"
    )
    username_key = Unicode(
        os.environ.get('OAUTH2_USERNAME_KEY', ''),
        config=True,
        help="Userdata username key from returned json for USERDATA_URL"
    )

    access_token = ''
    refresh_token = ''
    odr_baseurl = ''

    # Mechanism for the Authenticator to provide some environment variables for the Spawner
    def pre_spawn_start(self, user, spawner):
        # No sense spawning anything if the access/refresh tokens aren't provided
        if self.access_token == '' and self.refresh_token == '':
            raise web.HTTPError(400, "The spawner can't load the necessary access_token and refresh_token parameters...try logging out of JupyterHub, then logging back in.  If the error persists, inform the ODR group about it.")

        # Set additional environment variables for the soon-to-be-spawned notebook
        oauth_session_token = secrets.token_hex(32)
        spawner.env.update({
            'ODR_BASEURL': self.odr_baseurl,
            'OAUTH_SESSION_TOKEN': oauth_session_token,
        })

        # Build an API request so the OAuth_manager can store the user's data
        params = dict(
            api_auth_token=self.manager_token,
            access_token=self.access_token,
            refresh_token=self.refresh_token,

            username=user.name,
            user_session_token=oauth_session_token,
        )
        params = json.dumps(params)

        req = HTTPRequest(
            'http://127.0.0.1:8094/services/odr_oauth_manager/create_user',
            method="POST",
            body=params,
        )

        # POST this data to the OAuth_manager
        http_client = HTTPClient()
        resp = http_client.fetch(req)

        # Also, copy a readme and a utility python file into the user's directory
        shutil.copyfile('/root/odr_env.py', '/home/' + user.name + '/odr_env.py')
        shutil.copystat('/root/odr_env.py', '/home/' + user.name + '/odr_env.py')


    @gen.coroutine
    def authenticate(self, handler, data=None):
        code = handler.get_argument("code", False)
        if not code:
            raise web.HTTPError(400, "(ODR) oauth callback made without a token")
        # TODO: Configure the curl_httpclient for tornado
        http_client = AsyncHTTPClient()


        # Request an authorization code from ODR
        params = dict(
            redirect_uri=self.get_callback_url(handler),
            code=code,
            grant_type='authorization_code',

            client_id=self.client_id,
            client_secret=self.client_secret
        )
        url = url_concat(self.token_url, params)

        headers = {
            "Accept": "application/json",
            "User-Agent": "JupyterHub",
        }
        req = HTTPRequest(url,
#                          method="POST",    # TODO - apparently this should be a POST according to the RFC?  Not sure why ODR currently demands the GET method...
                          method="GET",
                          headers=headers,
#                          body=''  # Body is required for a POST...
                          )

        resp = yield http_client.fetch(req)


        # Extract info from ODR's initial response
        resp_json = json.loads(resp.body.decode('utf8', 'replace'))
        token_type = resp_json['token_type']

        self.access_token = resp_json['access_token']
        self.refresh_token = resp_json['refresh_token']

        # Get ODR to tell us which user just logged in via OAuth
        headers = {
            "Accept": "application/json",
            "User-Agent": "JupyterHub",
        }
        url = url_concat(self.userdata_url, {"access_token": self.access_token})

        req = HTTPRequest(url,
                          method="GET",
                          headers=headers,
                          )
        resp = yield http_client.fetch(req)

        # Extract info from ODR's second response
        resp_json = json.loads(resp.body.decode('utf8', 'replace'))

        self.odr_baseurl = resp_json['baseurl']
        username = resp_json[self.username_key]

        # Authenticators for JupyterHub are supposed to return a username so the Spawners know where to start the notebook server
        return username
