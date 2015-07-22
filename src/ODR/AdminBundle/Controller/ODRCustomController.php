<?php

/**
* Open Data Repository Data Publisher
* ODRCustom Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This controller attempts to store a single copy of mostly
* utility and rendering functions that would otherwise be
* effectively duplicated across multiple controllers.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entites
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementField;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\Boolean;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\FileChecksum;
use ODR\AdminBundle\Entity\ImageChecksum;
use ODR\AdminBundle\Entity\UserPermissions;
use ODR\AdminBundle\Entity\UserFieldPermissions;

// Forms
use ODR\AdminBundle\Form\BooleanForm;
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\ShortVarcharForm;
use ODR\AdminBundle\Form\MediumVarcharForm;
use ODR\AdminBundle\Form\LongVarcharForm;
use ODR\AdminBundle\Form\LongTextForm;
use ODR\AdminBundle\Form\DecimalValueForm;
use ODR\AdminBundle\Form\DatetimeValueForm;
use ODR\AdminBundle\Form\IntegerValueForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;


class ODRCustomController extends Controller
{

    /**
     * Utility function that renders a list of datarecords inside a wrapper template (shortresutlslist.html.twig or textresultslist.html.twig).
     * This is to allow various functions to only worry about what needs to be rendered, instead of having to do it all themselves.
     *
     * @param array $datarecord_list The unfiltered list of datarecord ids that need rendered...this should contain EVERYTHING
     * @param DataType $datatype     Which datatype the datarecords belong to
     * @param Theme $theme           ...TODO - eventually need to use this to indicate which version to use when rendering
     * @param User $user             Which user is requesting this list
     *
     * @param string $target         "Results" or "Record"...where to redirect when a datarecord from this list is selected
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     *
     * @param Request $request
     *
     * @return string TODO
     */
    public function renderList($datarecords, $datatype, $theme, $user, $path_str, $target, $search_key, $offset, Request $request)
    {
        // -----------------------------------
        // Grab necessary objects
        $templating = $this->get('templating');
        $session = $this->get('session');
        $repo_datarecord = $this->getDoctrine()->getManager()->getRepository('ODRAdminBundle:DataRecord');

        $user_permissions = array();
        if ($user !== 'anon.')
            $user_permissions = self::getPermissionsArray($user->getId(), $request);

        // Grab the tab's id, if it exists
        $params = $request->query->all();
        $odr_tab_id = '';
        if ( isset($params['odr_tab_id']) ) {
            // If the tab id exists, use that
            $odr_tab_id = $params['odr_tab_id'];
        }
        else {
            // ...otherwise, generate a random key to identify this tab
            $tokenGenerator = $this->container->get('fos_user.util.token_generator');
            $odr_tab_id = substr($tokenGenerator->generateToken(), 0, 15);
        }

        // Grab the page length for this tab from the session, if possible
        $page_length = 100;
        if ( $odr_tab_id !== '' && $session->has('stored_tab_data') ) {
            $stored_tab_data = $session->get('stored_tab_data');
            if ( isset($stored_tab_data[$odr_tab_id]) ) {
                if ( isset($stored_tab_data[$odr_tab_id]['page_length']) ) {
                    $page_length = $stored_tab_data[$odr_tab_id]['page_length'];
                }
                else {
                    $stored_tab_data[$odr_tab_id]['page_length'] = $page_length;
                    $session->set('stored_tab_data', $stored_tab_data);
                }
            }
        }

        // Save how many datarecords were passed to this function
        $total_datarecords = count($datarecords);

        // -----------------------------------
        // Determine where on the page to scroll to if possible
        $scroll_target = '';
        if ($session->has('scroll_target')) {
            $scroll_target = $session->get('scroll_target');
            if ($scroll_target !== '') {
                // Don't scroll to someplace on the page if the datarecord doesn't match the datatype
                $datarecord = $repo_datarecord->find($scroll_target);
                if ( $datarecord == null || $datarecord->getDataType()->getId() != $datatype->getId() || !in_array($scroll_target, $datarecords) )
                    $scroll_target = '';

                // Null out the scroll target
                $session->set('scroll_target', '');     // WTF WHY
            }
        }


        // -----------------------------------
        $final_html = '';
        if ( $theme->getId() == 2 ) {   // TODO - THIS HAS TO SUPPORT MORE THAN JUST ONE
            // -----------------------------------
            // Ensure offset exists for shortresults list
            if ( (($offset-1) * $page_length) > count($datarecords) )
                $offset = 1;

            // Reduce datarecord_list to just the list that will get rendered
            $datarecord_list = array();
            $start = ($offset-1) * $page_length;
            for ($index = $start; $index < ($start + $page_length); $index++) {
                if ( !isset($datarecords[$index]) )
                    break;

                $datarecord_list[] = $datarecords[$index];
            }


            // -----------------------------------
            // Build the html required for this...
            $pagination_html = self::buildPaginationHeader( $total_datarecords, $offset, $path_str, $request);
            $shortresults_html = self::renderShortResultsList($datarecord_list, $datatype, $theme, $request);
            $html = $pagination_html.$shortresults_html.$pagination_html;


            // -----------------------------------
            // Finally, insert the html into the correct template
            $template = 'ODRAdminBundle:ShortResults:shortresultslist.html.twig';
            $final_html = $templating->render(
                $template,
                array(
                    'datatype' => $datatype,
                    'count' => $total_datarecords,
                    'html' => $html,
                    'scroll_target' => $scroll_target,
                    'user' => $user,
                    'user_permissions' => $user_permissions,
                    'odr_tab_id' => $odr_tab_id,

                    // required for load_datarecord_js.html.twig
                    'target' => $target,
                    'search_key' => $search_key,
                    'offset' => $offset,
                )
            );

        }
        else if ( $theme->getId() == 4 ) {  // TODO - THIS IS NOT AN OFFICIAL OR CORRECT USE OF THIS THEME
            // -----------------------------------
            // Grab the...
            $column_data = self::getDatatablesColumnNames($datatype->getId());
            $column_names = $column_data['column_names'];
            $num_columns = $column_data['num_columns'];
/*
print_r($column_names);
print "\n\n";
*/

            // Don't render the starting textresults list here, it'll always be loaded via ajax later

            // -----------------------------------
            //
            $template = 'ODRAdminBundle:TextResults:textresultslist.html.twig';
            if ($target == 'linking')
                $template = 'ODRAdminBundle:Record:link_datarecord_form_search.html.twig';

            $final_html = $templating->render(
                $template,
                array(
                    'datatype' => $datatype,
                    'count' => $total_datarecords,
                    'column_names' => $column_names,
                    'num_columns' => $num_columns,
                    'odr_tab_id' => $odr_tab_id,
                    'page_length' => $page_length,
                    'scroll_target' => $scroll_target,
                    'user' => $user,
                    'user_permissions' => $user_permissions,

                    // required for load_datarecord_js.html.twig
                    'target' => $target,
                    'search_key' => $search_key,
                    'offset' => $offset,

                    // Provide the list of all possible datarecord ids to twig just incase...though not strictly used by the datatables ajax, the rows returned will always end up being some subset of this list
                    'all_datarecords' => $datarecords,
                )
            );
        }

        return $final_html;
    }


    /**
     * Attempt to load the ShortResults version of the cached entries for each datarecord in $datarecord_list, returning a blank "click here to recache" entry if the actual cached version does not exist.
     *
     * @param array $datarecord_list The list of datarecord ids that need rendered
     * @param DataType $datatype     Which datatype the datarecords belong to
     * @param Theme $theme           ...TODO - eventually need to use this to indicate which version to use when rendering
     * @param Request $request
     *
     * @return string TODO
     */
    public function renderShortResultsList($datarecord_list, $datatype, $theme, Request $request)
    {
        // Grab necessary objects
        $templating = $this->get('templating');
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');


        // Build...
        $datatype_revision = $datatype->getRevision();
        $final_html = '';
        foreach ($datarecord_list as $num => $datarecord_id) {
            // Attempt to grab the textresults thingy for this datarecord from the cache
            $data = $memcached->get($memcached_prefix.'.data_record_short_form_'.$datarecord_id);

            // No caching in dev environment
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $data = null;

            $html = null;
            if ($data !== null && is_array($data) && count($data) == 2 && $data['revision'] == $datatype_revision) {
                // Grab the array of data from the cache entry
                $html = $data['html'];
            }
            else {
                $html = $templating->render(
                    'ODRAdminBundle:ShortResults:shortresults_blank.html.twig',
                    array(
                        'datatype_id' => $datatype->getId(),
                        'datarecord_id' => $datarecord_id,
                    )
                );

                // Since the memcached entries was null, schedule the datarecord for a memcached update
                // ...unless we're in dev environment, where it won't matter because it'll get ignored
                if ($this->container->getParameter('kernel.environment') !== 'dev') {
                    $options = array();
                    self::updateDatarecordCache($datarecord_id, $options);
                }
            }

            $final_html .= $html;
        }

        return $final_html;
    }


    /**
     * Attempt to load the textresult version of the cached entries for each datarecord in $datarecord_list.
     *
     * @param array $datarecord_list The list of datarecord ids that need rendered
     * @param DataType $datatype     Which datatype the datarecords belong to
     * @param Request $request
     *
     * @return boolean TODO
     */
    public function renderTextResultsList($datarecord_list, $datatype, Request $request)
    {
        // Grab necessary objects
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        // Build...
        $datatype_revision = $datatype->getRevision();
        $rows = array();
        foreach ($datarecord_list as $num => $datarecord_id) {
            // Attempt to grab the textresults thingy for this datarecord from the cache
            $data = $memcached->get($memcached_prefix.'.data_record_short_text_form_'.$datarecord_id);

            // No caching in dev environment
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $data = null;

            $fields = null;
            if ($data !== null && is_array($data) && count($data) == 2 && $data['revision'] == $datatype_revision) {
                // Grab the array of data from the cache entry
                $fields = $data['html'];
            }
            else {
                // Rebuilding one of these is cheap?
                $fields = self::Text_GetDisplayData($request, $datarecord_id);

                // Store immediately?
                $data = array('revision' => $datatype_revision, 'html' => $fields);
                $memcached->set($memcached_prefix.'.data_record_short_text_form_'.$datarecord_id, $data, 0);
            }

            $rows[] = $fields;
        }

        return $rows;
    }

    /**
     * Returns true if the datafield is currently in use by ShortResults
     * TODO - this will need to be changed when multiple ShortResults themes become available
     *
     * @param DataField $datafield
     *
     * @return boolean TODO
     */
    protected function inShortResults($datafield)
    {
        foreach ($datafield->getThemeDataField() as $tdf) {
            if ($tdf->getTheme()->getId() == 2 && $tdf->getActive())
                return true;
        }

        return false;
    }


    /**
     * Determines values for the pagination header TODO 
     *
     * @param integer $num_datarecords The total number of datarecords belonging to the datatype/search result 
     * @param integer $offset          Which page of results the user is currently on
     * @param string $path_str         The base url used before paging gets involved...$path_str + '/2' would redirect to page 2, $path_str + '/3' would redirect to page 3, etc TODO
     * @param Request $request
     *
     * @return array TODO
     */
    protected function buildPaginationHeader($num_datarecords, $offset, $path_str, Request $request)
    {
        // Grab necessary objects
        $session = $request->getSession();

        // Grab the tab's id, if it exists
        $params = $request->query->all();
        $odr_tab_id = '';
        if ( isset($params['odr_tab_id']) )
            $odr_tab_id = $params['odr_tab_id'];

        // Grab the page length for this tab from the session, if possible
        $page_length = 100;
        if ( $odr_tab_id !== '' && $session->has('stored_tab_data') ) {
            $stored_tab_data = $session->get('stored_tab_data');
            if ( isset($stored_tab_data[$odr_tab_id]) ) {
                if ( isset($stored_tab_data[$odr_tab_id]['page_length']) ) {
                    $page_length = $stored_tab_data[$odr_tab_id]['page_length'];
                }
                else {
                    $stored_tab_data[$odr_tab_id]['page_length'] = $page_length;
                    $session->set('stored_tab_data', $stored_tab_data);
                }
            }
        }

        // If only one page, don't bother with pagination block
        $num_pages = ceil( $num_datarecords / $page_length );
//        if ($num_pages == 1)
//            return '';

        // Ensure $offset is in bounds
        if ($offset === '' || $offset < 1)
            $offset = 1;
        if ( (($offset-1) * $page_length) > $num_datarecords )
            $offset = ceil($num_datarecords / $page_length);


        // Render the pagination block
        $templating = $this->get('templating');
        $html = $templating->render(
            'ODRAdminBundle:Default:pagination_header.html.twig',
            array(
                'num_pages' => $num_pages,
                'offset' => $offset,
                'path_str' => $path_str,
                'num_datarecords' => $num_datarecords,
                'page_length' => $page_length
            )
        );
        return $html;
    }


    /**
     * Get (or create) a list of datarecords returned by searching on the given search key
     *
     * @param string $search_key
     * @param boolean $logged_in Whether the user is logged in or not
     * @param Request $request
     *
     * @return array TODO
     */
    protected function getSavedSearch($search_key, $logged_in, Request $request)
    {
        //
        $session = $request->getSession();
        $data = array('encoded_search_key' => '', 'datarecord_list' => '');

        //
        $search_controller = $this->get('odr_search_controller', $request);
        $search_controller->setContainer($this->container);

        if ( !$session->has('saved_searches') ) {
            // No saved searches at all, redo the search with the given search key...
            $search_controller->performSearch($search_key, $request);
        }

        // Grab the list of saved searches and attempt to locate the desired search
        $saved_searches = $session->get('saved_searches');
        $search_checksum = md5($search_key);

        if ( !isset($saved_searches[$search_checksum]) ) {
            // No saved search for this query, redo the search...
            $search_controller->performSearch($search_key, $request);

            // Grab the list of saved searches again
            $saved_searches = $session->get('saved_searches');
        }

        $search_params = $saved_searches[$search_checksum];
        $was_logged_in = $search_params['logged_in'];

        // If user's login status changed between now and when the search was run...
        if ($was_logged_in !== $logged_in) {
            // ...run the search again 
            $search_controller->performSearch($search_key, $request);
            $saved_searches = $session->get('saved_searches');
            $search_params = $saved_searches[$search_checksum];
        }

        // Now that the search is guaranteed to exist and be correct...get all pieces of info about the search
        $data['datarecord_list'] = $search_params['datarecords'];
        $data['encoded_search_key'] = $search_params['encoded_search_key'];

        return $data;
    }


    /**
     * Determines values for the search header (next/prev/return to search results) of Results/Records when searching
     *
     * @param string $datarecord_list A comma-separated list of datarecord ids that satisfy the search TODO - why comma-separated?  just going to explode() them...
     * @param integer $datarecord_id  The database id of the datarecord the user is currently at...used to determine where the next/prev datarecord buttons redirect to
     * @param Request $request
     *
     * @return array TODO
     */
    protected function getSearchHeaderValues($datarecord_list, $datarecord_id, Request $request)
    {
        // Grab necessary objects
        $session = $request->getSession();

        // Grab the tab's id, if it exists
        $params = $request->query->all();
        $odr_tab_id = '';
        if ( isset($params['odr_tab_id']) )
            $odr_tab_id = $params['odr_tab_id'];

        // Grab the page length for this tab from the session, if possible
        $page_length = 100;
        if ( $odr_tab_id !== '' && $session->has('stored_tab_data') ) {
            $stored_tab_data = $session->get('stored_tab_data');
            if ( isset($stored_tab_data[$odr_tab_id]) ) {
                if ( isset($stored_tab_data[$odr_tab_id]['page_length']) ) {
                    $page_length = $stored_tab_data[$odr_tab_id]['page_length'];
                }
                else {
                    $stored_tab_data[$odr_tab_id]['page_length'] = $page_length;
                    $session->set('stored_tab_data', $stored_tab_data);
                }
            }
        }

        $next_datarecord = '';
        $prev_datarecord = '';
        $search_result_current = '';
        $search_result_count = '';

        if ($datarecord_list !== null && trim($datarecord_list) !== '') {
            // Turn the search results string into an array of datarecord ids
            $search_results = explode(',', trim($datarecord_list));

            foreach ($search_results as $num => $id) {
                if ( $datarecord_id == $id ) {
                    $search_result_current = $num+1;
                    $search_result_count = count($search_results);

                    if ($num == count($search_results)-1 )
                        $next_datarecord = $search_results[0];
                    else
                        $next_datarecord = $search_results[$num+1];

                    if ($num == 0)
                        $prev_datarecord = $search_results[ count($search_results)-1 ];
                    else
                        $prev_datarecord = $search_results[$num-1];
                }
            }
        }

        $search_header = array( 'page_length' => $page_length, 'next_datarecord' => $next_datarecord, 'prev_datarecord' => $prev_datarecord, 'search_result_current' => $search_result_current, 'search_result_count' => $search_result_count );
        return $search_header;
    }


    /**
     * Utility function that does the work of encrypting a given File/Image entity.
     * 
     * @param integer $object_id  The id of the File/Image to encrypt
     * @param string $object_type "File" or "Image"
     * 
     * @return none
     */
    protected function encryptObject($object_id, $object_type)
    {
        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $generator = $this->container->get('security.secure_random');
            $crypto = $this->get("dterranova_crypto.crypto_adapter");

            $repo_filechecksum = $em->getRepository('ODRAdminBundle:FileChecksum');
            $repo_imagechecksum = $em->getRepository('ODRAdminBundle:ImageChecksum');


            $absolute_path = '';
            $base_obj = null;
            $object_type = strtolower($object_type);
            if ($object_type == 'file') {
                // Grab the file and associated information
                $base_obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
                $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
                $filename = 'File_'.$object_id.'.'.$base_obj->getExt();

                if ( !file_exists($file_upload_path.$filename) )
                    throw new \Exception("File does not exist");
        
                // crypto bundle requires an absolute path to the file to encrypt/decrypt
                $absolute_path = realpath($file_upload_path.$filename);
            }
            else if ($object_type == 'image') {
                // Grab the image and associated information
                $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
                $image_upload_path = dirname(__FILE__).'/../../../../web/uploads/images/';
                $imagename = 'Image_'.$object_id.'.'.$base_obj->getExt();

                if ( !file_exists($image_upload_path.$imagename) )
                    throw new \Exception("Image does not exist");
        
                // crypto bundle requires an absolute path to the file to encrypt/decrypt
                $absolute_path = realpath($image_upload_path.$imagename);
            }

            // Generate a random number for encryption purposes
            $bytes = $generator->nextBytes(16); // 128-bit random number
//print 'bytes ('.gettype($bytes).'): '.$bytes."\n";

            // Convert the binary key into a hex string for db storage
            $hexEncoded_num = bin2hex($bytes);

            // Save the encryption key 
            $base_obj->setEncryptKey($hexEncoded_num);
            $em->persist($base_obj);

            // Encrypt the file
            $crypto->encryptFile($absolute_path, $bytes);


            // Locate the directory where the encrypted files exist
            $encrypted_basedir = $this->container->getParameter('dterranova_crypto.temp_folder');
            if ($object_type == 'file')
                $encrypted_basedir .= '/File_'.$object_id.'/';
            else if ($object_type == 'image')
                $encrypted_basedir .= '/Image_'.$object_id.'/';

            // Create an md5 checksum of all the pieces of that encrypted file
            $chunk_id = 0;
            while ( file_exists($encrypted_basedir.'enc.'.$chunk_id) ) {
                $checksum = md5_file($encrypted_basedir.'enc.'.$chunk_id);

                // Attempt to load a checksum object
                // TODO: is this always going to be null?
                $obj = null;
                if ($object_type == 'file')
                    $obj = $repo_filechecksum->findOneBy( array('File' => $object_id, 'chunk_id' => $chunk_id) );
                else if ($object_type == 'image')
                    $obj = $repo_imagechecksum->findOneBy( array('Image' => $object_id, 'chunk_id' => $chunk_id) );

                // Create a checksum entry if it doesn't exist
                if ($obj == null) {
                    if ($object_type == 'file') {
                        $obj = new FileChecksum();
                        $obj->setFile($base_obj);
                    }
                    else if ($object_type == 'image') {
                        $obj = new ImageChecksum();
                        $obj->setImage($base_obj);
                    }
                }

                // Save the checksum entry
                $obj->setChunkId($chunk_id);
                $obj->setChecksum($checksum);

                $em->persist($obj);

                // Look for any more encrypted chunks
                $chunk_id++;
            }

            // Save all changes
            $em->flush();

        }
        catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }

    }


    /**
     * Utility function that does the work of decrypting a given File/Image entity.
     * 
     * @param integer $object_id  The id of the File/Image to decrypt
     * @param string $object_type "File" or "Image"
     * 
     * @return string The absolute path to the newly decrypted file/image
     */
    protected function decryptObject($object_id, $object_type)
    {
        // Grab necessary objects
        $em = $this->getDoctrine()->getManager();
        $crypto = $this->get("dterranova_crypto.crypto_adapter");

        // TODO: auto-check the checksum?
//        $repo_filechecksum = $em->getRepository('ODRAdminBundle:FileChecksum');
//        $repo_imagechecksum = $em->getRepository('ODRAdminBundle:ImageChecksum');


        $absolute_path = '';
        $base_obj = null;
        if ($object_type == 'file') {
            // Grab the file and associated information
            $base_obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
            $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
            $filename = 'File_'.$object_id.'.'.$base_obj->getExt();

            // crypto bundle requires an absolute path to the file to encrypt/decrypt
            $absolute_path = realpath($file_upload_path).'/'.$filename;
        }
        else if ($object_type == 'image') {
            // Grab the image and associated information
            $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            $image_upload_path = dirname(__FILE__).'/../../../../web/uploads/images/';
            $imagename = 'Image_'.$object_id.'.'.$base_obj->getExt();

            // crypto bundle requires an absolute path to the file to encrypt/decrypt
            $absolute_path = realpath($image_upload_path).'/'.$imagename;
        }

        // Apparently files/images can decrypt to a zero length file sometimes...check for and deal with this
        if ( file_exists($absolute_path) && filesize($absolute_path) == 0 )
            unlink($absolute_path);

        // Since errors apparently don't cascade from the CryptoBundle through to here...
        if ( !file_exists($absolute_path) ) {
            // Grab the hex string representation that the file was encrypted with
            $key = $base_obj->getEncryptKey();
            // Convert the hex string representation to binary...php had a function to go bin->hex, but didn't have a function for hex->bin for at least 7 years?!?
            $key = pack("H*" , $key);   // don't have hex2bin() in current version of php...this appears to work based on the "if it decrypts to something intelligible, you did it right" theory

            // Decrypt the file (does NOT delete the encrypted version)
            $crypto->decryptFile($absolute_path, $key, false);
        }

        return $absolute_path;
    }


    /**
     * Determines and returns an array of top-level datatype ids
     * 
     * @return array
     */
    public function getTopLevelDatatypes()
    {
        $em = $this->getDoctrine()->getManager();
        $query = $em->createQuery(
           'SELECT dt.id AS datatype_id
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $all_datatypes = array();
        foreach ($results as $num => $result)
            $all_datatypes[] = $result['datatype_id'];
//print_r($all_datatypes);

        $query = $em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
            FROM ODRAdminBundle:DataTree AS dt
            JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE dt.is_link = 0
            AND dt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $parent_of = array();
        foreach ($results as $num => $result)
            $parent_of[ $result['descendant_id'] ] = $result['ancestor_id'];
//print_r($parent_of);

        $top_level_datatypes = array();
        foreach ($all_datatypes as $datatype_id) {
            if ( !isset($parent_of[$datatype_id]) )
                $top_level_datatypes[] = $datatype_id;
        }
//print_r($top_level_datatypes);

        return $top_level_datatypes;
    }


    /**
     * Utility function to returns the DataTree table in array format
     *
     * @param EntityManager $em
     *
     * @return array TODO
     */
    public function getDatatreeArray($em)
    {
        // 
        $query = $em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id, dt.is_link AS is_link
            FROM ODRAdminBundle:DataType AS ancestor
            JOIN ODRAdminBundle:DataTree AS dt WITH ancestor = dt.ancestor
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE ancestor.deletedAt IS NULL AND dt.deletedAt IS NULL AND descendant.deletedAt IS NULL');
        $results = $query->getArrayResult();

        $datatree_array = array(
//            'ancestor_of' => array(),
          'descendant_of' => array(),
        );
        foreach ($results as $num => $result) {
            $ancestor_id = $result['ancestor_id'];
            $descendant_id = $result['descendant_id'];
            $is_link = $result['is_link'];

            if ( !isset($datatree_array['descendant_of'][$ancestor_id]) )
                $datatree_array['descendant_of'][$ancestor_id] = '';

            if ($is_link == 0)
                $datatree_array['descendant_of'][$descendant_id] = $ancestor_id;
        }

        return $datatree_array;
    }


    /**
     * Builds an array of all datatype permissions possessed by the given user.
     * 
     * @param integer $user_id          The database id of the user to grab permissions for
     * @param Request $request
     * @param boolean $save_permissions If true, save the calling user's permissions in the user's session...if false, just return an array
     * 
     * @return array
     */
    public function getPermissionsArray($user_id, Request $request, $save_permissions = true)
    {
        try {
//$save_permissions = false;
            $session = $request->getSession();
            if ( !$save_permissions || !$session->has('permissions') ) {
                // Permissions not set, need to build an array
                $em = $this->getDoctrine()->getManager();
                $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');

                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id, up.can_view_type, up.can_edit_record, up.can_add_record, up.can_delete_record, up.can_design_type, up.is_type_admin
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:UserPermissions AS up WITH up.dataType = dt
                    WHERE up.user_id = :user'
                )->setParameters( array('user' => $user_id) );
                $results = $query->getArrayResult();
//print_r($results);
//return;

                // Grab the contents of ODRAdminBundle:DataTree as an array
                $datatree_array = self::getDatatreeArray($em);

                $all_permissions = array();
                foreach ($results as $result) {
                    $datatype_id = $result['dt_id'];

                    $all_permissions[$datatype_id] = array();
                    $save = false;

                    if ($result['can_view_type'] == 1) {
                        $all_permissions[$datatype_id]['view'] = 1;
                        $save = true; 
                    }
                    if ($result['can_edit_record'] == 1) {
                        $all_permissions[$datatype_id]['edit'] = 1;
                        $save = true;

                        // If this is a child datatype, then the user needs to be able to access the edit page of its eventual top-level parent datatype
                        $dt_id = $datatype_id;
                        while ( isset($datatree_array['descendant_of'][$dt_id]) ) {
                            $dt_id = $datatree_array['descendant_of'][$dt_id];
                            if ($dt_id !== '')
                                $all_permissions[$dt_id]['child_edit'] = 1;
                        }
                    }
                    if ($result['can_add_record'] == 1) {
                        $all_permissions[$datatype_id]['add'] = 1;
                        $save = true; 
                    }
                    if ($result['can_delete_record'] == 1) {
                        $all_permissions[$datatype_id]['delete'] = 1;
                        $save = true; 
                    }
                    if ($result['can_design_type'] == 1) {
                        $all_permissions[$datatype_id]['design'] = 1;
                        $save = true; 
                    }
                    if ($result['is_type_admin'] == 1) {
                        $all_permissions[$datatype_id]['admin'] = 1;
                        $save = true; 
                    }

                    if (!$save)
                        unset( $all_permissions[$datatype_id] );
                }

                ksort( $all_permissions );

                if ($save_permissions) {
                    // Save and return the permissions array
                    $session->set('permissions', $all_permissions);
                }
/*
print '<pre>';
print_r($all_permissions);
print '</pre>';
*/
                return $all_permissions;
            }
            else {
                // Return the stored permissions array
                return $session->get('permissions');
            }
        }
        catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }


    /**
     * Builds an array of all datafield permissions possessed by the given user.
     * 
     * @param integer $user_id          The database id of the user to grab datafield permissions for.
     * @param Request $request
     * @param boolean $save_permissions If true, save the calling user's permissions in the user's session...if false, just return an array
     * 
     * @return array
     */
    protected function getDatafieldPermissionsArray($user_id, Request $request, $save_permissions = true)
    {
        try {
            $session = $request->getSession();

$save_permissions = false;

            if ( !$save_permissions || !$session->has('datafield_permissions') ) {
                // Permissions not set, need to build the array
                $datatype_permissions = self::getPermissionsArray($user_id, $request, false);

                $em = $this->getDoctrine()->getManager();
                $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');
                $repo_user_field_permissions = $em->getRepository('ODRAdminBundle:UserFieldPermissions');

                $user = $repo_user->find($user_id);
                $user_field_permissions = $repo_user_field_permissions->findBy( array('user_id' => $user_id) );

                $all_permissions = array();

                // Grab and store all datafield permissions for this user
                $all_datafields = array();
                foreach ($user_field_permissions as $permission) {
                    $datafield_id = $permission->getDataFields()->getId();
                    $all_datafields[$datafield_id] = 1;
                    if ($permission->getCanViewField() == 1)
                        $all_permissions[$datafield_id]['view'] = 1;
                    if ($permission->getCanEditField() == 1)
                        $all_permissions[$datafield_id]['edit'] = 1;
                }

                // check for any missing datafield permissions?
                $created_permission = false;
                $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
                $datafields = $repo_datafield->findAll();
                foreach ($datafields as $df) {
                    $datafield_id = $df->getId();

                    if ( !isset($all_datafields[$datafield_id]) ) {
                        $created_permission = true;
                        $datatype = $df->getDataType();
                        $user_field_permission = new UserFieldPermissions();
                        $user_field_permission->setUserId($user);
                        $user_field_permission->setDatatype($datatype);
                        $user_field_permission->setDataFields($df);
                        $user_field_permission->setCreatedBy($user);

                        $user_field_permission->setCanViewField('1');   // always able to view by default
                        $all_permissions[$datafield_id]['view'] = 1;

                        if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'edit' ]) ) {
                            $user_field_permission->setCanEditField('1');   // able to edit datafields by default if already have edit permission
                            $all_permissions[$datafield_id]['edit'] = 1;
                        }
                        else {
                            $user_field_permission->setCanEditField('0');
                        }

                        $em->persist($user_field_permission);
                    }
                }

                if ($created_permission)
                    $em->flush();

                if ($save_permissions) {
                    // Save and reeturn the permissions array
                    $session->set('datafield_permissions', $all_permissions);
                }

                return $all_permissions;
            }
            else {
                // Returned the stored datafield permissions array
                return $session->get('datafield_permissions');
            }
        }
        catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }


    /**
     * Utility function so other controllers can return 403 errors easily.
     * 
     * @param string $type
     * 
     * @return a Symfony JSON response
     */
    protected function permissionDeniedError($type = '')
    {
        $str = '';
        if ($type !== '')
            $str = "<h2>Permission Denied - You can't ".$type." this DataType!</h2>";
        else
            $str = "<h2>Permission Denied</h2>";

        $return = array();
        $return['r'] = 403;
        $return['t'] = 'html';
        $return['d'] = array(
            'html' => $str
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Utility function so other controllers can notify of deleted entities easily.
     * 
     * @param string $type
     * 
     * @return a Symfony JSON respose
     */
    protected function deletedEntityError($entity = '')
    {
        $str = '';
        if ($entity !== '')
            $str = "<h2>This ".$entity." has been deleted!</h2>";
//        else
//            $str = "<h2>Permission Denied</h2>";

        $return = array();
        $return['r'] = 1;
        $return['t'] = 'html';
        $return['d'] = array(
            'html' => $str
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Notifies beanstalk to schedule a rebuild of all cached versions of all DataRecords of this DataType.
     * Usually called after a changes is made via DisplayTemplate or SearchTemplate.
     * 
     * @param integer $datatype_id The database id of the DataType that needs to be rebuilt.
     * @param array $options       
     * 
     * @return TODO
     */
    public function updateDatatypeCache($datatype_id, $options = array())
    {

//print "call to update datatype cache\n";
//return;

        // ----------------------------------------
        // Grab necessary objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $pheanstalk = $this->get('pheanstalk');
        $router = $this->container->get('router');
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        $api_key = $this->container->getParameter('beanstalk_api_key');

        // Generate the url for cURL to use
        $url = $this->container->getParameter('site_baseurl');
//        if ( $this->container->getParameter('kernel.environment') === 'dev') { $url .= './app_dev.php'; }
        $url .= $router->generate('odr_recache_record');

        // Attempt to get the user
        $user = 'anon.';
        $token = $this->container->get('security.context')->getToken(); // token will be NULL when this function is called from the command line
        if ($token != NULL)
            $user = $token->getUser();
        if ($user === 'anon.')
            $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find(3);   // TODO - system user

        // ----------------------------------------
        // Get the top-most parent of the datatype scheduled for update
        $datatree_array = self::getDatatreeArray($em);
        while ( isset($datatree_array['descendant_of'][$datatype_id]) && $datatree_array['descendant_of'][$datatype_id] !== '' )
            $datatype_id = $datatree_array['descendant_of'][$datatype_id];


        // ----------------------------------------
        // Grab options
        $mark_as_updated = false;
        $force_shortresults_recache = false;
        $force_textresults_recache = false;
        if ( isset($options['mark_as_updated']) && $options['mark_as_updated'] == true )
            $mark_as_updated = true;
        if ( isset($options['force_shortresults_recache']) && $options['force_shortresults_recache'] == true )
            $force_shortresults_recache = true;
        if ( isset($options['force_textresults_recache']) && $options['force_textresults_recache'] == true )
            $force_textresults_recache = true;


        // ----------------------------------------
        // Mark this datatype as updated
        $current_time = new \DateTime();
        $datatype = $repo_datatype->find($datatype_id);
        if ($datatype == null)
            return self::deletedEntityError('DataType');

        $em->refresh($datatype);
//print 'refreshed datatype '.$datatype->getId().' ('.$datatype->getShortName().')'."\n";
        if ($mark_as_updated) {
            $datatype->setUpdated($current_time);
            $datatype->setUpdatedBy($user);
            $datatype->setRevision( $datatype->getRevision() + 1 );
            $em->persist($datatype);
            $em->flush();
            $em->refresh($datatype);
        }

        // TODO - invalidate XSD file somehow?

        // ----------------------------------------
        // Locate all datarecords of this datatype
        $query = $em->createQuery(
           'SELECT dr.id AS dr_id
            FROM ODRAdminBundle:DataRecord dr
            WHERE dr.dataType = :dataType AND dr.deletedAt IS NULL'
        )->setParameters( array('dataType' => $datatype->getId()) );
        $results = $query->getArrayResult();

        if ( count($results) > 0 ) {
            // ----------------------------------------
            // Get/create an entity to track the progress of this datatype recache
            $job_type = 'recache';
            $target_entity = 'datatype_'.$datatype_id;
            $additional_data = array('description' => 'Recache of DataType '.$datatype_id);
            $restrictions = $datatype->getRevision();
            $total = count($results);
            $reuse_existing = true;

            $tracked_job = self::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
            $tracked_job_id = $tracked_job->getId();

            // ----------------------------------------
            // Schedule all of these datarecords for an update
            foreach ($results as $num => $result) {
                $datarecord_id = $result['dr_id'];

                if ($force_shortresults_recache)
                    $memcached->delete($memcached_prefix.'.data_record_short_form_'.$datarecord_id);
                if ($force_textresults_recache)
                    $memcached->delete($memcached_prefix.'.data_record_short_text_form_'.$datarecord_id);

                // Insert the new job into the queue
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        "tracked_job_id" => $tracked_job_id,
                        "datarecord_id" => $datarecord_id,
                        "scheduled_at" => $current_time->format('Y-m-d H:i:s'),
                        "memcached_prefix" => $memcached_prefix,    // debug purposes only
                        "url" => $url,
                        "api_key" => $api_key,
                    )
                );

                $delay = 10;
                $pheanstalk->useTube('recache_type')->put($payload, $priority, $delay);
            }
        }

        // ----------------------------------------
        // Notify any datarecords linking to this datatype that they need to update too
        $query = $em->createQuery(
           'SELECT DISTINCT grandparent.id AS grandparent_id
            FROM ODRAdminBundle:DataRecord descendant
            LEFT JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = descendant
            LEFT JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
            LEFT JOIN ODRAdminBundle:DataRecord AS grandparent WITH ancestor.grandparent = grandparent
            WHERE descendant.dataType = :datatype
            AND descendant.deletedAt IS NULL AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( array('datatype' => $datatype->getId()) );
        $results = $query->getResult();
        foreach ($results as $num => $data) {
            $grandparent_id = $data['grandparent_id'];
            if ( $grandparent_id == null || trim($grandparent_id) == '' )
                continue;

            // Delete relevant memcached entries...
            $memcached->delete($memcached_prefix.'.data_record_long_form_'.$grandparent_id);
            $memcached->delete($memcached_prefix.'.data_record_long_form_public_'.$grandparent_id);

            // Insert the new job into the queue
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    "tracked_job_id" => -1,     // don't track job status for single datarecord recache
                    "datarecord_id" => $grandparent_id,
                    "scheduled_at" => $current_time->format('Y-m-d H:i:s'),
                    "memcached_prefix" => $memcached_prefix,    // debug purposes only
                    "url" => $url,
                    "api_key" => $api_key,
                )
            );

            $delay = 5;
            $pheanstalk->useTube('recache_record')->put($payload, $priority, $delay);
        }

    }

    /**
     * Notifies beanstalk to eventually schedule a rebuild of all cache entries of a specific DataRecord.
     * Usually called after one of the DataFields of the DataRecord have been updated with a new value/file/image.
     * 
     * @param integer $id    The database id of the DataRecord that needs to be recached.
     * @param array $options 
     * 
     * @return TODO
     */
    public function updateDatarecordCache($id, $options = array())
    {
//print 'call to updateDatarecordCache()';
//return;

        // Grab necessary objects
        $em = $this->getDoctrine()->getManager();
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');

        $pheanstalk = $this->get('pheanstalk');
        $router = $this->container->get('router');
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        $api_key = $this->container->getParameter('beanstalk_api_key');
        
        // Generate the url for cURL to use
        $url = $this->container->getParameter('site_baseurl');
        $url .= $router->generate('odr_recache_record');

        // Grab options
        $mark_as_updated = false;
        $force_shortresults_recache = false;
        $force_textresults_recache = false;
        if ( isset($options['mark_as_updated']) && $options['mark_as_updated'] == true )
            $mark_as_updated = true;
        if ( isset($options['force_shortresults_recache']) && $options['force_shortresults_recache'] == true )
            $force_shortresults_recache = true;
        if ( isset($options['force_textresults_recache']) && $options['force_textresults_recache'] == true )
            $force_textresults_recache = true;

        // Attempt to get the user
        $user = null;
        if ( isset($options['user_id']) ) {
            $user = $repo_user->find( $options['user_id'] );
        }
        else {
            $user = 'anon.';
            $token = $this->container->get('security.context')->getToken(); // token will be NULL when this function is called from the command line
            if ($token != NULL)
                $user = $token->getUser();
            if ($user === 'anon.')
                $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find(3);
        }

        // Mark this datarecord (and its grandparent, if different) as updated
        $current_time = new \DateTime();
        $datarecord = $repo_datarecord->find($id);

        // Don't try to update a deleted datarecord
        if ($datarecord == null)
            return;

        if ($mark_as_updated) {
            $datarecord->setUpdated($current_time);
            $datarecord->setUpdatedBy($user);
            $em->persist($datarecord);
        }

        if ($datarecord->getId() !== $datarecord->getGrandparent()->getId()) {
            $datarecord = $datarecord->getGrandparent();

            if ($mark_as_updated) {
                $datarecord->setUpdated($current_time);
                $datarecord->setUpdatedBy($user);
                $em->persist($datarecord);
            }
        }

        if ($mark_as_updated)
            $em->flush();

        // Delete the memcached entries so a recache is guaranteed...
        $datarecord_id = $datarecord->getId();
        if ($force_shortresults_recache)
            $memcached->delete($memcached_prefix.'.data_record_short_form_'.$datarecord_id);
        if ($force_textresults_recache)
            $memcached->delete($memcached_prefix.'.data_record_short_text_form_'.$datarecord_id);

        $memcached->delete($memcached_prefix.'.data_record_long_form_'.$datarecord_id);
        $memcached->delete($memcached_prefix.'.data_record_long_form_public_'.$datarecord_id);

        // Insert the new job into the queue
        $priority = 1024;   // should be roughly default priority
        $payload = json_encode(
            array(
                "tracked_job_id" => -1,     // don't track job status for single datarecord recache
                "datarecord_id" => $datarecord->getId(),
                "scheduled_at" => $current_time->format('Y-m-d H:i:s'),
                "memcached_prefix" => $memcached_prefix,    // debug purposes only
                "url" => $url,
                "api_key" => $api_key,
            )
        );

        $delay = 5;
        $pheanstalk->useTube('recache_record')->put($payload, $priority, $delay);


        // Notify any datarecords linking to this record that they need to update too
        $query = $em->createQuery(
           'SELECT grandparent.id AS grandparent_id
            FROM ODRAdminBundle:DataRecord descendant
            LEFT JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = descendant
            LEFT JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
            LEFT JOIN ODRAdminBundle:DataRecord AS grandparent WITH ancestor.grandparent = grandparent
            WHERE descendant = :datarecord
            AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( array('datarecord' => $datarecord->getId()) );
        $results = $query->getArrayResult();

        foreach ($results as $num => $data) {
            $grandparent_id = $data['grandparent_id'];
            if ($grandparent_id == null || trim($grandparent_id) == '')
                continue;

            // Delete relevant memcached entries...
            $memcached->delete($memcached_prefix.'.data_record_long_form_'.$grandparent_id);
            $memcached->delete($memcached_prefix.'.data_record_long_form_public_'.$grandparent_id);

            // Insert the new job into the queue
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    "tracked_job_id" => -1,     // don't track job status for single datarecord recache
                    "datarecord_id" => $grandparent_id,
                    "scheduled_at" => $current_time->format('Y-m-d H:i:s'),
                    "memcached_prefix" => $memcached_prefix,    // debug purposes only
                    "url" => $url,
                    "api_key" => $api_key,
                )
            );

            $delay = 5;
            $pheanstalk->useTube('recache_record')->put($payload, $priority, $delay);
        }

    }


    /**
     * Sorts DataRecords of a given DataType by the value contained in the DataField marked as the DataRecord's sorting field, and returns a comma-separated string of DataRecord ids in that order.
     * 
     * @param DataType $datatype The DataType that needs to have its DataRecords sorted
     * @param string $subset_str The subset of datarecord ids to return as a string
     * 
     * @return string
     */
    public function getSortedDatarecords($datatype, $subset_str = '')
    {
        // Get Entity Manager and setup objects
        $em = $this->getDoctrine()->getManager();
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        // Attempt to grab the list of datarecords for this datatype from the cache
        $datarecords = array();
        $datarecord_str = $memcached->get($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');

        // No caching in dev environment
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $datarecord_str = null;
//print 'loaded record_order: '.$datarecord_str."\n";


        if ($datarecord_str !== null && trim($datarecord_str) !== '') {
            // List exists in cache, load datarecords
            $datarecord_str = explode(',', trim($datarecord_str));

            foreach ($datarecord_str as $dr_id)
                $datarecords[$dr_id] = 1;
        }
        else {
            // Need to get the DataField used to sort the DataType
            $sortfield = $datatype->getSortField();
            if ($sortfield === null) {
                // ...no sort order defined, use database id order
                
                // Create a query to return the ids of all datarecords belonging to this datatype
                $query = $em->createQuery(
                   'SELECT dr.id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :datatype
                    AND dr.deletedAt IS NULL
                    ORDER BY dr.id'
                )->setParameters( array('datatype' => $datatype) );

                $results = $query->getResult();
//print_r($results);

                // Flatten the array
                $datarecords = array();
                foreach ($results as $id => $data)
                    $datarecords[ $data['id'] ] = 1;
            }
            else {
                $field_typename = $sortfield->getFieldType()->getTypeName();
                $field_typeclass = $sortfield->getFieldType()->getTypeClass();

                // Create a query to return a collection of datarecord ids, sorted by the sortfield of the datatype
                $query = $em->createQuery(
                    'SELECT dr.id, e.value
                     FROM ODRAdminBundle:DataRecord AS dr
                     JOIN ODRAdminBundle:'.$field_typeclass.' AS e WITH e.dataRecord = dr
                     WHERE dr.dataType = :datatype AND e.dataField = :datafield
                     AND dr.deletedAt IS NULL AND e.deletedAt IS NULL
                     ORDER BY e.value'
                )->setParameters( array('datatype' => $datatype, 'datafield' => $sortfield) );

                $results = $query->getResult();
//print_r($results);


                // Flatten the array
                $datarecords = array();
                foreach ($results as $num => $data) {
                    if ( isset($data['value']) ) {
                        if ($field_typename == "DateTime")
                            $datarecords[ $data['id'] ] = $data['value']->format('Y-m-d H:i:s');
                        else
                            $datarecords[ $data['id'] ] = $data['value'];
                    }
                    else  {
                        $datarecords[ $data['id'] ] = $data['id'];
                    }
                }
                asort($datarecords);
            }

            // Turn the sorted datarecords into a string to store in memcached
            $str = '';
//print_r($datarecords);
            foreach ($datarecords as $id => $key)
                $str .= $id.',';
            $datarecord_str = substr($str, 0, strlen($str)-1);
//print 'saving record_order: '.$datarecord_str."\n";

            $memcached->set($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order', $datarecord_str, 0);
        }

        // TODO - leave this as comma-separated list, or return an array instead?
        $sorted_datarecords = '';
        if ($subset_str !== '') {
            // The user only wants the sort order for a subset of the DataType's DataRecords...
            $subset = explode(',', $subset_str);

            foreach ($datarecords as $id => $key) {
                if ( in_array($id, $subset) )
                    $sorted_datarecords .= $id.',';
            }
        }
        else {
            // Just flatten the array...
            foreach ($datarecords as $id => $key)
                $sorted_datarecords .= $id.',';
        }

        $sorted_datarecords = substr($sorted_datarecords, 0, strlen($sorted_datarecords)-1);
//print 'sorted_datarecords: '.$sorted_datarecords."\n";

        return $sorted_datarecords;
    }

    /**
     * Gets or creates a TrackedJob entity in the database for use by background processes
     * 
     * @param EntityManager $em
     * @param User $user              The user to use if a new TrackedJob is to be created
     * @param string $job_type        A label used to indicate which type of job this is  e.g. 'recache', 'import', etc.
     * @param string $target_entity   Which entity this job is operating on
     * @param array $additional_data  Additional data related to the TrackedJob
     * @param string $restrictions    TODO - ...additional info/restrictions attached to the job
     * @param integer $total          ...how many pieces the job is broken up into?
     * @param boolean $reuse_existing TODO - multi-user concerns
     * 
     * @return TODO
     */
    protected function ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing = false)
    {
        $tracked_job = null;

        // TODO - more flexible way of doing this?
        if ($reuse_existing)
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => $job_type, 'target_entity' => $target_entity) );
        else
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => $job_type, 'target_entity' => $target_entity, 'completed' => null) );

        if ($tracked_job == null) {
            $tracked_job = new TrackedJob();
            $tracked_job->setJobType($job_type);
            $tracked_job->setTargetEntity($target_entity);
            $tracked_job->setCreatedBy($user);
        }
        else {
            $tracked_job->setCreated( new \DateTime() );
        }

        $tracked_job->setStarted(null);

        $tracked_job->setAdditionalData( json_encode($additional_data) );
        $tracked_job->setRestrictions($restrictions);

        $tracked_job->setCompleted(null);
        $tracked_job->setCurrent(0);                // TODO - possible desynch, though haven't spotted one yet
        $tracked_job->setTotal($total);
        $em->persist($tracked_job);
        $em->flush();

