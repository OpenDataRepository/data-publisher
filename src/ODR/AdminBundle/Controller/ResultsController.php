<?php

/**
* Open Data Repository Data Publisher
* Results Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The results controller displays actual record results to the 
* user. These results may be pulled from memcached or directly
* rendered if no cached copy exists.  It also handles file and
* image downloads because of routing constraints within Symfony.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entites
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\File;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ResultsController extends ODRCustomController
{
    /**
     * Returns the "Results" version of the given DataRecord.
     * 
     * @param integer $datarecord_id The database id of the datarecord to return.
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function viewAction($datarecord_id, $search_key, $offset, Request $request) 
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Current User
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();

            // Set up repositories
            $em = $this->getDoctrine()->getManager();
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');

            // Ensure the datarecord isn't deleted
            $datarecord = $repo_datarecord->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');
            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // TODO - not technically accurate
            if ($datarecord->getProvisioned() == true)
                return parent::permissionDeniedError();

            // ----------------------------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = array();
            $logged_in = true;
            $has_view_permission = false;

            if ( $user === 'anon.' ) {
                $logged_in = false;

                if ( !$datatype->isPublic() ) {
                    // non-public datatype and anonymous user, can't view
                    return parent::permissionDeniedError('view');
                }
                else {
                    // public datatype, anybody can view
                }
            }
            else {
                // Grab user's permissions
                $user_permissions = parent::getPermissionsArray($user->getId(), $request);

                // If user has view permissions, show non-public sections of the datarecord
                if ( isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'view' ]) )
                    $has_view_permission = true;

                // If datatype is not public and user doesn't have permissions to view anything other than public sections of the datarecord, then don't allow them to view
                if ( !$datatype->isPublic() && !$has_view_permission )
                    return parent::permissionDeniedError('view');
            }
            // ----------------------------------------


            // ----------------------------------------
            // If this datarecord is being viewed from a search result list, attempt to grab the list of datarecords from that search result
            $datarecord_list = '';
            $encoded_search_key = '';
            if ($search_key !== '') {
                // 
                $data = parent::getSavedSearch($search_key, $logged_in, $request);
                $encoded_search_key = $data['encoded_search_key'];
                $datarecord_list = $data['datarecord_list'];

                // If the user is attempting to view a datarecord from a search that returned no results...
                if ($encoded_search_key !== '' && $datarecord_list === '') {
                    // ...get the search controller to redirect to "no results found" page
                    $search_controller = $this->get('odr_search_controller', $request);
                    return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
                }
            }


            // ----------------------------------------
            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];

            // Locate a sorted list of datarecords for search_header.html.twig if possible
            if ( $session->has('stored_tab_data') && $odr_tab_id !== '' ) {
                // Prefer the use of the sorted lists created during usage of the datatables plugin over the default list created during searching
                $stored_tab_data = $session->get('stored_tab_data');

                if ( isset($stored_tab_data[$odr_tab_id]) ) {
                    // Grab datarecord list if it exists
                    if ( isset($stored_tab_data[$odr_tab_id]['datarecord_list']) )
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


            // ----------------------------------------
            // Build an array of values to use for navigating the search result list, if it exists
            $header_html = '';
            $search_header = parent::getSearchHeaderValues($datarecord_list, $datarecord->getId(), $request);

            $router = $this->get('router');
            $templating = $this->get('templating');

            $redirect_path = $router->generate('odr_results_view', array('datarecord_id' => 0));    // blank path
            $header_html = $templating->render(
                'ODRAdminBundle:Results:results_header.html.twig',
                array(
                    'user_permissions' => $user_permissions,
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
            // Attempt to grab the correct version of the record from the cache
            $cache_html = '';
            // If user is not logged in and datarecord is not public
            if ($user === 'anon.' && !$datarecord->isPublic()) {
                return parent::permissionDeniedError();
            }
            else {
                // Display the public version unless the user is logged in and has view permissions
                $public_only = true;
                if ( $user !== 'anon.' && $has_view_permission )
                    $public_only = false;


                // ----------------------------------------
                // Attempt to load the correct version of the datarecord from the cache...
                $data = null;
                if ($this->container->getParameter('kernel.environment') === 'dev') {
                    /* no caching in dev environment, do nothing */
                }
                else if ($public_only) {
                    // ...load the variant of the DataRecord that hides the children
                    $data = $memcached->get($memcached_prefix.'.data_record_long_form_public_'.$datarecord_id);
                }
                else {
                    // TODO - child datarecords that aren't public?
                    // ...load the variant of the DataRecord that shows everything
                    $data = $memcached->get($memcached_prefix.'.data_record_long_form_'.$datarecord_id);
                }


                // ----------------------------------------
                // Ensure the cached version exists and is up to date
                $datatype_revision = $datatype->getRevision();
                if ($data == null || $data['revision'] < $datatype_revision) {
                    // If the cached html doesn't exist, ensure all the entities exist 
                    parent::verifyExistence($datarecord);

                    // Render the variant of the DataRecord that the user is going to get to see, and save it to memcached immediately
                    if ($public_only) {
                        $cache_html = parent::Long_GetDisplayData($request, $datarecord->getId(), 'public_only');

                        $data = array( 'revision' => $datatype_revision, 'html' => $cache_html );
                        $memcached->set($memcached_prefix.'.data_record_long_form_public_'.$datarecord_id, $data, 0);
                    }
                    else {
                        $cache_html = parent::Long_GetDisplayData($request, $datarecord->getId());

                        $data = array( 'revision' => $datatype_revision, 'html' => $cache_html );
                        $memcached->set($memcached_prefix.'.data_record_long_form_'.$datarecord_id, $data, 0);
                    }

                    // Get a worker process to ensure all the cache entries for the datarecord exist
                    parent::updateDatarecordCache($datarecord->getId());
                }
                else {
                    // The cache version exists and is up to date...extract the html from the memcached entry
                    $cache_html = $data['html'];
                }
            }

            // ----------------------------------------
            // Return the HTML
            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $header_html.$cache_html
            );

            // Store which datarecord to scroll to when the user returns to the datarecord list
            $session = $request->getSession();
            $session->set('scroll_target', $datarecord->getId());
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38978321 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Creates a Symfony response that so browsers can download files from the server.
     * TODO - http://symfony.com/doc/current/components/http_foundation/introduction.html#serving-files
     * 
     * @param integer $file_id The database id of the file to download.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function filedownloadAction($file_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        $response = new Response();

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $crypto = $this->get("dterranova_crypto.crypto_adapter");

            // Locate the file in the database
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                return parent::deletedEntityError('File');
            $datarecord = $file->getDataRecord();
            if ($datarecord == null)
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();


            // --------------------
            // Check to see if the user is permitted to download this file
            if ( !$file->isPublic() ) {
                // Determine user privileges
                $user = $this->container->get('security.context')->getToken()->getUser();
                if ($user === 'anon.') {
                    // Non-logged in users not allowed to download non-public files
                    return parent::permissionDeniedError();
                }
                else {
                    // Grab the user's permission list
                    $user_permissions = parent::getPermissionsArray($user->getId(), $request);

                    // Ensure user has permissions to be doing this
                    if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'view' ])) )
                        return parent::permissionDeniedError();
                }
            }
            else {
                /* file is public, so no restrictions on who can download it */
            }
            // --------------------


            // Ensure the file exists in decrypted format
            $file_path = parent::decryptObject($file->getId(), 'file');
            $handle = fopen($file_path, 'r');
            if ($handle !== false) {

                $display_filename = $file->getOriginalFileName();
                if ($display_filename == null)
                    $display_filename = 'File_'.$file_id.'.'.$file->getExt();

                // Set up a response to send the file back
                $response->setPrivate();
                $response->headers->set('Content-Type', mime_content_type($file_path));
                $response->headers->set('Content-Length', filesize($file_path));
                $response->headers->set('Content-Disposition', 'attachment; filename="'.$display_filename.'";');

                $response->sendHeaders();

                $content = file_get_contents($file_path);   // using file_get_contents() because apparently readfile() tacks on # of bytes read at end of file for firefox
                $response->setContent($content);

                fclose($handle);

                // If the file isn't public, delete the decrypted version so isn't be accessible from web
                if (!$file->isPublic())
                    unlink($file_path);
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x848418123 ' . $e->getMessage();
        }

        if ($return['r'] !== 0) {
            // If error encountered, do a json return
            $response = new Response(json_encode($return));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        }
        else {
            // Otherwise, return the previously created response
            return $response;
        }

    }


    /**
     * Creates a Symfony response that so browsers can download images from the server.
     * TODO - http://symfony.com/doc/current/components/http_foundation/introduction.html#serving-files
     *
     * @param integer $image_id The database_id of the image to download.
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function imagedownloadAction($image_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        $response = new Response();

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $crypto = $this->get("dterranova_crypto.crypto_adapter");

            // Locate the image object in the database
            $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
            if ($image == null)
                return parent::deletedEntityError('Image');
            $datarecord = $image->getDataRecord();
            if ($datarecord == null)
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();


            // --------------------
            // Check to see if the user is permitted to download this image
            if ( !$image->isPublic() ) {
                // Determine user privileges
                $user = $this->container->get('security.context')->getToken()->getUser();
                if ($user === 'anon.') {
                    // Non-logged in users not allowed to download non-public images
                    return parent::permissionDeniedError();
                }
                else {
                    // Grab the user's permission list
                    $user_permissions = parent::getPermissionsArray($user->getId(), $request);

                    // Ensure user has permissions to be doing this
                    if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'view' ])) )
                        return parent::permissionDeniedError();
                }
            }
            else {
                /* image is public, so no restrictions on who can download it */
            }
            // --------------------


            // Ensure the image exists in decrypted form
            $image_path = parent::decryptObject($image->getId(), 'image');
            $handle = fopen($image_path, 'r');
            if ($handle !== false) {

                // Have to send image headers first, apparently...
                $response->setPrivate();
                switch ($image->getExt()) {
                    case 'GIF':
                    case 'gif':
                        $response->headers->set('Content-Type', 'image/gif');
                        break;
                    case 'PNG':
                    case 'png':
                        $response->headers->set('Content-Type', 'image/png');
                        break;
                    case 'JPG':
                    case 'jpg':
                    case 'jpeg':
                        $response->headers->set('Content-Type', 'image/jpeg');
                        break;
                }
                $response->sendHeaders();

                // After headers are sent, send the image itself
                switch ($image->getExt()) {
                    case 'GIF':
                    case 'gif':
                        $im = imagecreatefromgif($image_path);
                        imagegif($im);
                        break;
                    case 'PNG':
                    case 'png':
                        $im = imagecreatefrompng($image_path);
                        imagepng($im);
                        break;
                    case 'JPG':
                    case 'jpg':
                    case 'jpeg':
                        $im = imagecreatefromjpeg($image_path);
                        imagejpeg($im);
                        break;
                }
                imagedestroy($im);

                fclose($handle);

                // If the image isn't public, delete the decrypted version so isn't be accessible from web
                if (!$image->isPublic())
                    unlink($image_path);
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x848418124 ' . $e->getMessage();
        }

        if ($return['r'] !== 0) {
            // If error encountered, do a json return
            $response = new Response(json_encode($return));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        }
        else {
            // Return the previously created response
            return $response;
        }

    }


    /**
     * TODO - sitemap function
     * 
     * @param Integer $datarecord_id
     * @param string $datarecord_name
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function mapAction($datarecord_id, $datarecord_name, Request $request)
    {
        $return = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $templating = $this->get('templating');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Grab the desired datarecord
            $datarecord = $repo_datarecord->find($datarecord_id);
//            $datatype = $datarecord->getDataType();

            // Determine which memcached key to load
            $has_non_public_children = false;
            $childrecords = $repo_datarecord->findByGrandparent($datarecord);
            foreach ($childrecords as $childrecord) {
                if ($childrecord->isPublic()) {
                    $has_non_public_children = true;
                    break;
                }
            }

            // If user is not logged in and the DataRecord has children that need to be hidden...
            $user = 'anon.';
            if ($user === 'anon.' && $has_non_public_children) {
                // ...load the varient of the DataRecord that hides the children
                $cache_html = $memcached->get($memcached_prefix.'.data_record_long_form_public_'.$datarecord_id);

                // No caching in dev environment
                if ($this->getParameter('kernel.environment') === 'dev')
                    $cache_html = null;

                if ($cache_html == null) {
                    // If the cached html doesn't exist, ensure all the entities exist before rendering caching the DataRecord's html
                    parent::verifyExistence($datarecord);
                    $cache_html = parent::Long_GetDisplayData($request, $datarecord->getId(), 'public_only');
                    $memcached->set($memcached_prefix.'.data_record_long_form_public_'.$datarecord_id, $cache_html, 0);
                }
            }
            else {
                // ...user is logged in, or DataRecord has nothing to hide
                $cache_html = $memcached->get($memcached_prefix.'.data_record_long_form_'.$datarecord_id);

                // No caching in dev environment
                if ($this->getParameter('kernel.environment') === 'dev')
                    $cache_html = null;


                if ($cache_html == null) {
                    // If the cached html doesn't exist, ensure all the entities exist before rendering caching the DataRecord's html
                    parent::verifyExistence($datarecord);
                    $cache_html = parent::Long_GetDisplayData($request, $datarecord->getId());
                    $memcached->set($memcached_prefix.'.data_record_long_form_'.$datarecord_id, $cache_html, 0);
                }
            }

            // Render the javascript redirect
            $prefix = '/app_dev.php/search#';
//            $redirect_str = $this->generateUrl( 'odr_results_view', array('datarecord_id' => $datarecord_id, 'search_key' => '', 'search_string' => '') );
            $redirect_str = $this->generateUrl( 'odr_results_view', array('datarecord_id' => $datarecord_id) );
            $header = $templating->render(
                'ODRAdminBundle:Default:redirect_js.html.twig',
                array(
                    'prefix' => $prefix,
                    'url' => $redirect_str
                )
            );

            // Concatenate the two
            $return = $header.$cache_html;
        }
        catch (\Exception $e) {
/*
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x802484450 ' . $e->getMessage();
*/
        }

        $response = new Response($return);
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }

}
