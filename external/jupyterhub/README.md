# ODR + JupyterHub Readme
These setup instructions are intended for a "simple" installation of JupyterHub, and haven't been tested for situations where JupyterHub is spawning notebooks inside a container and/or on some remote server.
There's probably a better way of setting this up (especially on the JupyterHub/Python side), but following these instructions **should** allow a working install of JupyterHub to use a working install of ODR as an OAuth provider.  This document is by no means final...the python side of things in particular could use improvements.

## ODR side
1.  In ODR's `/app/config/parameters.yml` file, ensure that the `jupyterhub_config.use_jupyterhub` key is set to true and that the `jupyterhub_config.jupyterhub_server` has the URL of the JupyterHub server.
2.  Run the following Symfony command after substituting the JupyterHub server info.  Save the resulting output...it contains `client_id` and `client_secret` values that will be needed later for the JupyterHub configuration.
```
php app/console fos:oauth-server:client:create --redirect-uri="[[ ENTER JUPYTERHUB SERVER BASEURL HERE ]]/oauth/callback" --grant-type="authorization_code" --grant-type="refresh_token" --grant-type="token"
```
3.  Log into ODR and grant yourself the "JupyterHub User" role.  If a user lacks this role, ODR will refuse to provide JupyterHub the information it needs to start a notebook for that user.

## JupyterHub side
1.  Copy the contents of the file at `/external/jupyterhub/jupyterhub_config.py` into the existing JupyterHub config file (or make one using the process outlined here <http://jupyterhub.readthedocs.io/en/latest/getting-started.html#configuration-file>).  Be sure to fill in the base urls for both the ODR and the JupyterHub server, generate the two keys for the OAuth token manager, and fill in the `client_id` and `client_secret` settings with the values obtained earlier.
2.  Copy the files at `/external/jupyterhub/odr_oauth_manager.py`, `/external/jupyterhub/odr_env.py`, `/external/jupyterhub/odr_env_readme.md`, and `/external/jupyterhub/oauth_token_storage.txt` into the directory that JupyterHub "starts" from.  `odr_env.py` should have the permissions `-rw-r--r--`, and `oauth_token_storage.txt` should have the permissions `-rw------`.
3.  Get the existing JupyterHub OAuthenticator package at <https://github.com/jupyterhub/oauthenticator>.
4.  Copy the file at `/external/jupyterhub/oauthenticator/odr.py` into the base directory of the newly installed OAuthenticator package, and change the line in `ODREnvMixin` to match the ODR server info.  I also had to manually copy this `odr.py` file to the `[OAuthenticator_base_dir]/build/lib/oauthenticator/` directory for some reason...
5.  This step is most likely due to my lack of understanding of python, but I had to manually modify the `__init__.py` files in the same directories as the previous step to include the line `from .odr import *`.  Without that change, the JupyterHub server will immediately exit after starting up because it seemingly can't locate the authenticator class to use.
6.  Run the command `python ./setup.py install` in the OAuthenticator base directory.
7.  Start the JupyterHub server, and attempt to access its baseurl...you should see a generic screen with a single button "Sign in with ODR OAuth".  Clicking that button should redirect you to ODR to start the OAuth login sequence, after which you should end up back on the JupyterHub server with a single button "Start my Server".

## Troubleshooting
* (JupyterHub <= 0.7.2) If accessing the baseurl of the JupyterHub server immediately redirects you to the OAuth login process (i.e. doesn't require you to click the button first), then implementing the changes here <https://github.com/jupyterhub/jupyterhub/pull/969/files> should solve it in theory.
* Not troubleshooting per se, but I had to manually download/unzip the JupyterHub OAuthenticator package since `conda` couldn't locate that package and `pip` was pointing to the wrong Python directory...