//        $tracked_job->resetCurrent($em);          // TODO - potential fix for possible desynch mentioned earlier
        $em->refresh($tracked_job);
        return $tracked_job;
    }


    /**
     * Gets an array of TrackedError entities for a specified TrackedJob
     *
     * @param EntityManager $em
     * @param integer $tracked_job_id
     *
     * @return TODO
     */
    protected function ODR_getTrackedErrorArray($em, $tracked_job_id)
    {
        $job_errors = array();

        $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);
        if ($tracked_job == null)
            return parent::deletedEntityError('TrackedJob');

        $tracked_errors = $em->getRepository('ODRAdminBundle:TrackedError')->findBy( array('trackedJob' => $tracked_job_id) );
        foreach ($tracked_errors as $error)
            $job_errors[ $error->getId() ] = array('error_level' => $error->getErrorLevel(), 'error_body' => json_decode( $error->getErrorBody(), true ));

        return $job_errors;
    }


    /**
     * Deletes all TrackedError entities associated with a specified TrackedJob
     *
     * @param EntityManager $em
     * @param integer $tracked_job_id
     *
     * @return TODO
     */
    protected function ODR_deleteTrackedErrorsByJob($em, $tracked_job_id)
    {
        // Because there could potentially be thousands of errors for this TrackedJob, do a mass DQL deletion 
        $query = $em->createQuery(
           'DELETE FROM ODRAdminBundle:TrackedError AS te
            WHERE te.trackedJob = :tracked_job'
        )->setParameters( array('tracked_job' => $tracked_job_id) );
        $rows = $query->execute();

        return $rows;
    }


    /**
     * Ensures a ThemeDataType entity exists for a given combination of a datatype and a theme.
     * 
     * @param User $user         The user to use if a new ThemeDataType is to be created
     * @param Datatype $datatype 
     * @param Theme $theme       
     * 
     * @return TODO
     */
    protected function ODR_checkThemeDataType($user, $datatype, $theme) {
        $em = $this->getDoctrine()->getManager();
        $theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType')->findOneBy( array('dataType' => $datatype->getId(), 'theme' => $theme->getId()) );

        // If the entity doesn't exist, create it
        if ($theme_datatype == null) {
            self::ODR_addThemeDatatypeEntry($em, $user, $datatype, $theme);
            $em->flush();
        }
    }


    /**
     * Ensures a ThemeDataField entity exists for a given combination of a datafield and a theme.
     * 
     * @param User $user            The user to use if a new ThemeDataField is to be created
     * @param DataFields $datafield 
     * @param Theme $theme          
     * 
     * @return TODO
     */
    protected function ODR_checkThemeDataField($user, $datafield, $theme) {
        $em = $this->getDoctrine()->getManager();
        $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield->getId(), 'theme' => $theme->getId()) );

        // If the entity doesn't exist, create it
        if ($theme_datafield == null) {
            self::ODR_addThemeDataFieldEntry($em, $user, $datafield, $theme);
            $em->flush();
        }
    }


    /**
     * Creates and persists a new DataRecordField entity.
     *
     * @param Manager $em            
     * @param User $user             The user requesting the creation of this entity
     * @param DataRecord $datarecord 
     * @param DataField $datafield   
     *
     * @return DataRecordField
     */
    protected function ODR_addDataRecordField($em, $user, $datarecord, $datafield)
    {
        // Initial create
        $datarecordfield = new DataRecordFields();
        $datarecordfield->setDataRecord($datarecord);
        $datarecordfield->setDataField($datafield);
        $datarecordfield->setCreatedBy($user);
        $datarecordfield->setUpdatedBy($user);

        $em->persist($datarecordfield);

        return $datarecordfield;
    }

    /**
     * Creates and persists a new DataRecord entity.
     *
     * @param Manager $em
     * @param User $user         The user requesting the creation of this entity
     * @param DataType $datatype 
     *
     * @return DataRecord
     */
    protected function ODR_addDataRecord($em, $user, $datatype)
    {
        // Initial create
        $datarecord = new DataRecord();

        $datarecord->setExternalId('');
        $datarecord->setNamefieldValue('');
        $datarecord->setSortfieldValue('');

        $datarecord->setDataType($datatype);
        $datarecord->setCreatedBy($user);
        $datarecord->setUpdatedBy($user);
        $datarecord->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public

        $em->persist($datarecord);
        $em->flush();
        $em->refresh($datarecord);

        // Create initial objects
        foreach($datatype->getDataFields() as $datafield) {
            // Create a datarecordfield entry for every datafield of the datatype
            $datarecordfield = self::ODR_addDataRecordField($em, $user, $datarecord, $datafield);
            // Need to flush/refresh so ODR_addStorageEntity() doesn't create a new drf entry
            $em->flush();
            $em->refresh($datarecordfield);

            // Create initial storage entity if necessary
            self::ODR_addStorageEntity($em, $user, $datarecord, $datafield);

            // Create radio_selection entities so searching works immediately
            $typename = $datafield->getFieldType()->getTypeName();
            if ($typename == 'Single Select' || $typename == 'Multiple Select' || $typename == 'Single Radio' || $typename == 'Multiple Radio') {
                // Need to create radio selection entities for the new datarecord...
                $radio_options = $em->getRepository('ODRAdminBundle:RadioOptions')->findBy( array('dataFields' => $datafield->getId()) );
                foreach ($radio_options as $radio_option) {
                    self::ODR_addRadioSelection($em, $user, $radio_option, $datarecordfield);   // use default of radio_option for selected status
                }
            }
        }

        // TODO - Need to flush because of creating new storage entity/radio selection objects?
        $em->flush();

        return $datarecord;
    }

    /**
     * Ensures a link exists from $ancestor_datarecord to $descendant_datarecord, undeleting an old link if possible.
     *
     * @param Manager $em
     * @param User $user                        The user requesting the creation of this link
     * @param DataRecord $ancestor_datarecord   The DataRecord which will be the 'ancestor' side of this link
     * @param DataRecord $descendant_datarecord The DataRecord which will be the 'descendant' side of this link
     *
     * @return LinkedDataTree
     */
    protected function ODR_linkDataRecords($em, $user, $ancestor_datarecord, $descendant_datarecord)
    {
        // Want to reuse old link entries if possible...temporarily disable the softdeleable filter to allow doctrine queries to return deleted entities
        $em->getFilters()->disable('softdeleteable');
        $query = $em->createQuery(
           'SELECT ldt
            FROM ODRAdminBundle:LinkedDataTree AS ldt
            WHERE ldt.ancestor = :ancestor AND ldt.descendant = :descendant'
        )->setParameters( array('ancestor' => $ancestor_datarecord, 'descendant' => $descendant_datarecord) );
        $results = $query->getResult();
        $em->getFilters()->enable('softdeleteable');

        // TODO - check for an already active link?

        $linked_datatree = null;
        if ( count($results) > 0 ) {
            // If an earlier deleted linked_datatree entry was found, undelete it to indicate the link is working again
            foreach ($results as $num => $ldt)
                $linked_datatree = $ldt;

            $linked_datatree->setDeletedAt(null);
        }
        else {
            // ...otherwise, create a new linked_datatree entry
            $linked_datatree = new LinkedDataTree();
            $linked_datatree->setCreatedBy($user);
            $linked_datatree->setMultipleRecordsPerParent(0);   // TODO: why is this still here

            $linked_datatree->setAncestor($ancestor_datarecord);
            $linked_datatree->setDescendant($descendant_datarecord);
        }

        $linked_datatree->setUpdatedBy($user);
        $em->persist($linked_datatree);
        $em->flush();

        // Refresh the cache entries for the datarecords
        $options = array();
        self::updateDatarecordCache($ancestor_datarecord->getId(), $options);
        self::updateDatarecordCache($descendant_datarecord->getId(), $options);

        return $linked_datatree;
    }


    /**
     * Creates a new storage entity (Short/Medium/Long Varchar, File, Radio, etc)
     * TODO - shouldn't $datarecordfield imply $datarecord and $datafield already?
     *
     * @param Manager $em
     * @param User $user                        The user requesting the creation of this entity
     * @param DataRecord $datarecord            
     * @param DataFields $datafield             
     *
     * @return mixed
     */
    protected function ODR_addStorageEntity($em, $user, $datarecord, $datafield)
    {
        $my_obj = null;

        $field_type = $datafield->getFieldType();
        $classname = "ODR\\AdminBundle\\Entity\\" . $field_type->getTypeClass();

        // Create initial entity if insert_on_create
        if ($field_type->getInsertOnCreate() == 1) {
            // Create Instance of field
            $my_obj = new $classname();
            $my_obj->setDataRecord($datarecord);
            $my_obj->setDataField($datafield);

            $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId()) );
            if ($drf == null) {
                $drf = self::ODR_addDataRecordField($em, $user, $datarecord, $datafield);
                $em->persist($drf);
            }
            $my_obj->setDataRecordFields($drf);

            $my_obj->setFieldType($field_type);
            if ($field_type->getTypeClass() == 'DatetimeValue') {
                $my_obj->setValue( new \DateTime('0000-00-00 00:00:00') );
            }
            else if ($field_type->getTypeClass() == 'DecimalValue') {
                $my_obj->setBase(0);
                $my_obj->setExponent(0);
                $my_obj->setOriginalValue('0');
                $my_obj->setValue(0);
            }
            else {
                $my_obj->setValue("");
            }
            $my_obj->setCreatedBy($user);
            $my_obj->setUpdatedBy($user);
            $em->persist($my_obj);

            // TODO - is this necessary?
            // Attach the new object to the associated datarecordfield entity
            self::saveToDataRecordField($em, $drf, $field_type->getTypeClass(), $my_obj);
        }

        return $my_obj;
    }


    /**
     * Creates a new RadioOption entity
     *
     * @param Manager $em
     * @param User $user            The user requesting the creation of this entity.
     * @param DataFields $datafield
     * @param string $option_name   An optional name to immediately assign to the RadioOption entity
     *
     * @return RadioOption
     */
    protected function ODR_addRadioOption($em, $user, $datafield, $option_name = "Option")
    {
        //
        $radio_option = new RadioOptions();
        $radio_option->setValue(0);
        $radio_option->setOptionName($option_name);
        $radio_option->setDisplayOrder(0);
        $radio_option->setIsDefault(false);
        $radio_option->setDataFields($datafield);
        $radio_option->setExternalId(0);
        $radio_option->setParent(null);
        $radio_option->setCreatedBy($user);
        $radio_option->setUpdatedBy($user);
        $em->persist($radio_option);

        return $radio_option;
    }


    /**
     * Creates a new RadioSelection entity
     *
     * @param Manager $em
     * @param User $user                  The user requesting the creation of this entity.
     * @param RadioOption $radio_option   The RadioOption entity receiving this RadioSelection
     * @param DataRecordFields $datafield 
     * @param integer $initial_value      If "auto", initial value is based on the default setting from RadioOption...otherwise 0 for unselected, or 1 for selected
     *
     * @return RadioSelection
     */
    protected function ODR_addRadioSelection($em, $user, $radio_option, $datarecordfield, $initial_value = "auto")
    {
        //
        $radio_selection = new RadioSelection();
        $radio_selection->setRadioOption($radio_option);
        $radio_selection->setDataRecordFields($datarecordfield);
        $radio_selection->setCreatedBy($user);
        $radio_selection->setUpdatedBy($user);

        if ($initial_value == "auto") {
            if ($radio_option->getIsDefault() == true)
                $radio_selection->setSelected(1);
            else
                $radio_selection->setSelected(0);
        }
        else {
            $radio_selection->setSelected($initial_value);
        }
        $em->persist($radio_selection);

        return $radio_selection;
    }


    /**
     * Creates and persists a new DataFields entity.
     *
     * @param Manager $em
     * @param User $user                 The user requesting the creation of this entity
     * @param DataType $datatype         
     * @param FieldType $fieldtype       
     * @param RenderPlugin $renderplugin The RenderPlugin for this new DataField to use...(almost?) always going to be the default RenderPlugin
     *
     * @return DataFields
     */
    protected function ODR_addDataFieldsEntry($em, $user, $datatype, $fieldtype, $renderplugin)
    {
        // Poplulate new DataFields form
        $datafield = new DataFields();
        $datafield->setFieldName('New Field');
        $datafield->setDescription('Field description.');
        $datafield->setMarkdownText('');
        $datafield->setCreatedBy($user);
        $datafield->setUpdatedBy($user);
        $datafield->setDataType($datatype);
        $datafield->setFieldType($fieldtype);
        $datafield->setIsUnique(false);
        $datafield->setRequired(false);
        $datafield->setSearchable(0);
        $datafield->setUserOnlySearch(false);
        $datafield->setRenderPlugin($renderplugin);
        $datafield->setDisplayOrder(-1);
        $datafield->setChildrenPerRow(1);
        $datafield->setRadioOptionNameSort(0);
        if ( $fieldtype->getTypeClass() === 'File' || $fieldtype->getTypeClass() === 'Image' ) {
            $datafield->setAllowMultipleUploads(1);
            $datafield->setShortenFilename(1);
        }
        else {
            $datafield->setAllowMultipleUploads(0);
            $datafield->setShortenFilename(0);
        }
        $em->persist($datafield);

        return $datafield;
    }


    /**
     * Creates and persists a new ThemeElement entity.
     *
     * @param Manager $em
     * @param User $user         The user requesting the creation of this entity
     * @param DataType $datatype 
     * @param Theme $theme       
     *
     * @return ThemeElement
     */
    protected function ODR_addThemeElementEntry($em, $user, $datatype, $theme)
    {
        $theme_element = new ThemeElement();
        $theme_element->setDataType($datatype);
        $theme_element->setCreatedBy($user);
        $theme_element->setUpdatedBy($user);
        $theme_element->setTemplateType('form');
        $theme_element->setElementType('div');
//        $theme_element->setXpos(0);
//        $theme_element->setYpos(0);
//        $theme_element->setZpos(0);
//        $theme_element->setWidth(800);
//        $theme_element->setHeight(300);
//        $theme_element->setFieldWidth(0);
//        $theme_element->setFieldHeight(0);
        $theme_element->setDisplayOrder(-1);
        $theme_element->setDisplayInResults(1);
        $theme_element->setCssWidthXL('1-1');
        $theme_element->setCssWidthMed('1-1');
        $theme_element->setTheme($theme);

        $em->persist($theme_element);
        return $theme_element;
    }


    /**
     * Creates and persists a new ThemeElementField entity.
     *
     * @param Manager $em
     * @param User $user                  The user requesting the creation of this entity.
     * @param DataType $datatype          
     * @param DataFields $datafield       
     * @param ThemeElement $theme_element 
     *
     * @return ThemeElementField
     */
    protected function ODR_addThemeElementFieldEntry($em, $user, $datatype, $datafield, $theme_element)
    {
        $theme_element_field = new ThemeElementField();
        $theme_element_field->setCreatedBy($user);
        $theme_element_field->setUpdatedBy($user);
        if ($datatype !== null)
            $theme_element_field->setDataType($datatype);
        if ($datafield !== null)
            $theme_element_field->setDataFields($datafield);
        $theme_element_field->setThemeElement($theme_element);
        $theme_element_field->setDisplayorder(999);

        $em->persist($theme_element_field);

        return $theme_element_field;
    }


    /**
     * Creates and persists a new ThemeDataField entity.
     *
     * @param Manager $em
     * @param User $user            The user requesting the creation of this entity.
     * @param DataFields $datafield 
     * @param Theme $theme          
     *
     * @return ThemeDataField
     */
    protected function ODR_addThemeDataFieldEntry($em, $user, $datafield, $theme)
    {
        // Create theme entry
        $theme_data_field = new ThemeDataField();
        $theme_data_field->setDataFields($datafield);
        $theme_data_field->setTheme($theme);
        $theme_data_field->setTemplateType('form');
//        $theme_data_field->setXpos('0');
//        $theme_data_field->setYpos('0');
//        $theme_data_field->setZpos('0');
//        $theme_data_field->setWidth('200');
        if ($theme->getId() != 1) {
            $theme_data_field->setActive(false);
//            $theme_data_field->setHeight('0');
        }
        else {
            $theme_data_field->setActive(true);
//            $theme_data_field->setHeight('50');
        }
//        $theme_data_field->setLabelWidth('70');
//        $theme_data_field->setFieldWidth('100');
//        $theme_data_field->setFieldHeight('32');
        $theme_data_field->setCSS('');
        $theme_data_field->setCssWidthXL('1-3');
        $theme_data_field->setCssWidthMed('1-3');

        $theme_data_field->setCreatedBy($user);
        $theme_data_field->setUpdatedBy($user);

        $em->persist($theme_data_field);
        return $theme_data_field;
    }


    /**
     * Creates and persists a new ThemeDataType entity.
     * 
     * @param Manager $em
     * @param User $user         The user requesting the creation of this entity
     * @param DataType $datatype 
     * @param Theme $theme       
     *
     * @return ThemeDataType
     */
    protected function ODR_addThemeDatatypeEntry($em, $user, $datatype, $theme)
    {
        // Create theme entry
        $theme_data_type = new ThemeDataType();
        $theme_data_type->setDataType($datatype);
        $theme_data_type->setTheme($theme);
        $theme_data_type->setTemplateType('form');
//        $theme_data_type->setXpos('0');
//        $theme_data_type->setYpos('0');
//        $theme_data_type->setZpos('0');
//        $theme_data_type->setWidth('600');
//        $theme_data_type->setHeight('300');
        $theme_data_type->setCSS('');

        $theme_data_type->setCreatedBy($user);
        $theme_data_type->setUpdatedBy($user);

        $em->persist($theme_data_type);
        return $theme_data_type;
    }


    /**
     * Usually called after an image is uploaded, this resizes the uploaded image for use in different areas.
     * Will automatically attempt to replace existing thumbnails if possible.
     * 
     * @param Image $my_obj The Image that was just uploaded.
     * @param User $user    The user requesting this action
     *
     * @return TODO
     */
    public function resizeImages(\ODR\AdminBundle\Entity\Image $my_obj, $user)
    {
        $em = $this->getDoctrine()->getManager();
        $repo_image = $em->getRepository('ODRAdminBundle:Image');
//        $user = $this->container->get('security.context')->getToken()->getUser();

        // Create Thumbnails
        $repo = $em->getRepository('ODRAdminBundle:ImageSizes');
        $sizes = $repo->findByDataFields($my_obj->getDataField());

        foreach($sizes as $size) {
            // Set original
            if($size->getOriginal()) {
                $my_obj->setImageSize($size);
                $em->persist($my_obj);
            }
            else {
                $proportional = false;
                if($size->getSizeConstraint() == "width" ||
                    $size->getSizeConstraint() == "height" ||
                    $size->getSizeConstraint() == "both") {
                        $proportional = true;
                }

                $filename = sha1(uniqid(mt_rand(), true));
                $ext = pathinfo($my_obj->getLocalFileName(), PATHINFO_EXTENSION);
                $new_file_path = "/tmp/" . $filename . "." . $ext;
                copy($my_obj->getLocalFileName(), $new_file_path);

                // resize file
                $resize = self::smart_resize_image(
                    $new_file_path,
                    $size->getWidth(),
                    $size->getHeight(),
                    $proportional,
                    'file',
                    false,
                    false
                );

                // Attempt to locate an already existing thumbnail for overwrite
                $image = $repo_image->findOneBy( array('parent' => $my_obj->getId(), 'imageSize' => $size->getId()) );

                // If thumbnail doesn't exist, create a new image entity 
                if ( $image == null ) {
                    $image = new Image();
                    $image->setDataField($my_obj->getDataField());
                    $image->setFieldType($my_obj->getFieldType());
                    $image->setDataRecord($my_obj->getDataRecord());
                    $image->setDataRecordFields($my_obj->getDataRecordFields());
                    $image->setOriginal(0);
                    $image->setDisplayOrder(0);
                    $image->setImageSize($size);
                    $image->setOriginalFileName($my_obj->getOriginalFileName());
                    $image->setCreatedBy($user);
                    $image->setUpdatedBy($user);
                    $image->setParent($my_obj);
                    $image->setExt($my_obj->getExt());
                    $image->setPublicDate(new \DateTime('1980-01-01 00:00:00'));
                    $image->setExternalId('');
                    $image->setOriginalChecksum('');

                    $em->persist($image);
                    $em->flush();
                }

                // Copy temp file to new file name
                $filename = $image->getUploadDir()."/Image_" . $image->getId() . "." . $ext;
                copy($new_file_path, $filename);
                $image->setLocalFileName($filename);

                $sizes = getimagesize($filename);
                $image->setImageWidth( $sizes[0] );
                $image->setImageHeight( $sizes[1] );
                $em->persist($image);
                $em->flush();

                // Encrypt thumbnail AFTER everything else is done
                self::encryptObject($image->getId(), 'image');

                // Set original checksum for thumbnail
                $file_path = self::decryptObject($image->getId(), 'image');
                $original_checksum = md5_file($file_path);
                $image->setOriginalChecksum($original_checksum);

                $em->persist($image);
                $em->flush();
            }
        }
    }


    /**
     * Utility function to return the column definition for use by the datatables plugin
     * 
     * @param Request $request
     * @param integer $datatype_id The database id of the Datatype to grab the TextResults field names for
     * 
     * @return TODO
     */
    public function getDatatablesColumnNames($datatype_id)
    {
        // First and second columns are always datarecord id and sort value, respectively
        $column_names  = '{"title":"datarecord_id","visible":false,"searchable":false},';
        $column_names .= '{"title":"datarecord_sortvalue","visible":false,"searchable":false},';
        $num_columns = 2;

        // Do a query to locate the names of all datafields that can be in the table
        $em = $this->getDoctrine()->getManager();
        $query = $em->createQuery(
           'SELECT df.fieldName AS field_name
            FROM ODRAdminBundle:DataFields AS df
            WHERE df.dataType = :datatype AND df.displayOrder > 0
            AND df.deletedAt IS NULL
            ORDER BY df.displayOrder'
        )->setParameters( array('datatype' => $datatype_id) );

        $results = $query->getArrayResult();
        foreach ($results as $num => $data) {
            $fieldname = $data['field_name'];
            $column_names .= '{"title":"'.$fieldname.'"},';
            $num_columns++;
        }

        return array('column_names' => $column_names, 'num_columns' => $num_columns);
    }


    /**
     * Re-renders and returns the TextResults version of a given Datarecord
     * 
     * @param Request $request
     * @param integer $datarecord_id The database id of the DataRecord...
     * @param string $template_name  unused right now
     * 
     * @return array
     */
    public function Text_GetDisplayData(Request $request, $datarecord_id, $template_name = 'default')
    {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $datarecord = $repo_datarecord->find($datarecord_id);
        $router = $this->get('router');

$debug = true;
$debug = false;
if ($debug)
    print '<pre>datarecord '.$datarecord_id.'...'."\n";

        // Store the values in the correct display_order
        $value_list = array();
        foreach ($datarecord->getDataRecordFields() as $drf) {
if ($debug)
    print '-- drf '.$drf->getId()."\n";

            $datafield = $drf->getDataField();
            $display_order = intval($datafield->getDisplayOrder());

            // Only care about this datafield if it's in TextResults
            if ($display_order > 0) {
                $value = null;

                $render_plugin = $datafield->getRenderPlugin();
                if ( $render_plugin->getId() == '1' ) {
                    // Grab the datafield's value directly from the db
                    $field_typeclass = $datafield->getFieldType()->getTypeClass();
                    if ($field_typeclass == 'Radio') {
                        foreach ($drf->getRadioSelection() as $rs) {
                            if ($rs->getSelected() == 1) {
                                $value = $rs->getRadioOption()->getOptionName();
                                break;
                            }
                        }
                    }
                    else if ($field_typeclass == 'DatetimeValue') {
//                        $date = $drf->getAssociatedEntity()->getValue();
//                        $value = $date->format('Y-m-d H:i:s');

                        $value = $drf->getAssociatedEntity()->getStringValue();
                    }
                    else if ($field_typeclass == 'Boolean') {
                        $value = $drf->getAssociatedEntity()->getValue();
                        if ($value == 1)
                            $value = 'YES';
                        else
                            $value = '';
                    }
                    else if ($field_typeclass == 'File') {
                        $collection = $drf->getAssociatedEntity();

                        $str = '';
                        if ( isset($collection[0]) ) {
                            // ...should only be one file in here anyways
                            $file = $collection[0];

                            $url = $router->generate('odr_file_download', array('file_id' => $file->getId()));
                            $str = '<a href='.$url.'>'.$file->getOriginalFileName().'</a>'; // textresultslist.html.twig will add other required attributes because of str_replace later in this function
                        }

                        $value = $str;
                    }
                    else {
                        $value = $drf->getAssociatedEntity()->getValue();
                    }

                }
                else {
                    // Get the Render Plugin to return a string value for the table
                    $plugin = $this->get($render_plugin->getPluginClassName());
                    $value = $plugin->execute($drf, $render_plugin, '', 'TextResults');
                }

//                $value = str_replace( array("'", '"', "\r", "\n"), array("\'", '\"', "", " "), $value);   // This doesn't appear to be required anymore...

if ($debug)
    print '-- -- storing value "'.$value.'" from datafield '.$datafield->getId().', display_order '.$display_order."\n";

//                $value_list[$display_order] = "\"".$value."\"";
                $value_list[$display_order] = $value;
            }
        }

        ksort($value_list);

        // Don't need to call twig just to do this one line...
//        $html = "[\"".$datarecord_id."\",\"".$datarecord->getSortfieldValue()."\",".implode(',', $value_list)."],";
        $html = array();
        $html[] = strval($datarecord_id);
        $html[] = strval($datarecord->getSortfieldValue());
        foreach ($value_list as $num => $val)
            $html[] = strval($val);

if ($debug) {
    print_r($html);
    print "\n</pre>";
}

        return $html;
    }


    /**
     * Re-renders and returns a given ShortResults version of a given DataRecord's html.
     * 
     * @param Request $request
     * @param integer $datarecord_id The database id of the DataRecord...
     * @param integer $theme_id      The database id of the Theme to use when rendering this DataRecord
     * @param string $template_name  unused
     * 
     * @return string
     */
    public function Short_GetDisplayData(Request $request, $datarecord_id, $theme_id = 2, $template_name = 'default') {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);

        // --------------------
        // Attempt to get the user
        $user = 'anon.';
        $token = $this->container->get('security.context')->getToken(); // token will be NULL when called from thh command line
        if ($token != NULL)
            $user = $token->getUser();

        // If this function is being called without a user, grab the 'system' user
        if ($user === 'anon.')
            $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find(3);   // TODO - need to verify whether i can actually search by name or not...
        // --------------------

        $theme_element = null;
        $datarecord = $repo_datarecord->find($datarecord_id);
        $datatype = $datarecord->getDataType();
        $datarecords = array($datarecord);

        $indent = 0;
        $is_link = 0;
        $top_level = 1;
        $short_form = true;
        $use_render_plugins = false;
        $public_only = true; // TODO - currently never show non-public info on ShortResults ever?

