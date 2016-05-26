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

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Cookie;
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
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                return parent::deletedEntityError('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');


            // ----------------------------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = array();
            $datafield_permissions = array();
            $has_view_permission = false;
            $logged_in = true;

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
                $datatype_permissions = parent::getPermissionsArray($user->getId(), $request);
                $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

                // If user has view permissions, show non-public sections of the datarecord
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'view' ]) )
                    $has_view_permission = true;

                // If datatype is not public and user doesn't have permissions to view anything other than public sections of the datarecord, then don't allow them to view
                if ( !$datatype->isPublic() && !$has_view_permission )
                    return parent::permissionDeniedError('view');
            }
            // ----------------------------------------


            // ----------------------------------------
            // If this datarecord is being viewed from a search result list...
            $datarecord_list = '';
            $encoded_search_key = '';
            if ($search_key !== '') {
                // ...attempt to grab the list of datarecords from that search result
                $data = parent::getSavedSearch($datatype->getId(), $search_key, $logged_in, $request);
                $encoded_search_key = $data['encoded_search_key'];
                $datarecord_list = $data['datarecord_list'];

                if ($data['error'] == true || ($encoded_search_key !== '' && $datarecord_list === '') ) {
                    // Some sort of error encounted...bad search query, invalid permissions, or empty datarecord list
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
            $search_header = parent::getSearchHeaderValues($datarecord_list, $datarecord->getId(), $request);

            $router = $this->get('router');
            $templating = $this->get('templating');

            $redirect_path = $router->generate('odr_display_view', array('datarecord_id' => 0));    // blank path
            $header_html = $templating->render(
                'ODRAdminBundle:Results:results_header.html.twig',
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
            // Always bypass cache if in dev mode?
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;


            // Grab all datarecords "associated" with the desired datarecord...
            $associated_datarecords = $memcached->get($memcached_prefix.'.associated_datarecords_for_'.$datarecord_id);
            if ($bypass_cache || $associated_datarecords == false) {
                $associated_datarecords = parent::getAssociatedDatarecords($em, array($datarecord_id));

//print '<pre>'.print_r($associated_datarecords, true).'</pre>';  exit();

                $memcached->set($memcached_prefix.'.associated_datarecords_for_'.$datarecord_id, $associated_datarecords, 0);
            }


            // Grab the cached versions of all of the associated datarecords, and store them all at the same level in a single array
            $datarecord_array = array();
            foreach ($associated_datarecords as $num => $dr_id) {
                $datarecord_data = $memcached->get($memcached_prefix.'.cached_datarecord_'.$dr_id);
                if ($bypass_cache || $datarecord_data == false)
                    $datarecord_data = parent::getDatarecordData($em, $dr_id, true);

                foreach ($datarecord_data as $dr_id => $data)
                    $datarecord_array[$dr_id] = $data;
            }

//print '<pre>'.print_r($datarecord_array, true).'</pre>';  exit();

            // ----------------------------------------
            //
            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);

            // Grab all datatypes associated with the desired datarecord
            // NOTE - not using parent::getAssociatedDatatypes() here on purpose...don't care about child/linked datatypes if they aren't attached to this datarecord
            $associated_datatypes = array();
            foreach ($datarecord_array as $dr_id => $dr) {
                $dt_id = $dr['dataType']['id'];

                if ( !in_array($dt_id, $associated_datatypes) )
                    $associated_datatypes[] = $dt_id;
            }

            // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
            $datatype_array = array();
            foreach ($associated_datatypes as $num => $dt_id) {
                $datatype_data = $memcached->get($memcached_prefix.'.cached_datatype_'.$dt_id);
                if ($bypass_cache || $datatype_data == false)
                    $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

                foreach ($datatype_data as $dt_id => $data)
                    $datatype_array[$dt_id] = $data;
            }

//print '<pre>'.print_r($datatype_array, true).'</pre>';  exit();

            // ----------------------------------------
            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            parent::filterByUserPermissions($datatype->getId(), $datatype_array, $datarecord_array, $datatype_permissions, $datafield_permissions);


            // ----------------------------------------
            // Render the DataRecord
            $templating = $this->get('templating');
            $page_html = $templating->render(
                'ODRAdminBundle:Display:display_ajax.html.twig',
                array(
                    'datatype_array' => $datatype_array,
                    'datarecord_array' => $datarecord_array,
                    'theme_id' => $theme->getId(),

                    'initial_datatype_id' => $datatype->getId(),
                    'initial_datarecord_id' => $datarecord->getId(),

                    'search_key' => $search_key,
                )
            );

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $header_html.$page_html
            );

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
     * Given a datarecord and datafield, re-render and return the html for that datafield.
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
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                return parent::deletedEntityError('Datarecord');
            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                return parent::deletedEntityError('DataField');
            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $has_view_permission = false;

            if ( $user === 'anon.' ) {
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
                $datatype_permissions = parent::getPermissionsArray($user->getId(), $request);
                $datafield_permissions = parent::getPermissionsArray($user->getId(), $request);

                // If user has view permissions, show non-public sections of the datarecord
                if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'view' ])) )
                    $has_view_permission = false;
                if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'view' ])) )
                    $has_view_permission = false;

                // If datatype is not public and user doesn't have permissions to view anything other than public sections of the datarecord, then don't allow them to view
                if ( !$datatype->isPublic() && !$has_view_permission )
                    return parent::permissionDeniedError('view');
            }
            // --------------------

            // Always bypass cache if in dev mode?
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;


            // ----------------------------------------
            // Grab the cached versions of the desired datarecord
            $datarecord_array = array();
            $datarecord_data = $memcached->get($memcached_prefix.'.cached_datarecord_'.$datarecord->getId());
            if ($bypass_cache || $datarecord_data == false)
                $datarecord_data = parent::getDatarecordData($em, $datarecord->getId(), $bypass_cache);

            foreach ($datarecord_data as $dr_id => $data)
                $datarecord_array[$dr_id] = $data;


            // Grab the cached version of the datafield's datatype
            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);
            $datatype_array = array();
            $datatype_data = $memcached->get($memcached_prefix.'.cached_datatype_'.$datatype->getId());
            if ($bypass_cache || $datatype_data == false)
                $datatype_data = parent::getDatatypeData($em, $datatree_array, $datatype->getId(), $bypass_cache);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;


            // Extract datafield and theme_datafield from datatype_array
            $datafield = null;
            foreach ($datatype_array[$datatype->getId()]['themes'][$theme_id]['themeElements'] as $te_num => $te) {
                foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                    if ($tdf['dataField']['id'] == $datafield_id) {
                        $datafield = $tdf['dataField'];
                        break;
                    }
                }
                if ($datafield !== null)
                    break;
            }


            // ----------------------------------------
            // Render and return the HTML for this datafield
            $templating = $this->get('templating');
            $html = $templating->render(
                'ODRAdminBundle:Display:display_datafield.html.twig',
                array(
                    'datatype' => $datatype_array[ $datatype->getId() ],
                    'datarecord' => $datarecord_array[ $datarecord->getId() ],
                    'datafield' => $datafield,
                )
            );

            $return['d'] = array('html' => $html);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x438381285 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
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

        $file_decryptions = array();
        $temp_filename = '';

        try {
            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Locate the file in the database
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                return parent::deletedEntityError('File');
            $datarecord = $file->getDataRecord();
            if ($datarecord == null)
                return parent::deletedEntityError('DataRecord');
            $datatype = $datarecord->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            // Files that aren't done encrypting shouldn't be downloaded
            if ($file->getOriginalChecksum() == '')
                return parent::deletedEntityError('File');

            // ----------------------------------------
            // Public files are quicker/easier to deal with
            if ( $file->isPublic() ) {
                $local_filepath = realpath( dirname(__FILE__).'/../../../../web/'.$file->getLocalFileName() );
                if (!$local_filepath) {
                    // File is public, but doesn't exist in decrypted format for some reason
                    $local_filepath = parent::decryptObject($file_id, 'file');
                }
                else if ( filesize($local_filepath) < $file->getFilesize() ) {
                    // File exists but isn't fully decrypted yet for some reason...it's most likely in the process of being decrypted
                    $previous_filesize = null;
                    $current_filesize = filesize($local_filepath);

                    $tries = 0;
                    while ( $current_filesize < $file->getFilesize() ) {
                        // Grab current filesize of decrypted file
                        clearstatcache(true, $local_filepath);
                        $current_filesize = filesize($local_filepath);

                        if ($previous_filesize !== $current_filesize) {
                            // Keep track of progress of file decryption
                            $previous_filesize = $current_filesize;
                            $tries = 0;
                        }
                        else {
                            // ...No progress was made on the file decryption for some reason
                            $tries++;
                            if ($tries >= 15)
                                throw new \Exception('Decryption of public File '.$file_id.' appears to be frozen, aborting...');
                        }

                        // Sleep for 2 seconds to give whatever process is decrypting the file time to finish
                        sleep(2);
                    }
                }

                // File exists and is fully decrypted...stream it to the requesting user
                $response = self::createDownloadResponse($file, $local_filepath);
                return $response;
            }


            // ----------------------------------------
            // Non-Public files are more work because they always need decryption...but first, ensure user is permitted to download
            /** @var User $user */
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

            // Determine the temporary filename for this file
            $temp_filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId());
            $temp_filename .= '.'.$file->getExt();
            $local_filepath = dirname(__FILE__).'/../../../../web/uploads/files/'.$temp_filename;

            // Determine whether the user is already decrypting this file
            $request_number = 1;
            $file_decryptions = $memcached->get($memcached_prefix.'_file_decryptions');
            if ( $file_decryptions == false ) {
                // User is either not decrypting any file at the moment
                $memcached->set($memcached_prefix.'_file_decryptions', array($temp_filename => 1), 0);
            }
            else {
                // User is currently decrypting something...
                if ( !isset($file_decryptions[$temp_filename]) ) {
                    // ...but not this specific file, which is fine
                    $file_decryptions[$temp_filename] = 1;
                    $memcached->set($memcached_prefix.'_file_decryptions', $file_decryptions, 0);
                }
                else {
                    // ...and they happen to somehow have already requested a decryption on this file

                    // Store that another process is requesting this file...
                    // The first process will finish decrypting, but only the most recent requesting process should serve the file
                    $request_number = $file_decryptions[$temp_filename] + 1;
                    $file_decryptions[$temp_filename] = $request_number;
                    $memcached->set($memcached_prefix.'_file_decryptions', $file_decryptions, 0);
                }
            }
