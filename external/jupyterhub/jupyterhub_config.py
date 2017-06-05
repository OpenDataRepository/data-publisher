# Configuration file for jupyterHub...there's probably a better way of doing this

# Define the custom authentication class JupyterHub attempts to use
c.JupyterHub.authenticator_class = 'oauthenticator.LocalODROAuthenticator'

# Define the ODR server location
odr_base_url = '[[ ENTER ODR SERVER BASEURL HERE ]]'
c.ODROAuthenticator.token_url = odr_base_url + '/oauth/v2/token'
c.ODROAuthenticator.userdata_url = odr_base_url + '/api/userdata'
c.ODROAuthenticator.username_key = 'jupyterhub_username'

# Define the JupyterHub server location
jupyterhub_base_url = '[[ ENTER JUPYTERHUB SERVER BASEURL HERE ]]'
c.ODROAuthenticator.oauth_callback_url = jupyterhub_base_url + '/hub/oauth_callback'

# Define parameters needed for the OAuth process
c.ODROAuthenticator.client_id = '[[ ENTER OAUTH CLIENT_ID HERE ]]'
c.ODROAuthenticator.client_secret = '[[ ENTER OAUTH CLIENT_SECRET HERE ]]'

# Instruct JupyterHub to create system users based on the OAuth server
c.LocalAuthenticator.create_system_users = True


# Needed to secure a route to the OAuth token manager...can use "openssl rand -hex 32".  Shouldn't match other keys.
c.ODROAuthenticator.manager_token = '[[ ENTER SOME SECRET KEY HERE ]]'

# API tokens to allow JupyterHub services to communicate with JupyterHub's API...can use "openssl rand -hex 32".
c.JupyterHub.service_tokens = {
    '[[ ENTER SOME OTHER SECRET KEY HERE ]]': 'odr_oauth_manager',
    '[[ ENTER YET ANOTHER SECRET KEY HERE ]]': 'odr_external',
}

# JupyterHub service definition
c.JupyterHub.services = [
    {
        'name': 'odr_oauth_manager',
        'admin': True,      # DEBUG - needed to test access to jupyterhub api
        'command': ['python', 'odr_oauth_manager.py'],
        'url': 'http://127.0.0.1:8094',     # port number needs to match value defined towards the end of odr_oauth_manager.py
        'environment': {
            'oauth_client_id': c.ODROAuthenticator.client_id,
            'oauth_client_secret': c.ODROAuthenticator.client_secret,
            'oauth_token_url': c.ODROAuthenticator.token_url,

            'oauth_manager_token': c.ODROAuthenticator.manager_token,
        },
    },
    {
        'name': 'odr_external',
        'admin': True,
        'url': odr_base_url
    }
]