$debug = true;
$debug = false;
if ($debug)
    print '<pre>';

        // Construct the arrays which contain all the required data
        $datatype_tree = self::buildDatatypeTree($user, $theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);
        $datarecord_tree = array();
        foreach ($datarecords as $datarecord)
            $datarecord_tree[] = self::buildDatarecordTree($datarecord, $em, $user, $short_form, $use_render_plugins, $public_only, $debug, $indent);

if ($debug)
    print '</pre>';

        // Determine which template to use for rendering
        $template = 'ODRAdminBundle:ShortResults:shortresults_ajax.html.twig';

        // Render the DataRecord
        $templating = $this->get('templating');
        $html = $templating->render(
            $template,
            array(
                'datatype_tree' => $datatype_tree,
                'datarecord_tree' => $datarecord_tree,
            )
        );

        return $html;
    }


    /**
     * Renders the Results version of the datarecord.
     *
     * @param Request $request
     * @param integer $datarecord_id The database id of the DataRecord to render
     * @param string $template_name  If "public_only", do not render any non-public parts of the datarecord...
     *
     * @return string
     */
    protected function Long_GetDisplayData(Request $request, $datarecord_id, $template_name = 'default')
    {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);

        // --------------------
        // Attempt to get the user
        $public_only = false;
        $user = 'anon.';
        $token = $this->container->get('security.context')->getToken(); // token will be NULL when called from thh command line
        if ($token != NULL)
            $user = $token->getUser();

        // If this function is being called without a user, grab the 'system' user
        if ($user === 'anon.') {
            $public_only = true;
            $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find(3);   // TODO - need to verify whether i can actually search by name or not...
        }
        // --------------------

        $theme_element = null;
        $datarecord = $repo_datarecord->find($datarecord_id);
        $datatype = $datarecord->getDataType();
        $datarecords = array($datarecord);

        $indent = 0;
        $is_link = 0;
        $top_level = 1;
        $short_form = false;
        $use_render_plugins = true;

        if ($template_name == 'public_only')
            $public_only = true;
        else if ($template_name == 'force_render_all')
            $public_only = false;

