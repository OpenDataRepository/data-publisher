# Open Data Repository Data Publisher
# ODR environment support for single-user JupyterHub notebooks
# (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
# (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
# Released under the GPLv2

"""
This file contains utility methods to ease use of ODR's API, and is intentionally not editable by regular JupyterHub users.
The authenticator should automatically create this file for each user...

Will (hopefully) be rendered obsolete by https://github.com/jupyterhub/jupyterhub/pull/938 , whenever that is done.
"""

import json
import os
import random
import requests
import shutil
import string

from tornado.httputil import url_concat


# Found at http://stackoverflow.com/a/2257449
def _id_generator(size=15, chars=string.ascii_letters + string.digits):
    return ''.join(random.choice(chars) for _ in range(size))


def __getAccessToken():
    """
    Contacts the oauth token manager to get this user's access_token

    Returns the access_token as a string
    """

    r = requests.get( 'http://127.0.0.1:' + os.environ['OAUTH_MANAGER_PORT'] + '/services/odr_oauth_manager/get_access_token/' + os.environ['OAUTH_SESSION_TOKEN'] )
    if (r.status_code != 200):
        raise RuntimeError( r.json() )

    data = json.loads(r.text)
    return data['access_token']


def __useRefreshToken():
    """
    Contacts the oauth token manager to attempt to get a new access/refresh_token pair

    Returns the result of requesting a new access_token as a dict...usually going to contain a new access token, but could also
      notify that the refresh token has expired.  Or some other error entirely.
    """

    r = requests.get( 'http://127.0.0.1:' + os.environ['OAUTH_MANAGER_PORT'] + '/services/odr_oauth_manager/get_new_access_token/' + os.environ['OAUTH_SESSION_TOKEN'] )
    if (r.status_code != 200):
        raise RuntimeError( r.json() )

    data = json.loads(r.text)
    return data


def getDatatypeList():
    """
    Attempts to download a JSON list of all top-level datatypes the user can access from ODR.
    If the 'list_type' argument is set to 'all', then the list also includes child datatypes.

    Returns a dict of datatypes from ODR
    """

    # TODO - handle xml format as well?
    file_format = 'json'
    list_type = ''

    # Ensure arguments are valid
    if not (file_format == 'xml' or file_format == 'json'):
        raise ValueError('file_format must be either "xml" or "json"')
    if not (list_type == '' or list_type == 'all'):
        raise ValueError('list_type must be either "" or "all"')


    # Send an API request to ODR
    api_url = os.environ['ODR_BASEURL'] + '/api/datatype_list'
    if (list_type == "all"):
        api_url = api_url + '/all'

    return _makeRequest(api_url, file_format)


def getDatatypeData(datatype_id):
    """
    Attempts to download a JSON representation of the given datatype from ODR

    Returns a dict describing the datatype
    """

    # TODO - handle xml format as well?
    file_format = 'json'

    # Ensure arguments are valid
    if not isinstance(datatype_id, int):
        raise ValueError('datatype_id must be numeric')
    if not (file_format == 'xml' or file_format == 'json'):
        raise ValueError('file_format must be either "xml" or "json"')


    # Send an API request to ODR
    api_url = os.environ['ODR_BASEURL'] + '/api/datatype/v1/' + str(datatype_id) + '/' + file_format
    return _makeRequest(api_url, file_format)


def getDatarecordList(datatype_id):
    """
    Attempts to download a brief JSON list of all datarecords the user can see in a given datatype.

    Returns a dict
    """

    # TODO - handle xml format as well?
    file_format = 'json'

    # Ensure arguments are valid
    if not isinstance(datatype_id, int):
        raise ValueError('datatype_id must be numeric')
    if not (file_format == 'xml' or file_format == 'json'):
        raise ValueError('file_format must be either "xml" or "json"')


    # Send an API request to ODR
    api_url = os.environ['ODR_BASEURL'] + '/api/datarecord_list/' + str(datatype_id)
    return _makeRequest(api_url, file_format)