/*
$log_file = fopen( dirname(__FILE__).'/../../../../app/logs/test_'.$request_number.'.log', 'w');
if (!$log_file)
    print 'could not open log file';
fwrite($log_file, time().': request number: '.$request_number."\n");
*/
            // User is allowed to download file...
            if ($request_number == 1) {
                // This is (currently) the only request the user has made for this file...begin manually decrypting it because the crypto bundle offers limited control over filenames
                $crypto = $this->get("dterranova_crypto.crypto_adapter");
                $crypto_dir = dirname(__FILE__).'/../../../../app/crypto_dir/';     // TODO - load from config file somehow?
                $crypto_dir .= 'File_'.$file_id;

                // Grab the hex string representation that the file was encrypted with
                $key = $file->getEncryptKey();
                // Convert the hex string representation to binary...php had a function to go bin->hex, but didn't have a function for hex->bin for at least 7 years?!?
                $key = pack("H*" , $key);   // don't have hex2bin() in current version of php...this appears to work based on the "if it decrypts to something intelligible, you did it right" theory

                // Open the target file
                $handle = fopen($local_filepath, "wb");
                if (!$handle)
                    throw new \Exception('Unable to open "'.$local_filepath.'" for writing');

                // Decrypt each chunk and write to target file
                $chunk_id = 0;
                while( file_exists($crypto_dir.'/'.'enc.'.$chunk_id) ) {
                    if ( !file_exists($crypto_dir.'/'.'enc.'.$chunk_id) )
                        throw new \Exception('Encrypted chunk not found: '.$crypto_dir.'/'.'enc.'.$chunk_id);

                    $data = file_get_contents($crypto_dir.'/'.'enc.'.$chunk_id);
                    fwrite($handle, $crypto->decrypt($data, $key));
                    $chunk_id++;

//fwrite($log_file, time().': decrypted chunk '.$chunk_id."\n");

                    // Check occasionally to see if the decryption was cancelled
                    if ( ($chunk_id % 50) == 0 ) {
                        $file_decryptions = $memcached->get($memcached_prefix.'_file_decryptions');
/*
fwrite($log_file, time().': checking memcached...');
fwrite($log_file, print_r($file_decryptions, true) );
fwrite($log_file, "\n");
*/

                        if ( $file_decryptions == false || !isset($file_decryptions[$temp_filename]) ) {
                            // Memcached claims no ongoing file decryption requests, or a cancellation of this decryption request...stop decrypting this file immediately
//fwrite($log_file, time().': aborting decryption'."\n");
                            break;
                        }
                    }
                }

                // Done decrypting the file
                fclose($handle);
            }
            else {
                // This is another request made for the same file by the same user...
                // Only way happen is by attempting to download the same file on multiple tabs, or downloading then refreshing page then downloading same file again
                // Regardless of how it happened, the server should only decrypt the file once, then stream the file to the most recent response, then delete the file

                // Ensure file exists...
                $tries = 0;
                while ( !file_exists($local_filepath) ) {
                    $tries++;
                    if ($tries > 15)
                        throw new \Exception('Decryption of non-public File '.$file_id.' appears to be frozen, aborting...');

                    // Sleep for 2 seconds to try to give whichever process is decrypting the file a chance to create it...
                    sleep(2);
                }

                // File exists but isn't fully decrypted yet...it's most likely in the process of being decrypted
                $previous_filesize = null;
                $current_filesize = filesize($local_filepath);

                $tries = 0;
                while ( $current_filesize < $file->getFilesize() ) {
                    // Grab current filesize of decrypted file
                    clearstatcache(true, $local_filepath);
                    $current_filesize = filesize($local_filepath);

//fwrite($log_file, time().': previous_filesize '.$previous_filesize.'  current_filesize '.$current_filesize."\n");

                    if ($previous_filesize !== $current_filesize) {
                        // Keep track of progress of file decryption
                        $previous_filesize = $current_filesize;
                        $tries = 0;
                    }
                    else {
                        // ...No progress was made on the file decryption for some reason
                        $tries++;
                        if ($tries >= 15)
                            throw new \Exception('File decryption seems stuck...');
                    }

                    // If the decryption process got cancelled by the user, or the user somehow managed to start yet another decryption request for this file...don't sit around waiting
                    $file_decryptions = $memcached->get($memcached_prefix.'_file_decryptions');
/*
fwrite($log_file, time().': checking memcached...');
fwrite($log_file, print_r($file_decryptions, true) );
fwrite($log_file, "\n");
*/

                    if ( $file_decryptions == false || !isset($file_decryptions[$temp_filename]) ) {
                        // Memcached claims no ongoing file decryption requests, or a cancellation of this decryption request...stop decrypting this file immediately
//fwrite($log_file, time().': decryption cancelled, aborting wait process...'."\n");
                        break;
                    }

                    // Sleep for 2 seconds to give whatever process is decrypting the file time to finish
                    sleep(2);
                }
            }


            // ----------------------------------------
            // File decryption is done
            $file_decryptions = $memcached->get($memcached_prefix.'_file_decryptions');
            if ( $file_decryptions != false && isset($file_decryptions[$temp_filename]) ) {

                if ( $file_decryptions[$temp_filename] == $request_number ) {
/*
fwrite($log_file, time().': returning file...'."\n");
fclose($log_file);
*/
                    // Decryption wasn't cancelled, and this is the most recent request for the file...create the streaming response
                    $response = self::createDownloadResponse($file, $local_filepath);

                    // Delete the file off the server...this still works, despite the order sounding odd
                    if (file_exists($local_filepath))
                        unlink($local_filepath);

                    // No longer waiting on this file to decrypt
                    unset($file_decryptions[$temp_filename]);
                    $memcached->set($memcached_prefix.'_file_decryptions', $file_decryptions, 0);

                    // Start the file download
                    return $response;
                }
                else {
                    /* do nothing, a different process has everything under control */
/*
fwrite($log_file, time().': stepping down...'."\n");
fclose($log_file);
*/
                }
            }
            else if ( $request_number == 1 ) {
/*
fwrite($log_file, time().': attempting to delete decrypted file...'."\n");
fclose($log_file);
*/
                // Decryption was cancelled...only have the first process delete the decrypted file
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);
            }

            // If the process didn't return the file download, then return nothing
            $response = new Response();
            $response->setStatusCode(503);  // TODO - 503 works as a status code to return?
            return $response;

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x848418123: ' . $e->getMessage();

            // No longer waiting on this file to decrypt
            if ( isset($file_decryptions[$temp_filename]) ) {
                $memcached = $this->get('memcached');
                $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
                $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

                unset($file_decryptions[$temp_filename]);
                $memcached->set($memcached_prefix.'_file_decryptions', $file_decryptions, 0);
            }

            // If error encountered, do a json return
            $response = new Response(json_encode($return));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
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
            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Locate the file in the database
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                return parent::deletedEntityError('File');
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
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'view' ])) )
                return parent::permissionDeniedError();
            // ----------------------------------------


            // ----------------------------------------
            // Only able to cancel downloads of non-public files...
            if ( !$file->isPublic() ) {

                // Determine the temporary filename being used to store the decrypted file
                $temp_filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId());
                $temp_filename .= '.'.$file->getExt();

                // Ensure that the memcached marker for the decryption of this file does not exist
                $file_decryptions = $memcached->get($memcached_prefix.'_file_decryptions');
                if ($file_decryptions != false && isset($file_decryptions[$temp_filename])) {
                    unset($file_decryptions[$temp_filename]);
                    $memcached->set($memcached_prefix.'_file_decryptions', $file_decryptions, 0);
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

        $response = new Response();

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Locate the image object in the database
            /** @var Image $image */
            $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
            if ($image == null)
                return parent::deletedEntityError('Image');
            $datarecord = $image->getDataRecord();
            if ($datarecord == null)
                return parent::deletedEntityError('DataRecord');
            $datatype = $datarecord->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            // Images that aren't done encrypting shouldn't be downloaded
            if ($image->getEncryptKey() == '')
                return parent::deletedEntityError('Image');

            // --------------------
            // Check to see if the user is permitted to download this image
            if ( !$image->isPublic() ) {
                // Determine user privileges
                /** @var User $user */
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


            // Ensure the image exists in decrypted format
            $image_path = realpath( dirname(__FILE__).'/../../../../web/'.$image->getLocalFileName() );     // realpath() returns false if file does not exist
            if ( !$image->isPublic() || !$image_path )
                $image_path = parent::decryptObject($image->getId(), 'image');

            $handle = fopen($image_path, 'r');
            if ($handle === false)
                throw new \Exception('Unable to open image at "'.$image_path.'"');


            // Have to send image headers first...
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
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x848418124: ' . $e->getMessage();
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

}