$debug = true;
$debug = false;
if ($debug)
    print '<pre>';

$start = microtime(true);
if ($debug)
    print "\n>> starting timing...\n\n";

        // Construct the arrays which contain all the required data
        $datatype_tree = self::buildDatatypeTree($user, $theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);
if ($debug)
    print "\n>> datatype_tree done in: ".(microtime(true) - $start)."\n\n";

        $datarecord_tree = array();
        foreach ($datarecords as $datarecord) {
            $datarecord_tree[] = self::buildDatarecordTree($datarecord, $em, $user, $short_form, $use_render_plugins, $public_only, $debug, $indent);

if ($debug)
    print "\n>> datarecord_tree for datarecord ".$datarecord->getId()." done in: ".(microtime(true) - $start)."\n\n";
        
        }


if ($debug)
    print '</pre>';

        // Determine which template to use for rendering
        $template = 'ODRAdminBundle:Results:results_ajax.html.twig';

if ($debug) {
    if ($public_only == true)
        print 'public_only: true'."\n";
    else
        print 'public_only: false'."\n";
}

        // Render the DataRecord
        $templating = $this->get('templating');
        $html = $templating->render(
            $template,
            array(
                'datatype_tree' => $datatype_tree,
                'datarecord_tree' => $datarecord_tree,
                'theme' => $theme,
//                'user_permissions' => $user_permissions,
                'public_only' => $public_only,
            )
        );

        return $html;
    }


    /**
     * Renders the XMLExport version of the datarecord.
     *
     * @param Request $request
     * @param integer $datarecord_id The database id of the DataRecord to render...
     * @param string $template_name  unused right now...
     *
     * @return string
     */
    protected function XML_GetDisplayData(Request $request, $datarecord_id, $template_name = 'default')
    {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);

        // --------------------
        // Attempt to get the user
        $public_only = false;
        $user = 'anon.';
        $token = $this->container->get('security.context')->getToken(); // token will be NULL when called from thh command line
        if ($token != NULL)
            $user = $token->getUser();

        // If this function is being called without a user, grab the 'system' user
        if ($user === 'anon.') {
            $public_only = true;
            $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find(3);   // TODO - need to verify whether i can actually search by name or not...
        }
        // --------------------

        $theme_element = null;
        $datarecord = $repo_datarecord->find($datarecord_id);
        $datatype = $datarecord->getDataType();
        $datarecords = array($datarecord);

        $indent = 0;
        $is_link = 0;
        $top_level = 1;
        $short_form = false;
        $use_render_plugins = false;

        $using_metadata = true;
