# Open Data Repository Data Publisher
# ODR environment support for single-user JupyterHub notebooks
# (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
# (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
# Released under the GPLv2

"""
This file contains utility methods to ease use of ODR's API, and is intentionally not editable by regular JupyterHub users.
The authenticator should automatically create this file for each user.

Will (hopefully) be rendered obsolete by https://github.com/jupyterhub/jupyterhub/pull/938 , whenever that is done.
"""

import json
import os
import requests
#import sys

from tornado.httputil import url_concat


def __getAccessToken():
    """
    Contacts the oauth token manager to get this user's access_token

    Returns the access_token as a string
    """

    r = requests.get( 'http://127.0.0.1:8094/services/odr_oauth_manager/get_access_token/' + os.environ['OAUTH_SESSION_TOKEN'] )
    data = json.loads(r.text)
    return data['access_token']


def __useRefreshToken():
    """
    Contacts the oauth token manager to attempt to get a new access/refresh_token pair

    Returns the result of requesting a new access_token as a dict...usually going to contain a new access token, but could also
      notify that the refresh token has expired.  Or some other error entirely.
    """

    r = requests.get( 'http://127.0.0.1:8094/services/odr_oauth_manager/get_new_access_token/' + os.environ['OAUTH_SESSION_TOKEN'] )
    data = json.loads(r.text)
    return data


def downloadDatarecord(datarecord_id):
    """
    Attempts to download a JSON representation of the given datarecord from ODR

    Returns a dict describing the datarecord
    """

    # TODO - handle xml format as well?
    file_format = 'json'

    # Ensure arguments are vaild
    if not isinstance(datarecord_id, int):
        raise ValueError('datarecord_id must be numeric')
    if not (file_format == 'xml' or file_format == 'json'):
        raise ValueError('file_format must be either "xml" or "json"')


    # Send an API request to ODR
    api_url = os.environ['ODR_BASEURL'] + '/api/datarecord/v1/' + str(datarecord_id) + '/' + file_format
    access_token = __getAccessToken()

    r = requests.get( api_url + '?access_token=' + access_token)
    data = json.loads(r.text)

    # Deal with the response...
    if "datarecords" in data:
        # Nothing went wrong, return the result as a dict
        return data
    elif "d" in data:
        # Got an ODR error...TODO - FIX ODR SO ITS ERRORS ARE CONSISTENT
        return data['d']['html']
    elif "error_description" in data:
        # Got an OAuth error, assume it's most likely to be an access token issue...
        if data['error_description'] == 'The access token provided has expired.':
            # Attempt to get a new access token
            result = __useRefreshToken()

            if 'access_token' not in result:
                # Something happened while trying to get a new access_token, abort
                raise RuntimeError( result )
            else:
                new_access_token = result['access_token']

                # Run the http request again with the new access token
                request_url = api_url + '?access_token=' + new_access_token
                r = requests.get(request_url)
                data = json.loads(r.text)

                if "datarecords" in data:
                    # Nothing wrong with the second request, return the result as a dict
                    return data
                elif "d" in data:
                    # Got an ODR error on the second request...TODO - FIX ODR SO ITS ERRORS ARE CONSISTENT
                    return data['d']['html']
                elif "error_description" in data:
                    # Got an OAUth error again, most likely the refresh token is no longer valid
                    raise RuntimeError( data['error_description'] + "\nTry logging out, and then logging back into JupyterHub to fix this...")
                else:
                    # Something completely unexpected happened on the second request, abort
                    raise RuntimeError( data )

        else:
            # Some other unexpected OAuth error, abort
            raise RuntimeError( data['error_description'] )

    else:
        # Something completely unexpected happened on the first request, abort
        raise RuntimeError( data )


#    def downloadFile(file_id):
#        """Attempts to download a specified file from ODR
#
#        Returns a
#        """
#        # Ensure arguments are vaild
#        if not file_id.isnumeric():
#            raise InvalidArgumentException('file_id must be numeric')
#
#        # 
#        api_url = self.baseurl + '/api/file/' + str(file_id)
#        return __makeAPIRequest(api_url)


#    def downloadImage(image_id):
#        """Attempts to download a specified image from ODR
#
#        Returns a
#        """
#        # Ensure arguments are vaild
#        if not image_id.isnumeric():
#            raise InvalidArgumentException('image_id must be numeric')
#
#        # 
#        api_url = self.baseurl + '/api/image/' + str(image_id) 
#        return __makeAPIRequest(api_url)

