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
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
// Symfony
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class BridgeController extends ODRCustomController
{

    /**
     * Returns the user's Jupyterhub username.
     *
     * @param ODRUser $user
     *
     * @return string
     */
    private function getJupyterhubUsername($user)
    {
        // TODO - something more sophisticated?
        return 'jupyter_user_'.$user->getId();
    }


    /**
     * Loads and returns a list of available "apps" to run inside Jupyterhub.
     *
     * @return array
     */
    private function loadAvailableJupyterhubApps()
    {
        // TODO - load from a directory or parameter file somewhere...
        $app_list = array(
            0 => array(
                'id' => 'app_a',
                'name' => 'Raman Rollup Graph by Sample',
            ),
            1 => array(
                'id' => 'app_b',
                'name' => 'Raman Rollup Graph by Wavelength',
            ),
            2 => array(
                'id' => 'app_c',
                'name' => 'Mars Average Soil',
            ),
            3 => array(
                'id' => 'app_d',
                'name' => 'XRD Rollup Graph',
            ),
            4 => array(
                'id' => 'app_e',
                'name' => 'Peak Fit',
            )
        );

        return $app_list;
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

            if ($user !== 'anon.' /*&& $user->hasRole('ROLE_JUPYTERHUB_USER')*/ ) {
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

            // Otherwise, user isn't allowed to do this
            throw new ODRForbiddenException();
        }
        catch (\Exception $e) {
            $source = 0xfd346a45;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Load and return a listing of all available "apps" in jupyterhub as an HTML select.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function applistAction($datatype_id, Request $request)
    {
        try {
            // ----------------------------------------
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ($user === 'anon.')
                throw new ODRForbiddenException();

            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'dt_view' ]) )
                $can_view_datatype  = true;

            if ( !($datatype->isPublic() || $can_view_datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Load the list of available "apps"
            $app_list = self::loadAvailableJupyterhubApps();

            // TODO - do something to reduce the list

            // Sort the array by app name
            usort($app_list, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });


            // ----------------------------------------
            // Return the applist as a json array
            $templating = $this->get('templating');
            $html = $templating->render(
                'ODROpenRepositoryJupyterhubBridgeBundle:Default:app_list.html.twig',
                array(
                    'app_list' => $app_list
                )
            );

            return new JsonResponse( array('html' => $html) );
        }
        catch (\Exception $e) {
            $source = 0x1c91d10f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Attempts to export the current set of search results to a specific jupyterhub file...
     *
     * @param Request $request
     *
     * @return JsonResponse|RedirectResponse
     */
    public function jupyterhubexportAction(Request $request)
    {
        try {
            // ----------------------------------------
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Going to need these...
            $jupyterhub_config = $this->container->getParameter('jupyterhub_config');
            $jupyterhub_server_baseurl = $jupyterhub_config['jupyterhub_baseurl'];

            $jupyterhub_api_baseurl = $jupyterhub_server_baseurl.'/hub/api';
            $jupyterhub_api_key = $jupyterhub_config['api_key'];

            // Extract parameters from the $_POST request
            $parameters = $request->request;
            if ( !$parameters->has('datatype_id') || !$parameters->has('search_key') || !$parameters->has('app_id') )
                throw new ODRBadRequestException('Invalid form');

            $datatype_id = $parameters->get('datatype_id');
            $search_key = urldecode( $parameters->get('search_key') );
            $app_id = $parameters->get('app_id');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ($user === 'anon.')
                throw new ODRForbiddenException();

            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $username = self::getJupyterhubUsername($user);


            // ----------------------------------------
            // Save which datarecords this search covers
            $saved_search = parent::getSavedSearch($em, $user, $user_permissions['datatypes'], $user_permissions['datafields'], $datatype_id, $search_key, $request);

            // If no datarecords, then don't continue
            if ( strlen($saved_search['datarecord_list']) == 0 )
                throw new ODRNotFoundException('No datarecords found', true);


            // ----------------------------------------
            // Ensure the requested "app" exists...
            $app_list = self::loadAvailableJupyterhubApps();

            $found = false;
            foreach ($app_list as $app) {
                if ($app['id'] == $app_id) {
                    $found = true;
                    break;
                }
            }

            // If nothing found, notify user
            if (!$found)
                throw new ODRNotFoundException('Invalid app', true);


            // ----------------------------------------
            // TODO - ensure the user is logged in to jupyterhub?  if that is the case, then ODR doesn't need to worry about getting the user an oauth token...

            // Attempt to ensure the user's jupyterhub server is started
            self::startJupyterhubServerFor($user, $jupyterhub_api_baseurl, $jupyterhub_api_key);

            $new_notebook_name = self::createJupyterhubNotebook($jupyterhub_server_baseurl, $jupyterhub_api_key, $user, $app_id, $saved_search);

            // Instruct the user's browser to redirect to the new notebook
            return new RedirectResponse($jupyterhub_server_baseurl.'/hub/user/'.$username.'/notebooks/'.$new_notebook_name, 303);   // 303 status code is intentional

            // Returning a 302 redirect here causes IE to redirect to the given url by sending a POST request...most other browsers send a GET request
            // Oddly enough, IE is the one that's following the original HTTP spec...a 302 response to a POST should technically send another POST to the given redirect URL

        }
        catch (\Exception $e) {
            $source = 0xa7b712c8;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
     * @param string $app_id
     * @param array $saved_search
     *
     * @throws \Exception
     *
     * @return string
     */
    private function createJupyterhubNotebook($jupyterhub_server_baseurl, $jupyterhub_api_key, $user, $app_id, $saved_search)
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
            'plugin_name' => $app_id,
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


    /**
     * Instructs the jupyterhub server to shutdown.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function hubshutdownAction(Request $request)
    {
        try {
            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();


            // Going to need these...
            $jupyterhub_config = $this->container->getParameter('jupyterhub_config');
            $jupyterhub_server_baseurl = $jupyterhub_config['jupyterhub_baseurl'];

            $jupyterhub_api_baseurl = $jupyterhub_server_baseurl.'/hub/api';
            $jupyterhub_api_key = $jupyterhub_config['api_key'];

            $jupyterhub_api_url = $jupyterhub_api_baseurl.'/shutdown';


            // Need to use cURL to send a POST request...thanks symfony
            $ch = curl_init();
/*
            $parameters = array(
                'proxy' => true,    // ensure the proxy and any open notebook servers are shutdown as well
                'servers' => true,
            );
            $parameters = json_encode($parameters);
*/

            // Set the options for the POST request
            $headers = array();
            $headers[] = 'Authorization: token '.$jupyterhub_api_key;

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
//                    CURLOPT_POSTFIELDS => http_build_query($parameters),  // TODO - complains about invalid json in the body

                    CURLOPT_SSL_VERIFYPEER => 0,        // TODO - temporary

                    // Debug options
//                CURLOPT_HEADER => 1,
//                CURLINFO_HEADER_OUT => 1,
                )
            );

            // Execute the cURL request, and check for errors
            $ret = curl_exec($ch);

//            $status_code = intval( curl_getinfo($ch, CURLINFO_HTTP_CODE) );
//            exit( print_r(curl_getinfo($ch), true)."\n\n\n".print_r($ret, true) );

            if (!$ret) {
                // Attempt to throw an exception with details...
                throw new \Exception('Error when instructing jupyterhub server to shutdown: '.curl_error($ch) );
            }

            return new Response('Hub shutdown successfully');
        }
        catch (\Exception $e) {
            $source = 0x558b2f44;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