//        $using_metadata = false;

$debug = true;
$debug = false;
if ($debug)
    print '<pre>';

        // Construct the arrays which contain all the required data
        $datatype_tree = self::buildDatatypeTree($user, $theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);
        $datarecord_tree = array();
        foreach ($datarecords as $datarecord)
            $datarecord_tree[] = self::buildDatarecordTree($datarecord, $em, $user, $short_form, $use_render_plugins, $public_only, $debug, $indent);

if ($debug)
    print '</pre>';

        // Determine which template to use for rendering
        $baseurl = $this->container->getParameter('site_baseurl');
        $template = 'ODRAdminBundle:XMLExport:xml_ajax.html.twig';

        // Render the DataRecord
        $templating = $this->get('templating');
        $html = $templating->render(
            $template,
            array(
                'datatype_tree' => $datatype_tree,
                'datarecord_tree' => $datarecord_tree,
                'theme' => $theme,
                'using_metadata' => $using_metadata,
                'baseurl' => $baseurl,
            )
        );

        return $html;
    }


    /**
     * Does the actual work of resizing an image to some arbitrary dimension.
     * TODO - need source for this...pretty sure it's copy/pasted from somewhere
     *
     * @param string $file                Should be a path to the file
     * @param integer $width              Desired width for the resulting thumbnail
     * @param integer $height             Desired height for the resulting thumbnail
     * @param boolean $proportional       Whether to preserve aspect ratio while resizing
     * @param string $output              'browser', 'file', or 'return'
     * @param boolean $delete_original    Whether to delete the original file or not after resizing
     * @param boolean $use_linux_commands If true, use linux commands to delete the original file, otherwise use windows commands
     * 
     * @return array Contains height/width after resizing
     */
    public static function smart_resize_image(
                              $file,
                              $width              = 0,
                              $height             = 0,
                              $proportional       = false,
                              $output             = 'file',
                              $delete_original    = true,
                              $use_linux_commands = false ) {

        if ( $height <= 0 && $width <= 0 ) return false;
   
        # Setting defaults and meta
        $info                         = getimagesize($file);
        $image                        = '';
        $final_width                  = 0;
        $final_height                 = 0;

        list($width_old, $height_old) = $info;

        # Calculating proportionality
        if ($proportional) {
            if      ($width  == 0)  $factor = $height/$height_old;
            elseif  ($height == 0)  $factor = $width/$width_old;
            else                    $factor = min( $width / $width_old, $height / $height_old );

            $final_width  = round( $width_old * $factor );
            $final_height = round( $height_old * $factor );
        }
        else {
            $final_width = ( $width <= 0 ) ? $width_old : $width;
            $final_height = ( $height <= 0 ) ? $height_old : $height;
        }

        # Loading image to memory according to type
        switch ( $info[2] ) {
            case IMAGETYPE_GIF:   $image = imagecreatefromgif($file);   break;
            case IMAGETYPE_JPEG:  $image = imagecreatefromjpeg($file);  break;
            case IMAGETYPE_PNG:   $image = imagecreatefrompng($file);   break;
            case IMAGETYPE_WBMP:   $image = imagecreatefromwbmp($file);   break;
            default: return false;
        }

        # This is the resizing/resampling/transparency-preserving magic
        $image_resized = imagecreatetruecolor( $final_width, $final_height );
        if ( ($info[2] == IMAGETYPE_GIF) || ($info[2] == IMAGETYPE_PNG) ) {
            $transparency = imagecolortransparent($image);

            if ($transparency >= 0) {
                $transparent_color  = imagecolorsforindex($image, $trnprt_indx);
                $transparency       = imagecolorallocate($image_resized, $transparent_color['red'], $transparent_color['green'], $transparent_color['blue']);
                imagefill($image_resized, 0, 0, $transparency);
                imagecolortransparent($image_resized, $transparency);
            }
            elseif ($info[2] == IMAGETYPE_PNG) {
                imagealphablending($image_resized, false);
                $color = imagecolorallocatealpha($image_resized, 0, 0, 0, 127);
                imagefill($image_resized, 0, 0, $color);
                imagesavealpha($image_resized, true);
            }
        }
        imagecopyresampled($image_resized, $image, 0, 0, 0, 0, $final_width, $final_height, $width_old, $height_old);

        # Taking care of original, if needed
        if ( $delete_original ) {
            if ( $use_linux_commands ) exec('rm '.$file);
            else @unlink($file);
        }

        # Preparing a method of providing result
        switch ( strtolower($output) ) {
            case 'browser':
                $mime = image_type_to_mime_type($info[2]);
                header("Content-type: $mime");
                $output = NULL;
            break;
            case 'file':
                $output = $file;
            break;
            case 'return':
                return $image_resized;
            break;
            default:
            break;
        }

        # Writing image according to type to the output destination
        switch ( $info[2] ) {
            case IMAGETYPE_GIF:   imagegif($image_resized, $output );    break;
            case IMAGETYPE_JPEG:  imagejpeg($image_resized, $output, '90');   break;
            case IMAGETYPE_PNG:   imagepng($image_resized, $output, '2');    break;
            default: return false;
        }
   
        $stats = array($final_height, $final_width);
        return $stats;
    }


    /**
     * Ensures the given datarecord and all its child datarecords have datarecordfield entries for all datafields they contain.
     * 
     * @param DataType $datatype     TODO: this doesn't appear to be needed... 
     * @param DataRecord $datarecord 
     * 
     * @return none
     */
    public function verifyExistence($datatype, $datarecord)
    {
$start = microtime(true);
$debug = true;
$debug = false;

if ($debug)
    print '<pre>';

        // Verify the existence of all fields in this datatype/datarecord first
        self::verifyExistence_worker($datatype, $datarecord, $debug);

        // Next, get all children datarecords of the given datarecord
        $em = $this->getDoctrine()->getManager();
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
//        $childrecords = $repo_datarecord->findByGrandparent($datarecord);

        // Verify the existence of all fields in all the child datarecords
        $query = $em->createQuery(
            'SELECT dr
            FROM ODRAdminBundle:DataRecord dr
            JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
            WHERE dr.deletedAt IS NULL AND dt.deletedAt IS NULL
            AND dr.grandparent = :grandparent AND dr.id != :datarecord'
        )->setParameters( array('grandparent' => $datarecord->getId(), 'datarecord' => $datarecord->getId()) );
        $childrecords = $query->getResult();

        foreach ($childrecords as $childrecord) {
            $childtype = $childrecord->getDataType();
            self::verifyExistence_worker($childtype, $childrecord, $debug);
        }

        // Verify the existence of all fields in all linked datarecords
        $query = $em->createQuery(
           'SELECT descendant
            FROM ODRAdminBundle:DataRecord AS ancestor
            JOIN ODRAdminBundle:LinkedDataTree AS dt WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataRecord AS descendant WITH dt.descendant = descendant
            WHERE ancestor = :ancestor
            AND ancestor.deletedAt IS NULL AND dt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( array('ancestor' => $datarecord->getId()) );
        $linked_datarecords = $query->getResult();

        foreach ($linked_datarecords as $linked_datarecord) {
            $linked_datatype = $linked_datarecord->getDataType();
            self::verifyExistence_worker($linked_datatype, $linked_datarecord, $debug);
        }

if ($debug) {
    print 'verifyExistence() completed in '.(microtime(true) - $start)."\n";
    print '</pre>';
}

    }

    /**
     * Ensures the given datarecord has datarecordfield entries for all datafields it contains.
     * 
     * @param DataType $datatype     TODO: this doesn't appear to be used... 
     * @param DataRecord $datarecord 
     *
     * @return none
     */
    private function verifyExistence_worker($datatype, $datarecord, $debug)
    {
        // Track whether we need to flush
        $made_change = false;

        // Attempt to get the user
        $user = 'anon.';
        $token = $this->container->get('security.context')->getToken(); // token will be NULL when called from the command line
        if ($token != NULL)
            $user = $token->getUser();

        // If this function is being called without a user, grab the 'system' user
        if ($user === 'anon.')
            $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find(3);   // TODO - need to verify whether i can actually search by name or not...


        // Get Entity Manager and setup repo 
        $em = $this->getDoctrine()->getManager();

$start = microtime(true);
if ($debug)
    print "\n---------------\nattempting to verify datarecord ".$datarecord->getId()." of datatype ".$datatype->getId()."...\n";


        $datarecordfield_array = array();
        foreach ($datarecord->getDataRecordFields() as $datarecordfield) {
            $datafield = $datarecordfield->getDataField();
if ($debug)
    print "-- storing datafield: ".$datafield->getId()." drf: ".$datarecordfield->getId()."\n";
            array_push($datarecordfield_array, $datafield->getId());
        }

if ($debug)
    print "\ndatatype: ".$datatype->getId()."\n";
        // Create initial record fields
        $forms = array();
        foreach($datatype->getDataFields() as $datafield) {
if ($debug)
    print "-- datafield: ".$datafield->getId()."\n";

            if (!in_array($datafield->getId(), $datarecordfield_array) ) {
                // Don't create a datarecordfield entry for...
                if ($datafield->getFieldType()->getTypeName() == "Markdown") {
if ($debug)
    print "-- -- ignoring ".$datafield->getFieldType()->getTypeName()." field\n";
                }
                else {
if ($debug)
    print "-- -- creating new datarecordfield\n";
                    // Need to save changes
                    $made_change = true;

                    // Create a new DataRecordFields to link the datarecord and the datafield
                    $datarecordfield = self::ODR_addDataRecordField($em, $user, $datarecord, $datafield);

                    // Create initial storage entity if necessary
                    self::ODR_addStorageEntity($em, $user, $datarecord, $datafield);

                    $datarecord->addDataRecordField($datarecordfield);   // $datarecord gets the right objects, but the properties of one of them aren't set... 
                }
            }
        }

if ($debug)
    print "datarecord: ".$datarecord->getId()."\n";
        // All the datarecordfields exist, ensure object that would point to them exist as well
        foreach ($datarecord->getDataRecordFields() as $datarecordfield) {
            $datafield = $datarecordfield->getDataField();
            $type_class = $datafield->getFieldType()->getTypeClass();
if ($debug)
    print "-- looking for \"".$type_class."\" entity object of datarecordfield ".$datarecordfield->getId()."\n";

            $my_obj = $datarecordfield->getAssociatedEntity();

            if ($my_obj === NULL && $datafield->getFieldType()->getInsertOnCreate() == 1) {
                // Need to save changes
                $made_change = true;

                // Create Instance of field
                $classname = "ODR\\AdminBundle\\Entity\\".$type_class;
if ($debug)
    print "-- -- creating new \"".$classname."\" for datarecordfield\n";

                self::ODR_addStorageEntity($em, $user, $datarecordfield->getDataRecord(), $datafield);
            }

            // Need to ensure that entries in odr_image_sizes exist for images...
            if ($type_class == 'Image') {
                $image_sizes = $em->getRepository('ODRAdminBundle:ImageSizes')->findBy( array('dataFields' => $datafield->getId()) );
                if ( count($image_sizes) == 0 ) {
                    // Need to save changes
                    $made_change = true;

if ($debug)
    print "-- -- creating default image sizes\n";
                    // No image sizes exist for this datafield, create the two default ones
                    $size = new ImageSizes();
                    $size->setWidth(0);
                    $size->setHeight(0);
                    $size->setMinWidth(1024);
                    $size->setMinHeight(768);
                    $size->setMaxWidth(0);
                    $size->setMaxHeight(0);
                    $size->setFieldType( $datafield->getFieldType() );
                    $size->setCreatedBy($user);
                    $size->setUpdatedBy($user);
                    $size->setDataFields($datafield);
                    $size->setSizeConstraint('none');
                    $size->setOriginal(1);
                    $size->setImageType(null);
                    $em->persist($size);

                    $size = new ImageSizes();
                    $size->setWidth(500);
                    $size->setHeight(375);
                    $size->setMinWidth(500);
                    $size->setMinHeight(375);
                    $size->setMaxWidth(500);
                    $size->setMaxHeight(375);
                    $size->setFieldType( $datafield->getFieldType() );
                    $size->setCreatedBy($user);
                    $size->setUpdatedBy($user);
                    $size->setDataFields($datafield);
                    $size->setSizeConstraint('both');
                    $size->setOriginal(0);
                    $size->setImageType('thumbnail');
                    $em->persist($size);
                }
            }
        }

if ($debug)
    print "\ndatarecord ".$datarecord->getId()." of datatype ".$datatype->getId()." has been verified in ".(microtime(true) - $start)."\n";

        // Only flush if changes were made
        if ($made_change)
            $em->flush();
    }


    /**
     * Assigns a data entity (Boolean, File, etc) to a DataRecordFields entity.
     * TODO - does this actually do anything?
     * 
     * @param Manager $em
     * @param DataRecordFields $datarecordfields
     * @param string $type_class
     * @param mixed $my_obj
     * 
     * @return TODO
     */
    protected function saveToDataRecordField($em, $datarecordfields, $type_class, $my_obj) {
        switch ($type_class) {
            case 'Boolean':
                $datarecordfields->setBoolean($my_obj);
            break;
            case 'File':
                $datarecordfields->setFile($my_obj);
            break;
            case 'Image':
                $datarecordfields->setImage($my_obj);
            break;
            case 'DecimalValue':
                $datarecordfields->setDecimalValue($my_obj);
            break;
            case 'IntegerValue':
                $datarecordfields->setIntegerValue($my_obj);
            break;
            case 'LongText':
                $datarecordfields->setLongText($my_obj);
            break;
            case 'LongVarchar':
                $datarecordfields->setLongVarchar($my_obj);
            break;
            case 'MediumVarchar':
                $datarecordfields->setMediumVarchar($my_obj);
            break;
            case 'Radio':
//                $datarecordfields->setRadio($my_obj);
            break;
            case 'ShortVarchar':
                $datarecordfields->setShortVarchar($my_obj);
            break;
            case 'DatetimeValue':
                $datarecordfields->setDatetimeValue($my_obj);
            break;
        }

        $em->persist($datarecordfields);
        $em->persist($my_obj);
        $em->flush();
    }


    /**
     * Returns errors encounted while processing a Symfony Form object as a string.
     * 
     * @param Form $form 
     * 
     * @return TODO
     */
    protected function ODR_getErrorMessages(\Symfony\Component\Form\Form $form) {
/*
        $errors = array();
        if ($form->hasChildren()) {
            foreach ($form->getChildren() as $child) {
                if (!$child->isValid()) {
                    $errors[$child->getName()] = self::ODR_getErrorMessages($child);
                }
            }
        } else {
            foreach ($form->getErrors() as $key => $error) {
                $errors[] = $error->getMessage();
            }
        }

        return $errors;
*/
        return $form->getErrorsAsString();
    }


    /**
     * Gathers and returns an array of all layout information needed to render a DataType.
     *
     * @param DataType $datatype                 The datatype to build the tree from.
     * @param ThemeElement $target_theme_element If reloading a 'fieldarea' the theme_element to be reloaded, null otherwise.
     * @param Manager $em                        
     * @param boolean $is_link                   Whether $datatype is the descendent side of a linked datatype in this context.
     * @param boolean $top_level                 Whether $datatype is a top-level datatype or not.
     * @param boolean $short_form                If true, don't recurse...used for SearchTemplate, ShortResults, and TextResults.
     *
     * @param boolean $debug                     Whether to print debug information or not
     * @param integer $indent                    How "deep" in the tree this function is, effectively...used to print out tabs so debugging output looks nicer
     */
    protected function buildDatatypeTree($user, $theme, $datatype, $target_theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent)
    {

$start = microtime(true);
if ($debug) {
    print "\n";
    self::indent($indent);
    print ">> starting buildDatatypeTree (indent ".$indent.") timing...\n\n";
}

        $tree = array();

        $tree['datatype'] = $datatype;
        $tree['is_link'] = $is_link;
        $tree['top_level'] = $top_level;
        $tree['has_childtype'] = 0;
        $tree['fieldarea_reload'] = 0;

        // If just reloading a 'field_area'...
        if ($target_theme_element !== null) {
            $em->refresh($target_theme_element);
            $tree['fieldarea_reload'] = 1;
        }

        $em->refresh($theme);
        $em->refresh($datatype);

        // Ensure theme_datatype exists
        self::ODR_checkThemeDataType($user, $datatype, $theme);

if ($debug) {
    self::indent($indent);
    print 'refreshed datatype '.$datatype->getId().' ('.$datatype->getShortName().')'." in ".(microtime(true) - $start)."\n";
    self::indent($indent+1);
    print 'is_link: '.$is_link."\n";
    self::indent($indent+1);
    print 'top_level: '.$top_level."\n";
}

        // Grab theme_datatype
/*
        $query = $em->createQuery(
           'SELECT tdt
            FROM ODRAdminBundle:ThemeDataType tdt
            WHERE tdt.dataType = :datatype AND tdt.theme = :theme AND tdt.deletedAt IS NULL'
        )->setParameters( array('datatype' => $datatype->getId(), 'theme' => $theme->getId()) );
        $result = $query->getResult();
        $theme_datatype = $result[0];
*/
        $theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType')->findOneBy( array('dataType' => $datatype->getId(), 'theme' => $theme->getId()) );

        $em->refresh($theme_datatype);
        $tree['theme_datatype'] = $theme_datatype;
        $tree['theme_elements'] = array();

if ($debug) {
    self::indent($indent+1);
    print 'loaded theme_datatype '.$theme_datatype->getId()." in ".(microtime(true) - $start)."\n";
}

        // Grab theme_elements
        $theme_elements = array();
        foreach($datatype->getThemeElement() as $theme_element) {
            // if 'field_area' reload and this theme element isn't the one that we wanted to reload, skip
            if ( $target_theme_element !== null && $target_theme_element->getId() != $theme_element->getId() )
                continue;

            $em->refresh($theme_element);

            // Don't grab theme elements belonging to different themes
            if ( $theme_element->getTheme()->getId() != $theme->getId() )
                continue;

            $ted_child = array();
            $ted_child['theme_element'] = $theme_element;
            $ted_child['datafields'] = null;
            $ted_child['datatype'] = null;

if ($debug) {
    self::indent($indent+1);
    print 'loaded theme_element '.$theme_element->getId()." in ".(microtime(true) - $start)."\n";
}

            foreach ($theme_element->getThemeElementField() as $theme_element_field) {
                if ($theme_element_field->getDataFields() !== null) {
                    $datafield = $theme_element_field->getDataFields();
                    $em->refresh($datafield);
                    self::ODR_checkThemeDataField($user, $datafield, $theme);

                    if ($ted_child['datafields'] == null)
                        $ted_child['datafields'] = array();
/*
                    $query = $em->createQuery(
                       'SELECT tdf
                        FROM ODRAdminBundle:ThemeDataField tdf
                        WHERE tdf.dataFields = :datafield AND tdf.theme = :theme AND tdf.deletedAt IS NULL'
                    )->setParameters( array('datafield' => $datafield->getId(), 'theme' => $theme->getId()) );
                    $result = $query->getResult();
                    $theme_datafield = $result[0];
*/
                    $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield->getId(), 'theme' => $theme->getId()) );
                    $em->refresh($theme_datafield);

                    if ( $theme_datafield->getActive() == true ) {
                        $tmp = array();
                        $tmp['datafield'] = $datafield;
                        $tmp['theme_datafield'] = $theme_datafield;

if ($debug) {
    self::indent($indent+2);
    print 'loaded datafield '.$datafield->getId().' ('.$datafield->getFieldName().')'." in ".(microtime(true) - $start)."\n";
    self::indent($indent+2);
    print 'loaded theme_data_field '.$theme_datafield->getId()."\n";
}

                        $ted_child['datafields'][] = $tmp;
                    }
                }
                else if (!$short_form) {
                    // Only grab childtypes if not rendering SearchTemplate/ShortResults/TextResults
                    $childtype = $theme_element_field->getDataType();

                    $query = $em->createQuery(
                       'SELECT dt.is_link AS is_link
                        FROM ODRAdminBundle:DataTree dt
                        WHERE dt.ancestor = :ancestor AND dt.descendant = :descendant AND dt.deletedAt IS NULL'
                    )->setParameters( array('ancestor' => $datatype, 'descendant' => $childtype) );
                    $result = $query->getResult();

                    $tree['has_childtype'] = 1;
                    $top_level = 0;

                    $is_link = 0;
                    if ($result[0]['is_link'] == true)
                        $is_link = 1;

                    $ted_child['datatype'] = self::buildDatatypeTree($user, $theme, $childtype, null, $em, $is_link, $top_level, $short_form, $debug, $indent+2);
                }
            }

            $theme_elements[] = $ted_child;
        }

        $tree['theme_elements'] = $theme_elements;

