<?php

/**
 * Open Data Repository Data Publisher
 * Jupyterhub Bridge Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Handles requests to/from Jupyterhub installations.
 */

namespace ODR\OpenRepository\JupyterhubBridgeBundle\Controller;

use ODR\AdminBundle\Controller\ODRCustomController;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;


class BridgeController extends ODRCustomController
{
    /**
     * Utility function to cleanly return JSON error responses.
     *
     * @param integer $status_code
     * @param string|null $message
     *
     * @return JsonResponse
     */
    private function createJSONError($status_code, $message = null)
    {
        // Change 403 codes to 401 if user isn't logged in
        $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

        $logged_in = true;
        if ($user === 'anon.')
            $logged_in = false;

        if (!$logged_in && $status_code == 403)
            $status_code = 401;

        // Return an error response
        return new JsonResponse(
            array(
                'error_description' => $message     // TODO
            ),
            $status_code
        );
    }


    /**
     * TODO
     *
     * @param ODRUser $user
     *
     * @return string
     */
    private function getJupyterhubUsername($user)
    {
        return 'jupyter_user_'.$user->getId();
    }


    /**
     * Used by JupyterHub to determine which user has logged in via ODR's OAuth
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function userdataAction(Request $request)
    {
        try {
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if ($user !== 'anon.' && $user->hasRole('ROLE_JUPYTERHUB_USER')) {
                return new JsonResponse(
                    array(
                        'id' => $user->getEmail(),
                        'username' => $user->getUserString(),
                        'realname' => $user->getUserString(),
                        'email' => $user->getEmail(),
                        'jupyterhub_username' => self::getJupyterhubUsername($user),
                        'baseurl' => $this->getParameter('site_baseurl'),
                    )
                );
            }
            else {
                return self::createJSONError(403, 'Permission Denied');
            }
        }
        catch (\Exception $e) {
            return self::createJSONError(500, $e->getMessage());
        }
    }


    /**
     * Attempts to export the current set of search results to a specific jupyterhub file...
     *
     * @param integer $datatype_id
     * @param string $search_key
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function jupyterhubexportAction($datatype_id, $search_key, Request $request)
    {
        try {
            // ----------------------------------------
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                return self::createJSONError(404, 'Datatype is deleted');


            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());

            // Only procede if the user is logged in
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ($user === 'anon.')
                throw new AccessDeniedException();
            $username = self::getJupyterhubUsername($user);


            // Going to need these...
            $jupyterhub_config = $this->container->getParameter('jupyterhub_config');
            $jupyterhub_server_baseurl = $jupyterhub_config['jupyterhub_baseurl'];

            $jupyterhub_api_baseurl = $jupyterhub_server_baseurl.'/hub/api';
            $jupyterhub_api_key = $jupyterhub_config['api_key'];


            // ----------------------------------------
            // TODO - ensure the user has a jupyterhub account?

            // TODO - ensure the user is logged in to jupyterhub?  if that is the case, then ODR doesn't need to worry about getting the user an oauth token...

            // Attempt to ensure the user's jupyterhub server is started
            self::startJupyterhubServerFor($user, $jupyterhub_api_baseurl, $jupyterhub_api_key);

            // Instruct jupyterhub to create a notebook to run off the saved search
            $saved_search = parent::getSavedSearch($em, $user, $user_permissions['datatypes'], $user_permissions['datafields'], $datatype_id, $search_key, $request);

            $plugin_name = 'TODO';
            $new_notebook_name = self::createJupyterhubNotebook($jupyterhub_server_baseurl, $jupyterhub_api_key, $user, $plugin_name, $saved_search);


            // Instruct the user's browser to redirect to the new notebook
            $data = array(
                'redirect_url' => $jupyterhub_server_baseurl.'/hub/user/'.$username.'/notebooks/'.$new_notebook_name
            );

            return new JsonResponse($data);
        }
        catch (\Exception $e) {
            return self::createJSONError(500, $e->getMessage());
        }
    }


    /**
     * Ensures a user's jupyterhub server has been started
     *
     * @param ODRUser $user
     * @param string $jupyterhub_api_baseurl
     * @param string $jupyterhub_api_key
     *
     * @throws \Exception
     */
    private function startJupyterhubServerFor($user, $jupyterhub_api_baseurl, $jupyterhub_api_key)
    {
        // Need to use cURL to send a POST request...thanks symfony
        $ch = curl_init();

        // Set the options for the POST request
        $headers = array();
        $headers[] = 'Authorization: token '.$jupyterhub_api_key;

        // Juypyterhub API url is of the form  /users/{name}/server
        $username = self::getJupyterhubUsername($user);
        $jupyterhub_api_url = $jupyterhub_api_baseurl.'/users/'.$username.'/server';

        curl_setopt_array(
            $ch,
            array(
                CURLOPT_POST => 1,
                CURLOPT_URL => $jupyterhub_api_url,
                CURLOPT_FRESH_CONNECT => 1,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_FORBID_REUSE => 1,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => $headers,

                CURLOPT_SSL_VERIFYPEER => 0,        // TODO - temporary

                // Debug options
//                CURLOPT_HEADER => 1,
//                CURLINFO_HEADER_OUT => 1,
            )
        );

        // Execute the cURL request, and check for errors
        $ret = curl_exec($ch);
        $status_code = intval( curl_getinfo($ch, CURLINFO_HTTP_CODE) );

//        exit( print_r(curl_getinfo($ch), true)."\n\n\n".print_r($ret, true) );

        if( !$ret ) {
            if ( $status_code == 201 || $status_code == 202 ) {
                // Not actually a cURL error, do nothing
            }
            else {
                // Attempt to throw an exception with details...
                throw new \Exception('Error starting server for ('.$username.'): '.curl_error($ch) );
            }
        }
        else if ( $status_code >= 401 ) {
            // Some error from jupyterhub?
            $obj = json_decode($ret);
            $message = $obj->message;

            throw new \Exception('Error starting server for ('.$username.'): '.$message);
        }

        // The Jupyterhub API can also return a 400 indicating the server is already running...
        // ...but that isn't really an error from ODR's point of view
    }