def getDatarecordData(datarecord_id):
    """
    Attempts to download a JSON representation of the given datarecord from ODR

    Returns a dict describing the datarecord
    """

    # TODO - handle xml format as well?
    file_format = 'json'

    # Ensure arguments are valid
    if not isinstance(datarecord_id, int):
        raise ValueError('datarecord_id must be numeric')
    if not (file_format == 'xml' or file_format == 'json'):
        raise ValueError('file_format must be either "xml" or "json"')


    # Send an API request to ODR
    api_url = os.environ['ODR_BASEURL'] + '/api/datarecord/v1/' + str(datarecord_id) + '/' + file_format
    return _makeRequest(api_url, file_format)


def _makeRequest(api_url, file_format):
    """
    Makes a request to the specified api_url, and attempts to deal with any errors that arise.

    Returns a dict of the request data if successful
    """

    access_token = __getAccessToken()

    accept_header = {'accept': 'application/json'}
    if (file_format == 'xml'):
        accept_header = {'accept': 'text/xml'}

    r = requests.get(api_url + '?access_token=' + access_token, headers=accept_header)
#    if (r.status_code != 200):
#        raise RuntimeError( r.json() )

    # TODO - handle XML data as well
    data = json.loads(r.text)

    # Deal with the response...
    if (r.status_code == 200):
        # Nothing went wrong, return the result as a dict
        return data
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
                r = requests.get(request_url, headers={'accept': 'application/json'})
                if (r.status_code != 200):
                    raise RuntimeError( r.json() )

                data = json.loads(r.text)

                if (r.status_code == 200):
                    # Nothing wrong with the second request, return the result as a dict
                    return data
                elif "error_description" in data:
                    # Got an OAUth error again, most likely the refresh token is no longer valid
                    raise RuntimeError( data['error_description'] + "\nTry logging out, and then logging back into JupyterHub to fix this...")
                else:
                    # Some ODR error, or something completely unexpected happened on the second request, abort
                    raise RuntimeError( data )

        else:
            # Some other unexpected OAuth error, abort
            raise RuntimeError( data['error_description'] )

    else:
        # Some ODR error, or something completely unexpected happened on the first request, abort
        raise RuntimeError( data )


def getFileList(datarecord_id):
    """
    Given a top-level datarecord id, returns a dict where that datarecord's data has been reduced to just file information.

    Returns a dict.
    """

    # TODO - handle xml format as well?
    file_format = 'json'

    # Ensure arguments are valid
    if not isinstance(datarecord_id, int):
        raise ValueError('datarecord_id must be numeric')
    if not (file_format == 'xml' or file_format == 'json'):
        raise ValueError('file_format must be either "xml" or "json"')


    # Get the JSON version of the top-level datarecord from ODR
    dr_data = getDatarecordData(datarecord_id)

    # Parse the returned data to get the listing of files
    return _buildFileListJSON(dr_data, dict())


def _buildFileListJSON(datarecord_list, file_list):
    """
    Traverses a JSON representation of a list of ODR datarecords, and extracts file information from it.

    Returns a dict of file information.
    """

    for dr in datarecord_list['datarecords']:
        # Grab the name to identify this datarecord by
        dr_name = dr['datarecord_name']

        for df_name, df in dr['datafields'].items():
            # In earlier versions of the spec, the datafield name is a value inside the datafield object
            if 'datafield_name' in df:
                df_name = df['datafield_name']

            if 'files' in df:
                for f in df['files']:
                    # Grab the identifiers for each file
                    f_id = f['internal_id']
                    f_name = f['original_name']

                    # Store them in the dict
                    data = dict()
                    data['filename'] = f_name
                    data['datafield_name'] = df_name
                    data['datarecord_name'] = dr_name

                    file_list[f_id] = data

        # If this datarecord has children, recursively iterate through them looking for files as well
        if 'child_datarecords' in dr:
            for child_dt_name, child_dt in dr['child_datarecords'].items():
                file_list = _buildFileListJSON(child_dt, file_list)

    return file_list


def downloadImageToDisk(image_id):
    """
    Attempts to download the specified image from ODR, and saves it in the user's notebook directory.

    Returns the path to the downloaded image, or some other error...
    """

    # Ensure arguments are valid
    if not isinstance(image_id, int):
        raise ValueError('image_id must be numeric')

    # Attempt to download the image from ODR
    api_url = os.environ['ODR_BASEURL'] + '/api/image_download/' + str(image_id)
    r = _makeFileRequest(api_url)

    # Name of image is stored within the Content-Disposition header, which is 'attachment; filename="<filename>";'
    filename_header = r.headers['Content-Disposition']
    final_filename = filename_header[22:-2]

    # Apparently Symfony doesn't send a 'Content-Length' header despite ODR specifying one?