if ($debug) {
    print "\n";
    self::indent($indent);
    print ">> ending buildDatatypeTree (indent ".$indent.") timing...\n\n";
}
        return $tree;
    }

    /**
     * Gathers and returns an array of all DataRecordField/DataFields/Form objects needed to render the data in a DataRecord.
     *
     * @param DataRecord $datarecord      The datarecord to build the tree from.
     * @param Manager $em                 
     * @param User $user                  The user to use for building forms.
     * @param boolean $short_form         If true, don't recurse...used for SearchTemplate, ShortResults, and TextResults.
     * @param boolean $use_render_plugins 
     * @param boolean $public_only        If true, don't render non-public items...if false, render everything
     *
     * @param boolean $debug              Whether to print debug information or not
     * @param integer $indent             How "deep" in the tree this function is, effectively...used to print out tabs so debugging output looks nicer
     */
    protected function buildDatarecordTree($datarecord, $em, $user, $short_form, $use_render_plugins, $public_only, $debug, $indent)
    {
$start = microtime(true);
if ($debug) {
    print "\n";
    self::indent($indent);
    print ">> starting buildDatarecordTree (indent ".$indent.") timing...\n\n";
}

        $tree = array();

        $tree['datarecord'] = $datarecord;
        $tree['datarecordfields'] = array();
        $tree['forms'] = array();
        $tree['child_datarecords'] = array();

if ($debug) {
    self::indent($indent);
    print 'building tree for datarecord '.$datarecord->getId()."\n";
}

        $override_child = false;
        $override_fields = false;
        $render_plugin = $datarecord->getDataType()->getRenderPlugin();
        if ($use_render_plugins && $render_plugin->getId() != '1') {
            if ($render_plugin->getOverrideChild() == '1')
                $override_child = true;
            if ($render_plugin->getOverrideFields() == '1')
                $override_fields = true;

if ($debug) {
    self::indent($indent);
    print '> override_child: '.$override_child."\n";
    self::indent($indent);
    print '> override_fields: '.$override_fields."\n";
}
        }

        // If this datarecord uses a render_plugin that doesn't override_child...
        // ...plugins that use override_child require an array as the first argument of execute()?
        if ($use_render_plugins && $render_plugin->getId() != '1' && !$override_child) {
            $plugin = $this->get($render_plugin->getPluginClassName());
            $html = $plugin->execute($datarecord, $render_plugin, $public_only);

            $tree['render_plugin_html'] = $html;
if ($debug) {
    self::indent($indent+1);
    print 'created and stored render plugin html for datarecord'." in ".(microtime(true) - $start)."\n";
}
        }

        // If a render plugin isn't overriding datafield display, grab all datarecordfield and build all form entities for this datarecord
        if (!$override_fields) {
            // Grab all datarecordfield entries for this datarecord
            foreach ($datarecord->getDataRecordFields() as $datarecordfield) {
                $datafield = $datarecordfield->getDataField();
                $datafield_id = $datafield->getId();

                if ($datafield->getFieldType()->getTypeName() !== "Markdown") {
                    $tree['datarecordfields'][$datafield_id] = $datarecordfield;
if ($debug) {
    self::indent($indent+1);
    print 'stored datarecordfield '.$datarecordfield->getId().' and associated form under datafield_id '.$datafield_id." in ".(microtime(true) - $start)."\n";
}

                    // Build the form object while we're here...
                    $tree['forms'][$datafield_id] = self::buildForm($em, $user, $datarecord, $datafield, $datarecordfield, $debug, $indent+1);
                }
            }
        }

        // Only grab child/linked datarecords if not rendering ShortResults or TextResults...
        if (!$short_form) {
            // Grab all child datarecords of this datarecord
            $query = $em->createQuery(
               'SELECT dr
                FROM ODRAdminBundle:DataRecord dr
                JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
                WHERE dr.parent = :datarecord AND dr.id != :datarecord_id
                AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $datarecord->getId(), 'datarecord_id' => $datarecord->getId()) );
            $results = $query->getResult();
            foreach ($results as $num => $child_datarecord) {
                $datatype = $child_datarecord->getDataType();
                $datatype_id = $datatype->getId();
                if ( !isset($tree['child_datarecords'][$datatype_id]) )
                    $tree['child_datarecords'][$datatype_id] = array();

if ($debug) {
    self::indent($indent+1);
    print 'storing child_datarecord '.$child_datarecord->getId().' under datatype '.$datatype_id."...\n";
}
                $tree['child_datarecords'][$datatype_id][] = self::buildDatarecordTree($child_datarecord, $em, $user, $short_form, $use_render_plugins, $public_only, $debug, $indent+2);
            }

            // Grab all datarecords that are linked to from this datarecord
            $query = $em->createQuery(
               'SELECT descendant
                FROM ODRAdminBundle:LinkedDataTree ldt
                JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                JOIN ODRAdminBundle:DataType AS dt WITH descendant.dataType = dt
                WHERE ldt.ancestor = :datarecord
                AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $datarecord->getId()) );
            $results = $query->getResult();
            foreach ($results as $num => $linked_datarecord) {
                $datatype = $linked_datarecord->getDataType();
                $datatype_id = $datatype->getId();

                if ( !isset($tree['child_datarecords'][$datatype_id]) )
                    $tree['child_datarecords'][$datatype_id] = array();

if ($debug) {
    self::indent($indent+1);
    print 'storing linked_datarecord '.$linked_datarecord->getId().' under datatype '.$datatype_id."...\n";
}

                $tree['child_datarecords'][$datatype_id][] = self::buildDatarecordTree($linked_datarecord, $em, $user, $short_form, $use_render_plugins, $public_only, $debug, $indent+2);
//                $tree['child_datarecords'][$datatype_id][] = self::buildDatarecordTree($linked_datarecord, $em, $user, $short_form, true, $public_only, $debug, $indent+2);     // render Results version for linked datarecords
            }

            // If using render plugins (just in general), check to see whether any of the child records use a render_plugin that overrides child datarecords (graph, comments, etc)...
            if ($use_render_plugins) {
                foreach ( $tree['child_datarecords'] as $datatype_id => $child_tree ) {
                    $child_datarecord = $child_tree[0]['datarecord'];
                    $render_plugin = $child_datarecord->getDataType()->getRenderPlugin();

                    if ($render_plugin->getId() != '1' && $render_plugin->getOverrideChild() > 0) {
                        // ...gather the child datarecords into an array and pass it to the plugin to render
                        $child_datarecords = array();
                        foreach ($child_tree as $num => $tmp)
                            $child_datarecords[] = $tmp['datarecord'];

                        $plugin = $this->get($render_plugin->getPluginClassName());
                        $html = $plugin->execute($child_datarecords, $render_plugin);

                        if ( !isset($tree['rendered_child_datarecords_html']) )
                            $tree['rendered_child_datarecords_html'] = array();

                        $tree['rendered_child_datarecords_html'][$datatype_id] = $html;
if ($debug) {
    self::indent($indent+1);
    print 'created and stored child_override render plugin html for all datarecord children of datatype '.$datatype->getId()."\n";
}
                    }
                }
            }
        }

