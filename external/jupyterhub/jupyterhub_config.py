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