#    expected_filesize = float(r.headers['Content-Length']) / 1024.0 / 1024.0

    # Download the image from ODR
    temp_filename = _id_generator() + '.tmp'
    with open(temp_filename, 'wb') as fd:
        for chunk in r.iter_content(chunk_size=65536):  # Attempt to read 64kb at a time
            fd.write(chunk)

    # Rename the downloaded image to match the original name provided by the server
    shutil.move(temp_filename, final_filename)

    print( 'Saved "' + final_filename + '"' )

    return final_filename


def downloadFileToDisk(file_id):
    """
    Attempts to download the specified file from ODR, and saves it in the user's notebook directory.

    Returns the path to the downloaded file, or some other error...
    """

    # Ensure arguments are valid
    if not isinstance(file_id, int):
        raise ValueError('file_id must be numeric')

    # Attempt to download the file from ODR
    api_url = os.environ['ODR_BASEURL'] + '/api/file_download/' + str(file_id)
    r = _makeFileRequest(api_url)

    # Name of file is stored within the Content-Disposition header, which is 'attachment; filename="<filename>";'
    filename_header = r.headers['Content-Disposition']
    final_filename = filename_header[22:-2]

    # Apparently Symfony doesn't send a 'Content-Length' header despite ODR specifying one?
#    expected_filesize = float(r.headers['Content-Length']) / 1024.0 / 1024.0

    # Download the file from ODR
    temp_filename = _id_generator() + '.tmp'
    with open(temp_filename, 'wb') as fd:
        for chunk in r.iter_content(chunk_size=65536):  # Attempt to read 64kb at a time
            fd.write(chunk)

    # Rename the downloaded file to match the original name provided by the server
    shutil.move(temp_filename, final_filename)

    print( 'Saved "' + final_filename + '"' )

    return final_filename


def downloadFile(file_id):
    """
    Attempts to download the specified file from ODR.

    Returns the contents of the file as a single binary string, since that's easier for NumPy to handle.
    """

    # Ensure arguments are valid
    if not isinstance(file_id, int):
        raise ValueError('file_id must be numeric')

    # Attempt to download the file from ODR
    api_url = os.environ['ODR_BASEURL'] + '/api/file_download/' + str(file_id)
    r = _makeFileRequest(api_url)

    # Name of file is stored within the Content-Disposition header, which is 'attachment; filename="<filename>";'
    filename_header = r.headers['Content-Disposition']
    final_filename = filename_header[22:-2]

    # Apparently Symfony doesn't send a 'Content-Length' header despite ODR specifying one?
#    expected_filesize = float(r.headers['Content-Length']) / 1024.0 / 1024.0

    # Download the file from ODR
    file_contents = b""
    for chunk in r.iter_content(chunk_size=65536):  # Attempt to read 64kb at a time
        file_contents = b"".join([file_contents, chunk])

    return file_contents


def _makeFileRequest(api_url):
    """
    Attempts to download the specified file from ODR.

    Returns the request object holding the file download response
    """

    # Run the http request
    access_token = __getAccessToken()
    r = requests.get(api_url + '?access_token=' + access_token, stream=True)

    if (r.status_code == 200):
        # Nothing went wrong, return the response
        return r
    else:
        data = json.loads(r.text)

        if "error_description" in data:
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
                r = requests.get(api_url + '?access_token=' + new_access_token, stream=True)
                if (r.status_code == 200):
                    # Nothing went wrong, return the response
                    return r
                else:
                    data = json.loads(r.text)

                    if "error_description" in data:
                        # Got an OAUth error again, most likely the refresh token is no longer valid
                        raise RuntimeError( data['error_description'] + "\nTry logging out, and then logging back into JupyterHub to fix this...")
                    else:
                        # Some ODR error, or something completely unexpected happened on the second request, abort
                        raise RuntimeError( data )

            else:
                # Some other unexpected OAuth error, abort
                raise RuntimeError( data['error_description'] )

        else:
            # Some ODR error, or something completely unexpected happened on the first request, abort
            raise RuntimeError( data )
