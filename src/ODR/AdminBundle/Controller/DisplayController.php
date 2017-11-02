<?php

/**
 * Open Data Repository Data Publisher
 * Display Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The display controller displays actual record results to the
 * user, executing render plugins as necessary to change how the
 * data looks.  It also handles file and image downloads because
 * of security concerns and routing constraints within Symfony.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Controllers/Classes
use ODR\OpenRepository\SearchBundle\Controller\DefaultController as SearchController;
// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;


class DisplayController extends ODRCustomController
{

    /**
     * Returns the "Results" version of the given DataRecord.
     * 
     * @param integer $datarecord_id The database id of the datarecord to return.
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     * @param Request $request
     * 
     * @return Response
     */
    public function viewAction($datarecord_id, $search_key, $offset, Request $request) 
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $session = $request->getSession();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            // ----------------------------------------
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Save in case the user requested a child datarecord
            $requested_datarecord = $datarecord;
            $requested_datatype = $datatype;

            // Want the grandparent datarecord and datatype for everything else
            $is_top_level = 1;
            if ( $datarecord->getId() !== $datarecord->getGrandparent()->getId() ) {
                // This is a child datatype
                $is_top_level = 0;
                $datarecord = $datarecord->getGrandparent();
                if ($datarecord->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datarecord');

                $datatype = $datarecord->getDataType();
                if ($datatype->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datatype');
            }


            // ----------------------------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);
            $datatype_permissions = $pm_service->getDatatypePermissions($user);
            $datafield_permissions = $pm_service->getDatafieldPermissions($user);

            if ( !$pm_service->canViewDatatype($user, $datatype) || !$pm_service->canViewDatarecord($user, $datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // If this datarecord is being viewed from a search result list...
            $datarecord_list = '';
            $encoded_search_key = '';
            if ($search_key !== '') {
                // ...attempt to grab the list of datarecords from that search result
                $data = parent::getSavedSearch($em, $user, $datatype_permissions, $datafield_permissions, $datatype->getId(), $search_key, $request);
                $encoded_search_key = $data['encoded_search_key'];
                $datarecord_list = $data['datarecord_list'];

                if (!$data['redirect'] && $encoded_search_key !== '' && $datarecord_list === '') {
                    // Some sort of error encounted...bad search query, invalid permissions, or empty datarecord list
                    /** @var SearchController $search_controller */
                    $search_controller = $this->get('odr_search_controller', $request);
                    return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
                }
                else if ($data['redirect']) {
                    $url = $this->generateUrl('odr_display_view', array('datarecord_id' => $datarecord_id, 'search_key' => $encoded_search_key, 'offset' => 1));
                    return parent::searchPageRedirect($user, $url);
                }
            }


            // ----------------------------------------
            // Grab the tab's id, if it exists...Prefer the use of the sorted lists created during usage of the datatables plugin over the default list created during searching
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];

            // Locate a sorted list of datarecords for search_header.html.twig if possible
            if ( $session->has('stored_tab_data') && $odr_tab_id !== '' ) {
                $stored_tab_data = $session->get('stored_tab_data');

                if ( isset($stored_tab_data[$odr_tab_id]) && isset($stored_tab_data[$odr_tab_id]['datarecord_list']) ) {
                    $dr_list = explode(',', $stored_tab_data[$odr_tab_id]['datarecord_list']);
                    if ( !in_array($datarecord->getId(), $dr_list) ) {
                        // There's some sort of mismatch between the URL the user wants and the data stored by the tab id...wipe the tab data and just use the search results
                        unset( $stored_tab_data[$odr_tab_id] );
                    }
                    else {
                        // Otherwise, use the sorted list stored in the user's session
                        $datarecord_list = $stored_tab_data[$odr_tab_id]['datarecord_list'];

                        // Grab start/length from the datatables state object if it exists
                        if ( isset($stored_tab_data[$odr_tab_id]['state']) ) {
                            $start = intval($stored_tab_data[$odr_tab_id]['state']['start']);
                            $length = intval($stored_tab_data[$odr_tab_id]['state']['length']);

                            // Calculate which page datatables says it's on
                            $datatables_page = 0;
                            if ($start > 0)
                                $datatables_page = $start / $length;
                            $datatables_page++;

                            // If the offset doesn't match the page, update it
                            if ( $offset !== '' && intval($offset) !== intval($datatables_page) ) {
                                $new_start = strval( (intval($offset) - 1) * $length );

                                $stored_tab_data[$odr_tab_id]['state']['start'] = $new_start;
                                $session->set('stored_tab_data', $stored_tab_data);
                            }
                        }
                    }
                }
            }


            // ----------------------------------------
            // Build an array of values to use for navigating the search result list, if it exists
            $search_header = parent::getSearchHeaderValues($datarecord_list, $datarecord->getId(), $request);

            $router = $this->get('router');
            $templating = $this->get('templating');

            $redirect_path = $router->generate('odr_display_view', array('datarecord_id' => 0));    // blank path
            $header_html = $templating->render(
                'ODRAdminBundle:Display:display_header.html.twig',
                array(
                    'user_permissions' => $datatype_permissions,
                    'datarecord' => $datarecord,
                    'datatype' => $datatype,

                    // values used by search_header.html.twig
                    'search_key' => $encoded_search_key,
                    'offset' => $offset,
                    'page_length' => $search_header['page_length'],
                    'next_datarecord' => $search_header['next_datarecord'],
                    'prev_datarecord' => $search_header['prev_datarecord'],
                    'search_result_current' => $search_header['search_result_current'],
                    'search_result_count' => $search_header['search_result_count'],
                    'redirect_path' => $redirect_path,
                )
            );


            // ----------------------------------------
            // Load the cached versions of all datarecords, datatypes, and themes that will be
            //  needed for rendering this datarecord
            $datarecord_array = $dri_service->getDatarecordArray($datarecord->getId());
            $datatype_array = $dti_service->getDatatypeArray($datatype->getId());
            $theme_array = $theme_service->getThemesForDatatype($datatype->getId(), $user);


            // ----------------------------------------
            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            // Intentionally leaving the theme array alone
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


            // "Inflate" the currently flattened $datarecord_array and $datatype_array...
            // This is required so that render plugins for a datatype can also correctly render
            //  that datatype's child/linked datatypes
            $stacked_datarecord_array[ $requested_datarecord->getId() ] =
                $dri_service->stackDatarecordArray($datarecord_array, $requested_datarecord->getId());
            $stacked_datatype_array[ $requested_datatype->getId() ] =
                $dti_service->stackDatatypeArray($datatype_array, $requested_datatype->getId());


            // ----------------------------------------
            // Render the DataRecord
            $templating = $this->get('templating');
            $page_html = $templating->render(
                'ODRAdminBundle:Display:display_ajax.html.twig',
                array(
                    'datatype_array' => $stacked_datatype_array,
                    'datarecord_array' => $stacked_datarecord_array,
                    'theme_array' => $theme_array,

                    'initial_datatype_id' => $requested_datatype->getId(),
                    'initial_datarecord_id' => $requested_datarecord->getId(),

                    'is_top_level' => $is_top_level,
                    'search_key' => $search_key,
                    'user' => $user,
                    'record_display_view' => 'single',
                )
            );

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $header_html.$page_html
            );

            // Store which datarecord to scroll to if returning to the search results list
            $session->set('scroll_target', $datarecord->getId());
        }
        catch (\Exception $e) {
            $source = 0x8f465413;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Given a datarecord and datafield, re-render and return the html for that datafield.
     * TODO - I believe the old version of file handling was the only thing that used this...
     * @deprecated
     *
     * @param integer $datarecord_id
     * @param integer $datafield_id
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function reloaddatafieldAction($datarecord_id, $datafield_id, $theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');
            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');


            // Save incase the user originally requested a re-render of a datafield from a child datarecord
            $original_datarecord = $datarecord;
            $original_datatype = $datatype;

            // ...want the grandparent datarecord and datatype for everything else, however
            if ( $datarecord->getId() !== $datarecord->getGrandparent()->getId() ) {
                $datarecord = $datarecord->getGrandparent();
                if ($datarecord->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datarecord');

                $datatype = $datarecord->getDataType();
                if ($datatype->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datatype');
            }


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = array();
            $datarecord_restriction = '';

            if ( $user === 'anon.' ) {
                if ( $datatype->isPublic() && $datarecord->isPublic() && $datafield->isPublic() ) {
                    /* anonymous users aren't restricted from a public datafield in a public datarecord that belongs to a public datatype */
                }
                else {
                    // ...if any of the relevant entities are non-public, return false
                    throw new ODRForbiddenException();
                }
            }
            else {
                // Grab user's permissions
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];
                $datafield_permissions = $user_permissions['datafields'];
//                $datarecord_restriction = $user_permissions['datarecord_restriction'];      // TODO

                $can_view_datatype = false;
                if ( isset($datatype_permissions[ $original_datatype->getId() ]) && isset($datatype_permissions[ $original_datatype->getId() ][ 'dt_view' ]) )
                    $can_view_datatype = true;

                $can_view_datarecord = false;
                if ( isset($datatype_permissions[ $original_datatype->getId() ]) && isset($datatype_permissions[ $original_datatype->getId() ][ 'dr_view' ]) )
                    $can_view_datarecord = true;

                $can_view_datafield = false;
                if ( isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'view' ]) )
                    $can_view_datafield = true;

                // If datatype is not public and user doesn't have permissions to view anything other than public sections of the datarecord, then don't allow them to view
                if ( !($original_datatype->isPublic() || $can_view_datatype)  || !($datarecord->isPublic() || $can_view_datarecord) || !($datafield->isPublic() || $can_view_datafield) )
                    throw new ODRForbiddenException();
            }
            // --------------------


            // ----------------------------------------
            // Get all Datarecords and Datatypes that are associated with the datarecord to render
            $datarecord_array = $dri_service->getDatarecordArray($datarecord->getId());
            $datatype_array = $dti_service->getDatatypeArray($datatype->getId());

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

            // Don't need to stack these two arrays, it'll just make finding the datafield harder

            // Extract datafield and theme_datafield from datatype_array
            $datafield = null;
            if ( isset($datatype_array['dataFields']) && isset($datatype_array['dataFields'][$datafield_id]) )
                $datafield = $datatype_array['dataFields'][$datafield_id];

            if ( $datafield == null )
                throw new ODRException('Unable to locate array entry for datafield '.$datafield_id);


            // ----------------------------------------
            // Render and return the HTML for this datafield
            $templating = $this->get('templating');
            $html = $templating->render(
                'ODRAdminBundle:Display:display_datafield.html.twig',
                array(
                    'datarecord' => $datarecord_array[ $original_datarecord->getId() ],
                    'datafield' => $datafield,

                    'image_thumbnails_only' => false,
                )
            );

            $return['d'] = array('html' => $html);
        }
        catch (\Exception $e) {
            $source = 0xb667f28f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Starts the process of downloading a file from the server.
     *
     * @param integer $file_id The database id of the file to download.
     * @param Request $request
     *
     * @return Response
     */
    public function filedownloadstartAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $redis_prefix = $this->getParameter('memcached_key_prefix');     // debug purposes only

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Locate the file in the database
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');

            $datafield = $file->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datarecord = $file->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files that aren't done encrypting shouldn't be downloaded
            if ($file->getProvisioned() == true)
                throw new ODRNotFoundException('File');


            // ----------------------------------------
            // First, ensure user is permitted to download
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if (!$pm_service->canViewDatatype($user, $datatype)
                || !$pm_service->canViewDatarecord($user, $datarecord)
                || !$pm_service->canViewDatafield($user, $datafield)
            ) {
                throw new ODRForbiddenException();
            }
            // ----------------------------------------


            // ----------------------------------------
            // Generate the url for cURL to use
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_crypto_request');

            $api_key = $this->container->getParameter('beanstalk_api_key');
            $file_decryptions = $cache_service->get('file_decryptions');


            // ----------------------------------------
            // Slightly different courses of action depending on the public status of the file
            if ( $file->isPublic() ) {
                // Check that the file exists...
                $local_filepath = realpath( dirname(__FILE__).'/../../../../web/'.$file->getLocalFileName() );
                if (!$local_filepath) {
                    // File does not exist for some reason...see if it's getting decrypted right now
                    $target_filename = 'File_'.$file_id.'.'.$file->getExt();

                    if ( !isset($file_decryptions[$target_filename]) ) {
                        // File is not scheduled to get decrypted at the moment, store that it will be decrypted
                        $file_decryptions[$target_filename] = 1;
                        $cache_service->set('file_decryptions', $file_decryptions);

                        // Schedule a beanstalk job to start decrypting the file
                        $priority = 1024;   // should be roughly default priority
                        $payload = json_encode(
                            array(
                                "object_type" => 'File',
                                "object_id" => $file_id,
                                "target_filename" => $target_filename,
                                "crypto_type" => 'decrypt',

                                "archive_filepath" => '',
                                "desired_filename" => '',

                                "redis_prefix" => $redis_prefix,    // debug purposes only
                                "url" => $url,
                                "api_key" => $api_key,
                            )
                        );

                        //$delay = 1;
                        $delay = 0;
                        $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
                    }
                }
                else {
                    // Grab current filesize of file
                    clearstatcache(true, $local_filepath);
                    $current_filesize = filesize($local_filepath);

                    if ( $file->getFilesize() == $current_filesize ) {

                        // File exists and is fully decrypted, determine path to download it
                        $download_url = $this->generateUrl('odr_file_download', array('file_id' => $file_id));

                        // Return a link to the download URL
                        $response = new Response();
                        $response->setStatusCode(200);
                        $response->headers->set('Location', $download_url);

                        return $response;
                    }
                    else {
                        /* otherwise, decryption in progress, do nothing */
                    }
                }
            }
            else {
                // File is not public...see if it's getting decrypted right now
                // Determine the temporary filename for this file
                $target_filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId());
                $target_filename .= '.'.$file->getExt();

                if ( !isset($file_decryptions[$target_filename]) ) {
                    // File is not scheduled to get decrypted at the moment, store that it will be decrypted
                    $file_decryptions[$target_filename] = 1;
                    $cache_service->set('file_decryptions', $file_decryptions);

                    // Schedule a beanstalk job to start decrypting the file
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "object_type" => 'File',
                            "object_id" => $file_id,
                            "target_filename" => $target_filename,
                            "crypto_type" => 'decrypt',

                            "archive_filepath" => '',
                            "desired_filename" => '',

                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    //$delay = 1;
                    $delay = 0;
                    $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
                }

                /* otherwise, decryption already in progress, do nothing */
            }

            // Return a URL to monitor decryption progress
            $monitor_url = $this->generateUrl('odr_get_file_decrypt_progress', array('file_id' => $file_id));

            $response = new Response();
            $response->setStatusCode(202);
            $response->headers->set('Location', $monitor_url);

            return $response;
        }
        catch (\Exception $e) {
            $source = 0x9afc6f73;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates a Symfony response that so browsers can download files from the server.
     *
     * @param integer $file_id The database id of the file to download.
     * @param Request $request
     *
     * @return Response
     */
    public function filedownloadAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Locate the file in the database
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');

            $datafield = $file->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datarecord = $file->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files that aren't done encrypting shouldn't be downloaded
            if ($file->getProvisioned() == true)
                throw new ODRNotFoundException('File');


            // ----------------------------------------
            // First, ensure user is permitted to download
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if (!$pm_service->canViewDatatype($user, $datatype)
                || !$pm_service->canViewDatarecord($user, $datarecord)
                || !$pm_service->canViewDatafield($user, $datafield)
            ) {
                throw new ODRForbiddenException();
            }
            // ----------------------------------------


            // ----------------------------------------
            // Ensure file exists before attempting to download it
            $filename = 'File_'.$file_id.'.'.$file->getExt();
            if ( !$file->isPublic() )
                $filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId()).'.'.$file->getExt();

            $local_filepath = realpath( dirname(__FILE__).'/../../../../web/'.$file->getUploadDir().'/'.$filename );
            if (!$local_filepath)
                throw new FileNotFoundException($local_filepath);

            $response = self::createDownloadResponse($file, $local_filepath);

            // If the file is non-public, then delete it off the server...despite technically being deleted prior to serving the download, it still works
            if ( !$file->isPublic() && file_exists($local_filepath) )
                unlink($local_filepath);

            return $response;
        }
        catch (\Exception $e) {
            // Usually this'll be called via the jQuery fileDownload plugin, and therefore need a json-format error
            // But in the off-chance it's a direct link, then the error format needs to remain html
            if ( $request->query->has('error_type') && $request->query->get('error_type') == 'json' )
                $request->setRequestFormat('json');

            $source = 0xe3de488a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates (but does not start) a Symfony StreamedResponse to permit downloading of any size of file.
     *
     * @param File $file
     * @param string $absolute_filepath
     *
     * @throws \Exception
     *
     * @return StreamedResponse
     */
    private function createDownloadResponse($file, $absolute_filepath)
    {
        $response = new StreamedResponse();

        $handle = fopen($absolute_filepath, 'r');
        if ($handle === false)
            throw new \Exception('Unable to open existing file at "'.$absolute_filepath.'"');

        // Attach the original filename to the download
        $display_filename = $file->getOriginalFileName();
        if ($display_filename == null)
            $display_filename = 'File_'.$file->getId().'.'.$file->getExt();

        // Set up a response to send the file back
        $response->setPrivate();
        $response->headers->set('Content-Type', mime_content_type($absolute_filepath));
        $response->headers->set('Content-Length', filesize($absolute_filepath));
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$display_filename.'";');

        // Have to specify all these properties just so that the last one can be false...otherwise Flow.js can't keep track of the progress
        $response->headers->setCookie(
            new Cookie(
                'fileDownload', // name
                'true',         // value
                0,              // duration set to 'session'
                '/',            // default path
                null,           // default domain
                false,          // don't require HTTPS
                false           // allow cookie to be accessed outside HTTP protocol
            )
        );

        //$response->sendHeaders();

        // Use symfony's StreamedResponse to send the decrypted file back in chunks to the user
        $response->setCallback(function () use ($handle) {
            while (!feof($handle)) {
                $buffer = fread($handle, 65536);    // attempt to send 64Kb at a time
                echo $buffer;
                flush();
            }
            fclose($handle);
        });

        return $response;
    }


    /**
     * Provides users the ability to cancel the decryption of a file.
     * @deprecated
     *
     * @param integer $file_id  The database id of the file currently being decrypted
     * @param Request $request
     *
     * @return Response
     */
    public function cancelfiledecryptAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new ODRNotImplementedException();

            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            // Locate the file in the database
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                return parent::deletedEntityError('File');
            $datafield = $file->getDataField();
            if ($datafield == null)
                return parent::deletedEntityError('DataField');
            $datarecord = $file->getDataRecord();
            if ($datarecord == null)
                return parent::deletedEntityError('DataRecord');
            $datatype = $datarecord->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            // Files that aren't done encrypting shouldn't be downloaded
            if ($file->getEncryptKey() == '')
                return parent::deletedEntityError('File');

            // ----------------------------------------
            // Ensure user has permissions to be doing this
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            // Grab the user's permission list
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_view' ]) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dr_view' ]) )
                $can_view_datarecord = true;

            $can_view_datafield = false;
            if ( isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'view' ]) )
                $can_view_datafield = true;

            // If datatype is not public and user doesn't have permissions to view anything other than public sections of the datarecord, then don't allow them to view
            if ( !($datatype->isPublic() || $can_view_datatype)  || !($datarecord->isPublic() || $can_view_datarecord) || !($datafield->isPublic() || $can_view_datafield) )
                return parent::permissionDeniedError();
            // ----------------------------------------


            // ----------------------------------------
            // Only able to cancel downloads of non-public files...
            if ( !$file->isPublic() ) {

                // Determine the temporary filename being used to store the decrypted file
                $temp_filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId());
                $temp_filename .= '.'.$file->getExt();

                // Ensure that the memcached marker for the decryption of this file does not exist
                $file_decryptions = parent::getRedisData(($redis->get($redis_prefix.'_file_decryptions')));
                if ($file_decryptions != false && isset($file_decryptions[$temp_filename])) {
                    unset($file_decryptions[$temp_filename]);
                    $redis->set($redis_prefix.'_file_decryptions', gzcompress(serialize($file_decryptions)));
                }
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x68387321: ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Creates a Symfony response that so browsers can download images from the server.
     *
     * @param integer $image_id The database_id of the image to download.
     * @param Request $request
     *
     * @return Response
     */
    public function imagedownloadAction($image_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Locate the image object in the database
            /** @var Image $image */
            $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');

            $datafield = $image->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Images that aren't done encrypting shouldn't be downloaded
            if ($image->getEncryptKey() == '')
                throw new ODRNotFoundException('Image');


            // ----------------------------------------
            // Non-Public images are more work because they always need decryption...but first, ensure user is permitted to download
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if (!$pm_service->canViewDatatype($user, $datatype)
                || !$pm_service->canViewDatarecord($user, $datarecord)
                || !$pm_service->canViewDatafield($user, $datafield)
            ) {
                throw new ODRForbiddenException();
            }
            // ----------------------------------------


            // Ensure the image exists in decrypted format
            $image_path = realpath( dirname(__FILE__).'/../../../../web/'.$image->getLocalFileName() );     // realpath() returns false if file does not exist
            if ( !$image->isPublic() || !$image_path )
                $image_path = parent::decryptObject($image->getId(), 'image');

            $handle = fopen($image_path, 'r');
            if ($handle === false)
                throw new FileNotFoundException($image_path);

            // Have to send image headers first...
            $response = new Response();
            $response->setPrivate();
            switch ( strtolower($image->getExt()) ) {
                case 'gif':
                    $response->headers->set('Content-Type', 'image/gif');
                    break;
                case 'png':
                    $response->headers->set('Content-Type', 'image/png');
                    break;
                case 'jpg':
                case 'jpeg':
                    $response->headers->set('Content-Type', 'image/jpeg');
                    break;
            }

            // Attach the image's original name to the headers...
            $display_filename = $image->getOriginalFileName();
            if ($display_filename == null)
                $display_filename = 'Image_'.$image_id.'.'.$image->getExt();
            $response->headers->set('Content-Disposition', 'inline; filename="'.$display_filename.'";');

            $response->sendHeaders();

            // After headers are sent, send the image itself
            $im = null;
            switch ( strtolower($image->getExt()) ) {
                case 'gif':
                    $im = imagecreatefromgif($image_path);
                    imagegif($im);
                    break;
                case 'png':
                    $im = imagecreatefrompng($image_path);
                    imagepng($im);
                    break;
                case 'jpg':
                case 'jpeg':
                    $im = imagecreatefromjpeg($image_path);
                    imagejpeg($im);
                    break;
            }
            imagedestroy($im);

            fclose($handle);

            // If the image isn't public, delete the decrypted version so it can't be accessed without going through symfony
            if ( !$image->isPublic() )
                unlink($image_path);

            // Return the previously created response
            return $response;
        }
        catch (\Exception $e) {
            $source = 0xc2fbf062;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates and renders an HTML list of all files/images that the user is allowed to see in the given datarecord
     *
     * @param integer $grandparent_datarecord_id
     * @param integer $datarecord_id
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    function listallfilesAction($grandparent_datarecord_id, $datarecord_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Get necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataRecord $grandparent_datarecord */
            $grandparent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($grandparent_datarecord_id);
            if ($grandparent_datarecord == null)
                throw new ODRNotFoundException('Grandparent Datarecord');

            $grandparent_datatype = $grandparent_datarecord->getDataType();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datarecord');

            if ( ($datarecord_id === 0 && $datafield_id !== 0) || ($datarecord_id !== 0 && $datafield_id === 0) )
                throw new ODRBadRequestException();


            /** @var DataType $datatype */
            $datatype = null;

            /** @var DataRecord|null $datarecord */
            $datarecord = null;
            if ($datarecord_id !== 0) {
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
                if ($datarecord == null)
                    throw new ODRNotFoundException('Datarecord');

                $datatype = $datarecord->getDataType();
                if ($datatype->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datatype');
            }

            /** @var DataFields|null $datafield */
            $datafield = null;
            if ($datafield_id !== 0) {
                $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
                if ($datafield == null)
                    throw new ODRNotFoundException('Datafield');

                $datatype = $datafield->getDataType();
                if ($datatype->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datatype');
            }


            // ----------------------------------------
            // Ensure user has permissions to be doing this
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // Ensure the user can view the grandparent datarecord/datatype first...
            if ( !$pm_service->canViewDatatype($user, $grandparent_datatype)
                || !$pm_service->canViewDatarecord($user, $grandparent_datarecord)
            ) {
                throw new ODRForbiddenException();
            }

            // If they requested all files in a datarecord, ensure they can view the datarecord
            if ($datarecord != null) {
                if ( !$pm_service->canViewDatarecord($user, $datarecord) )
                    throw new ODRForbiddenException();
            }

            // If they requested all files in a datafield, ensure they can view the datafield
            if ($datafield != null) {
                if ( !$pm_service->canViewDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------


            // ----------------------------------------
            // Get all Datarecords and Datatypes that are associated with the datarecord...need to render an abbreviated view in order to select files
            $datarecord_array = $dri_service->getDatarecordArray($grandparent_datarecord->getId());
            $datatype_array = $dti_service->getDatatypeArray($grandparent_datatype->getId());

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

            // Get rid of all non-file/image datafields while the datarecord array is still "deflated"
            $datafield_ids = array();
            $datatype_ids = array();
            foreach ($datarecord_array as $dr_id => $dr) {
                foreach ($dr['dataRecordFields'] as $df_id => $drf) {
                    if ( count($drf['file']) == 0 /*&& count($drf['image']) == 0*/ )    // TODO - download images in zip too?
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id] );
                    else {
                        $datafield_ids[] = $df_id;
                        $datatype_ids[] = $dr['dataType']['id'];
                    }
                }
            }
            $datafield_ids = array_unique($datafield_ids);
            $datatype_ids = array_unique($datatype_ids);

            // Faster/easier to query the database again to store datafield names
            $query = $em->createQuery(
               'SELECT df.id, dfm.fieldName
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                WHERE df.id IN (:datafield_ids)
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
            )->setParameters( array('datafield_ids' => $datafield_ids) );
            $results = $query->getArrayResult();

            $datafield_names = array();
            foreach ($results as $result) {
                $df_id = $result['id'];
                $df_name = $result['fieldName'];

                $datafield_names[$df_id] = $df_name;
            }

            // Faster/easier to query the database again to store datatype names
            $query = $em->createQuery(
               'SELECT dt.id, dtm.shortName
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                WHERE dt.id IN (:datatype_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $datatype_ids) );
            $results = $query->getArrayResult();

            $datatype_names = array();
            foreach ($results as $result) {
                $dt_id = $result['id'];
                $dt_name = $result['shortName'];

                $datatype_names[$dt_id] = $dt_name;
            }


            // ----------------------------------------
            // "Inflate" the currently flattened $datarecord_array and $datatype_array...needed so that render plugins for a datatype can also correctly render that datatype's child/linked datatypes
            $stacked_datarecord_array[ $grandparent_datarecord->getId() ]
                = $dri_service->stackDatarecordArray($datarecord_array, $grandparent_datarecord->getId());
//print '<pre>'.print_r($stacked_datarecord_array, true).'</pre>';  exit();

            $ret = self::locateFilesforDownloadAll($stacked_datarecord_array, $grandparent_datarecord->getId());
            if ( is_null($ret) ) {
                $return['d'] = 'No files are available to download';
            }
            else {
                $stacked_datarecord_array = array($grandparent_datarecord->getId() => $ret);
//print '<pre>'.print_r($stacked_datarecord_array, true).'</pre>';  exit();

                $templating = $this->get('templating');
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Default:file_download_dialog_form.html.twig',
                    array(
                        'datarecord_id' => $grandparent_datarecord_id,

                        'datarecord_array' => $stacked_datarecord_array,
                        'datafield_names' => $datafield_names,
                        'datatype_names' => $datatype_names,

                        'is_top_level' => true,
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0xce2c6ae9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Recursively goes through an "inflated" datarecord array entry and deletes all (child) datarecords that don't have files/images.
     * Assumes that all non-file/image datafields have already been deleted out of the "inflated" array prior to calling this function, so the recursive logic is somewhat simplified.
     * @see parent::stackDatarecordArray()
     *
     * @param array $dr_array         An already "inflated" array of all datarecord entries for this datatype
     * @param integer $datarecord_id  The specific datarecord to check
     *
     * @return null|array
     */
    private function locateFilesforDownloadAll($dr_array, $datarecord_id)
    {
        // Probably going to be deleting entries from $dr_array, so make a copy for looping purposes
        $dr = $dr_array[$datarecord_id];

        if ( count($dr['children']) > 0 ) {
            foreach ($dr['children'] as $child_dt_id => $child_datarecords) {

                foreach ($child_datarecords as $child_dr_id => $child_dr) {
                    // Determine whether this child datarecord has files/images, or has (grand)children with files/images
                    $ret = self::locateFilesforDownloadAll($child_datarecords, $child_dr_id);

                    if ( is_null($ret) ) {
                        // This child datarecord didn't have any files/images, and also didn't have any children of its own with files/images...don't want to see it later
                        unset( $dr_array[$datarecord_id]['children'][$child_dt_id][$child_dr_id] );

                        // If this datarecord has no child datarecords of this child datatype with files/images, then get rid of the entire array entry for the child datatype
                        if ( count($dr_array[$datarecord_id]['children'][$child_dt_id]) == 0 )
                            unset( $dr_array[$datarecord_id]['children'][$child_dt_id] );
                    }
                    else {
                        // Otherwise, save the (probably) modified version of the datarecord entry
                        $dr_array[$datarecord_id]['children'][$child_dt_id][$child_dr_id] = $ret;
                    }
                }
            }
        }

        if ( count($dr_array[$datarecord_id]['children']) == 0 && count($dr_array[$datarecord_id]['dataRecordFields']) == 0 )
            // If the datarecord has no child datarecords, and doesn't have any files/images, return null
            return null;
        else
            // Otherwise, return the (probably) modified version of the datarecord entry
            return $dr_array[$datarecord_id];
    }


    /**
     * Assuming the user has the correct permissions, adds each file from this datarecord/datafield pair into a zip
     * archive and returns that zip archive for download.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function startdownloadarchiveAction($grandparent_datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Symfony firewall won't permit GET requests to reach this point
            $post = $request->request->all();

            // Require at least one of these...
            if ( !isset($post['files']) && !isset($post['images']) )
                throw new ODRBadRequestException();

            // Don't need to check whether the file/image ids are numeric...they're not sent to the database
            $file_ids = array();
            if ( isset($post['files']) )
                $file_ids = $post['files'];

            $image_ids = array();
            if ( isset($post['images']) )
                $image_ids = $post['images'];


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');     // debug purposes only

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataRecord $grandparent_datarecord */
            $grandparent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($grandparent_datarecord_id);
            if ($grandparent_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $grandparent_datatype = $grandparent_datarecord->getDataType();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // Don't need to verify any permissions, filterByGroupPermissions() will take care of it
            // ----------------------------------------


            // ----------------------------------------
            // Easier/faster to just load the entire datarecord/datatype arrays...
            $datarecord_array = $dri_service->getDatarecordArray($grandparent_datarecord->getId());
            $datatype_array = $dti_service->getDatatypeArray($grandparent_datatype->getId());

            // ...so the permissions service can prevent the user from downloading files/images they're not allowed to see
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


            // ----------------------------------------
            // Intersect the array of desired file/image ids with the array of permitted files/ids to determine which files/images to add to the zip archive
            $file_list = array();
            $filename_list = array();

            $image_list = array();
            $imagename_list = array();
            foreach ($datarecord_array as $dr_id => $dr) {
                foreach ($dr['dataRecordFields'] as $drf_num => $drf) {
                    if ( count($drf['file']) > 0 ) {
                        foreach ($drf['file'] as $f_num => $f) {
                            if ( in_array($f['id'], $file_ids) ) {
                                // Store by original checksum so multiples of the same file only get decrypted/stored once
                                $original_checksum = $f['original_checksum'];
                                $file_list[$original_checksum] = $f;

                                // Also store the file's name to detect different files with the same filename
                                $filename = $f['fileMeta']['originalFileName'];
                                $filename_list[$original_checksum] = $filename;
                            }
                        }
                    }

                    // TODO - also allow user to download images in a zip archive?
                }
            }


            // If needed, tweak the file list so different files that have the same filename on the server have different filenames in the zip archive
            asort($filename_list);
            $prev_filename = '';
            $num = 2;
            foreach($filename_list as $file_checksum => $filename) {
                if ($filename == $prev_filename) {
                    // This filename maches the previous one...insert a numerical string in this filename to differentiate between the two
                    $file_ext = $file_list[$file_checksum]['ext'];
                    $tmp_filename = substr($filename, 0, strlen($filename)-strlen($file_ext)-1);
                    $tmp_filename .= ' ('.$num.').'.$file_ext;
                    $num++;

                    // Save the new filename back in the array
                    $file_list[$file_checksum]['fileMeta']['originalFileName'] = $tmp_filename;
                }
                else {
                    // This filename is different from the previous one, reset for next potential indentical filename
                    $prev_filename = $filename;
                    $num = 2;
                }
            }

            // TODO - do the same for image names?
/*
print '<pre>'.print_r($file_list, true).'</pre>';
print '<pre>'.print_r($image_list, true).'</pre>';
exit();
*/

            // ----------------------------------------
            // If any files/images remain...
            if ( count($file_list) == 0 && count($image_list) == 0 ) {
                // TODO - what to return?
                $exact = true;
                throw new ODRNotFoundException('No files are available to download', $exact);
            }
            else {
                // Generate the url for cURL to use
                $pheanstalk = $this->get('pheanstalk');
                $router = $this->container->get('router');
                $url = $this->container->getParameter('site_baseurl');
                $url .= $router->generate('odr_crypto_request');

                $api_key = $this->container->getParameter('beanstalk_api_key');


                // Create a filename for the zip archive
                $tokenGenerator = $this->get('fos_user.util.token_generator');
                $random_id = substr($tokenGenerator->generateToken(), 0, 12);

                $archive_filename = $random_id.'.zip';
                $archive_filepath = dirname(__FILE__).'/../../../../web/uploads/files/'.$archive_filename;

                $archive_size = count($file_list) + count($image_list);

                foreach ($file_list as $f_checksum => $file) {
                    // Determine the decrypted filename
                    $desired_filename = $file['fileMeta']['originalFileName'];

                    $target_filename = '';
                    if ( $file['fileMeta']['publicDate']->format('Y-m-d') == '2200-01-01' ) {
                        // non-public files need to be decrypted to something difficult to guess
                        $target_filename = md5($file['original_checksum'].'_'.$file['id'].'_'.$user->getId());
                        $target_filename .= '.'.$file['ext'];
                    }
                    else {
                        // public files need to be decrypted to this format
                        $target_filename = 'File_'.$file['id'].'.'.$file['ext'];
                    }

                    // Schedule a beanstalk job to start decrypting the file
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "object_type" => 'File',
                            "object_id" => $file['id'],
                            "target_filename" => $target_filename,
                            "crypto_type" => 'decrypt',

                            "archive_filepath" => $archive_filepath,
                            "desired_filename" => $desired_filename,

                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 0;
                    $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
                }
            }

            $return['d'] = array('archive_filename' => $archive_filename, 'archive_size' => $archive_size);
        }
        catch (\Exception $e) {
            $source = 0xc31d45b5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        // If error encountered, do a json return
        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Downloads a zip archive constructed by self::startdownloadarchiveAction()
     *
     * @param string $archive_filename
     * @param Request $request
     *
     * @return Response
     */
    public function downloadarchiveAction($archive_filename, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // TODO - some level of permissions checking?  maybe store archive filename in user's session?

            // Symfony firewall requires $archive_filename to match "0|[0-9a-zA-Z\-\_]{12}.zip"
            if ($archive_filename == '0')
                throw new ODRBadRequestException();

            $archive_filepath = dirname(__FILE__).'/../../../../web/uploads/files/'.$archive_filename;
            if ( !file_exists($archive_filepath) )
                throw new FileNotFoundException($archive_filename);

            $handle = fopen($archive_filepath, 'r');
            if ($handle === false)
                throw new FileNotFoundException($archive_filename);


            // Set up a response to send the file back
            $response = new StreamedResponse();
            $response->setPrivate();
            $response->headers->set('Content-Type', mime_content_type($archive_filepath));
            $response->headers->set('Content-Length', filesize($archive_filepath));
            $response->headers->set('Content-Disposition', 'attachment; filename="'.$archive_filename.'";');

            // Have to specify all these properties just so that the last one can be false...otherwise Flow.js can't keep track of the progress
            $response->headers->setCookie(
                new Cookie(
                    'fileDownload', // name
                    'true',         // value
                    0,              // duration set to 'session'
                    '/',            // default path
                    null,           // default domain
                    false,          // don't require HTTPS
                    false           // allow cookie to be accessed outside HTTP protocol
                )
            );

            //$response->sendHeaders();

            // Use symfony's StreamedResponse to send the decrypted file back in chunks to the user
            $response->setCallback(function () use ($handle) {
                while (!feof($handle)) {
                    $buffer = fread($handle, 65536);    // attempt to send 64Kb at a time
                    echo $buffer;
                    flush();
                }
                fclose($handle);
            });

            // Delete the zip archive off the server
            unlink($archive_filepath);

            return $response;
        }
        catch (\Exception $e) {
            // Usually this'll be called via the jQuery fileDownload plugin, and therefore need a json-format error
            // But in the off-chance it's a direct link, then the error format needs to remain html
            if ( $request->query->has('error_type') && $request->query->get('error_type') == 'json' )
                $request->setRequestFormat('json');

            $source = 0xc953bbf3;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Get available themes for user and datatype.
     * This function is in display controller to automatically set the theme type.
     * IE: called by display = use "master" theme.
     *
     * TODO - Perhaps should be moved to theme controller and a theme type passed.
     *
     * @param integer $datatype_id
     * @param string $page_type     'display' or 'search_results'
     * @param Request $request
     *
     * @return Response $response
     */
    public function getavailablethemesAction($datatype_id, $page_type, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'json';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // --------------------
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $is_datatype_admin = $pm_service->isDatatypeAdmin($user, $datatype);
            // --------------------


            // We will eventually use master only for 'edit'
            if ($page_type == "display")
                $page_type = "master";


            // Get all available themes for this datatype that the user can view
            $themes = $theme_service->getAvailableThemes($user, $datatype, $page_type);

            // Get the user's default theme for this datatype, if they have one
            $user_default_theme = $theme_service->getUserDefaultTheme($user, $datatype_id, $page_type);
            $selected_theme_id = $theme_service->getPreferredTheme($user, $datatype_id, $page_type);


            // Render and return the theme chooser dialog
            $templating = $this->get('templating');
            $return['d'] = $templating->render(
                'ODRAdminBundle:Default:choose_view.html.twig',
                array(
                    'themes' => $themes,
                    'user_default_theme' => $user_default_theme,
                    'selected_theme_id' => $selected_theme_id,

                    'user' => $user,
                    'datatype_admin' => $is_datatype_admin
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x81fad8c3;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
