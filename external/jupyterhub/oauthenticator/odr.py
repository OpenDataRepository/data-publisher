"""
Custom Authenticator to use OAuth2 with ODR
"""

import json
import os
import base64

from tornado.auth import OAuth2Mixin
from tornado import gen, web

from tornado.httputil import url_concat
from tornado.httpclient import HTTPRequest, AsyncHTTPClient

from jupyterhub.auth import LocalAuthenticator

from traitlets import Unicode, Dict

from .oauth2 import OAuthLoginHandler, OAuthenticator


class ODREnvMixin(OAuth2Mixin):
    odr_base_url = '[[ ENTER ODR SERVER BASEURL HERE ]]'

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


    @gen.coroutine
    def authenticate(self, handler, data=None):
        code = handler.get_argument("code", False)
        if not code:
            raise web.HTTPError(400, "oauth callback made without a token")
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
#                          method="POST",    # TODO - apparently this should be a POST according to the RFC?  Not sure why ODR demands the GET method...
                          method="GET",
                          headers=headers,
#                          body=''  # Body is required for a POST...
                          )

        resp = yield http_client.fetch(req)


        # Extract info from ODR's response
        resp_json = json.loads(resp.body.decode('utf8', 'replace'))
        access_token = resp_json['access_token']
        token_type = resp_json['token_type']


        # Get ODR to tell us which user just logged in via OAuth
        headers = {
            "Accept": "application/json",
            "User-Agent": "JupyterHub",
        }
        url = url_concat(self.userdata_url, {"access_token": access_token})

        req = HTTPRequest(url,
                          method="GET",
                          headers=headers,
                          )
        resp = yield http_client.fetch(req)


        # Extract the username from ODR's response
        resp_json = json.loads(resp.body.decode('utf8', 'replace'))

        if resp_json.get(self.username_key):
            return resp_json[self.username_key]

