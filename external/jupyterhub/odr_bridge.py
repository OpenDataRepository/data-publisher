# Open Data Repository Data Publisher
# ODR Bridge
# (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
# (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
# Released under the GPLv2

"""
Defines a Tornado web application as a Jupyterhub service that manages creation of notebooks to run on searches done by ODR.
"""

import datetime
import nbformat as nbf
import os
import random
import shutil

from tornado.escape import json_decode
from tornado.gen import coroutine
from tornado.ioloop import IOLoop
from tornado.log import app_log
from tornado.web import Application, HTTPError, RequestHandler


class BridgeRequestHandler(RequestHandler):
    pass


class CreateNotebook(BridgeRequestHandler):
    """
    """

    def post(self):
        # Since request will have the mimetype 'application/x-www-form-urlencoded', have to use tornado.RequestHandler.get_body_argument() instead of tornado.escape.json_decode()
        # They'll automatically throw 400 status codes if the arguments don't exist
        self.bridge_token = self.get_body_argument('bridge_token')
        self.username = self.get_body_argument('username')
        self.plugin_name = self.get_body_argument('plugin_name')
        self.datarecord_list = self.get_body_argument('datarecord_list')

        # Ensure that only JupyterHub can access this method...
        if not ( self.bridge_token == os.environ['bridge_token'] ):
            raise HTTPError(403)

        # Locate and read the desired notebook
        notebook_info = self.getNotebookPath(self.plugin_name)
        nb = nbf.read(notebook_info['path'], as_version=4)

        # Insert any parameters for the new notebook in a cell before the rest of the notebook
        code = self.getCodeToInsert(notebook_info['language'], self.datarecord_list)
        nb['cells'].insert(0, nbf.v4.new_code_cell(code))

        # Determine the name for the new notebook
        current_date = datetime.datetime.now().strftime ("%Y%m%d")
        random_id = random.randrange(100000, 999999)
        notebook_name = 'ODR_export_' + current_date + '_' + str(random_id) + notebook_info['extension']

        # Write the notebook into the user's directory
        final_path = '/home/' + self.username + '/' + notebook_name
        with open(final_path, 'w') as f:
            nbf.write(nb, f)

        # Change the new notebook's owner so it's automatically trusted
        shutil.chown(final_path, self.username, self.username)

        # Return the name of the new notebook to ODR
        self.write({'notebook_path': notebook_name})


    def getNotebookPath(self, plugin_name):
        """
        Given the identifier of some app/plugin/whatever, returns a dict containing the path to the notebook, which language it's written in, and what extension it should use
        """
        if (plugin_name == 'app_a'):
            return {'path': '/root/jupyterhub_apps/raman_graph_by_sample.ipynb', 'language': 'python', 'extension': '.ipynb'}
        elif (plugin_name == 'app_b'):
            return {'path': '/root/jupyterhub_apps/raman_graph_by_wavelength.ipynb', 'language': 'python', 'extension': '.ipynb'}
        elif (plugin_name == 'app_c'):
            return {'path': '/root/jupyterhub_apps/mars_average_soil_composition.ipynb', 'language': 'python', 'extension': '.ipynb'}
        else:
            return {'path': '/root/jupyterhub_apps/raman_graph_by_sample.ipynb', 'language': 'python', 'extension': '.ipynb'}


    def getCodeToInsert(self, notebook_language, datarecord_list):
        """
        Returns a string of code to insert at the beginning of the rewritten notebook, so it has access to the correct variables to do its job
        """
        if (notebook_language == 'python'):
            return "_odr_datarecord_list = [" + datarecord_list + "]"


def make_app():
    # All registered urls MUST be prefixed with os.environ['JUPYTERHUB_SERVICE_PREFIX']
    return Application([
        ( os.environ['JUPYTERHUB_SERVICE_PREFIX'] + "/create_notebook", CreateNotebook),
    ])

if __name__ == '__main__':
    app = make_app()
    app.listen( os.environ['port_number'] )

    # Apparently need these lines so the service exits cleanly when the hub is shut down
    try:
        IOLoop.current().start()
    except KeyboardInterrupt:
        pass
