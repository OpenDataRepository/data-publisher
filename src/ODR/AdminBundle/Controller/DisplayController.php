<?php

/**
 * Open Data Repository Data Publisher
 * Display Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The display controller renders and displays datarecords for the user, executing render plugins
 * as necessary to change how the data looks.  It also handles file and image downloads because
 * of security concerns and routing constraints within Symfony.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
// Symfony
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Router;
use Symfony\Component\Templating\EngineInterface;


class DisplayController extends ODRCustomController
{

    /**
     * Fixes searches to follow the new URL system and redirects the user.
     *
     * @param $datarecord_id
     * @param $search_key
     * @param $offset
     * @param Request $request
     *
     * @return Response
     */
    public function legacy_viewAction($datarecord_id, $search_key, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');


            $search_theme_id = 0;
            // Need to reformat to create proper search key and forward internally to view controller

            $search_param_elements = preg_split("/\|/",$search_key);
            $search_params = array();
            foreach($search_param_elements as $search_param_element) {
                $search_param_data = preg_split("/\=/",$search_param_element);
                $search_params[$search_param_data[0]] = $search_param_data[1];
            }
            $new_search_key = $search_key_service->encodeSearchKey($search_params);

            // Generate new style search key from passed search key
            return $search_redirect_service->redirectToViewPage($datarecord_id, $search_theme_id, $new_search_key, $offset);
            /*
            return $this->redirectToRoute(
                "odr_display_view",
                array(
                    'datarecord_id' => $datarecord_id,
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $new_search_key,
                    'offset' => $offset
                )
            );
            */
        }
        catch (\Exception $e) {
            $source = 0x9c453393;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns the "Results" version of the given DataRecord.
     *
     * @param integer $datarecord_id The database id of the datarecord to return.
     * @param integer $search_theme_id
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     * @param Request $request
     *
     * @return Response
     */
    public function viewAction($datarecord_id, $search_theme_id, $search_key, $offset, Request $request)
    {
        $time = microtime(true);
        // print "Start: " . $time . "<br />";
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

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');

            /** @var EngineInterface $templating */
            $templating = $this->get('templating');
            /** @var Router $router */
            $router = $this->get('router');


            // ----------------------------------------
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // TODO - allow rendering of child datarecords?
            if ( $datarecord->getId() !== $datarecord->getGrandparent()->getId() )
                throw new ODRBadRequestException('Not allowed to directly render child datarecords');


            // If $search_theme_id is set...
            if ($search_theme_id != 0) {
                // ...require a search key to also be set
                if ($search_key == '')
                    throw new ODRBadRequestException('Search theme set without search key');

                // ...require the referenced theme to exist
                /** @var Theme $search_theme */
                $search_theme = $em->getRepository('ODRAdminBundle:Theme')->find($search_theme_id);
                if ($search_theme == null)
                    throw new ODRNotFoundException('Search Theme');

                // ...require it to match the datatype being rendered
                // TODO - how to recover from this?
                if ($search_theme->getDataType()->getId() !== $datatype->getId())
                    throw new ODRBadRequestException('The results list from the current search key does not contain datarecord '.$datarecord->getId());
            }


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // Store whether the user is permitted to edit at least one datarecord for this datatype
            $can_edit_datatype = $pm_service->canEditDatatype($user, $datatype);
            // Store whether the user is permitted to edit this specific datarecord
            $can_edit_datarecord = $pm_service->canEditDatarecord($user, $datarecord);
            // Store whether the user is permitted to create new datarecords for this datatype
            $can_add_datarecord = $pm_service->canAddDatarecord($user, $datatype);

            if ( !$pm_service->canViewDatatype($user, $datatype) || !$pm_service->canViewDatarecord($user, $datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];
            else
                $odr_tab_id = $odr_tab_service->createTabId();


            // Determine whether the user has a restriction on which datarecords they can edit
            $restricted_datarecord_list = $pm_service->getDatarecordRestrictionList($user, $datatype);

            // Determine which list of datarecords to pull from the user's session
            $cookies = $request->cookies;
            $only_display_editable_datarecords = true;
            if ( $cookies->has('datatype_'.$datatype->getId().'_editable_only') )
                $only_display_editable_datarecords = $cookies->get('datatype_'.$datatype->getId().'_editable_only');

            // If a datarecord restriction exists, and the user only wants to display editable datarecords...
            $editable_only = false;
            if ( $can_edit_datatype && !is_null($restricted_datarecord_list) && $only_display_editable_datarecords )
                $editable_only = true;


            // If this datarecord is being viewed from a search result list...
            $datarecord_list = '';
            if ($search_key !== '') {
                // Ensure the search key is valid first
                $search_key_service->validateSearchKey($search_key);
                // Determine whether the user is allowed to view this search key
                $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);
                if ($filtered_search_key !== $search_key) {
                    // User can't view the results of this search key, redirect to the one they can view
                    return $search_redirect_service->redirectToViewPage($datarecord_id, $search_theme_id, $filtered_search_key, $offset);
                }
                $search_params = $search_key_service->decodeSearchKey($search_key);

                // Ensure the tab refers to the given search key
                $expected_search_key = $odr_tab_service->getSearchKey($odr_tab_id);
                if ( $expected_search_key !== $search_key )
                    $odr_tab_service->setSearchKey($odr_tab_id, $search_key);

                // Need to ensure a sort criteria is set for this tab, otherwise the table plugin
                //  will display stuff in a different order
                $sort_datafields = array();
                $sort_directions = array();

                $sort_criteria = $odr_tab_service->getSortCriteria($odr_tab_id);
                if ( !is_null($sort_criteria) ) {
                    // Prefer the criteria from the user's session whenever possible
                    $sort_datafields = $sort_criteria['datafield_ids'];
                    $sort_directions = $sort_criteria['sort_directions'];
                }
                else if ( isset($search_params['sort_by']) ) {
                    // If the user's session doesn't have anything but the search key does, then
                    //  use that
                    foreach ($search_params['sort_by'] as $display_order => $data) {
                        $sort_datafields[$display_order] = intval($data['sort_df_id']);
                        $sort_directions[$display_order] = $data['sort_dir'];
                    }

                    // Store this in the user's session
                    $odr_tab_service->setSortCriteria($odr_tab_id, $sort_datafields, $sort_directions);
                }
                else {
                    // No criteria set...get this datatype's current list of sort fields, and convert
                    //  into a list of datafield ids for storing this tab's criteria
                    foreach ($datatype->getSortFields() as $display_order => $df) {
                        $sort_datafields[$display_order] = $df->getId();
                        $sort_directions[$display_order] = 'asc';
                    }
                    $odr_tab_service->setSortCriteria($odr_tab_id, $sort_datafields, $sort_directions);
                }

                // No problems, so get the datarecords that match the search
                $original_datarecord_list = $odr_tab_service->getSearchResults($odr_tab_id);
                if ( is_null($original_datarecord_list) ) {
                    $original_datarecord_list = $search_api_service->performSearch(
                        $datatype,
                        $search_key,
                        $user_permissions,
                        false,  // only want the grandparent datarecord ids that match the search
                        $sort_datafields,
                        $sort_directions
                    );
                    $odr_tab_service->setSearchResults($odr_tab_id, $original_datarecord_list);
                }


                // ----------------------------------------
                // Determine the correct lists of datarecords to use for rendering...
                $datarecord_list = $original_datarecord_list;
                if ($can_edit_datatype && $editable_only) {
                    // ...user has a restriction list, and only wants to have datarecords in the
                    //  search header that they can edit

                    // array_flip() + isset() is orders of magnitude faster than repeated calls to in_array()
                    $editable_datarecord_list = array_flip($restricted_datarecord_list);
                    foreach ($original_datarecord_list as $num => $dr_id) {
                        if (!isset($editable_datarecord_list[$dr_id]))
                            unset($original_datarecord_list[$num]);
                    }

                    $datarecord_list = array_values($original_datarecord_list);
                }

                // Compute which page of the search results this datarecord is on
                $key = array_search($datarecord->getId(), $datarecord_list);

                $page_length = $odr_tab_service->getPageLength($odr_tab_id);
                $offset = floor($key / $page_length) + 1;

                // Ensure the session has the correct offset stored
                $odr_tab_service->updateDatatablesOffset($odr_tab_id, $offset);
            }

            $now = microtime(true);
            // print "NOW: " . $now . "<br />";
            // print "Elapsed: " . ($now - $time) . "<br />";

            // ----------------------------------------
            // Build an array of values to use for navigating the search result list, if it exists
            $search_header = null;
            if ($search_key !== '')
                $search_header = $odr_tab_service->getSearchHeaderValues($odr_tab_id, $datarecord->getId(), $datarecord_list);

            // Need this array to exist right now so the part that's not the search header will display
            if ( is_null($search_header) ) {
                $search_header = array(
                    'page_length' => 0,
                    'next_datarecord_id' => 0,
                    'prev_datarecord_id' => 0,
                    'search_result_current' => 0,
                    'search_result_count' => 0
                );
            }

            $redirect_path = $router->generate('odr_display_view', array('datarecord_id' => 0));    // blank path
            $header_html = $templating->render(
                'ODRAdminBundle:Display:display_header.html.twig',
                array(
                    'page_type' => 'display',

                    'can_edit_datarecord' => $can_edit_datarecord,
                    'can_add_datarecord' => $can_add_datarecord,
                    'datarecord' => $datarecord,
                    'datatype' => $datatype,

                    'odr_tab_id' => $odr_tab_id,

                    // values used by search_header.html.twig
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $search_key,
                    'offset' => $offset,

                    'page_length' => $search_header['page_length'],
                    'next_datarecord' => $search_header['next_datarecord_id'],
                    'prev_datarecord' => $search_header['prev_datarecord_id'],
                    'search_result_current' => $search_header['search_result_current'],
                    'search_result_count' => $search_header['search_result_count'],
                    'redirect_path' => $redirect_path,
                )
            );

            $now = microtime(true);
            // print "NOW: " . $now . "<br />";
            // print "Elapsed: " . ($now - $time) . "<br />";

            // ----------------------------------------
            // Determine the user's preferred theme
            $theme_id = $theme_info_service->getPreferredThemeId($user, $datatype->getId(), 'display');
            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);

            // Render the display page for this datarecord
            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            $page_html = $odr_render_service->getDisplayHTML($user, $datarecord, $search_key, $theme);

            $now = microtime(true);
            // print "NOW: " . $now . "<br />";
            // print "Elapsed: " . ($now - $time) . "<br />";

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $now = microtime(true);
        // print "NOW: " . $now . "<br />";
        // print "Elapsed: " . ($now - $time); exit();
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
            if ($file->getEncryptKey() === '')
                throw new ODRNotFoundException('File');


            // ----------------------------------------
            // First, ensure user is permitted to download
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canViewFile($user, $file) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Determine the decrypted filename
            $filename = 'File_'.$file_id.'.'.$file->getExt();
            if ( !$file->isPublic() )
                $filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId()).'.'.$file->getExt();

            // Ensure file exists before attempting to download it
            $local_filepath = realpath( $this->getParameter('odr_web_directory').'/'.$file->getUploadDir().'/'.$filename );
            if ( !file_exists($local_filepath) ) {
                // Need to decrypt the file...generate the url for cURL to use
                $url = $this->generateUrl('odr_crypto_request', array(), UrlGeneratorInterface::ABSOLUTE_URL);
                $pheanstalk = $this->get('pheanstalk');
                $api_key = $this->container->getParameter('beanstalk_api_key');

                // Schedule a beanstalk job to start decrypting the file
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        "object_type" => 'File',
                        "object_id" => $file_id,
                        "crypto_type" => 'decrypt',

                        "local_filename" => $filename,
                        "archive_filepath" => '',
                        "desired_filename" => '',

                        "redis_prefix" => $redis_prefix,    // debug purposes only
                        "url" => $url,
                        "api_key" => $api_key,
                    )
                );

                $delay = 0;
                $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);

                // Return a URL to monitor decryption progress
                $monitor_url = $this->generateUrl('odr_get_file_decrypt_progress', array('file_id' => $file_id));

                $response = new JsonResponse(array());
                $response->setStatusCode(202);
                $response->headers->set('Location', $monitor_url);

                return $response;
            }
            else {
                // File already exists, determine whether it's done decrypting yet
                clearstatcache(true, $local_filepath);
                $current_filesize = filesize($local_filepath);

                if ( $file->getFilesize() == $current_filesize ) {
                    // File exists and is fully decrypted, determine path to download it
                    $download_url = $this->generateUrl('odr_file_download', array('file_id' => $file_id));

                    // Return a link to the download URL
                    $response = new JsonResponse(array());
                    $response->setStatusCode(200);
                    $response->headers->set('Location', $download_url);

                    return $response;
                }
                else if ( $file->getFilesize() < $current_filesize ) {
                    // Return a URL to monitor decryption progress
                    $monitor_url = $this->generateUrl('odr_get_file_decrypt_progress', array('file_id' => $file_id));

                    $response = new JsonResponse(array());
                    $response->setStatusCode(202);
                    $response->headers->set('Location', $monitor_url);

                    return $response;
                }
                else {
                    // ...this seems to only happen when the encrypted files in the crypto dir have
                    //  been manually replaced, but...
                    if ( file_exists($local_filepath) )
                        unlink( $local_filepath );
                    else
                        throw new ODRException('file does not exist, but too much of it is decrypted??');

                    // Return a URL to monitor decryption progress
                    $monitor_url = $this->generateUrl('odr_get_file_decrypt_progress', array('file_id' => $file_id));

                    $response = new JsonResponse(array());
                    $response->setStatusCode(202);
                    $response->headers->set('Location', $monitor_url);

                    return $response;
                }
            }
        }
        catch (\Exception $e) {
            $source = 0xcc3f073c;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
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

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
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
            if ($file->getEncryptKey() === '')
                throw new ODRNotFoundException('File');


            // ----------------------------------------
            // First, ensure user is permitted to download
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canViewFile($user, $file) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Ensure file exists before attempting to download it
            $filename = 'File_'.$file_id.'.'.$file->getExt();
            if ( !$file->isPublic() )
                $filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId()).'.'.$file->getExt();

            $local_filepath = realpath( $this->getParameter('odr_web_directory').'/'.$file->getUploadDir().'/'.$filename );
            if (!$local_filepath) {
                // If file doesn't exist, and user has permissions...just decrypt it directly?
                // TODO - don't really like this, but downloading a file via table theme or interactive graph feature can't get at non-public files otherwise...
                $local_filepath = $crypto_service->decryptFile($file->getId(), $filename);
            }

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
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
            // $start = microtime(true);
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // print microtime(true) - $start . "<br />";
            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            // print microtime(true) - $start . "<br />";

            // Locate the image object in the database
            /** @var Image $image */
            $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');

            /*
            $datafield = $image->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            */

            // Images that aren't done encrypting shouldn't be downloaded
            if ($image->getEncryptKey() == '')
                throw new ODRNotFoundException('Image');

            // print microtime(true) - $start . "<br />";

            // Ensure file exists before attempting to download it
            $filename = 'Image_'.$image->getId().'.'.$image->getExt();
            if ( !$image->isPublic() ) {

                /** @var PermissionsManagementService $pm_service */
                $pm_service = $this->container->get('odr.permissions_management_service');
                // ----------------------------------------
                // Non-Public images are more work because they always need decryption...but first, ensure user is permitted to download
                /** @var ODRUser $user */
                $user = $this->container->get('security.token_storage')->getToken()->getUser();

                if ( !$pm_service->canViewImage($user, $image) )
                    throw new ODRForbiddenException();
                // ----------------------------------------

                // If image isn't public, then it needs to have this filename instead...
                $filename = md5($image->getOriginalChecksum().'_'.$image->getId().'_'.$user->getId()).'.'.$image->getExt();

                // Ensure the image exists in decrypted format
                $image_path = realpath( $this->getParameter('odr_web_directory').'/'.$filename );     // realpath() returns false if file does not exist
                if ( !$image->isPublic() || !$image_path )
                    $image_path = $crypto_service->decryptImage($image->getId(), $filename);

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
                    $display_filename = 'Image_'.$image->getId().'.'.$image->getExt();
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
            else {
                // If image is public but doesn't exist, decrypt now
                $image_path = realpath( $this->getParameter('odr_web_directory').'/'.$filename );     // realpath() returns false if file does not exist
                if ( !$image_path )
                    $image_path = $crypto_service->decryptImage($image->getId(), $filename);

                // print microtime(true) - $start . "<br />";
                $url = $this->getParameter('site_baseurl') . '/uploads/images/' . $filename;
                // print microtime(true) - $start . "<br />";exit();
                // $response = new Response($url);
                // $response->headers->set('Content-Type', 'text/html');
                //return $response;
                return $this->redirect($url, 301);
            }
        }
        catch (\Exception $e) {
            $source = 0xc2fbf062;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates and renders an HTML list of all files/images that the user is allowed to see in the
     * given datarecord.
     *
     * @param integer $grandparent_datarecord_id
     * @param boolean $group_by_datafield
     * @param Request $request
     *
     * @return Response
     */
    public function listallfilesAction($grandparent_datarecord_id, $group_by_datafield, Request $request)
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

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataRecord $grandparent_datarecord */
            $grandparent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($grandparent_datarecord_id);
            if ( is_null($grandparent_datarecord) )
                throw new ODRNotFoundException('Grandparent Datarecord');

            $grandparent_datatype = $grandparent_datarecord->getDataType();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datarecord');
            $grandparent_datatype_id = $grandparent_datatype->getId();


            // ----------------------------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // Ensure the user can view the grandparent datarecord/datatype
            if ( !$pm_service->canViewDatatype($user, $grandparent_datatype)
                || !$pm_service->canViewDatarecord($user, $grandparent_datarecord)
            ) {
                throw new ODRForbiddenException();
            }
            // ----------------------------------------


            // ----------------------------------------
            // Get all Datarecords and Datatypes that are associated with the datarecord...need to
            //  render an abbreviated view in order to select files
            $datarecord_array = $dri_service->getDatarecordArray($grandparent_datarecord->getId());
            $datatype_array = $dbi_service->getDatatypeArray($grandparent_datatype->getId());

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

            // Extracts the entity "names" of the datatypes/datarecords/datafields that will be
            //  displayed...and also filters out all array entries that aren't relevant to files/images
            $entity_names = self::extractEntityNames($datatype_array, $datarecord_array);

            // Extract the filenames from the cached data arrays, organizing them by user request
            $file_array = array();
            if ( !$group_by_datafield )
                $file_array = self::groupFilesByDatarecord($grandparent_datarecord_id, $entity_names, $datarecord_array);
            else
                $file_array = self::groupFilesByDatafield($grandparent_datatype_id, $entity_names, $datatype_array, $datarecord_array);

            // If no files/images have been uploaded to the grandparent datarecord or any of its
            //  descendants, then completely erase the array so the templating files can correctly
            //  display
            $key = $grandparent_datarecord_id;
            if ( $group_by_datafield )
                $key = $grandparent_datatype_id;

            if ( empty($file_array[$key]['datafields']) && empty($file_array[$key]['child_datatypes']) )
                $file_array = array();


            // ----------------------------------------
            // Render and return a tree structure of data
            $return['d'] = $templating->render(
                'ODRAdminBundle:Default:file_download_dialog_form.html.twig',
                array(
                    'file_array' => $file_array,
                    'entity_names' => $entity_names,

                    'grandparent_datarecord_id' => $grandparent_datarecord_id,
                    'group_by_datafield' => $group_by_datafield,
                )
            );

        }
        catch (\Exception $e) {
            $source = 0xce2c6ae9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Extracts the names of the Datatypes, Datafields, and Datarecords from the given cached
     * arrays.  Also removes all non-file/image datafields from the cached arrays so later functions
     * don't have to do that check...
     *
     * @param array $datatype_array
     * @param array $datarecord_array
     *
     * @return array
     */
    private function extractEntityNames(&$datatype_array, &$datarecord_array)
    {
        $entity_names = array(
            'datatypes' => array(),
            'datafields' => array(),
            'datarecords' => array()
        );

        foreach ($datatype_array as $dt_id => $dt) {
            // Always want to save the datatype's name, since it might be used during rendering
            $entity_names['datatypes'][$dt_id] = $dt['dataTypeMeta']['shortName'];

            foreach ($dt['dataFields'] as $df_id => $df) {
                $typename = $df['dataFieldMeta']['fieldType']['typeName'];
                if ( $typename === 'File' || $typename === 'Image' ) {
                    // Probably going to display this datafield, save the name
                    $entity_names['datafields'][$df_id] = array(
                        'fieldName' => $df['dataFieldMeta']['fieldName'],
                        'typeName' => $typename,
                    );
                }
                else {
                    // Don't want this datafield in the array
                    unset( $datatype_array[$dt_id]['dataFields'][$df_id] );
                }
            }
        }
        // Not unsetting any datatype entries here because they may be required for stacking

        foreach ($datarecord_array as $dr_id => $dr) {
            // Always want to save the datarecord's name, since it might be used during rendering
            $entity_names['datarecords'][$dr_id] = $dr['nameField_value'];

            // Only interested in this datarecord if it has at least one file/image field...
            $dt_id = $dr['dataType']['id'];
            if ( isset($entity_names['datatypes'][$dt_id]) ) {
                foreach ($dr['dataRecordFields'] as $df_id => $drf) {
                    // Only interested in the contents of this datafield when it's a file/image field
                    if ( !isset($entity_names['datafields'][$df_id]) ) {
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id] );
                    }
                }
            }

            // Not unsetting any datarecord entries here because they may be required for stacking
        }

        // Done filtering the cached arrays
        return $entity_names;
    }


    /**
     * Recursively traverses the cached data arrays in order to build/return an array for selecting
     * files to download.  This version is organized to make it easier to download all data from
     * a specific child/linked datarecord.
     *
     * @param integer $current_datarecord_id
     * @param array $entity_names
     * @param array $datarecord_array
     *
     * @return array
     */
    private function groupFilesByDatarecord($current_datarecord_id, $entity_names, $datarecord_array)
    {
        $file_array = array(
            'datafields' => array(),
            'child_datatypes' => array(),
        );

        $dr = $datarecord_array[$current_datarecord_id];

        // If this datarecord has file/image fields...
        foreach ($dr['dataRecordFields'] as $df_id => $drf) {
            // ...and they have files/images uploaded into them...
            if ( !empty($drf['file']) ) {
                $file_array['datafields'][$df_id] = array();
                foreach ($drf['file'] as $num => $file) {
                    // ...then store the file_id and the filename
                    $file_id = $file['id'];
                    $file_array['datafields'][$df_id][$file_id] = $file['fileMeta']['originalFileName'];
                }
            }
            if ( !empty($drf['image']) ) {
                $file_array['datafields'][$df_id] = array();
                foreach ($drf['image'] as $num => $thumbnail_image) {
                    // Don't want to store the thumbnail image
                    $image = $thumbnail_image['parent'];
                    // ...then store the image_id and the filename
                    $image_id = $image['id'];
                    $file_array['datafields'][$df_id][$image_id] = $image['imageMeta']['originalFileName'];
                }
            }
        }

        // If this datarecord has child/linked datarecords...
        foreach ($dr['children'] as $child_dt_id => $child_dr_list) {

            // ...sort array of child datarecords by their respective sortvalue...
            $sorted_dr_list = array();
            foreach ($child_dr_list as $num => $child_dr_id) {
                // User may not have permission to see the child/linked datarecord...
                if ( isset($datarecord_array[$child_dr_id]) )
                    $sorted_dr_list[$child_dr_id] = $datarecord_array[$child_dr_id]['sortField_value'];
            }

            if ( !empty($sorted_dr_list) ) {
                uasort($sorted_dr_list, function ($a, $b) {
                    return strnatcmp($a, $b);
                });
            }

            foreach ($sorted_dr_list as $child_dr_id => $sort_value) {
                // ...then determine if the child/linked datarecord has any files
                $tmp = self::groupFilesByDatarecord($child_dr_id, $entity_names, $datarecord_array);

                // Only store the data for the child/linked datarecord if it has files, or has some
                //  descendant that has files
                if ( !empty($tmp[$child_dr_id]['datafields']) || !empty($tmp[$child_dr_id]['child_datatypes']) ) {
                    if ( !isset($file_array['child_datatypes'][$child_dt_id]) )
                        $file_array['child_datatypes'][$child_dt_id] = array();

                    $file_array['child_datatypes'][$child_dt_id][$child_dr_id] = $tmp[$child_dr_id];
                }
            }
        }

        return array($current_datarecord_id => $file_array);
    }


    /**
     * Recursively traverses the cached data arrays in order to build/return an array for selecting
     * files to download.  This version is organized to make it easier to download all files/images
     * that have been uploaded to a specific datafield.
     *
     * @param integer $current_datatype_id
     * @param array $entity_names
     * @param array $datatype_array
     * @param array $datarecord_array
     *
     * @return array
     */
    private function groupFilesByDatafield($current_datatype_id, $entity_names, $datatype_array, $datarecord_array)
    {
        $file_array = array(
            'datafields' => array(),
            'child_datatypes' => array(),
        );

        $dt = $datatype_array[$current_datatype_id];

        // Don't want to have to sort the same list of datarecords more than once...
        $sorted_dr_list = array();
        foreach ($datarecord_array as $dr_id => $dr) {
            if ( $dr['dataType']['id'] === $current_datatype_id )
                $sorted_dr_list[$dr_id] = $dr['sortField_value'];
        }

        if ( !empty($sorted_dr_list) ) {
            uasort($sorted_dr_list, function ($a, $b) {
                return strnatcmp($a, $b);
            });
        }


        // If this datatype has file/image fields...
        foreach ($dt['dataFields'] as $df_id => $df) {
            // ...then determine whether any datarecords of this datatype have files/images uploaded
            //  into this datafield...
            foreach ($sorted_dr_list as $dr_id => $sort_value) {
                $dr = $datarecord_array[$dr_id];

                // ...then ensure there's a datafield entry in this array...
                if ( isset($dr['dataRecordFields'][$df_id]) ) {
                    $drf = $dr['dataRecordFields'][$df_id];

                    if ( !empty($drf['file']) || !empty($drf['image']) ) {
                        if ( !isset($file_array['datafields'][$df_id]) )
                            $file_array['datafields'][$df_id] = array();

                        // ...create an entry for this datarecord...
                        if ( !isset($file_array['datafields'][$df_id][$dr_id]) )
                            $file_array['datafields'][$df_id][$dr_id] = array();

                        // ...and then create entries for all the files/images that have been
                        //  uploaded to this datarecord
                        foreach ($drf['file'] as $num => $file) {
                            $file_id = $file['id'];
                            $file_array['datafields'][$df_id][$dr_id][$file_id] = $file['fileMeta']['originalFileName'];
                        }
                        foreach ($drf['image'] as $num => $thumbnail_image) {
                            // Don't want the thumbnail image
                            $image = $thumbnail_image['parent'];
                            $image_id = $image['id'];
                            $file_array['datafields'][$df_id][$dr_id][$image_id] = $image['imageMeta']['originalFileName'];
                        }
                    }
                }
            }
        }

        // If this datatype has child/linked datatypes...
        if ( isset($dt['descendants']) ) {
            foreach ($dt['descendants'] as $child_dt_id => $child_dt_props) {
                // User may not have permission to view the child/linked datatype...
                if ( !isset($datatype_array[$child_dt_id]) )
                    continue;

                // ...then determine if the child/linked datatype has any files
                $tmp = self::groupFilesByDatafield($child_dt_id, $entity_names, $datatype_array, $datarecord_array);

                // Only store the data for the child/linked datarecord if it has files, or has some
                //  descendant that has files
                if ( !empty($tmp[$child_dt_id]['datafields']) || !empty($tmp[$child_dt_id]['child_datatypes']) ) {
                    if (!isset($file_array['child_datatypes'][$child_dt_id]))
                        $file_array['child_datatypes'][$child_dt_id] = array();

                    $file_array['child_datatypes'][$child_dt_id] = $tmp[$child_dt_id];
                }
            }
        }

        return array($current_datatype_id => $file_array);
    }



    /**
     * Assuming the user has the correct permissions, adds each file from this datarecord/datafield
     * pair into a zip archive and returns that zip archive for download.
     *
     * @param int $grandparent_datarecord_id
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

            // Faster to use isset() than in_array()
            $file_ids = array_flip($file_ids);
            $image_ids = array_flip($image_ids);


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);
            // Don't need to verify any permissions, filterByGroupPermissions() will take care of it

            // Need a user id for the temp directory to work...
            $user_id = null;
            if ($user == null || $user === 'anon.')
                $user_id = 0;
            else
                $user_id = $user->getId();
            // ----------------------------------------


            // ----------------------------------------
            // Easier/faster to just load the entire datarecord/datatype arrays...
            $datarecord_array = $dri_service->getDatarecordArray($grandparent_datarecord->getId());
            $datatype_array = $dbi_service->getDatatypeArray($grandparent_datatype->getId());

            // ...so the permissions service can prevent the user from downloading files/images they're not allowed to see
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


            // ----------------------------------------
            // Intersect the array of desired file/image ids with the array of permitted files/ids
            //  to determine which files/images to add to the zip archive
            $file_list = array();
            $image_list = array();

            // Also need to ensure no duplicate filenames will be added to the archive
            $filename_list = array();
            $filename_count = array();
            foreach ($datarecord_array as $dr_id => $dr) {
                foreach ($dr['dataRecordFields'] as $drf_num => $drf) {
                    // If this datarecord has files...
                    if ( !empty($drf['file']) ) {
                        foreach ($drf['file'] as $file_num => $file) {
                            // ...and the user wants to download this file...
                            $current_file_id = $file['id'];
                            if ( isset($file_ids[$current_file_id]) ) {
                                // ...then determine whether a file/image with this filename has
                                //  already been scheduled for adding to the archive
                                $desired_filename = $file['fileMeta']['originalFileName'];
                                $unconflicting_filename = self::getArchiveFilename($desired_filename, $file['ext'], $filename_list, $filename_count);

                                // Store the file under its unique filename
                                $file_list[$unconflicting_filename] = $file;
                            }
                        }
                    }

                    // If this datarecord has images...
                    if ( !empty($drf['image']) ) {
                        foreach ($drf['image'] as $i_num => $thumbnail_image) {
                            // Don't want the thumbnail image
                            $image = $thumbnail_image['parent'];

                            // ...and the user wants to download this image...
                            $current_image_id = $image['id'];
                            if ( isset($image_ids[$current_image_id]) ) {
                                // ...then determine whether a file/image with this filename has
                                //  already been scheduled for adding to the archive
                                $desired_filename = $image['imageMeta']['originalFileName'];
                                $unconflicting_filename = self::getArchiveFilename($desired_filename, $image['ext'], $filename_list, $filename_count);

                                // Store the image under its unique filename
                                $image_list[$unconflicting_filename] = $image;
                            }
                        }
                    }
                }
            }


            // ----------------------------------------
            // If any files/images remain...
            if ( empty($file_list) && empty($image_list) ) {
                // TODO - what to return?
                $exact = true;
                throw new ODRNotFoundException('No files are available for downloading', $exact);
            }
            else {
                // Create a filename for the zip archive
                $tokenGenerator = $this->get('fos_user.util.token_generator');
                $random_id = substr($tokenGenerator->generateToken(), 0, 12);

                $archive_filename = $random_id.'.zip';
                $archive_filepath = $this->getParameter('odr_tmp_directory').'/user_'.$user_id.'/'.$archive_filename;

                $archive_size = count($file_list) + count($image_list);

                $requests = array();
                foreach ($file_list as $desired_filename => $file) {
                    // Need to locate the decrypted version of the file
                    $local_filename = '';
                    if ( $file['fileMeta']['publicDate']->format('Y-m-d') == '2200-01-01' ) {
                        // non-public files need to be decrypted to something difficult to guess
                        // This won't ever be run when $user_id == 0, since users that aren't logged in can't see non-public files
                        $local_filename = md5($file['original_checksum'].'_'.$file['id'].'_'.$user_id);
                        $local_filename .= '.'.$file['ext'];
                    }
                    else {
                        // public files need to be decrypted to this format
                        $local_filename = 'File_'.$file['id'].'.'.$file['ext'];
                    }

                    $requests[] = array(
                        'object_type' => 'File',
                        'object_id' => $file['id'],
                        'local_filename' => $local_filename,
                        'desired_filename' => $desired_filename,
                    );
                }
                foreach ($image_list as $desired_filename => $image) {
                    // Need to locate the decrypted version of the image
                    $local_filename = '';
                    if ( $image['imageMeta']['publicDate']->format('Y-m-d') == '2200-01-01' ) {
                        // non-public images need to be decrypted to something difficult to guess
                        // This won't ever be run when $user_id == 0, since users that aren't logged in can't see non-public files
                        $local_filename = md5($image['original_checksum'].'_'.$image['id'].'_'.$user_id);
                        $local_filename .= '.'.$image['ext'];
                    }
                    else {
                        // public images need to be decrypted to this format
                        $local_filename = 'Image_'.$image['id'].'.'.$image['ext'];
                    }

                    $requests[] = array(
                        'object_type' => 'Image',
                        'object_id' => $image['id'],
                        'local_filename' => $local_filename,
                        'desired_filename' => $desired_filename,
                    );
                }

                // Create the decryption requests for each of the files/images
                self::createArchiveRequest($archive_filepath, $requests);
            }

            $return['d'] = array('archive_filename' => $archive_filename, 'archive_size' => $archive_size);
        }
        catch (\Exception $e) {
            $source = 0xc31d45b5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        // If error encountered, do a json return
        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Creates and renders an HTML list of all file datafields belonging to the datatype being
     * searched on.
     *
     * @param string $search_key
     * @param Request $request
     *
     * @return Response
     */
    public function listsearchresultfilesAction($search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // Need to locate the datatype from the search key
            $search_key_service->validateSearchKey($search_key);
            $search_params = $search_key_service->decodeSearchKey($search_key);

            // Since the search key is valid, it will always have a datatype id in there
            $search_params_dt_id = $search_params['dt_id'];

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($search_params_dt_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Only allow on top-level datatypes
            if ($datatype->getId() !== $datatype->getGrandparent()->getId())
                throw new ODRBadRequestException('This action only works on top-level datatypes');


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // TODO - loosen restrictions even more?
            if ( !$pm_service->canEditDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // Going to use the cached datatype array for this
            $dt_array = $dbi_service->getDatatypeArray($datatype->getId());

            // Filter down to what the user is allowed to see first
            $dr_array = array();
            $pm_service->filterByGroupPermissions($dt_array, $dr_array, $user_permissions);


            // Need the names of all the datatypes and file datafields
            $entity_names = array(
                'datatypes' => array(),
                'datafields' => array(),
            );
            foreach ($dt_array as $dt_id => $dt) {
                $entity_names['datatypes'][$dt_id] = $dt['dataTypeMeta']['shortName'];

                foreach ($dt['dataFields'] as $df_id => $df) {
                    $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                    if ($typeclass === 'File' || $typeclass === 'Image') {
                        // Is a file or image datafield, store the name
                        $entity_names['datafields'][$df_id] = $df['dataFieldMeta']['fieldName'];
                    }
                    else {
                        // Not a file or image datafield, delete it out of the array
                        unset( $dt_array[$dt_id]['dataFields'][$df_id] );
                    }
                }
            }

            // Stack the datatype array so recursion is easier
            $dt_array = $dbi_service->stackDatatypeArray($dt_array, $search_params_dt_id);
            // Wrap it with the datatype id for the same reason
            $dt_array = array($search_params_dt_id => $dt_array);


            // ----------------------------------------
            // Render the dialog
            $return['d'] = $templating->render(
                'ODRAdminBundle:Default:mass_download_dialog_form.html.twig',
                array(
                    'entity_names' => $entity_names,

                    'dt_array' => $dt_array,
                    'search_key' => $search_key,
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xa71012ea;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        // If error encountered, do a json return
        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Assuming the user has the correct permissions, adds each file uploaded to the specified
     * datafields into a zip archive, and returns that zip archive for download.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function startsearchresultfilesdownloadAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Symfony firewall won't permit GET requests to reach this point
            $post = $request->request->all();

            // Require both of these...
            if ( !isset($post['search_key']) || !isset($post['datafields']) )
                throw new ODRBadRequestException();

            $search_key = $post['search_key'];
            $datafields = $post['datafields'];
            if ( empty($datafields) )
                throw new ODRBadRequestException();


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');


            // Need to ensure the datatype from the search key matches the given datafield
            $search_key_service->validateSearchKey($search_key);
            $search_params = $search_key_service->decodeSearchKey($search_key);

            // Since the search key is valid, it will always have a datatype id in there
            $search_params_dt_id = intval($search_params['dt_id']);
            /** @var DataType $grandparent_datatype */
            $grandparent_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($search_params_dt_id);
            if ($grandparent_datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Need to verify that each datafield provided is related to the grandparent datatype,
            //  and that they're all file or image fields
            $associated_datatypes = $dti_service->getAssociatedDatatypes($search_params_dt_id);
            // Flip because isset() is faster than in_array()
            $associated_datatypes = array_flip($associated_datatypes);

            $hydrated_datafields = array();
            foreach ($datafields as $num => $df_id) {
                /** @var DataFields $df */
                $df = $em->getRepository('ODRAdminBundle:DataFields')->find($df_id);
                if ($df == null)
                    throw new ODRNotFoundException('Invalid datafield');

                if ( !isset($associated_datatypes[$df->getDataType()->getGrandparent()->getId()]) )
                    throw new ODRBadRequestException('Invalid search key');

                $typeclass = $df->getFieldType()->getTypeClass();
                if ( $typeclass !== 'File' && $typeclass !== 'Image' )
                    throw new ODRBadRequestException('Invalid datafield');

                $hydrated_datafields[$df_id] = $df;
            }
            /** @var DataFields[] $hydrated_datafields */


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // TODO - loosen restrictions even more?
            if ( !$pm_service->canEditDatatype($user, $grandparent_datatype) )
                throw new ODRForbiddenException();

            // The search results are already filtered to just the datarecords the user can view

            // Need to "manually" filter out non-public files though, depending on whether the user
            //  can view non-public datarecords or not
            $can_view_nonpublic_datarecords = array();
            foreach ($hydrated_datafields as $df_id => $df) {
                // Need to check whether the user can view each of the datafields, though
                if ( !$pm_service->canViewDatafield($user, $df) )
                    throw new ODRForbiddenException();

                // The filtering of non-public files has to be done on a per-datatype basis, but
                //  it's easier to use on a per-datafield basis
                if ( !isset($can_view_nonpublic_datarecords[$df_id]) ) {
                    $can_view_nonpublic_datarecords[$df_id] = $pm_service->canViewNonPublicDatarecords($user, $df->getDataType());
                }
            }
            // ----------------------------------------

            // Need to ensure that the filenames of all files/images to be added to the archive
            //  are unique
            $filename_list = array();
            $filename_count = array();


            // Loading and digging through potentially thousands of cached datarecord entries is
            //  untenable...faster to query the database directly for the relevant info

            // Going to need the list of all datarecords that matched the search
            $dr_list = $search_api_service->performSearch(
                $grandparent_datatype,
                $search_key,
                $user_permissions,
                true  // need the child/linked descendant records, not just grandparents...
            );

            $query = $em->createQuery(
               'SELECT partial drf.{id},
                    partial f.{id, ext, original_checksum},
                    partial fm.{id, originalFileName, publicDate},
                    partial df.{id}
                FROM ODRAdminBundle:DataRecordFields drf
                JOIN drf.file AS f
                JOIN f.fileMeta AS fm
                JOIN f.dataField AS df
                WHERE drf.dataRecord IN (:datarecords) AND drf.dataField IN (:datafields)
                AND drf.deletedAt IS NULL AND df.deletedAt IS NULL
                AND f.deletedAt IS NULL AND fm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datafields' => $datafields,
                    'datarecords' => $dr_list,
                )
            );
            $results = $query->getArrayResult();

            // Organize files by their filename
            $file_list = array();
            foreach ($results as $drf) {
                foreach ($drf['file'] as $file_num => $file) {
                    // Need to ignore files from deleted datafields...
                    if ( is_null($file['dataField']) )
                        continue;

                    // Need to filter out non-public files if the user can't view them
                    $is_public = true;
                    if ( $file['fileMeta'][0]['publicDate']->format('Y-m-d') == '2200-01-01' )
                        $is_public = false;

                    // Determine whether the user can view non-public records for this datatype
                    $df_id = $file['dataField']['id'];
                    $can_view_datarecord = $can_view_nonpublic_datarecords[$df_id];

                    // If the user can't view non-public records and the file is not public, then
                    //  don't store it in the array
                    if (!$can_view_datarecord && !$is_public)
                        continue;

                    // Otherwise, check whether the file is already slated to be added to the zip
                    //  archive...
                    $file['fileMeta'] = $file['fileMeta'][0];
                    $desired_filename = $file['fileMeta']['originalFileName'];
                    $unconflicting_filename = self::getArchiveFilename($desired_filename, $file['ext'], $filename_list, $filename_count);

                    // Store the file under its unique filename
                    $file_list[$unconflicting_filename] = $file;
                }
            }

            // Need to do the same query, but for images this time
            $query = $em->createQuery(
               'SELECT partial drf.{id},
                    partial i.{id},
                    partial ip.{id, ext, original_checksum},
                    partial ipm.{id, originalFileName, publicDate},
                    partial df.{id}
                FROM ODRAdminBundle:DataRecordFields drf
                JOIN drf.image AS i
                JOIN i.parent AS ip
                JOIN ip.imageMeta AS ipm
                JOIN ip.dataField AS df
                WHERE drf.dataRecord IN (:datarecords) AND drf.dataField IN (:datafields)
                AND drf.deletedAt IS NULL AND df.deletedAt IS NULL
                AND i.deletedAt IS NULL AND ip.deletedAt IS NULL AND ipm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datafields' => $datafields,
                    'datarecords' => $dr_list,
                )
            );
            $results = $query->getArrayResult();

            // Organize images by their filename
            $image_list = array();
            foreach ($results as $drf) {
                foreach ($drf['image'] as $image_num => $thumbnail_image) {
                    // Want to store the original image in the archive
                    $image = $thumbnail_image['parent'];

                    // Need to ignore images from deleted datafields...
                    if ( is_null($image['dataField']) )
                        continue;

                    // Need to filter out non-public images if the user can't view them
                    $is_public = true;
                    if ( $image['imageMeta'][0]['publicDate']->format('Y-m-d') == '2200-01-01' )
                        $is_public = false;

                    // Determine whether the user can view non-public records for this datatype
                    $df_id = $image['dataField']['id'];
                    $can_view_datarecord = $can_view_nonpublic_datarecords[$df_id];

                    // If the user can't view non-public records and the image is not public, then
                    //  don't store it in the array
                    if (!$can_view_datarecord && !$is_public)
                        continue;

                    // Otherwise, check whether the file is already slated to be added to the zip
                    //  archive...
                    $image['imageMeta'] = $image['imageMeta'][0];
                    $desired_filename = $image['imageMeta']['originalFileName'];
                    $unconflicting_filename = self::getArchiveFilename($desired_filename, $image['ext'], $filename_list, $filename_count);

                    // Store the file under its unique filename
                    $image_list[$unconflicting_filename] = $image;
                }
            }


            // ----------------------------------------
            // If any files/images remain...
            if ( empty($file_list) && empty($image_list) ) {
                // TODO - what to return?
                $exact = true;
                throw new ODRNotFoundException('No files or images are available for downloading', $exact);
            }
            else {
                // Create a filename for the zip archive
                $tokenGenerator = $this->get('fos_user.util.token_generator');
                $random_id = substr($tokenGenerator->generateToken(), 0, 12);

                $archive_filename = $random_id.'.zip';
                $archive_filepath = $this->getParameter('odr_tmp_directory').'/user_'.$user->getId().'/'.$archive_filename;

                $archive_size = count($file_list) + count($image_list);

                $requests = array();
                foreach ($file_list as $desired_filename => $file) {
                    // Need to locate the decrypted version of the file
                    $local_filename = '';
                    if ( $file['fileMeta']['publicDate']->format('Y-m-d') == '2200-01-01' ) {
                        // non-public files need to be decrypted to something difficult to guess
                        $local_filename = md5($file['original_checksum'].'_'.$file['id'].'_'.$user->getId());
                        $local_filename .= '.'.$file['ext'];
                    }
                    else {
                        // public files need to be decrypted to this format
                        $local_filename = 'File_'.$file['id'].'.'.$file['ext'];
                    }

                    $requests[] = array(
                        'object_type' => 'File',
                        'object_id' => $file['id'],
                        'local_filename' => $local_filename,
                        'desired_filename' => $desired_filename,
                    );
                }

                // Do the same for the images
                foreach ($image_list as $desired_filename => $image) {
                    // Need to locate the decrypted version of the image
                    $local_filename = '';
                    if ( $image['imageMeta']['publicDate']->format('Y-m-d') == '2200-01-01' ) {
                        // non-public images need to be decrypted to something difficult to guess
                        $local_filename = md5($image['original_checksum'].'_'.$image['id'].'_'.$user->getId());
                        $local_filename .= '.'.$image['ext'];
                    }
                    else {
                        // public images need to be decrypted to this format
                        $local_filename = 'Image_'.$image['id'].'.'.$image['ext'];
                    }

                    $requests[] = array(
                        'object_type' => 'Image',
                        'object_id' => $image['id'],
                        'local_filename' => $local_filename,
                        'desired_filename' => $desired_filename,
                    );
                }

                // Create the decryption requests for each of the files/images
                self::createArchiveRequest($archive_filepath, $requests);
            }

            // TODO - is there some way to return that there are going to be duplicate filenames and/or duplicate files before the download starts?
            $return['d'] = array(
                'archive_filename' => $archive_filename,
                'archive_size' => $archive_size
            );
        }
        catch (\Exception $e) {
            $source = 0x23ed5770;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Need to ensure that all files/images going into the archive have unique names, otherwise the
     * background process will never finish creating the archive.
     *
     * @param string $requested_filename
     * @param string $ext
     * @param array $filename_list
     * @param array $filename_count
     *
     * @return string
     */
    private function getArchiveFilename($requested_filename, $ext, &$filename_list, &$filename_count)
    {
        if ( !isset($filename_list[$requested_filename]) ) {
            // A file/image with this filename hasn't been seen before
            $filename_list[$requested_filename] = 1;
            $filename_count[$requested_filename] = 1;

            // Can use this filename
            return $requested_filename;
        }
        else {
            // A file/image with this filename has been seen before...need to modify the filename
            //  so there's no collision
            $duplicate_num = $filename_count[$requested_filename];

            // Drop the extension from the previous filename...
            $new_filename = substr($requested_filename, 0, strrpos($requested_filename, "."));
            // ...so a number can be appended immediately before the extension
            $new_filename .= '('.$duplicate_num.').'.$ext;

            // Store the entity under the modified filename
            $filename_list[$new_filename] = 1;

            // Increment this number incase there's yet another duplicate of this filename later on...
            $filename_count[$requested_filename]++;
            // ...and also store the modified filename in case it collides with a later
            //  file as well
            $filename_count[$new_filename] = 1;

            // Use the modified filename
            return $new_filename;
        }
    }


    /**
     * Converts an array of files/images scheduled for decryption into background jobs.
     *
     * @param string $archive_filepath
     * @param array $requests
     */
    private function createArchiveRequest($archive_filepath, $requests)
    {
        // Ensure the directory that the zip archive will reside in exists...ZipArchive::open()
        //  does not create missing directories
        $archive_directory = substr($archive_filepath, 0, strrpos($archive_filepath, '/'));
        if ( !file_exists($archive_directory) )
            mkdir( $archive_directory );

        // Generate the url for cURL to use
        $pheanstalk = $this->get('pheanstalk');
        $url = $this->generateUrl('odr_crypto_request', array(), UrlGeneratorInterface::ABSOLUTE_URL);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');     // debug purposes only
        $api_key = $this->container->getParameter('beanstalk_api_key');

        // Schedule a beanstalk job to start decrypting the requests in the array
        foreach ($requests as $request) {
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    "archive_filepath" => $archive_filepath,
                    "crypto_type" => 'decrypt',

                    "object_type" => $request['object_type'],
                    "object_id" => $request['object_id'],
                    "local_filename" => $request['local_filename'],
                    "desired_filename" => $request['desired_filename'],

                    "redis_prefix" => $redis_prefix,    // debug purposes only
                    "url" => $url,
                    "api_key" => $api_key,
                )
            );

            $delay = 0;
            $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
        }
    }


    /**
     * Zip archives constructed by startdownloadarchiveAction() or startsearchresultfilesdownloadAction()
     * are downloaded with this controller action.
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
            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            // Don't need to check user's permissions

            // Need a user id for the temp directory to work...
            $user_id = null;
            if ($user == null || $user === 'anon.')
                $user_id = 0;
            else
                $user_id = $user->getId();
            // ----------------------------------------


            // Symfony firewall requires $archive_filename to match "0|[0-9a-zA-Z\-\_]{12}.zip"
            if ($archive_filename == '0')
                throw new ODRBadRequestException();

            $archive_filepath = $this->getParameter('odr_tmp_directory').'/user_'.$user_id.'/'.$archive_filename;
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Redirects to a random datarecord the user can view from the given datatype.
     *
     * @param string $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function viewrandomAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var Router $router */
            $router = $this->get('router');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // Locate a random datarecord of this datatype that the user can view...
            $query = null;
            if ( $permissions_service->canViewNonPublicDatarecords($user, $datatype) ) {
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord dr
                    WHERE dr.dataType = :datatype_id
                    AND dr.deletedAt IS NULL'
                )->setParameters( array('datatype_id' => $datatype->getId()) );
            }
            else {
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord dr
                    LEFT JOIN ODRAdminBundle:DataRecordMeta drm WITH drm.dataRecord = dr
                    WHERE dr.dataType = :datatype_id AND drm.publicDate != :non_public_date
                    AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL'
                )->setParameters( array('datatype_id' => $datatype->getId(), 'non_public_date' => '2200-01-01 00:00:00') );
            }

            $results = $query->getArrayResult();
            $num = rand(0, count($results));

            // ...and return a url to it
            $url = $router->generate(
                'odr_display_view',
                array(
                    'datarecord_id' => $results[$num]['dr_id'],
                )
            );

            $return['d'] = array('url' => $url);
            $return['r'] = 2;
        }
        catch (\Exception $e) {
            $source = 0x06d6cbeb;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        return $response;
    }
}