if ($debug) {
    print "\n";
    self::indent($indent);
    print ">> ending buildDatarecordTree (indent ".$indent.") timing...\n\n";
}

        return $tree;
    }


    /**
     * Helper function for debugging buildDatatypeTree() and buildDatarecordTree()
     *
     * @param integer $indent
     */
    private function indent($indent)
    {
        for ($i = 0; $i < $indent; $i++)
            print '-- ';
    }


    /**
     * Generates the required Form objects for renders of Record (and results? might be able to speed up results rendering if not...)
     *
     * @param Manager $em                      
     * @param User $user                       The user to use when rendering this form object
     * @param DataRecord $datarecord           
     * @param DataField $datafield             
     * @param DataRecordField $datarecordfield 
     *
     * @param boolean $debug                   Whether to print out debug info or not
     *
     * @return TODO
     */
    protected function buildForm($em, $user, $datarecord, $datafield, $datarecordfield, $debug, $indent)
    {

        $type_class = $datarecordfield->getDataField()->getFieldType()->getTypeClass();
        $obj_classname = "ODR\\AdminBundle\\Entity\\".$type_class;
        $form_classname = "\\ODR\\AdminBundle\\Form\\".$type_class.'Form';

if ($debug) {
    self::indent($indent+1);
    print "attempting to load a \"".$type_class."\" from datarecordfield...\n";
}

        $my_obj = $datarecordfield->getAssociatedEntity();

        // Refresh the objects retrieved from the DataRecordField
        $form_obj = null;
        switch ($type_class) {
            case 'File':
            case 'Image':
            case 'Radio':
                // Files and Images return a collection...
                foreach ($my_obj as $obj) {
                    $em->refresh($obj);
if ($debug) {
    self::indent($indent+2);
    print "\"".$type_class."\" ".$obj->getId()." refreshed\n";
}
            }
                break;
            default:
                // Everything else returns a single object...
                $em->refresh($my_obj);
if ($debug) {
    self::indent($indent+2);
    print "\"".$type_class."\" ".$my_obj->getId()." refreshed\n";
}
                $form_obj = $my_obj;
                break;
        }

        // Files and Images just need a default form object
        if ($type_class == 'File' || $type_class == 'Image' || $type_class == 'Radio') {
            $form_obj = new $obj_classname();
            $form_obj->setDataField($datafield);
            $form_obj->setFieldType($datafield->getFieldType());
            $form_obj->setDataRecord($datarecord);
            $form_obj->setDataRecordFields($datarecordfield);
            $form_obj->setCreatedBy($user);
            $form_obj->setUpdatedBy($user);
            switch($type_class) {
                case 'File':
                    $form_obj->setGraphable('0');
                    break;
                case 'Image':
                    $form_obj->setOriginal('0');
                    break;
            }
        }

        $form = $this->createForm( new $form_classname($em), $form_obj );
        return $form->createView();
    }

}