    /**
     * Instructs the jupyterhub server to create a new notebook to run the desired search terms
     *
     * @param string $jupyterhub_server_baseurl
     * @param string $jupyterhub_api_key
     * @param ODRUser $user
     * @param string $plugin_name
     * @param array $saved_search
     *
     * @throws \Exception
     *
     * @return string
     */
    private function createJupyterhubNotebook($jupyterhub_server_baseurl, $jupyterhub_api_key, $user, $plugin_name, $saved_search)
    {
        // Need to use cURL to send a POST request...thanks symfony
        $ch = curl_init();

        // Set the options for the POST request
        $headers = array();
        $headers[] = 'Authorization: token '.$jupyterhub_api_key;

        // Juypyterhub API url is of the form
        $username = self::getJupyterhubUsername($user);
        $jupyterhub_api_url = $jupyterhub_server_baseurl.'/services/odr_bridge/create_notebook';

        $jupyterhub_config = $this->container->getParameter('jupyterhub_config');
        $bridge_token = $jupyterhub_config['bridge_token'];

        $parameters = array(
            'bridge_token' => $bridge_token,
            'username' => self::getJupyterhubUsername($user),
            'plugin_name' => $plugin_name,
            'datarecord_list' => $saved_search['datarecord_list'],      // TODO - or send the search key instead?
        );

        curl_setopt_array(
            $ch,
            array(
                CURLOPT_POST => 1,
                CURLOPT_URL => $jupyterhub_api_url,
                CURLOPT_FRESH_CONNECT => 1,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_FORBID_REUSE => 1,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => http_build_query($parameters),

                CURLOPT_SSL_VERIFYPEER => 0,        // TODO - temporary

                // Debug options
//                CURLOPT_HEADER => 1,
//                CURLINFO_HEADER_OUT => 1,
            )
        );


        // Execute the cURL request, and check for errors
        $ret = curl_exec($ch);

//        $status_code = intval( curl_getinfo($ch, CURLINFO_HTTP_CODE) );
//        exit( print_r(curl_getinfo($ch), true)."\n\n\n".print_r($ret, true) );

        if( !$ret ) {
            // Attempt to throw an exception with details...
            throw new \Exception('Error starting server for ('.$username.'): '.curl_error($ch) );
        }

        $ret = json_decode($ret);
//        exit( print_r($ret, true) );
        return $ret->notebook_path;
    }
}
