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

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileChecksum;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageChecksum;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementField;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\TrackedError;
use ODR\AdminBundle\Entity\UserPermissions;
use ODR\AdminBundle\Entity\UserFieldPermissions;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;


class ODRCustomController extends Controller
{

    /**
     * Utility function that renders a list of datarecords inside a wrapper template (shortresutlslist.html.twig or textresultslist.html.twig).
     * This is to allow various functions to only worry about what needs to be rendered, instead of having to do it all themselves.
     *
     * @param array $datarecord_list The unfiltered list of datarecord ids that need rendered...this should contain EVERYTHING
     * @param DataType $datatype     Which datatype the datarecords belong to
     * @param Theme $theme           Which theme to use for rendering this datatype
     * @param User $user             Which user is requesting this list
     *
     * @param string $target         "Results" or "Record"...where to redirect when a datarecord from this list is selected
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     *
     * @param Request $request
     *
     * @return string
     */
    public function renderList($datarecords, $datatype, $theme, $user, $path_str, $target, $search_key, $offset, Request $request)
    {
        // -----------------------------------
        // Grab necessary objects
        $templating = $this->get('templating');
        $session = $this->get('session');

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

        $logged_in = false;
        $datatype_permissions = array();
        $datafield_permissions = array();
        if ($user !== 'anon.') {
            $logged_in = true;
            $datatype_permissions = self::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = self::getDatafieldPermissionsArray($user->getId(), $request);
        }

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
                /** @var DataRecord $datarecord */
                $datarecord = $repo_datarecord->find($scroll_target);
                if ( $datarecord == null || $datarecord->getDataType()->getId() != $datatype->getId() || !in_array($scroll_target, $datarecords) )
                    $scroll_target = '';

                // Null out the scroll target
                $session->set('scroll_target', '');     // WTF WHY
            }
        }


        // -----------------------------------
        $final_html = '';
        if ( $theme->getThemeType() == 'search_results' ) {
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
            // Build the html required for the pagination header
            $pagination_html = self::buildPaginationHeader($total_datarecords, $offset, $path_str, $request);


            // ----------------------------------------
            // Load datatype and datarecord data from the cache
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Always bypass cache if in dev mode?
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;


            // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
            $datatype_array = $memcached->get($memcached_prefix.'.cached_datatype_'.$datatype->getId());
            if ($bypass_cache || $datatype_array == false)
                $datatype_array = self::getDatatypeData($em, self::getDatatreeArray($em, $bypass_cache), $datatype->getId(), $bypass_cache);

            // Grab the cached versions of all of the associated datarecords, and store them all at the same level in a single array
            $datarecord_array = array();
            foreach ($datarecord_list as $num => $dr_id) {
                $datarecord_data = $memcached->get($memcached_prefix.'.cached_datarecord_'.$dr_id);
                if ($bypass_cache || $datarecord_data == false)
                    $datarecord_data = self::getDatarecordData($em, $dr_id, true, $bypass_cache);

                foreach ($datarecord_data as $dr_id => $data)
                    $datarecord_array[$dr_id] = $data;
            }

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            self::filterByUserPermissions($datatype->getId(), $datatype_array, $datarecord_array, $datatype_permissions, $datafield_permissions);


            // -----------------------------------
            // Finally, render the list
            $template = 'ODRAdminBundle:ShortResults:shortresultslist.html.twig';
            $final_html = $templating->render(
                $template,
                array(
                    'datatype_array' => $datatype_array,
                    'datarecord_array' => $datarecord_array,
                    'theme_id' => $theme->getId(),

                    'initial_datatype_id' => $datatype->getId(),

                    'count' => $total_datarecords,
                    'scroll_target' => $scroll_target,
                    'user' => $user,
                    'user_permissions' => $datatype_permissions,
                    'odr_tab_id' => $odr_tab_id,

                    'logged_in' => $logged_in,

                    'pagination_html' => $pagination_html,

                    // required for load_datarecord_js.html.twig
                    'target' => $target,
                    'search_key' => $search_key,
                    'offset' => $offset,
                )
            );

        }
        else if ( $theme->getThemeType() == 'table' ) {
            // -----------------------------------
            // Grab the...
            $column_data = self::getDatatablesColumnNames($em, $theme);
            $column_names = $column_data['column_names'];
            $num_columns = $column_data['num_columns'];
/*
print '<pre>';
print_r($column_data);
print '</pre>';
exit();
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
                    'user_permissions' => $datatype_permissions,

                    'logged_in' => $logged_in,

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
     * @deprecated
     *
     * @param array $datarecord_list The list of datarecord ids that need rendered
     * @param DataType $datatype     Which datatype the datarecords belong to
     * @param Theme $theme           ...TODO - eventually need to use this to indicate which version to use when rendering
     * @param Request $request
     *
     * @return string
     */
    public function renderShortResultsList($datarecord_list, $datatype, $theme, Request $request)
    {
/*
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
*/
    }


    /**
     * Attempt to load the textresult version of the cached entries for each datarecord in $datarecord_list.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datarecord_list           The list of datarecord ids that need rendered
     * @param Theme $theme                     The 'table' theme for the relevant datatype
     * @param Request $request
     *
     * @throws \Exception
     *
     * @return array
     */
    public function renderTextResultsList($em, $datarecord_list, $theme, Request $request)
    {
        try {
            // --------------------
            // Store whether the user has view privileges for this datatype
            $datatype = $theme->getDataType();

            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $datafield_permissions = array();

            $can_view_datatype = false;
            if ($user !== 'anon.') {
                $datatype_permissions = self::getPermissionsArray($user->getId(), $request);
                $datafield_permissions = self::getDatafieldPermissionsArray($user->getId(), $request);

                // Check if user has permissions to download files
                if (isset($datatype_permissions[$datatype->getId()]) && isset($datatype_permissions[$datatype->getId()]['view']))
                    $can_view_datatype = true;
            }
            // --------------------


            // Grab necessary objects
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Always bypass cache if in dev mode?
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;


            // ----------------------------------------
            // Attempt to load from memcached...
//        $datatype_revision = intval( $datatype->getRevision() );      // TODO?
            $rows = array();
            foreach ($datarecord_list as $num => $datarecord_id) {
                // Get the table version for this datarecord from memcached if possible
                $data = $memcached->get($memcached_prefix.'.datarecord_table_data_'.$datarecord_id);
                if ($bypass_cache || $data == false)
                    $data = self::Text_GetDisplayData($em, $datarecord_id, $request);

                $row = array();
                // Only add this datarecord to the list if the user is allowed to see it...
                if ($can_view_datatype || $data['publicDate']->format('Y-m-d H:i:s') !== '2200-01-01 00:00:00') {
                    // Don't save values from datafields the user isn't allowed to see...
                    $dr_data = array();
                    foreach ($data[$theme->getId()] as $display_order => $df_data) {
                        $df_id = $df_data['id'];
                        $df_value = $df_data['value'];

                        if (isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['view']))
                            $dr_data[] = $df_value;
                    }

                    // If the user isn't prevented from seeing all datafields comprising this layout, store the data in an array
                    if (count($dr_data) > 0) {
                        $row[] = strval($datarecord_id);
                        $row[] = strval($data['default_sort_value']);

                        foreach ($dr_data as $tmp)
                            $row[] = strval($tmp);
                    }

                }

                // If something exists in the array, append it to the list
                if (count($row) > 0)
                    $rows[] = $row;
            }

            return $rows;
        }
        catch (\Exception $e) {
            throw new \Exception( $e->getMessage() );
        }
    }


    /**
     * Returns true if the datafield is currently in use by ShortResults
     * TODO - this will need to be changed when multiple ShortResults themes become available
     *
     * @param DataFields $datafield
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
     * Determines values for the pagination header
     *
     * @param integer $num_datarecords The total number of datarecords belonging to the datatype/search result 
     * @param integer $offset          Which page of results the user is currently on
     * @param string $path_str         The base url used before paging gets involved...$path_str + '/2' would redirect to page 2, $path_str + '/3' would redirect to page 3, etc
     * @param Request $request
     *
     * @return array
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
     * @param integer $datatype_id
     * @param string $search_key
     * @param boolean $logged_in Whether the user is logged in or not
     * @param Request $request
     *
     * @return array
     */
    public function getSavedSearch($datatype_id, $search_key, $logged_in, Request $request)
    {
        // Get necessary objects
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
        $search_checksum = md5($search_key);

        // Going to need the search controller if a cached search doesn't exist
        $search_controller = $this->get('odr_search_controller', $request);
        $search_controller->setContainer($this->container);

        $str = 'logged_in';
        if (!$logged_in)
            $str = 'not_logged_in';

        // Attempt to load the search result for this search_key
        $data = array();
        $cached_searches = $memcached->get($memcached_prefix.'.cached_search_results');
        if ( $cached_searches == false
            || !isset($cached_searches[$datatype_id])
            || !isset($cached_searches[$datatype_id][$search_checksum])
            || !isset($cached_searches[$datatype_id][$search_checksum][$str]) ) {

            // Saved search doesn't exist, redo the search and reload the results
            $ret = $search_controller->performSearch($search_key, $request);
            if ($ret !== true) {
                $data['error'] = true;
                $data['message'] = $ret['message'];
                $data['encoded_search_key'] = $ret['encoded_search_key'];
                $data['complete_datarecord_list'] = '';
                $data['datarecord_list'] = '';

                return $data;
            }

            $cached_searches = $memcached->get($memcached_prefix.'.cached_search_results');
        }

        // Now that the search result is guaranteed to exist, grab it
        $search_params = $cached_searches[$datatype_id][$search_checksum];

        // Pull the individual pieces of info out of the search results
        $data['error'] = false;
        $data['search_checksum'] = $search_checksum;
        $data['datatype_id'] = $datatype_id;
        $data['logged_in'] = $logged_in;

        $data['searched_datafields'] = $search_params['searched_datafields'];
        $data['encoded_search_key'] = $search_params['encoded_search_key'];

        $data['datarecord_list'] = $search_params[$str]['datarecord_list'];                     // ...just top-level datarecords
        $data['complete_datarecord_list'] = $search_params[$str]['complete_datarecord_list'];   // ...top-level, child, and linked datarecords

        return $data;
    }


    /**
     * Determines values for the search header (next/prev/return to search results) of Results/Records when searching
     *
     * @param string $datarecord_list A comma-separated list of datarecord ids that satisfy the search TODO - why comma-separated?  just going to explode() them...
     * @param integer $datarecord_id  The database id of the datarecord the user is currently at...used to determine where the next/prev datarecord buttons redirect to
     * @param Request $request
     *
     * @return array
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

                    if ( $num == count($search_results)-1 )
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

        return array(
            'page_length' => $page_length,
            'next_datarecord' => $next_datarecord,
            'prev_datarecord' => $prev_datarecord,
            'search_result_current' => $search_result_current,
            'search_result_count' => $search_result_count
        );
    }


    /**
     * Utility function that does the work of encrypting a given File/Image entity.
     *
     * @throws \Exception
     *
     * @param integer $object_id The id of the File/Image to encrypt
     * @param string $object_type "File" or "Image"
     *
     */
    protected function encryptObject($object_id, $object_type)
    {
        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
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
                /** @var File $base_obj */
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
                /** @var Image $base_obj */
                $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
                $image_upload_path = dirname(__FILE__).'/../../../../web/uploads/images/';
                $imagename = 'Image_'.$object_id.'.'.$base_obj->getExt();

                if ( !file_exists($image_upload_path.$imagename) )
                    throw new \Exception("Image does not exist");
        
                // crypto bundle requires an absolute path to the file to encrypt/decrypt
                $absolute_path = realpath($image_upload_path.$imagename);
            }
            /** @var File|Image $base_obj */

            // Generate a random number for encryption purposes
            $bytes = $generator->nextBytes(16); // 128-bit random number
//print 'bytes ('.gettype($bytes).'): '.$bytes."\n";

            // Convert the binary key into a hex string for db storage
            $hexEncoded_num = bin2hex($bytes);

            // Save the encryption key 
            $base_obj->setEncryptKey($hexEncoded_num);
            $em->persist($base_obj);

            // TODO - delete the directory with the encrypted chunks prior to encryptFile()?  the crypto bundle still works properly (in linux at least), but the error log does mention "directory already exists"

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
                $obj = null;
                if ($object_type == 'file')
                    $obj = $repo_filechecksum->findOneBy( array('File' => $object_id, 'chunk_id' => $chunk_id) );
                else if ($object_type == 'image')
                    $obj = $repo_imagechecksum->findOneBy( array('Image' => $object_id, 'chunk_id' => $chunk_id) );
                /** @var FileChecksum|ImageChecksum $obj */

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
     * Note that the filename of the decrypted file/image is determined solely by $object_id and $object_type because of constraints in the $crypto->decryptFile() function
     * 
     * @param integer $object_id  The id of the File/Image to decrypt
     * @param string $object_type "File" or "Image"
     * 
     * @return string The absolute path to the newly decrypted file/image
     */
    protected function decryptObject($object_id, $object_type)
    {
        // Grab necessary objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $crypto = $this->get("dterranova_crypto.crypto_adapter");

        // TODO: auto-check the checksum?
//        $repo_filechecksum = $em->getRepository('ODRAdminBundle:FileChecksum');
//        $repo_imagechecksum = $em->getRepository('ODRAdminBundle:ImageChecksum');


        $absolute_path = '';
        $base_obj = null;
        $object_type = strtolower($object_type);
        if ($object_type == 'file') {
            // Grab the file and associated information
            /** @var File $base_obj */
            $base_obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
            $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
            $filename = 'File_'.$object_id.'.'.$base_obj->getExt();

            // crypto bundle requires an absolute path to the file to encrypt/decrypt
            $absolute_path = realpath($file_upload_path).'/'.$filename;
        }
        else if ($object_type == 'image') {
            // Grab the image and associated information
            /** @var Image $base_obj */
            $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            $image_upload_path = dirname(__FILE__).'/../../../../web/uploads/images/';
            $imagename = 'Image_'.$object_id.'.'.$base_obj->getExt();

            // crypto bundle requires an absolute path to the file to encrypt/decrypt
            $absolute_path = realpath($image_upload_path).'/'.$imagename;
        }
        /** @var File|Image $base_obj */

        // Apparently files/images can decrypt to a zero length file sometimes...check for and deal with this
        if ( file_exists($absolute_path) && filesize($absolute_path) == 0 )
            unlink($absolute_path);

        // Since errors apparently don't cascade from the CryptoBundle through to here...
        if ( !file_exists($absolute_path) ) {
            // Grab the hex string representation that the file was encrypted with
            $key = $base_obj->getEncryptKey();
            // Convert the hex string representation to binary...php had a function to go bin->hex, but didn't have a function for hex->bin for at least 7 years?!?
            $key = pack("H*", $key);   // don't have hex2bin() in current version of php...this appears to work based on the "if it decrypts to something intelligible, you did it right" theory

            // Decrypt the file (do NOT delete the encrypted version)
            $crypto->decryptFile($absolute_path, $key, false);
        }

        return $absolute_path;
    }


    /**
     * Determines and returns an array of top-level datatype ids
     * 
     * @return int[]
     */
    public function getTopLevelDatatypes()
    {
        /** @var \Doctrine\ORM\EntityManager $em */
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

        $query = $em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
            FROM ODRAdminBundle:DataTree AS dt
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE dtm.is_link = 0
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $parent_of = array();
        foreach ($results as $num => $result)
            $parent_of[ $result['descendant_id'] ] = $result['ancestor_id'];

        $top_level_datatypes = array();
        foreach ($all_datatypes as $datatype_id) {
            if ( !isset($parent_of[$datatype_id]) )
                $top_level_datatypes[] = $datatype_id;
        }

        return $top_level_datatypes;
    }


    /**
     * Utility function to returns the DataTree table in array format
     *
     * @param \Doctrine\ORM\EntityManager $em
     *
     * @return array
     */
    public function getDatatreeArray($em, $force_rebuild = false)
    {
        // Attempt to load from cache first
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        $datatree_array = $memcached->get($memcached_prefix.'.cached_datatree_array');
        if ( !($force_rebuild || $datatree_array == false) )
            return $datatree_array;

        // 
        $query = $em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id, dtm.is_link AS is_link, dtm.multiple_allowed AS multiple_allowed
            FROM ODRAdminBundle:DataType AS ancestor
            JOIN ODRAdminBundle:DataTree AS dt WITH ancestor = dt.ancestor
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE ancestor.deletedAt IS NULL AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND descendant.deletedAt IS NULL');
        $results = $query->getArrayResult();

        $datatree_array = array(
            'descendant_of' => array(),
            'linked_from' => array(),
            'multiple_allowed' => array(),
        );
        foreach ($results as $num => $result) {
            $ancestor_id = $result['ancestor_id'];
            $descendant_id = $result['descendant_id'];
            $is_link = $result['is_link'];
            $multiple_allowed = $result['multiple_allowed'];

            if ( !isset($datatree_array['descendant_of'][$ancestor_id]) )
                $datatree_array['descendant_of'][$ancestor_id] = '';

            if ($is_link == 0)
                $datatree_array['descendant_of'][$descendant_id] = $ancestor_id;
            else
                $datatree_array['linked_from'][$descendant_id] = $ancestor_id;

            if ($multiple_allowed == 1)
                $datatree_array['multiple_allowed'][$descendant_id] = $ancestor_id;
        }

        // Store in cache and return
//print '<pre>'.print_r($datatree_array, true).'</pre>';  exit();
        $memcached->set($memcached_prefix.'.cached_datatree_array', $datatree_array, 0);
        return $datatree_array;
    }


    /**
     * Builds an array of all datatype permissions possessed by the given user.
     *
     * @throws \Exception
     *
     * @param integer $user_id        The database id of the user to grab permissions for
     * @param Request $request
     * @param boolean $force_rebuild  If true, save the calling user's permissions in memcached...if false, just return an array
     * 
     * @return array
     */
    public function getPermissionsArray($user_id, Request $request, $force_rebuild = false)
    {
        try {
            // Permissons are stored in memcached to allow other parts of the server to force a rebuild of any user's permissions
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $datatype_permissions = $memcached->get($memcached_prefix.'.user_'.$user_id.'_datatype_permissions');
            if ( !($force_rebuild || $datatype_permissions == false) )
                return $datatype_permissions;


            // ----------------------------------------
            // Datatype permissions not set for this user, or a recache is required...
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var User $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            $is_admin = $user->hasRole('ROLE_SUPER_ADMIN');

            // Load all permissions for this user from the database
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id, up.can_view_type, up.can_edit_record, up.can_add_record, up.can_delete_record, up.can_design_type, up.is_type_admin
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:UserPermissions AS up WITH up.dataType = dt
                WHERE up.user = :user_id
                AND dt.deletedAt IS NULL AND up.deletedAt IS NULL'
            )->setParameters( array('user_id' => $user_id) );
            $user_permissions = $query->getArrayResult();


            // ----------------------------------------
            // Count the number of datatypes to determine whether a permissions entity is missing
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id
                FROM ODRAdminBundle:DataType AS dt
                WHERE dt.deletedAt IS NULL'
            );
            $all_datatypes = $query->getArrayResult();

            if ( count($all_datatypes) !== count($user_permissions) ) {
                // There are fewer permissions objects than datatypes...create missing permissions objects
                $top_level_datatypes = self::getTopLevelDatatypes();
                foreach ($top_level_datatypes as $num => $datatype_id)
                    self::permissionsExistence($em, $user_id, $user_id, $datatype_id, null);

                // Reload permissions for user
                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id, up.can_view_type, up.can_edit_record, up.can_add_record, up.can_delete_record, up.can_design_type, up.is_type_admin
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:UserPermissions AS up WITH up.dataType = dt
                    WHERE up.user = :user_id
                    AND dt.deletedAt IS NULL AND up.deletedAt IS NULL'
                )->setParameters( array('user_id' => $user_id) );
                $user_permissions = $query->getArrayResult();
            }


            // ----------------------------------------
            // Grab the contents of ODRAdminBundle:DataTree as an array
            $datatree_array = self::getDatatreeArray($em, $force_rebuild);

            $all_permissions = array();
            foreach ($user_permissions as $result) {
                $datatype_id = $result['dt_id'];

                $all_permissions[$datatype_id] = array();
                $save = false;

                if ( $is_admin || $result['can_view_type'] == 1 ) {
                    $all_permissions[$datatype_id]['view'] = 1;
                    $save = true;
                }
                if ( $is_admin || $result['can_edit_record'] == 1 ) {
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
                if ( $is_admin || $result['can_add_record'] == 1 ) {
                    $all_permissions[$datatype_id]['add'] = 1;
                    $save = true;
                }
                if ( $is_admin || $result['can_delete_record'] == 1 ) {
                    $all_permissions[$datatype_id]['delete'] = 1;
                    $save = true;
                }
                if ( $is_admin || $result['can_design_type'] == 1 ) {
                    $all_permissions[$datatype_id]['design'] = 1;
                    $save = true;
                }
                if ( $is_admin || $result['is_type_admin'] == 1 ) {
                    $all_permissions[$datatype_id]['admin'] = 1;
                    $save = true;
                }

                if (!$save)
                    unset( $all_permissions[$datatype_id] );
            }

//print '<pre>'.print_r($all_permissions, true).'</pre>';

            // ----------------------------------------
            // Save and return the permissions array
            ksort($all_permissions);
            $memcached->set($memcached_prefix.'.user_'.$user_id.'_datatype_permissions', $all_permissions, 0);

            return $all_permissions;
        }
        catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }


    /**
     * Ensures the user permissions table has rows linking the given user and datatype.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $user_id                The User receiving the permissions
     * @param integer $admin_id               The admin User which triggered this function
     * @param integer $datatype_id            Which DataType these permissions are for
     * @param mixed $parent_permission        null if $datatype is top-level, otherwise the $user's UserPermissions object for this $datatype's parent
     *
     */
    protected function permissionsExistence($em, $user_id, $admin_id, $datatype_id, $parent_permission)
    {
        // Look up the user's permissions for this datatype
        $query = $em->createQuery(
           'SELECT up
            FROM ODRAdminBundle:UserPermissions AS up
            WHERE up.user = :user_id AND up.dataType = :datatype'
        )->setParameters( array('user_id' => $user_id, 'datatype' => $datatype_id) );
        $results = $query->getArrayResult();

        // getArrayResult() will return an empty array if nothing exists...
        $user_permission = null;
        if ( isset($results[0]) )
            $user_permission = $results[0];

        // Verify that a permissions object exists for this user/datatype
        if ($user_permission === null) {
            /** @var User $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            $default = 0;
            if ($user->hasRole('ROLE_SUPER_ADMIN'))
                $default = 1;   // SuperAdmins can edit/add/delete/design everything, no exceptions

            $initial_permissions = array();
            if ($parent_permission === null) {
                // If this is a top-level datatype, use the defaults
                $initial_permissions = array(
                    'can_view_type' => $default,
                    'can_add_record' => $default,
                    'can_edit_record' => $default,
                    'can_delete_record' => $default,
                    'can_design_type' => $default,
                    'is_type_admin' => $default
                );
            }
            else {
                // If this is a childtype, use the parent's permissions as defaults
                $initial_permissions = array(
                    'can_view_type' => $parent_permission['can_view_type'],
                    'can_add_record' => $parent_permission['can_add_record'],
                    'can_edit_record' => $parent_permission['can_edit_record'],
                    'can_delete_record' => $parent_permission['can_delete_record'],
                    'can_design_type' => $parent_permission['can_design_type'],
                    'is_type_admin' => 0    // DO NOT set admin permissions on childtypes
                );
            }
            self::ODR_addUserPermission($em, $user_id, $admin_id, $datatype_id, $initial_permissions);


            // Reload permission in array format for recursion purposes
            $query = $em->createQuery(
               'SELECT up
                FROM ODRAdminBundle:UserPermissions AS up
                WHERE up.user = :user_id AND up.dataType = :datatype'
            )->setParameters( array('user_id' => $user_id, 'datatype' => $datatype_id) );
            $results = $query->getArrayResult();
            $user_permission = $results[0];
        }
        /** @var UserPermissions $user_permission */

        // Locate all child datatypes of this datatype
        $query = $em->createQuery(
           'SELECT descendant.id
            FROM ODRAdminBundle:DataType AS ancestor
            JOIN ODRAdminBundle:DataTree AS dt WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE ancestor.id = :datatype AND dtm.is_link = 0
            AND ancestor.deletedAt IS NULL AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( array('datatype' => $datatype_id) );
        $results = $query->getArrayResult();

        foreach ($results as $result) {
            // Ensure the user has permission objects for all non-linked child datatypes as well
            $childtype_id = $result['id'];
            self::permissionsExistence($em, $user_id, $admin_id, $childtype_id, $user_permission);
        }

    }


    /**
     * Creates and persists a UserPermissions entity for the specified user/datatype pair
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $user_id            The user receiving this permission entity
     * @param integer $admin_id           The admin user creating the permission entity
     * @param integer $datatype_id        The Datatype this permission entity is for
     * @param array $initial_permissions
     *
     * @return UserPermissions
     */
    protected function ODR_addUserPermission($em, $user_id, $admin_id, $datatype_id, $initial_permissions)
    {
        // Ensure a permissions object doesn't already exist...
        $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');
        /** @var UserPermissions $up */
        $up = $repo_user_permissions->findOneBy( array('user' => $user_id, 'dataType' => $datatype_id) );

        // If the permissions object does not exist...
        if ($up == null) {
            // Load required objects
            $admin_user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($admin_id);

            // Ensure a permissions object doesn't already exist before creating one
            $query =
               'INSERT INTO odr_user_permissions (user_id, data_type_id)
                SELECT * FROM (SELECT :user_id AS user_id, :datatype_id AS dt_id) AS tmp
                WHERE NOT EXISTS (
                    SELECT id FROM odr_user_permissions WHERE user_id = :user_id AND data_type_id = :datatype_id AND deletedAt IS NULL
                ) LIMIT 1;';
            $params = array('user_id' => $user_id, 'datatype_id' => $datatype_id);
            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);

            $up = $repo_user_permissions->findOneBy( array('user' => $user_id, 'dataType' => $datatype_id) );
            $up->setCreated( new \DateTime() );
            $up->setCreatedBy($admin_user);

            if ( isset($initial_permissions['can_view_type']) )
                $up->setCanViewType($initial_permissions['can_view_type']);
            if ( isset($initial_permissions['can_edit_record']) )
                $up->setCanEditRecord($initial_permissions['can_edit_record']);
            if ( isset($initial_permissions['can_add_record']) )
                $up->setCanAddRecord($initial_permissions['can_add_record']);
            if ( isset($initial_permissions['can_delete_record']) )
                $up->setCanDeleteRecord($initial_permissions['can_delete_record']);
            if ( isset($initial_permissions['can_design_type']) )
                $up->setCanDesignType($initial_permissions['can_design_type']);
            if ( isset($initial_permissions['is_type_admin']) )
                $up->setIsTypeAdmin($initial_permissions['is_type_admin']);

            $em->persist($up);
            $em->flush($up);
            $em->refresh($up);
        }

        return $up;
    }


    /**
     * Creates and persists a UserFieldPermissions entity for the specified user/datafield pair.
     *
     * Calling function MUST FLUSH ENTITYMANAGER...if this function needs to be called, it will be called multiple times
     * in rapid succession because a user is missing a whole pile of UserFieldPermission entries.  As such, it's more
     * efficient for the calling function to control when flushes occur.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $user_id                The user receiving this permission entity
     * @param integer $admin_id               The admin user creating the permission entity
     * @param DataFields $datafield           The Datafield this permission entity is for
     * @param array $initial_permissions
     *
     * @return UserFieldPermissions
     */
    protected function ODR_addUserFieldPermission($em, $user_id, $admin_id, $datafield, $initial_permissions)
    {
        // Ensure a permissions object doesn't already exist...
        $repo_user_field_permissions = $em->getRepository('ODRAdminBundle:UserFieldPermissions');

        /** @var UserFieldPermissions $ufp */
        $ufp = $repo_user_field_permissions->findOneBy( array('user' => $user_id, 'dataField' => $datafield->getId()) );

        // If the permissions object does not exist...
        if ($ufp == null) {
            // Load required objects
            $admin_user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($admin_id);

            // Ensure a permissions object doesn't already exist before creating one
            $query =
               'INSERT INTO odr_user_field_permissions (user_id, data_field_id)
                SELECT * FROM (SELECT :user_id AS user_id, :datafield_id AS df_id) AS tmp
                WHERE NOT EXISTS (
                    SELECT id FROM odr_user_field_permissions WHERE user_id = :user_id AND data_field_id = :datafield_id AND deletedAt IS NULL
                ) LIMIT 1;';
            $params = array('user_id' => $user_id, 'datafield_id' => $datafield->getId());
            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);

            $ufp = $repo_user_field_permissions->findOneBy( array('user' => $user_id, 'dataField' => $datafield->getId()) );
            $ufp->setDataType( $datafield->getDataType() );
            $ufp->setCreated( new \DateTime() );
            $ufp->setCreatedBy($admin_user);

            if ( isset($initial_permissions['can_view_field']) )
                $ufp->setCanViewField($initial_permissions['can_view_field']);
            if ( isset($initial_permissions['can_edit_field']) )
                $ufp->setCanEditField($initial_permissions['can_edit_field']);

            $em->persist($ufp);
//            $em->flush($up);
//            $em->refresh($up);
        }

        return $ufp;
    }


    /**
     * Although it doesn't make sense to use previous UserPermissions entries, changes made are handled the same as
     * other soft-deleteable entities...delete the current one, and make a new one with the changes.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'can_view_type', 'can_add_record', 'can_edit_record', 'can_delete_record', 'can_design_type', 'is_type_admin'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user changing this UserPermissions entry
     * @param UserPermissions $permission      The UserPermissions entity being 'modified'
     * @param array $properties
     *
     * @return UserPermissions
     */
    protected function ODR_copyUserPermission($em, $user, $permission, $properties)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'can_view_type' => $permission->getCanViewType(),
            'can_add_record' => $permission->getCanAddRecord(),
            'can_edit_record' => $permission->getCanEditRecord(),
            'can_delete_record' => $permission->getCanDeleteRecord(),
            'can_design_type' => $permission->getCanDesignType(),
            'is_type_admin' => $permission->getIsTypeAdmin(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $permission;


        // Create a new UserPermissions entry and copy the old entry's data over
        $new_permission = new UserPermissions();
        $new_permission->setUser( $permission->getUser() );
        $new_permission->setDataType( $permission->getDataType() );

        $new_permission->setCanViewType( $permission->getCanViewType() );
        $new_permission->setCanAddRecord( $permission->getCanAddRecord() );
        $new_permission->setCanEditRecord( $permission->getCanEditRecord() );
        $new_permission->setCanDeleteRecord( $permission->getCanDeleteRecord() );
        $new_permission->setCanDesignType( $permission->getCanDesignType() );
        $new_permission->setIsTypeAdmin( $permission->getIsTypeAdmin() );

        $new_permission->setCreatedBy($user);
        $new_permission->setCreated( new \DateTime() );

        // Set any new properties
        if ( isset( $properties['can_view_type']) )
            $new_permission->setCanViewType( $properties['can_view_type'] );
        if ( isset( $properties['can_edit_record']) )
            $new_permission->setCanEditRecord( $properties['can_edit_record'] );
        if ( isset( $properties['can_add_record']) )
            $new_permission->setCanAddRecord( $properties['can_add_record'] );
        if ( isset( $properties['can_delete_record']) )
            $new_permission->setCanDeleteRecord( $properties['can_delete_record'] );
        if ( isset( $properties['can_design_type']) )
            $new_permission->setCanDesignType( $properties['can_design_type'] );
        if ( isset( $properties['is_type_admin']) )
            $new_permission->setIsTypeAdmin( $properties['is_type_admin'] );

        // Save the new meta entry and delete the old one
        $em->remove($permission);
        $em->persist($new_permission);
        $em->flush();

        // Return the new entry
        return $new_permission;
    }


    /**
     * Although it doesn't make sense to use previous UserFieldPermissions entries, changes made are handled the same as
     * other soft-deleteable entities...delete the current one, and make a new one with the changes.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'can_view_field', 'can_edit_field'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                        The user changing this UserFieldPermissions entry
     * @param UserFieldPermissions $permission  The UserFieldPermissions entity being 'modified'
     * @param array $properties
     *
     * @return UserFieldPermissions
     */
    protected function ODR_copyUserFieldPermission($em, $user, $permission, $properties)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'can_view_field' => $permission->getCanViewField(),
            'can_edit_field' => $permission->getCanEditField(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $permission;


        // Create a new UserPermissions entry and copy the old entry's data over
        $new_permission = new UserFieldPermissions();
        $new_permission->setUser( $permission->getUser() );
        $new_permission->setDataType( $permission->getDataType() );
        $new_permission->setDataField( $permission->getDataField() );

        $new_permission->setCanViewField( $permission->getCanViewField() );
        $new_permission->setCanEditField( $permission->getCanEditField() );

        $new_permission->setCreatedBy($user);
        $new_permission->setCreated( new \DateTime() );

        // Set any new properties
        if ( isset( $properties['can_view_field']) )
            $new_permission->setCanViewField( $properties['can_view_field'] );
        if ( isset( $properties['can_edit_field']) )
            $new_permission->setCanEditField( $properties['can_edit_field'] );

        // Save the new meta entry and delete the old one
        $em->remove($permission);
        $em->persist($new_permission);
        $em->flush();

        // Return the new entry
        return $new_permission;
    }


    /**
     * Builds an array of all datafield permissions possessed by the given user.
     *
     * @param integer $user_id       The database id of the user to grab datafield permissions for.
     * @param Request $request
     * @param boolean $force_rebuild If true, save the calling user's permissions in memcached...if false, just return an array
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function getDatafieldPermissionsArray($user_id, Request $request, $force_rebuild = false)
    {
        try {
            // Permissons are stored in memcached to allow other parts of the server to force a rebuild of any user's permissions
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $datafield_permissions = $memcached->get($memcached_prefix.'.user_'.$user_id.'_datafield_permissions');
            if ( !($force_rebuild || $datafield_permissions == false) )
                return $datafield_permissions;

            // ----------------------------------------
            // Permissions for a user other than the currently logged-in one requested, or permissions not set...need to build an array
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var User $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            $is_admin = $user->hasRole('ROLE_SUPER_ADMIN');

            // Load all datafield permissions for this user from the database
            $query = $em->createQuery(
               'SELECT df.id AS df_id, ufp.can_view_field, ufp.can_edit_field
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:UserFieldPermissions AS ufp WITH ufp.dataField = df
                WHERE ufp.user = :user_id
                AND df.deletedAt IS NULL AND ufp.deletedAt IS NULL'
            )->setParameters( array('user_id' => $user_id) );
            $datafield_permissions = $query->getArrayResult();


            // ----------------------------------------
            // Count the number of datafields to determine whether a datafield permissions entity is missing
            $query = $em->createQuery(
               'SELECT df.id AS df_id
                FROM ODRAdminBundle:DataFields AS df
                WHERE df.deletedAt IS NULL'
            );
            $all_datafields = $query->getArrayResult();

            if ( count($all_datafields) !== count($datafield_permissions) ) {
                // Need the user's datatype permissions to determine defaults for missing datafield permissions
                $datatype_permissions = self::getPermissionsArray($user_id, $request, true);

                // Create missing datafield permission objects
                self::datafieldPermissionsExistence($em, $user_id, $user_id, $datatype_permissions);

                // There are fewer datafield permissions objects than datafields...create missing permissions objects
                $top_level_datatypes = self::getTopLevelDatatypes();
                foreach ($top_level_datatypes as $num => $datatype_id)
                    self::permissionsExistence($em, $user_id, $user_id, $datatype_id, null);

                // Reload datafield permissions for user
                $query = $em->createQuery(
                   'SELECT df.id AS df_id, ufp.can_view_field, ufp.can_edit_field
                    FROM ODRAdminBundle:DataFields AS df
                    JOIN ODRAdminBundle:UserFieldPermissions AS ufp WITH ufp.dataField = df
                    WHERE ufp.user = :user_id
                    AND df.deletedAt IS NULL AND ufp.deletedAt IS NULL'
                )->setParameters( array('user_id' => $user_id) );
                $datafield_permissions = $query->getArrayResult();
            }


            // ----------------------------------------
            // Build an array of all datafield permissions the user has
            $all_permissions = array();
            foreach ($datafield_permissions as $result) {
                $datafield_id = $result['df_id'];

                $all_permissions[$datafield_id] = array();
                $save = false;

                if ( $is_admin || $result['can_view_field'] == 1 ) {
                    $all_permissions[$datafield_id]['view'] = 1;
                    $save = true;
                }
                if ( $is_admin || $result['can_edit_field'] == 1 ) {
                    $all_permissions[$datafield_id]['edit'] = 1;
                    $save = true;
                }

                if (!$save)
                    unset( $all_permissions[$datafield_id] );
            }

            // Save and return the permissions array
            ksort($all_permissions);
            $memcached->set($memcached_prefix.'.user_'.$user_id.'_datafield_permissions', $all_permissions, 0);

            return $all_permissions;
        }
        catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }


    /**
     * Ensures a user has all required datafield permission objects.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $user_id                The user that is being checked for missing datafield permissions
     * @param integer $admin_id               The user that triggered this existence check
     * @param array $datatype_permissions     The user's datatype permissions array, for determining defaults of missing datafield permissions
     */
    protected function datafieldPermissionsExistence($em, $user_id, $admin_id, $datatype_permissions)
    {
        // Load all datafield ids from the database
        $query = $em->createQuery(
           'SELECT df.id AS df_id
            FROM ODRAdminBundle:DataFields AS df
            WHERE df.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $all_datafield_ids = array();
        foreach ($results as $result)
            $all_datafield_ids[] = $result['df_id'];


        // Load all datafield permissions for this user from the database
        $query = $em->createQuery(
           'SELECT df.id AS df_id
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:UserFieldPermissions AS ufp WITH ufp.dataField = df
            WHERE ufp.user = :user_id
            AND df.deletedAt IS NULL AND ufp.deletedAt IS NULL'
        )->setParameters( array('user_id' => $user_id) );
        $results = $query->getArrayResult();

        $datafield_permissions = array();
        foreach ($results as $result)
            $datafield_permissions[] = $result['df_id'];


        // Determine which datafields this user doesn't have permission entities for
        $missing_permissions = array_diff($all_datafield_ids, $datafield_permissions);
        if ( count($missing_permissions) == 0 )
            return;

//print '<pre>'.print_r($missing_permissions, true).'</pre>'; exit();

        $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

        $count = 0;
        foreach ($missing_permissions as $num => $df_id) {
            // Load required objects
            /** @var DataFields $datafield */
            $datafield = $repo_datafield->find($df_id);
            $datatype_id = $datafield->getDataType()->getId();

            // User is able to edit by default if they have edit permissions to the datatype
            $can_edit_field = 0;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['edit']) )
                $can_edit_field = 1;


            // Create/persist the missing datafield permissions entry
            $initial_permissions = array(
                'can_view_field' => 1,                  // always able to view by default
                'can_edit_field' => $can_edit_field
            );
            self::ODR_addUserFieldPermission($em, $user_id, $admin_id, $datafield, $initial_permissions);

            // Flush a batch of datafield permission entities
            $count++;
            if (($count % 20) == 0)
                $em->flush();
        }

        // Flush any leftover datafield permission entities
        if (($count % 20) !== 0)
            $em->flush();
    }


    /**
     * Given a user's permission arrays, filter the provided datarecord/datatype arraysso twig doesn't render anything they're not supposed to see.
     *
     * @param integer $datatype_id
     * @param array &$datatype_array        @see self::getDatatypeArray()
     * @param array &$datarecord_array      @see self::getDatarecordArray()
     * @param array $datatype_permissions   @see self::getPermissionsArray()
     * @param array $datafield_permissions  @see self::getDatafieldPermissionsArray()
     */
    protected function filterByUserPermissions($datatype_id, &$datatype_array, &$datarecord_array, $datatype_permissions, $datafield_permissions)
    {
        // Determine relevant permissions...
        $has_view_permission = false;
        if ( isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'view' ]) )
            $has_view_permission = true;

        // If user is lacking the 'can_view_type' permission...
        if ( !$has_view_permission ) {
            // ...remove non-public datarecords
            foreach ($datarecord_array as $dr_id => $dr) {
                $public_date = $dr['dataRecordMeta']['publicDate'];
                if ($public_date->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
                    unset($datarecord_array[$dr_id]);
            }

            // ...remove non-public files and images
            foreach ($datarecord_array as $dr_id => $dr) {
                foreach ($dr['dataRecordFields'] as $df_id => $drf) {
                    foreach ($drf['files'] as $file_num => $file) {
                        $public_date = $file['fileMeta']['publicDate'];
                        if ($public_date->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
                            unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id][$file_num] );
                    }

                    foreach ($drf['images'] as $image_num => $image) {
                        $public_date = $image['parent']['imageMeta']['publicDate'];
                        if ($public_date->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
                            unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id][$image_num] );
                    }
                }
            }
/*
            // Remove non-public theme_elements
            foreach ($datatype_array as $dt_id => $dt) {
                foreach ($dt['themes'] as $theme_id => $theme) {
                    foreach ($theme['themeElements'] as $te_num => $te) {
                        $public_date = $te['themeElementMeta']['publicDate'];
                        if ($public_date->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
                            unset( $datatype_array[$dt_id]['themes'][$theme_id]['themeElements'][$te_num] );
                    }
                }
            }
*/
        }

        // For each datafield permission the user has...
        foreach ($datafield_permissions as $df_id => $permission) {
            // ...if they lack the 'can_view_field' permission for that datafield...
            if ( isset($permission['view']) && $permission['view'] == 0 ) {
/*
                // ...remove that datafield from the layout
                foreach ($datatype_array as $dt_id => $dt) {
                    foreach ($dt['themes'] as $theme_id => $theme) {
                        foreach ($theme['themeElements'] as $te_id => $te) {
                            foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                                if ( $tdf['dataField']['id'] == $df_id )
                                    unset( $datatype_array[$dt_id]['themes'][$theme_id]['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField'] );  // leave the theme_datafield entry
                            }
                        }
                    }
                }

                // ...also remove that datafield from the datarecord array
                foreach ($datarecord_array as $dr_id => $dr) {
                    if ( isset($dr['dataRecordFields'][$df_id]) )
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id] );
                }
*/
            }
        }
    }


    /**
     * Utility function so other controllers can return 403 errors easily.
     * 
     * @param string $type
     * 
     * @return Response
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
        $response->setStatusCode(403);
        return $response;
    }


    /**
     * Utility function so other controllers can notify of deleted entities easily.
     * 
     * @param string $entity
     * 
     * @return Response
     */
    protected function deletedEntityError($entity = '')
    {
        $str = '';
        if ($entity !== '')
            $str = "<h2>This ".$entity." has been deleted!</h2>";
//        else
//            $str = "<h2>Permission Denied</h2>";

        $return = array();
        $return['r'] = 1;   // TODO - switch to 404?
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
     */
    public function updateDatatypeCache($datatype_id, $options = array())
    {

//print "call to update datatype cache\n";

        // ----------------------------------------
        // Grab necessary objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');
        
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
        /** @var User $user */

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
        /** @var DataType $datatype */
        $datatype = $repo_datatype->find($datatype_id);

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
            FROM ODRAdminBundle:DataRecord AS dr
            WHERE dr.dataType = :dataType AND dr.provisioned = false
            AND dr.deletedAt IS NULL'
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
        // Don't worry about whether any linked datarecords are provisioned or not right this second, let WorkerController deal with it later
        $query = $em->createQuery(
           'SELECT DISTINCT grandparent.id AS grandparent_id
            FROM ODRAdminBundle:DataRecord AS descendant
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
     */
    public function updateDatarecordCache($id, $options = array())
    {
//print 'call to updateDatarecordCache()';

        // Grab necessary objects
        /** @var \Doctrine\ORM\EntityManager $em */
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
        /** @var User $user */

        // Mark this datarecord (and its grandparent, if different) as updated
        $current_time = new \DateTime();
        /** @var DataRecord $datarecord */
        $datarecord = $repo_datarecord->find($id);

        // Don't try to update a deleted or a provisioned datarecord
        if ($datarecord == null)
            return;
        if ($datarecord->getProvisioned() == true)
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
        // Don't worry about whether any linked datarecords are provisioned or not right this second, let WorkerController deal with it later
        $query = $em->createQuery(
           'SELECT grandparent.id AS grandparent_id
            FROM ODRAdminBundle:DataRecord AS descendant
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
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
//        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        // Attempt to grab the list of datarecords for this datatype from the cache
        $datarecords = array();
        $datarecord_str = $memcached->get($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');

        // No caching in dev environment
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;
//print 'loaded record_order: '.$datarecord_str."\n";


        if ( !$bypass_cache && $datarecord_str != false && trim($datarecord_str) !== '' ) {
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
                    WHERE dr.dataType = :datatype AND dr.provisioned = false
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
                     WHERE dr.dataType = :datatype AND dr.provisioned = false AND e.dataField = :datafield
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
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user              The user to use if a new TrackedJob is to be created
     * @param string $job_type        A label used to indicate which type of job this is  e.g. 'recache', 'import', etc.
     * @param string $target_entity   Which entity this job is operating on
     * @param array $additional_data  Additional data related to the TrackedJob
     * @param string $restrictions    TODO - ...additional info/restrictions attached to the job
     * @param integer $total          ...how many pieces the job is broken up into?
     * @param boolean $reuse_existing TODO - multi-user concerns
     * 
     * @return TrackedJob
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
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $tracked_job_id
     *
     * @return array
     */
    protected function ODR_getTrackedErrorArray($em, $tracked_job_id)
    {
        $job_errors = array();

        $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);
        if ($tracked_job == null)
            return self::deletedEntityError('TrackedJob');

        /** @var TrackedError[] $tracked_errors */
        $tracked_errors = $em->getRepository('ODRAdminBundle:TrackedError')->findBy( array('trackedJob' => $tracked_job_id) );
        foreach ($tracked_errors as $error)
            $job_errors[ $error->getId() ] = array('error_level' => $error->getErrorLevel(), 'error_body' => json_decode( $error->getErrorBody(), true ));

        return $job_errors;
    }


    /**
     * Deletes all TrackedError entities associated with a specified TrackedJob
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $tracked_job_id
     *
     */
    protected function ODR_deleteTrackedErrorsByJob($em, $tracked_job_id)
    {
        // Because there could potentially be thousands of errors for this TrackedJob, do a mass DQL deletion 
        $query = $em->createQuery(
           'DELETE FROM ODRAdminBundle:TrackedError AS te
            WHERE te.trackedJob = :tracked_job'
        )->setParameters( array('tracked_job' => $tracked_job_id) );
        $rows = $query->execute();
    }


    /**
     * Ensures a ThemeDataType entity exists for a given combination of a datatype and a theme.
     * @deprecated
     * 
     * @param User $user         The user to use if a new ThemeDataType is to be created
     * @param Datatype $datatype 
     * @param Theme $theme       
     *
     */
    protected function ODR_checkThemeDataType($user, $datatype, $theme)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        /** @var ThemeDataType $theme_datatype */
        $theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType')->findOneBy( array('dataType' => $datatype->getId(), 'theme' => $theme->getId()) );

        // If the entity doesn't exist, create it
        if ($theme_datatype == null) {
            self::ODR_addThemeDatatypeEntry($em, $user, $datatype, $theme);
            $em->flush();
        }
    }


    /**
     * Ensures a ThemeDataField entity exists for a given combination of a datafield and a theme.
     * @deprecated
     * 
     * @param User $user            The user to use if a new ThemeDataField is to be created
     * @param DataFields $datafield 
     * @param Theme $theme          
     *
     */
    protected function ODR_checkThemeDataField($user, $datafield, $theme)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        /** @var ThemeDataField $theme_datafield */
        $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield->getId(), 'theme' => $theme->getId()) );

        // If the entity doesn't exist, create it
        if ($theme_datafield == null) {
            self::ODR_addThemeDataFieldEntry($em, $user, $datafield, $theme);
            $em->flush();
        }
    }


    /**
     * Creates and persists a new DataRecordField entity, if one does not already exist for the given (DataRecord, DataField) pair.
     *
     * @param \Doctrine\ORM\EntityManager $em            
     * @param User $user             The user requesting the creation of this entity
     * @param DataRecord $datarecord 
     * @param DataFields $datafield
     *
     * @return DataRecordFields
     */
    protected function ODR_addDataRecordField($em, $user, $datarecord, $datafield)
    {
        /** @var DataRecordFields $drf */
        $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId()) );
        if ($drf == null) {
            // TODO - better method for doing this?
            $query =
               'INSERT INTO odr_data_record_fields (data_record_id, data_field_id)
                SELECT * FROM (SELECT :datarecord AS dr_id, :datafield AS df_id) AS tmp
                WHERE NOT EXISTS (
                    SELECT id FROM odr_data_record_fields WHERE data_record_id = :datarecord AND data_field_id = :datafield AND deletedAt IS NULL
                ) LIMIT 1;';
            $params = array('datarecord' => $datarecord->getId(), 'datafield' => $datafield->getId());
            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);

            $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId()) );
            $drf->setCreated( new \DateTime() );
            $drf->setCreatedBy($user);
//            $drf->setUpdated( new \DateTime() );
//            $drf->setUpdatedBy($user);

            $em->persist($drf);
            $em->flush($drf);
            $em->refresh($drf);
        }

        return $drf;
    }


    /**
     * Creates and persists a new DataRecord entity.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user         The user requesting the creation of this entity
     * @param DataType $datatype 
     *
     * @return DataRecord
     */
    protected function ODR_addDataRecord($em, $user, $datatype)
    {
        // Initial create
        $datarecord = new DataRecord();

        $datarecord->setDataType($datatype);
        $datarecord->setCreatedBy($user);
        $datarecord->setUpdatedBy($user);

        $datarecord->setProvisioned(true);  // Prevent most areas of the site from doing anything with this datarecord...whatever created this datarecord needs to eventually set this to false

        // TODO - delete this property
        $datarecord->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public

        $em->persist($datarecord);
        $em->flush();
        $em->refresh($datarecord);

        $datarecord_meta = new DataRecordMeta();
        $datarecord_meta->setDataRecord($datarecord);
        $datarecord_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public

        $em->persist($datarecord_meta);

        // Create initial objects
        foreach ($datatype->getDataFields() as $datafield) {
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
                /** @var RadioOptions[] $radio_options */
                $radio_options = $em->getRepository('ODRAdminBundle:RadioOptions')->findBy( array('dataField' => $datafield->getId()) );
                foreach ($radio_options as $radio_option) {
                    self::ODR_addRadioSelection($em, $user, $radio_option, $datarecordfield);   // use default of radio_option for selected status
                }
            }
        }

        // Need to flush because of possibility of having creating new storage entity/radio selection objects
        $em->flush();

        return $datarecord;
    }


    /**
     * Copies the given DatarecordMeta entry into a new DatarecordMeta entry for the purposes of soft-deletion.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'publicDate'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The User requesting the modification
     * @param DataRecord $datarecord           The DataRecord entry of the entity being modified
     * @param array $properties
     *
     * @return DataRecordMeta
     */
    protected function ODR_copyDatarecordMeta($em, $user, $datarecord, $properties)
    {
        // Load the old meta entry
        /** @var DataRecordMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:DataRecordMeta')->findOneBy( array('dataRecord' => $datarecord->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'publicDate' => $old_meta_entry->getPublicDate(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Create a new meta entry and copy the old entry's data over
        $new_datarecord_meta = new DataRecordMeta();
        $new_datarecord_meta->setDataRecord( $datarecord );

        $new_datarecord_meta->setPublicDate( $old_meta_entry->getPublicDate() );

        $new_datarecord_meta->setCreatedBy($user);
        $new_datarecord_meta->setCreated( new \DateTime() );

        // Set any new properties
        if ( isset($properties['publicDate']) )
            $new_datarecord_meta->setPublicDate( $properties['publicDate'] );

        // Save the new datatree entry and delete the old one
        $em->remove($old_meta_entry);
        $em->persist($new_datarecord_meta);
        $em->flush();

        // Return the new entry
        return $new_datarecord_meta;
    }


    /**
     * Copies the given DataTree entry into a new DataTree entry for the purposes of soft-deletion.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'multiple_allowed', 'is_link'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The User requesting the modification
     * @param DataTree $datatree               The DataTree entry of the entity being modified
     * @param array $properties
     *
     * @return DataTreeMeta
     */
    protected function ODR_copyDatatreeMeta($em, $user, $datatree, $properties)
    {
        // Load the old meta entry
        /** @var DataTreeMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:DataTreeMeta')->findOneBy( array('dataTree' => $datatree->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'multiple_allowed' => $old_meta_entry->getMultipleAllowed(),
            'is_link' => $old_meta_entry->getIsLink(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Create a new meta entry and copy the old entry's data over
        $new_datatree_meta = new DataTreeMeta();
        $new_datatree_meta->setDataTree( $datatree );

        $new_datatree_meta->setMultipleAllowed( $old_meta_entry->getMultipleAllowed() );
        $new_datatree_meta->setIsLink( $old_meta_entry->getIsLink() );

        $new_datatree_meta->setCreatedBy($user);
        $new_datatree_meta->setCreated( new \DateTime() );

        // Set any new properties
        if ( isset($properties['multiple_allowed']) )
            $new_datatree_meta->setMultipleAllowed( $properties['multiple_allowed'] );
        if ( isset($properties['is_link']) )
            $new_datatree_meta->setIsLink( $properties['is_link'] );

        // Save the new datatree entry and delete the old one
        $em->remove($old_meta_entry);
        $em->persist($new_datatree_meta);
        $em->flush();

        // Return the new entry
        return $new_datatree_meta;
    }


    /**
     * Create a datarecord link from $ancestor_datarecord to $descendant_datarecord.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                        The user requesting the creation of this link
     * @param DataRecord $ancestor_datarecord   The DataRecord which will be the 'ancestor' side of this link
     * @param DataRecord $descendant_datarecord The DataRecord which will be the 'descendant' side of this link
     *
     * @return LinkedDataTree
     */
    protected function ODR_linkDataRecords($em, $user, $ancestor_datarecord, $descendant_datarecord)
    {
        // Check to see if the two datarecords are already linked
        $query = $em->createQuery(
           'SELECT ldt
            FROM ODRAdminBundle:LinkedDataTree AS ldt
            WHERE ldt.ancestor = :ancestor AND ldt.descendant = :descendant'
        )->setParameters( array('ancestor' => $ancestor_datarecord, 'descendant' => $descendant_datarecord) );
        /** @var LinkedDataTree[] $results */
        $results = $query->getResult();

        $linked_datatree = null;
        if ( count($results) > 0 ) {
            // If an earlier deleted linked_datatree entry was found, don't do anything
            foreach ($results as $num => $ldt)
                return $ldt;
        }
        else {
            // ...otherwise, create a new linked_datatree entry
            $linked_datatree = new LinkedDataTree();
            $linked_datatree->setCreatedBy($user);

            $linked_datatree->setAncestor($ancestor_datarecord);
            $linked_datatree->setDescendant($descendant_datarecord);

            $em->persist($linked_datatree);
            $em->flush();
        }

        // Refresh the cache entries for the datarecords
        $options = array(
            'user_id' => $user->getId(),    // This action may be called via the command-line...specify user id so datarecord is guaranteed to be updated correctly
            'mark_as_updated' => true
        );
        self::updateDatarecordCache($ancestor_datarecord->getId(), $options);

        self::updateDatarecordCache($descendant_datarecord->getId(), array());  // nothing changed in the descendant

        return $linked_datatree;
    }


    /**
     * Creates a new File/Image entity from the given file at the given filepath, and persists all required information to the database.
     *
     * NOTE: the newly uploaded file/image will have its decrypted version deleted off the server...if you need it immediately after calling this function, you'll have to use decryptObject() to re-create it
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param string $filepath                 The absolute path to the file
     * @param string $original_filename        The original name of the file
     * @param integer $user_id                 Which user is doing the uploading
     * @param integer $datarecordfield_id      Which DataRecordField entity to store the file under
     *
     * @return File|Image
     */
    protected function finishUpload($em, $filepath, $original_filename, $user_id, $datarecordfield_id)
    {
        // ----------------------------------------
        // Load required objects
        /** @var User $user */
        $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
        /** @var DataRecordFields $drf */
        $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->find($datarecordfield_id);
        $typeclass = $drf->getDataField()->getFieldType()->getTypeClass();

        // Get Symfony to guess the extension of the file via mimetype...a potential wrong extension shouldn't matter since Results::filedownloadAction() renames the file during downloads anyways
        $path_prefix = dirname(__FILE__).'/../../../../web/';
        $uploaded_file = new SymfonyFile($path_prefix.$filepath.'/'.$original_filename);
        $extension = $uploaded_file->guessExtension();

        // ----------------------------------------
        // Determine where the file should ultimately be moved to
        $destination_path = $path_prefix.'uploads/';
        $my_obj = null;
        if ($typeclass == 'File') {
            $my_obj = new File();
            $destination_path .= 'files';

            // Ensure directory exists
            if ( !file_exists($destination_path) )
                mkdir( $destination_path );
        }
        else {
            $my_obj = new Image();
            $destination_path .= 'images';

            // Ensure directory exists
            if ( !file_exists($destination_path) )
                mkdir( $destination_path );
        }
        /** @var File|Image $my_obj */

        // ----------------------------------------
        // Set initial properties of the new File/Image
        $my_obj->setDataRecordFields($drf);
        $my_obj->setDataRecord ($drf->getDataRecord() );
        $my_obj->setDataField( $drf->getDataField() );
        $my_obj->setFieldType( $drf->getDataField()->getFieldType() );

        $my_obj->setExt($extension);
        $my_obj->setLocalFileName('temp');
        $my_obj->setCreatedBy($user);
        $my_obj->setOriginalChecksum('');
        // encrypt_key set by self::encryptObject() somewhat later

        // TODO - delete this property
        $my_obj->setUpdatedBy($user);

        if ($typeclass == 'Image') {
            /** @var Image $my_obj */
            $my_obj->setOriginal('1');

            // TODO - delete these five properties
            $my_obj->setOriginalFileName($original_filename);
            $my_obj->setDisplayorder(0);
            $my_obj->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public    TODO - let user decide default status
            $my_obj->setCaption(null);
            $my_obj->setExternalId('');
        }
        else if ($typeclass == 'File') {
            /** @var File $my_obj */
            $my_obj->setFilesize(0);

            // TODO - delete these four properties
            $my_obj->setOriginalFileName($original_filename);
            $my_obj->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public
            $my_obj->setCaption(null);
            $my_obj->setExternalId('');
        }

        // Save changes
        $em->persist($my_obj);
        $em->flush();
        $em->refresh($my_obj);

        // Also create the initial metadata entry for this new entity
        if ($typeclass == 'Image') {
            $new_image_meta = new ImageMeta();
            $new_image_meta->setImage($my_obj);

            $new_image_meta->setOriginalFileName($original_filename);
            $new_image_meta->setDisplayorder(0);
            $new_image_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public    TODO - let user decide default status
            $new_image_meta->setCaption(null);
            $new_image_meta->setExternalId('');

            $new_image_meta->setCreatedBy($user);
            $em->persist($new_image_meta);
        }
        else if ($typeclass == 'File') {
            $new_file_meta = new FileMeta();
            $new_file_meta->setFile($my_obj);

            $new_file_meta->setOriginalFileName($original_filename);
            $new_file_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public
            $new_file_meta->setDescription(null);
            $new_file_meta->setExternalId('');

            $new_file_meta->setCreatedBy($user);
            $em->persist($new_file_meta);
        }

        $em->flush();


        // ----------------------------------------
        // Set the remaining properties of the new File/Image dependent on the new entities ID
        //$file_path = '';
        if ($typeclass == 'Image') {
            // Generate local filename
            /** @var Image $my_obj */
            $image_id = $my_obj->getId();

            // Move image to correct spot
            $filename = 'Image_'.$image_id.'.'.$my_obj->getExt();
            rename($path_prefix.$filepath.'/'.$original_filename, $destination_path.'/'.$filename);

            $local_filename = $my_obj->getUploadDir().'/'.$filename;
            $my_obj->setLocalFileName($local_filename);

            $sizes = getimagesize($local_filename);
            $my_obj->setImageWidth( $sizes[0] );
            $my_obj->setImageHeight( $sizes[1] );
            // Create thumbnails and other sizes/versions of the uploaded image
            self::resizeImages($my_obj, $user);

            // Encrypt parent image AFTER thumbnails are created
            self::encryptObject($image_id, 'image');

            // Set original checksum for original image
            $filepath = self::decryptObject($image_id, 'image');
            $original_checksum = md5_file($filepath);
            $my_obj->setOriginalChecksum($original_checksum);

            // A decrypted version of the Image still exists on the server...delete it
            unlink($filepath);

            // Save changes again
            $em->persist($my_obj);
            $em->flush();
        }
        else if ($typeclass == 'File') {
            // Generate local filename
            /** @var File $my_obj */
            //$file_id = $my_obj->getId();

            // Move file to correct spot
            //$filename = 'File_'.$file_id.'.'.$my_obj->getExt();
            //rename($filepath.'/'.$original_filename, $destination_path.'/'.$filename);

            //$local_filename = $my_obj->getUploadDir().'/'.$filename;
            $local_filename = realpath( $path_prefix.$filepath.'/'.$original_filename );
            //dirname(__FILE__).'/../../../../web/'.$my_obj->getUploadDir().'/chunks/user_'.$user_id.'/completed/'.$original_filename );

            $my_obj->setLocalFileName($filepath.'/'.$original_filename);

            clearstatcache(true, $local_filename);
            $my_obj->setFilesize( filesize($local_filename) );

            // Save changes again before encryption process takes over
            $em->persist($my_obj);
            $em->flush();
            $em->refresh($my_obj);

            // Encrypt the file before it's used
            //self::encryptObject($file_id, 'file');

            // Decrypt the file and store its checksum in the database
            //$file_path = self::decryptObject($file_id, 'file');
            //$original_checksum = md5_file($file_path);
            //$my_obj->setOriginalChecksum($original_checksum);

            // ----------------------------------------
            // Use beanstalk to encrypt the file so the UI doesn't block on huge files
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $api_key = $this->container->getParameter('beanstalk_api_key');

            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_crypto_request');

            // Insert the new job into the queue
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    "object_type" => $typeclass,
                    "object_id" => $my_obj->getId(),
                    "target_filepath" => '',
                    "crypto_type" => 'encrypt',
                    "memcached_prefix" => $memcached_prefix,    // debug purposes only
                    "url" => $url,
                    "api_key" => $api_key,
                )
            );

            $delay = 1;
            $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
        }

        return $my_obj;
    }


    /**
     * Creates a new storage entity (Short/Medium/Long Varchar, File, Radio, etc)
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                        The user requesting the creation of this entity
     * @param DataRecord $datarecord            
     * @param DataFields $datafield             
     *
     * @return mixed
     */
    protected function ODR_addStorageEntity($em, $user, $datarecord, $datafield)
    {
        $storage_entity = null;

        $fieldtype = $datafield->getFieldType();
        $typeclass = $fieldtype->getTypeClass();

        $classname = "ODR\\AdminBundle\\Entity\\".$typeclass;

        // Create initial entity if insert_on_create
        if ($fieldtype->getInsertOnCreate() == 1) {
            // Create Instance of field
            /** @var mixed $storage_entity */
            $storage_entity = new $classname();
            $storage_entity->setDataRecord($datarecord);
            $storage_entity->setDataField($datafield);

            // If Datarecordfields entry is missing, create it
            /** @var DataRecordFields $drf */
            $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId()) );
            if ($drf == null) {
                $drf = self::ODR_addDataRecordField($em, $user, $datarecord, $datafield);
                $em->persist($drf);
            }
            $storage_entity->setDataRecordFields($drf);

            // Store Fieldtype and set default values
            $storage_entity->setFieldType($fieldtype);
            if ($typeclass == 'DatetimeValue') {
                $storage_entity->setValue( new \DateTime('0000-00-00 00:00:00') );
            }
            else if ($typeclass == 'IntegerValue') {
                $storage_entity->setValue(null);
            }
            else if ($typeclass == 'DecimalValue') {
                $storage_entity->setOriginalValue(null);
                $storage_entity->setValue(null);
            }
            else {
                $storage_entity->setValue('');
            }

            $storage_entity->setCreatedBy($user);
//            $storage_entity->setUpdatedBy($user);
            $em->persist($storage_entity);
        }

        return $storage_entity;
    }


    /**
     * Modifies a meta entry for a given File entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'description', 'original_filename', 'external_id', and/or 'public_date' (MUST BE A DATETIME OBJECT).
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param File $file                       The File entity of the meta entry being modified
     * @param array $properties
     *
     * @return FileMeta
     */
    protected function ODR_copyFileMeta($em, $user, $file, $properties)
    {
        // Load the old meta entry
        /** @var FileMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:FileMeta')->findOneBy( array('file' => $file->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'description' => $old_meta_entry->getDescription(),
            'original_filename' => $old_meta_entry->getOriginalFileName(),
            'external_id' => $old_meta_entry->getExternalId(),
            'publicDate' => $old_meta_entry->getPublicDate(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Create a new meta entry and copy the old entry's data over
        $file_meta = new FileMeta();
        $file_meta->setFile($file);

        $file_meta->setDescription( $old_meta_entry->getDescription() );
        $file_meta->setOriginalFileName( $old_meta_entry->getOriginalFileName() );
        $file_meta->setExternalId( $old_meta_entry->getExternalId() );
        $file_meta->setPublicDate( $old_meta_entry->getPublicDate() );

        $file_meta->setCreatedBy($user);
        $file_meta->setCreated( new \DateTime() );

        // Set any new properties
        if ( isset($properties['description']) )
            $file_meta->setDescription( $properties['description'] );
        if ( isset($properties['original_filename']) )
            $file_meta->setOriginalFileName( $properties['original_filename'] );
        if ( isset($properties['external_id']) )
            $file_meta->setExternalId( $properties['external_id'] );
        if ( isset($properties['publicDate']) )
            $file_meta->setPublicDate( $properties['publicDate'] );

        // Save the new meta entry and delete the old one
        $em->remove($old_meta_entry);
        $em->persist($file_meta);
        $em->flush();

        // Return the new entry
        return $file_meta;
    }


    /**
     * Modifies a meta entry for a given Image entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'caption', 'original_filename', 'external_id', 'public_date' (MUST BE A DATETIME OBJECT), and/or 'display_order.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param Image $image                     The Image entity of the meta entry being modified
     * @param array $properties
     *
     * @return ImageMeta
     */
    protected function ODR_copyImageMeta($em, $user, $image, $properties)
    {
        // Load the old meta entry
        /** @var ImageMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:ImageMeta')->findOneBy( array('image' => $image->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'caption' => $old_meta_entry->getCaption(),
            'original_filename' => $old_meta_entry->getOriginalFileName(),
            'external_id' => $old_meta_entry->getExternalId(),
            'publicDate' => $old_meta_entry->getPublicDate(),
            'display_order' => $old_meta_entry->getPublicDate()
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value)
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Create a new meta entry and copy the old entry's data over
        $image_meta = new ImageMeta();
        $image_meta->setImage($image);

        $image_meta->setCaption( $old_meta_entry->getCaption() );
        $image_meta->setOriginalFileName( $old_meta_entry->getOriginalFileName() );
        $image_meta->setExternalId( $old_meta_entry->getExternalId() );
        $image_meta->setPublicDate( $old_meta_entry->getPublicDate() );
        $image_meta->setDisplayorder( $old_meta_entry->getDisplayorder() );

        $image_meta->setCreatedBy($user);
        $image_meta->setCreated( new \DateTime() );

        // Set any new properties
        if ( isset($properties['caption']) )
            $image_meta->setCaption( $properties['caption'] );
        if ( isset($properties['original_filename']) )
            $image_meta->setOriginalFileName( $properties['original_filename'] );
        if ( isset($properties['external_id']) )
            $image_meta->setExternalId( $properties['external_id'] );
        if ( isset($properties['publicDate']) )
            $image_meta->setPublicDate( $properties['publicDate'] );
        if ( isset($properties['display_order']) )
            $image_meta->setDisplayorder( $properties['display_order'] );

        // Save the new meta entry and delete the old one
        $em->remove($old_meta_entry);
        $em->persist($image_meta);
        $em->flush();

        // Return the new entry
        return $image_meta;
    }


    /**
     * Creates a new RadioOption entity
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user            The user requesting the creation of this entity.
     * @param DataFields $datafield
     * @param boolean $force_create If true, always create a new RadioOption...otherwise find and return the existing RadioOption with $datafield and $option_name, or create one if it doesn't exist
     * @param string $option_name   An optional name to immediately assign to the RadioOption entity
     *
     * @return RadioOptions
     */
    protected function ODR_addRadioOption($em, $user, $datafield, $force_create, $option_name = "Option")
    {
        if ($force_create) {
            // Create a new RadioOption entity
            $radio_option = new RadioOptions();
            $radio_option->setDataField($datafield);
            $radio_option->setOptionName($option_name);     // exists to prevent potential concurrency issues, see below

            $radio_option->setCreatedBy($user);
            $radio_option->setCreated(new \DateTime());

            // TODO - delete these five properties
            $radio_option->setXmlOptionName('');
            $radio_option->setDisplayOrder(0);
            $radio_option->setIsDefault(false);
            $radio_option->setUpdatedBy($user);
            $radio_option->setUpdated(new \DateTime());

            // Save and reload the RadioOption so the associated meta entry can access it
            $em->persist($radio_option);
            $em->flush($radio_option);
            $em->refresh($radio_option);

            // Create a new RadioOptionMeta entity
            $radio_option_meta = new RadioOptionsMeta();
            $radio_option_meta->setRadioOptions($radio_option);
            $radio_option_meta->setOptionName($option_name);
            $radio_option_meta->setXmlOptionName('');
            $radio_option_meta->setDisplayOrder(0);
            $radio_option_meta->setIsDefault(false);

            $radio_option_meta->setCreatedBy($user);
            $radio_option_meta->setCreated( new \DateTime() );

            $em->persist($radio_option_meta);
            $em->flush($radio_option_meta);

            return $radio_option;
        }
        else {
            // See if a RadioOption entity for this datafield with this name already exists
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy( array('optionName' => $option_name, 'dataField' => $datafield->getId()) );
            if ($radio_option == null) {
                // TODO - CURRENTLY WORKS, BUT MIGHT WANT TO LOOK INTO AN OFFICIAL MUTEX...

                // Define and execute a query to manually create the absolute minimum required for a RadioOption entity...
                $query =
                    'INSERT INTO odr_radio_options (option_name, data_fields_id)
                     SELECT * FROM (SELECT :name AS option_name, :df_id AS df_id) AS tmp
                     WHERE NOT EXISTS (
                         SELECT option_name FROM odr_radio_options WHERE option_name = :name AND data_fields_id = :df_id AND deletedAt IS NULL
                     ) LIMIT 1;';
                $params = array('name' => $option_name, 'df_id' => $datafield->getId());
                $conn = $em->getConnection();
                $rowsAffected = $conn->executeUpdate($query, $params);

                // Now that it exists, fill out the properties of a RadioOption entity that were skipped during the manual creation...
                /** @var RadioOptions $radio_option */
                $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(array('optionName' => $option_name, 'dataField' => $datafield->getId()));
//            $radio_option->setOptionName($option_name);
                $radio_option->setCreatedBy($user);
                $radio_option->setCreated(new \DateTime());

                // TODO - delete these five properties
                $radio_option->setXmlOptionName('');
                $radio_option->setDisplayOrder(0);
                $radio_option->setIsDefault(false);
                $radio_option->setUpdatedBy($user);
                $radio_option->setUpdated(new \DateTime());

                // Save and reload the RadioOption so the associated meta entry can access it
                $em->persist($radio_option);
                $em->flush($radio_option);
                $em->refresh($radio_option);

                // See if a RadioOptionMeta entity exists for this RadioOption...
                /** @var RadioOptionsMeta $radio_option_meta */
                $radio_option_meta = $em->getRepository('ODRAdminBundle:RadioOptionsMeta')->findOneBy( array('radioOptions' => $radio_option->getId()) );
                if ($radio_option_meta == null) {
                    // Define and execute a query to manually create the absolute minimum required for a RadioOption entity...
                    $query =
                       'INSERT INTO odr_radio_options_meta (radio_option_id)
                        SELECT * FROM (SELECT :ro_id AS ro_id) AS tmp
                        WHERE NOT EXISTS (
                            SELECT radio_option_id FROM odr_radio_options_meta WHERE radio_option_id = :ro_id AND deletedAt IS NULL
                        ) LIMIT 1;';
                    $params = array('ro_id' => $radio_option->getId());
                    $conn = $em->getConnection();
                    $rowsAffected = $conn->executeUpdate($query, $params);

                    // Now that it exists, fill out the properties of a RadioOptionMeta entity that were skipped during the manual creation...
                    $radio_option_meta = $em->getRepository('ODRAdminBundle:RadioOptionsMeta')->findOneBy( array('radioOptions' => $radio_option->getId()) );
                    $radio_option_meta->setOptionName($option_name);
                    $radio_option_meta->setXmlOptionName('');
                    $radio_option_meta->setDisplayOrder(0);
                    $radio_option_meta->setIsDefault(false);

                    $radio_option_meta->setCreatedBy($user);
                    $radio_option_meta->setCreated( new \DateTime() );

                    $em->persist($radio_option_meta);
                    $em->flush($radio_option_meta);
                }
            }

            return $radio_option;
        }
    }


    /**
     * Modifies a meta entry for a given RadioOptions entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'option_name', 'xml_option_name', 'display_order', and/or 'is_default'.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param RadioOptions $radio_option       The RadioOption entity of the meta entry being modified
     * @param array $properties
     *
     * @return RadioOptionsMeta
     */
    protected function ODR_copyRadioOptionsMeta($em, $user, $radio_option, $properties)
    {
        // Load the old meta entry
        /** @var RadioOptionsMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:RadioOptionsMeta')->findOneBy( array('radioOptions' => $radio_option->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'option_name' => $old_meta_entry->getOptionName(),
            'xml_option_name' => $old_meta_entry->getXmlOptionName(),
            'display_order' => $old_meta_entry->getDisplayOrder(),
            'is_default' => $old_meta_entry->getIsDefault(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;

        // Create a new meta entry and copy the old entry's data over
        $radio_option_meta = new RadioOptionsMeta();
        $radio_option_meta->setRadioOptions($radio_option);

        $radio_option_meta->setOptionName( $old_meta_entry->getOptionName() );
        $radio_option_meta->setXmlOptionName( $old_meta_entry->getXmlOptionName() );
        $radio_option_meta->setDisplayOrder( $old_meta_entry->getDisplayOrder() );
        $radio_option_meta->setIsDefault( $old_meta_entry->getIsDefault() );

        $radio_option_meta->setCreatedBy($user);
        $radio_option_meta->setCreated( new \DateTime() );

        // Set any new properties
        if ( isset($properties['option_name']) )
            $radio_option_meta->setOptionName( $properties['option_name'] );
        if ( isset($properties['xml_option_name']) )
            $radio_option_meta->setXmlOptionName( $properties['xml_option_name'] );
        if ( isset($properties['display_order']) )
            $radio_option_meta->setDisplayOrder( $properties['display_order'] );
        if ( isset($properties['is_default']) )
            $radio_option_meta->setIsDefault( $properties['is_default'] );

        // Save the new meta entry and delete the old one
        $em->remove($old_meta_entry);
        $em->persist($radio_option_meta);
        $em->flush();

        // Return the new entry
        return $radio_option_meta;
    }


    /**
     * Creates a new RadioSelection entity
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                         The user requesting the creation of this entity.
     * @param RadioOptions $radio_option         The RadioOption entity receiving this RadioSelection
     * @param DataRecordFields $datarecordfield
     * @param integer|string $initial_value      If "auto", initial value is based on the default setting from RadioOption...otherwise 0 for unselected, or 1 for selected
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
     * Modifies a meta entry for a given Datatype entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     *
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param DataType $datatype               The DataField entity of the meta entry being modified
     * @param array $properties
     *
     * @return DataTypeMeta
     */
    protected function ODR_copyDatatypeMeta($em, $user, $datatype, $properties)
    {
        // Load the old meta entry
        /** @var DataTypeMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy( array('dataType' => $datatype->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'renderPlugin' => $old_meta_entry->getRenderPlugin()->getId(),
/*
            'externalIdField' => $old_meta_entry->getExternalIdField()->getId(),
            'nameField' => $old_meta_entry->getNameField()->getId(),
            'sortField' => $old_meta_entry->getSortField()->getId(),
            'backgroundImageField' => $old_meta_entry->getBackgroundImageField()->getId(),
*/
            'searchSlug' => $old_meta_entry->getSearchSlug(),
            'shortName' => $old_meta_entry->getShortName(),
            'longName' => $old_meta_entry->getLongName(),
            'description' => $old_meta_entry->getDescription(),
            'xml_shortName' => $old_meta_entry->getXmlShortName(),

            'useShortResults' => $old_meta_entry->getUseShortResults(),
            'display_type' => $old_meta_entry->getDisplayType(),
            'publicDate' => $old_meta_entry->getPublicDate(),
        );

        // These Datafields entries can be null
        if ( $old_meta_entry->getExternalIdField() !== null )
            $existing_values['externalIdField'] = $old_meta_entry->getExternalIdField()->getId();
        if ( $old_meta_entry->getNameField() !== null )
            $existing_values['nameField'] = $old_meta_entry->getNameField()->getId();
        if ( $old_meta_entry->getSortField() !== null )
            $existing_values['sortField'] = $old_meta_entry->getSortField()->getId();
        if ( $old_meta_entry->getBackgroundImageField() !== null )
            $existing_values['backgroundImageField'] = $old_meta_entry->getBackgroundImageField()->getId();


        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        // Need to do additional checking in case the mentioned datafields were null beforehand
        if ( isset($properties['externalIdField']) && !($properties['externalIdField'] == null || $properties['externalIdField'] == -1) && $datatype->getExternalIdField() == null )
//            print 'external id field changed from null'."\n";
            $changes_made = true;
        if ( isset($properties['nameField']) && !($properties['nameField'] == null || $properties['nameField'] == -1) && $datatype->getNameField() == null )
//            print 'name field changed from null'."\n";
            $changes_made = true;
        if ( isset($properties['sortField']) && !($properties['sortField'] == null || $properties['sortField'] == -1) && $datatype->getSortField() == null )
//            print 'sort field changed from null'."\n";
            $changes_made = true;
        if ( isset($properties['backgroundImageField']) && !($properties['backgroundImageField'] == null || $properties['backgroundImageField'] == -1) && $datatype->getBackgroundImageField() == null )
//            print 'background image field changed from null'."\n";
            $changes_made = true;

        if (!$changes_made)
            return $old_meta_entry;


        // Create a new meta entry and copy the old entry's data over
        $datatype_meta = new DataTypeMeta();
        $datatype_meta->setDataType($datatype);

        $datatype_meta->setRenderPlugin( $old_meta_entry->getRenderPlugin() );
        $datatype_meta->setExternalIdField( $old_meta_entry->getExternalIdField() );
        $datatype_meta->setNameField( $old_meta_entry->getNameField() );
        $datatype_meta->setSortField( $old_meta_entry->getSortField() );
        $datatype_meta->setBackgroundImageField( $old_meta_entry->getBackgroundImageField() );

        $datatype_meta->setSearchSlug( $old_meta_entry->getSearchSlug() );
        $datatype_meta->setShortName( $old_meta_entry->getShortName() );
        $datatype_meta->setLongName( $old_meta_entry->getLongName() );
        $datatype_meta->setDescription( $old_meta_entry->getDescription() );
        $datatype_meta->setXmlShortName( $old_meta_entry->getXmlShortName() );

        $datatype_meta->setUseShortResults( $old_meta_entry->getUseShortResults() );
        $datatype_meta->setDisplayType( $old_meta_entry->getDisplayType() );
        $datatype_meta->setPublicDate( $old_meta_entry->getPublicDate() );

        $datatype_meta->setCreatedBy($user);
        $datatype_meta->setCreated( new \DateTime() );

        // Set any new properties
        if ( isset($properties['renderPlugin']) )
            $datatype_meta->setRenderPlugin( $em->getRepository('ODRAdminBundle:RenderPlugin')->find( $properties['renderPlugin'] ) );

        if ( isset($properties['externalIdField']) ) {
            if ($properties['externalIdField'] == null || $properties['externalIdField'] == -1)
                $datatype_meta->setExternalIdField(null);
            else
                $datatype_meta->setExternalIdField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['externalIdField']) );
        }
        if ( isset($properties['nameField']) ) {
            if ($properties['nameField'] == null || $properties['nameField'] == -1)
                $datatype_meta->setNameField(null);
            else
                $datatype_meta->setNameField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['nameField']) );
        }
        if ( isset($properties['sortField']) ) {
            if ($properties['sortField'] == null || $properties['sortField'] == -1)
                $datatype_meta->setSortField(null);
            else
                $datatype_meta->setSortField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['sortField']) );
        }
        if ( isset($properties['backgroundImageField']) ) {
            if ($properties['backgroundImageField'] == null || $properties['backgroundImageField'] == -1)
                $datatype_meta->setBackgroundImageField(null);
            else
                $datatype_meta->setBackgroundImageField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['backgroundImageField']) );
        }

        if ( isset($properties['searchSlug']) )
            $datatype_meta->setSearchSlug( $properties['searchSlug'] );
        if ( isset($properties['shortName']) )
            $datatype_meta->setShortName( $properties['shortName'] );
        if ( isset($properties['longName']) )
            $datatype_meta->setLongName( $properties['longName'] );
        if ( isset($properties['description']) )
            $datatype_meta->setDescription( $properties['description'] );
        if ( isset($properties['xml_shortName']) )
            $datatype_meta->setXmlShortName( $properties['xml_shortName'] );

        if ( isset($properties['useShortResults']) )
            $datatype_meta->setUseShortResults( $properties['useShortResults'] );
        if ( isset($properties['display_type']) )
            $datatype_meta->setDisplayType( $properties['display_type'] );
        if ( isset($properties['publicDate']) )
            $datatype_meta->setPublicDate( $properties['publicDate'] );

        // Save the new meta entry and delete the old one
        $em->remove($old_meta_entry);
        $em->persist($datatype_meta);
        $em->flush();

        // Return the new entry
        return $datatype_meta;
    }


    /**
     * Creates and persists a new DataFields entity.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                 The user requesting the creation of this entity
     * @param DataType $datatype         
     * @param FieldType $fieldtype       
     * @param RenderPlugin $renderplugin The RenderPlugin for this new DataField to use...(almost?) always going to be the default RenderPlugin
     *
     * @return array
     */
    protected function ODR_addDataFieldsEntry($em, $user, $datatype, $fieldtype, $renderplugin)
    {
        // Poplulate new DataFields form
        $datafield = new DataFields();
        $datafield->setDataType($datatype);

        $datafield->setCreatedBy($user);

        // TODO - delete these properties
        $datafield->setFieldName('New Field');
        $datafield->setDescription('Field description.');
        $datafield->setXmlFieldName('');
        $datafield->setMarkdownText('');
        $datafield->setUpdatedBy($user);
        $datafield->setFieldType($fieldtype);
        $datafield->setIsUnique(false);
        $datafield->setRequired(false);
        $datafield->setSearchable(0);
        $datafield->setUserOnlySearch(false);
        $datafield->setRenderPlugin($renderplugin);
        $datafield->setDisplayOrder(-1);
        $datafield->setChildrenPerRow(1);
        $datafield->setRadioOptionNameSort(0);
        $datafield->setRadioOptionDisplayUnselected(0);
        if ( $fieldtype->getTypeClass() === 'File' || $fieldtype->getTypeClass() === 'Image' ) {
            $datafield->setAllowMultipleUploads(1);
            $datafield->setShortenFilename(1);
        }
        else {
            $datafield->setAllowMultipleUploads(0);
            $datafield->setShortenFilename(0);
        }

        $em->persist($datafield);
        $em->flush();
        $em->refresh($datafield);

        $datafield_meta = new DataFieldsMeta();
        $datafield_meta->setDataField($datafield);
        $datafield_meta->setFieldType($fieldtype);
        $datafield_meta->setRenderPlugin($renderplugin);

        $datafield_meta->setFieldName('New Field');
        $datafield_meta->setDescription('Field description.');
        $datafield_meta->setXmlFieldName('');
        $datafield_meta->setRegexValidator('');
        $datafield_meta->setPhpValidator('');

        $datafield_meta->setMarkdownText('');
        $datafield_meta->setIsUnique(false);
        $datafield_meta->setRequired(false);
        $datafield_meta->setSearchable(0);
        $datafield_meta->setUserOnlySearch(false);

        $datafield_meta->setDisplayOrder(-1);
        $datafield_meta->setChildrenPerRow(1);
        $datafield_meta->setRadioOptionNameSort(0);
        $datafield_meta->setRadioOptionDisplayUnselected(0);
        if ( $fieldtype->getTypeClass() === 'File' || $fieldtype->getTypeClass() === 'Image' ) {
            $datafield_meta->setAllowMultipleUploads(1);
            $datafield_meta->setShortenFilename(1);
        }
        else {
            $datafield_meta->setAllowMultipleUploads(0);
            $datafield_meta->setShortenFilename(0);
        }
        $datafield_meta->setCreatedBy($user);

        $em->persist($datafield_meta);

//        return $datafield;

//        return $datafield_meta;

        return array('datafield' => $datafield, 'datafield_meta' => $datafield_meta);
    }


    /**
     * Modifies a meta entry for a given DataField entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     *
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param DataFields $datafield            The DataField entity of the meta entry being modified
     * @param array $properties
     *
     * @return DataFieldsMeta
     */
    protected function ODR_copyDatafieldMeta($em, $user, $datafield, $properties)
    {
        // Load the old meta entry
        /** @var DataFieldsMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:DataFieldsMeta')->findOneBy( array('dataField' => $datafield->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'fieldType' => $old_meta_entry->getFieldType()->getId(),
            'renderPlugin' => $old_meta_entry->getRenderPlugin()->getId(),

            'fieldName' => $old_meta_entry->getFieldName(),
            'description' => $old_meta_entry->getDescription(),
            'xml_fieldName' => $old_meta_entry->getXmlFieldName(),
            'markdownText' => $old_meta_entry->getMarkdownText(),
            'regexValidator' => $old_meta_entry->getRegexValidator(),
            'phpValidator' => $old_meta_entry->getPhpValidator(),
            'required' => $old_meta_entry->getRequired(),
            'is_unique' => $old_meta_entry->getIsUnique(),
            'allow_multiple_uploads' => $old_meta_entry->getAllowMultipleUploads(),
            'shorten_filename' => $old_meta_entry->getShortenFilename(),
            'displayOrder' => $old_meta_entry->getDisplayOrder(),
            'children_per_row' => $old_meta_entry->getChildrenPerRow(),
            'radio_option_name_sort' => $old_meta_entry->getRadioOptionNameSort(),
            'radio_option_display_unselected' => $old_meta_entry->getRadioOptionDisplayUnselected(),
            'searchable' => $old_meta_entry->getSearchable(),
            'user_only_search' =>$old_meta_entry->getUserOnlySearch(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value)
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;

        // Create a new meta entry and copy the old entry's data over
        $datafield_meta = new DataFieldsMeta();
        $datafield_meta->setDataField($datafield);
        $datafield_meta->setFieldType( $old_meta_entry->getFieldType() );
        $datafield_meta->setRenderPlugin( $old_meta_entry->getRenderPlugin() );

        $datafield_meta->setFieldName( $old_meta_entry->getFieldName() );
        $datafield_meta->setDescription( $old_meta_entry->getDescription() );
        $datafield_meta->setXmlFieldName( $old_meta_entry->getXmlFieldName() );
        $datafield_meta->setMarkdownText( $old_meta_entry->getMarkdownText() );
        $datafield_meta->setRegexValidator( $old_meta_entry->getRegexValidator() );
        $datafield_meta->setPhpValidator( $old_meta_entry->getPhpValidator() );
        $datafield_meta->setRequired( $old_meta_entry->getRequired() );
        $datafield_meta->setIsUnique( $old_meta_entry->getIsUnique() );
        $datafield_meta->setAllowMultipleUploads( $old_meta_entry->getAllowMultipleUploads() );
        $datafield_meta->setShortenFilename( $old_meta_entry->getShortenFilename() );
        $datafield_meta->setDisplayOrder( $old_meta_entry->getDisplayOrder() );
        $datafield_meta->setChildrenPerRow( $old_meta_entry->getChildrenPerRow() );
        $datafield_meta->setRadioOptionNameSort( $old_meta_entry->getRadioOptionNameSort() );
        $datafield_meta->setRadioOptionDisplayUnselected( $old_meta_entry->getRadioOptionDisplayUnselected() );
        $datafield_meta->setSearchable( $old_meta_entry->getSearchable() );
        $datafield_meta->setUserOnlySearch( $old_meta_entry->getUserOnlySearch() );

        $datafield_meta->setCreatedBy($user);
        $datafield_meta->setCreated( new \DateTime() );

        // Set any new properties
        if ( isset($properties['fieldType']) )
            $datafield_meta->setFieldType( $em->getRepository('ODRAdminBundle:FieldType')->find( $properties['fieldType'] ) );
        if ( isset($properties['renderPlugin']) )
            $datafield_meta->setRenderPlugin( $em->getRepository('ODRAdminBundle:RenderPlugin')->find( $properties['renderPlugin'] ) );

        if ( isset($properties['fieldName']) )
            $datafield_meta->setFieldName( $properties['fieldName'] );
        if ( isset($properties['description']) )
            $datafield_meta->setDescription( $properties['description'] );
        if ( isset($properties['xml_fieldName']) )
            $datafield_meta->setXmlFieldName( $properties['xml_fieldName'] );
        if ( isset($properties['markdownText']) )
            $datafield_meta->setMarkdownText( $properties['markdownText'] );
        if ( isset($properties['regexValidator']) )
            $datafield_meta->setRegexValidator( $properties['regexValidator'] );
        if ( isset($properties['phpValidator']) )
            $datafield_meta->setPhpValidator( $properties['phpValidator'] );
        if ( isset($properties['required']) )
            $datafield_meta->setRequired( $properties['required'] );
        if ( isset($properties['is_unique']) )
            $datafield_meta->setIsUnique( $properties['is_unique'] );
        if ( isset($properties['allow_multiple_uploads']) )
            $datafield_meta->setAllowMultipleUploads( $properties['allow_multiple_uploads'] );
        if ( isset($properties['shorten_filename']) )
            $datafield_meta->setShortenFilename( $properties['shorten_filename'] );
        if ( isset($properties['displayOrder']) )
            $datafield_meta->setDisplayOrder( $properties['displayOrder'] );
        if ( isset($properties['children_per_row']) )
            $datafield_meta->setChildrenPerRow( $properties['children_per_row'] );
        if ( isset($properties['radio_option_name_sort']) )
            $datafield_meta->setRadioOptionNameSort( $properties['radio_option_name_sort'] );
        if ( isset($properties['radio_option_display_unselected']) )
            $datafield_meta->setRadioOptionDisplayUnselected( $properties['radio_option_display_unselected'] );
        if ( isset($properties['searchable']) )
            $datafield_meta->setSearchable( $properties['searchable'] );
        if ( isset($properties['user_only_search']) )
            $datafield_meta->setUserOnlySearch( $properties['user_only_search'] );


        // Save the new meta entry and delete the old one
        $em->remove($old_meta_entry);
        $em->persist($datafield_meta);
        $em->flush();

        // Return the new entry
        return $datafield_meta;
    }


    /**
     * Copies the contents of the given ThemeMeta entity into a new ThemeMeta entity if something was changed
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'templateName', 'templateDescription', 'isDefault'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param Theme $theme                     The Theme entity being modified
     * @param array $properties
     *
     * @return ThemeMeta
     */
    protected function ODR_copyThemeMeta($em, $user, $theme, $properties)
    {
        // Load the old meta entry
        /** @var ThemeMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:ThemeMeta')->findOneBy( array('theme' => $theme->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'templateName' => $old_meta_entry->getTemplateName(),
            'templateDescription' => $old_meta_entry->getTemplateDescription(),
            'isDefault' => $old_meta_entry->getIsDefault(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;

        // Create a new meta entry and copy the old entry's data over
        $theme_meta = new ThemeMeta();
        $theme_meta->setTheme($theme);

        $theme_meta->setTemplateName( $old_meta_entry->getTemplateName() );
        $theme_meta->setTemplateDescription( $old_meta_entry->getTemplateDescription() );
        $theme_meta->setIsDefault( $old_meta_entry->getIsDefault() );

        $theme_meta->setCreatedBy($user);
        $theme_meta->setCreated( new \DateTime() );

        // Set any new properties
        if ( isset($properties['templateName']) )
            $theme_meta->setTemplateName( $properties['templateName'] );
        if ( isset($properties['templateDescription']) )
            $theme_meta->setTemplateDescription( $properties['templateDescription'] );
        if ( isset($properties['isDefault']) )
            $theme_meta->setIsDefault( $properties['isDefault'] );

        // Save the new meta entry and delete the old one
        $em->remove($old_meta_entry);
        $em->persist($theme_meta);
        $em->flush();

        // Return the new entry
        return $theme_meta;
    }

    /**
     * Creates and persists a new ThemeElement entity.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user The user requesting the creation of this entity
     * @param DataType $datatype
     * @param Theme $theme
     *
     * @return array
     */
    protected function ODR_addThemeElementEntry($em, $user, $datatype, $theme)
    {
        $theme_element = new ThemeElement();
        $theme_element->setTheme($theme);

        $theme_element->setCreatedBy($user);

        // TODO - delete these six properties
        $theme_element->setDataType($datatype);
        $theme_element->setUpdatedBy($user);
        $theme_element->setDisplayOrder(-1);
        $theme_element->setDisplayInResults(1);
        $theme_element->setCssWidthXL('1-1');
        $theme_element->setCssWidthMed('1-1');

        $em->persist($theme_element);
        $em->flush();
        $em->refresh($theme_element);

        $theme_element_meta = new ThemeElementMeta();
        $theme_element_meta->setThemeElement($theme_element);

        $theme_element_meta->setDisplayOrder(-1);
        $theme_element_meta->setCssWidthMed('1-1');
        $theme_element_meta->setCssWidthXL('1-1');
        $theme_element_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));

        $theme_element_meta->setCreatedBy($user);

        $em->persist($theme_element_meta);

        return array('theme_element' => $theme_element, 'theme_element_meta' => $theme_element_meta);
    }


    /**
     * Modifies a meta entry for a given ThemeElement entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'displayOrder', 'cssWidthMed', 'cssWidthXL', 'publicDate'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                      The user requesting the modification of this meta entry.
     * @param ThemeElement $theme_element     The ThemeElement entity of the meta entry being modified
     * @param array $properties
     *
     * @return ThemeElementMeta
     */
    protected function ODR_copyThemeElementMeta($em, $user, $theme_element, $properties)
    {
        // Load the old meta entry
        /** @var ThemeElementMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:ThemeElementMeta')->findOneBy( array('themeElement' => $theme_element->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'displayOrder' => $old_meta_entry->getDisplayOrder(),
            'cssWidthMed' => $old_meta_entry->getCssWidthMed(),
            'cssWidthXL' => $old_meta_entry->getCssWidthXL(),
            'publicDate' => $old_meta_entry->getPublicDate(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;

        // Create a new meta entry and copy the old entry's data over
        $theme_element_meta = new ThemeElementMeta();
        $theme_element_meta->setThemeElement($theme_element);

        $theme_element_meta->setDisplayOrder( $old_meta_entry->getDisplayOrder() );
        $theme_element_meta->setCssWidthMed( $old_meta_entry->getCssWidthMed() );
        $theme_element_meta->setCssWidthXL( $old_meta_entry->getCssWidthXL() );
        $theme_element_meta->setPublicDate( $old_meta_entry->getPublicDate() );

        $theme_element_meta->setCreatedBy($user);
        $theme_element_meta->setCreated( new \DateTime() );

        // Set any new properties
        if ( isset($properties['displayOrder']) )
            $theme_element_meta->setDisplayOrder( $properties['displayOrder'] );
        if ( isset($properties['cssWidthMed']) )
            $theme_element_meta->setCssWidthMed( $properties['cssWidthMed'] );
        if ( isset($properties['cssWidthXL']) )
            $theme_element_meta->setCssWidthXL( $properties['cssWidthXL'] );
        if ( isset($properties['publicDate']) )
            $theme_element_meta->setPublicDate( $properties['publicDate'] );

        // Save the new meta entry and delete the old one
        $em->remove($old_meta_entry);
        $em->persist($theme_element_meta);
        $em->flush();

        // Return the new entry
        return $theme_element_meta;
    }


    /**
     * Creates and persists a new ThemeElementField entity.
     * @deprecated
     *
     * @param \Doctrine\ORM\EntityManager $em
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
        $theme_element_field->setDisplayOrder(999);

        $em->persist($theme_element_field);

        return $theme_element_field;
    }


    /**
     * Creates and persists a new ThemeDataField entity.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                  The user requesting the creation of this entity.
     * @param DataFields $datafield       The datafield this entry is for
     * @param ThemeElement $theme_element The theme_element this entry is attached to
     *
     * @return ThemeDataField
     */
    protected function ODR_addThemeDataFieldEntry($em, $user, $datafield, $theme_element)
    {
        // Create theme entry
        $theme_datafield = new ThemeDataField();
        $theme_datafield->setDataField($datafield);
        $theme_datafield->setThemeElement($theme_element);

        $theme_datafield->setDisplayOrder(999);
        $theme_datafield->setCssWidthMed('1-3');
        $theme_datafield->setCssWidthXL('1-3');

        $theme_datafield->setCreatedBy($user);

        // TODO - remove these three properties
        $theme_datafield->setTheme($theme_element->getTheme());
        $theme_datafield->setActive(true);
        $theme_datafield->setUpdatedBy($user);

        $em->persist($theme_datafield);
        return $theme_datafield;
    }


    /**
     * Copies the contents of the given ThemeDatafield entity into a new ThemeDatafield entity if something was changed
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'themeElement', 'displayOrder', 'cssWidthMed', 'cssWidthXL'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                      The user requesting the modification of this meta entry.
     * @param ThemeDatafield $theme_datafield The ThemeDatafield entity being modified
     * @param array $properties
     *
     * @return ThemeDataField
     */
    protected function ODR_copyThemeDatafield($em, $user, $theme_datafield, $properties)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'themeElement' => $theme_datafield->getThemeElement()->getId(),

            'displayOrder' => $theme_datafield->getDisplayOrder(),
            'cssWidthMed' => $theme_datafield->getCssWidthMed(),
            'cssWidthXL' => $theme_datafield->getCssWidthXL(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $theme_datafield;

        // Create a new meta entry and copy the old entry's data over
        $new_theme_datafield = new ThemeDataField();
        $new_theme_datafield->setDataField( $theme_datafield->getDataField() );
        $new_theme_datafield->setThemeElement( $theme_datafield->getThemeElement() );

        $new_theme_datafield->setDisplayOrder( $theme_datafield->getDisplayOrder() );
        $new_theme_datafield->setCssWidthMed( $theme_datafield->getCssWidthMed() );
        $new_theme_datafield->setCssWidthXL( $theme_datafield->getCssWidthXL() );

        // TODO - remove these three properties
        $new_theme_datafield->setTheme( $theme_datafield->getTheme() );
        $new_theme_datafield->setActive(true);
        $new_theme_datafield->setUpdatedBy($user);

        $new_theme_datafield->setCreatedBy($user);
        $new_theme_datafield->setCreated(new \DateTime());

        // Set any new properties
        if (isset($properties['themeElement']))
            $new_theme_datafield->setThemeElement( $em->getRepository('ODRAdminBundle:ThemeElement')->find($properties['themeElement']) );

        if (isset($properties['displayOrder']))
            $new_theme_datafield->setDisplayOrder( $properties['displayOrder'] );
        if (isset($properties['cssWidthMed']))
            $new_theme_datafield->setCssWidthMed( $properties['cssWidthMed'] );
        if (isset($properties['cssWidthXL']))
            $new_theme_datafield->setCssWidthXL( $properties['cssWidthXL'] );

        // Save the new meta entry and delete the old one
        $em->remove($theme_datafield);
        $em->persist($new_theme_datafield);
        $em->flush();

        // Return the new entry
        return $new_theme_datafield;
    }


    /**
     * Creates and persists a new ThemeDataType entity.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                  The user requesting the creation of this entity
     * @param DataType $datatype          The datatype this entry is for
     * @param ThemeElement $theme_element The theme_element this entry is attached to
     *
     * @return ThemeDataType
     */
    protected function ODR_addThemeDatatypeEntry($em, $user, $datatype, $theme_element)
    {
        // Create theme entry
        $theme_datatype = new ThemeDataType();
        $theme_datatype->setDataType($datatype);
        $theme_datatype->setThemeElement($theme_element);

        $theme_datatype->setDisplayType(0);     // 0 is accordion, 1 is tabbed, 2 is dropdown, 3 is list

        $theme_datatype->setCreatedBy($user);

        // TODO - remove these two properties
        $theme_datatype->setTheme( $theme_element->getTheme() );
        $theme_datatype->setUpdatedBy($user);

        $em->persist($theme_datatype);
        return $theme_datatype;
    }


    /**
     * Copies the contents of the given ThemeDatatype entity into a new ThemeDatatype entity if something was changed
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'display_type'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                      The user requesting the modification of this meta entry.
     * @param ThemeDataType $theme_datatype   The ThemeDatafield entity being modified
     * @param array $properties
     *
     * @return ThemeDataType
     */
    protected function ODR_copyThemeDatatype($em, $user, $theme_datatype, $properties)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'display_type' => $theme_datatype->getDisplayType(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $theme_datatype;

        // Create a new meta entry and copy the old entry's data over
        $new_theme_datatype = new ThemeDataType();
        $new_theme_datatype->setDataType( $theme_datatype->getDataType() );
        $new_theme_datatype->setThemeElement( $theme_datatype->getThemeElement() );

        $new_theme_datatype->setDisplayType( $theme_datatype->getDisplayType() );

        // TODO - remove these two properties
        $new_theme_datatype->setTheme($theme_datatype->getTheme());
        $new_theme_datatype->setUpdatedBy($user);

        $new_theme_datatype->setCreatedBy($user);
        $new_theme_datatype->setCreated( new \DateTime() );

        // Set any new properties
        if (isset($properties['display_type']))
            $new_theme_datatype->setDisplayType( $properties['display_type'] );

        // Save the new meta entry and delete the old one
        $em->remove($theme_datatype);
        $em->persist($new_theme_datatype);
        $em->flush();

        // Return the new entry
        return $new_theme_datatype;
    }


    /**
     * Usually called after an image is uploaded, this resizes the uploaded image for use in different areas.
     * Will automatically attempt to replace existing thumbnails if possible.
     *
     * NOTE: all thumbnails for the provided image will have their decrypted version deleted off the server...if for some reason you need it immediately after calling this function, you'll have to use decryptObject() to re-create it
     * 
     * @param Image $my_obj The Image that was just uploaded.
     * @param User $user    The user requesting this action
     *
     */
    public function resizeImages(\ODR\AdminBundle\Entity\Image $my_obj, $user)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_image = $em->getRepository('ODRAdminBundle:Image');
//        $user = $this->container->get('security.context')->getToken()->getUser();

        // Create Thumbnails
        /** @var ImageSizes[] $sizes */
        $sizes = $em->getRepository('ODRAdminBundle:ImageSizes')->findBy( array('dataFields' => $my_obj->getDataField()->getId()) );

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
                self::smart_resize_image(
                    $new_file_path,
                    $size->getWidth(),
                    $size->getHeight(),
                    $proportional,
                    'file',
                    false,
                    false
                );

                // Attempt to locate an already existing thumbnail for overwrite
                /** @var Image $image */
                $image = $repo_image->findOneBy( array('parent' => $my_obj->getId(), 'imageSize' => $size->getId()) );

                // If thumbnail doesn't exist, create a new image entity 
                if ( $image == null ) {
                    $image = new Image();
                    $image->setDataField($my_obj->getDataField());
                    $image->setFieldType($my_obj->getFieldType());
                    $image->setDataRecord($my_obj->getDataRecord());
                    $image->setDataRecordFields($my_obj->getDataRecordFields());

                    $image->setOriginal(0);
                    $image->setImageSize($size);
                    $image->setParent($my_obj);
                    $image->setExt($my_obj->getExt());
                    $image->setOriginalChecksum('');

                    $image->setCreatedBy($user);

                    // TODO - delete these six properties
                    $image->setOriginalFileName( $my_obj->getOriginalFileName() );
                    $my_obj->setDisplayorder(0);
                    $my_obj->setPublicDate( $my_obj->getPublicDate() );
                    $my_obj->setCaption( $my_obj->getCaption() );
                    $my_obj->setExternalId('');

                    $image->setUpdatedBy($user);

                    /* DO NOT create a new metadata entry for the thumbnail...all of its metadata properties are slaved to the parent image */
                }
                else {
                    // Ensure that thumbnail has same public date as original image
                    // TODO - delete this property
                    $image->setPublicDate( $my_obj->getPublicDate() );
                }

                $em->persist($image);
                $em->flush();

                // Copy temp file to new file name
                $filename = $image->getUploadDir()."/Image_" . $image->getId() . "." . $ext;
                copy($new_file_path, $filename);
                $image->setLocalFileName($filename);

                /** @var int[] $sizes */
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

                // A decrypted version of this thumbnail still exists on the server...delete it here since all its properties have been saved
                unlink($file_path);
            }
        }
    }


    /**
     * Locates and returns a datarecord based on its external id
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $datafield_id
     * @param string $external_id_value
     *
     * @return DataRecord|null
     */
    protected function getDatarecordByExternalId($em, $datafield_id, $external_id_value)
    {
        // Get required information
        /** @var DataFields $datafield */
        $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
        $typeclass = $datafield->getFieldType()->getTypeClass();

        // Attempt to locate the datarecord using the given external id
        $query = $em->createQuery(
           'SELECT dr
            FROM ODRAdminBundle:'.$typeclass.' AS e
            JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
            WHERE e.dataField = :datafield AND e.value = :value
            AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
        )->setParameters( array('datafield' => $datafield_id, 'value' => $external_id_value) );
        $results = $query->getResult();

        // Return the datarecord if it exists
        $datarecord = null;
        if ( isset($results[0]) )
            $datarecord = $results[0];

        return $datarecord;
    }


    /**
     * Locates and returns a child datarecord based on its external id and its parent's external id
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $child_datafield_id
     * @param string $child_external_id_value
     * @param integer $parent_datafield_id
     * @param string $parent_external_id_value
     *
     * @return DataRecord|null
     */
    protected function getChildDatarecordByExternalId($em, $child_datafield_id, $child_external_id_value, $parent_datafield_id, $parent_external_id_value)
    {
        // Get required information
        $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

        /** @var DataFields $child_datafield */
        $child_datafield = $repo_datafield->find($child_datafield_id);
        $child_typeclass = $child_datafield->getFieldType()->getTypeClass();

        /** @var DataFields $parent_datafield */
        $parent_datafield = $repo_datafield->find($parent_datafield_id);
        $parent_typeclass = $parent_datafield->getFieldType()->getTypeClass();

        // Attempt to locate the datarecord using the given external id
        $query = $em->createQuery(
           'SELECT dr
            FROM ODRAdminBundle:'.$child_typeclass.' AS e_1
            JOIN ODRAdminBundle:DataRecordFields AS drf_1 WITH e_1.dataRecordFields = drf_1
            JOIN ODRAdminBundle:DataRecord AS dr WITH drf_1.dataRecord = dr
            JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
            JOIN ODRAdminBundle:DataRecordFields AS drf_2 WITH drf_2.dataRecord = parent
            JOIN ODRAdminBundle:'.$parent_typeclass.' AS e_2
            WHERE e_1.dataField = :child_datafield AND e_1.value = :child_value AND e_2.dataField = :parent_datafield AND e_2.value = :parent_value
            AND e_1.deletedAt IS NULL AND drf_1.deletedAt IS NULL AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL AND drf_2.deletedAt IS NULL AND e_2.deletedAt IS NULL'
        )->setParameters( array('child_datafield' => $child_datafield_id, 'child_value' => $child_external_id_value, 'parent_datafield' => $parent_datafield_id, 'parent_value' => $parent_external_id_value) );
        $results = $query->getResult();

        // Return the datarecord if it exists
        $datarecord = null;
        if ( isset($results[0]) )
            $datarecord = $results[0];

        return $datarecord;
    }


    /**
     * Utility function to return the column definition for use by the datatables plugin
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param Theme $theme                     The 'table' theme that stores the order of datafields for its datatype
     * 
     * @return array
     */
    public function getDatatablesColumnNames($em, $theme)
    {
        // First and second columns are always datarecord id and sort value, respectively
        $column_names  = '{"title":"datarecord_id","visible":false,"searchable":false},';
        $column_names .= '{"title":"datarecord_sortvalue","visible":false,"searchable":false},';
        $num_columns = 2;

        // Do a query to locate the names of all datafields that can be in the table
        $query = $em->createQuery(
           'SELECT dfm.fieldName AS field_name
            FROM ODRAdminBundle:ThemeElement AS te
            JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
            JOIN ODRAdminBundle:DataFields AS df WITH tdf.dataField = df
            JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
            WHERE te.theme = :theme_id
            AND te.deletedAt IS NULL AND tdf.deletedAt IS NULL AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL
            ORDER BY tdf.displayOrder'
        )->setParameters( array('theme_id' => $theme->getId()) );
        $results = $query->getArrayResult();

        foreach ($results as $num => $data) {
            $fieldname = $data['field_name'];
            $fieldname = str_replace('"', "\\\"", $fieldname);  // escape double-quotes in datafield name
            $column_names .= '{"title":"'.$fieldname.'"},';
            $num_columns++;
        }

        return array('column_names' => $column_names, 'num_columns' => $num_columns);
    }


    /**
     * Rebuilds all cached versions of table themes for the given datarecord.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $datarecord_id
     * @param Request $request
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function Text_GetDisplayData($em, $datarecord_id, Request $request)
    {
        // ----------------------------------------
        // Grab necessary objects
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
        $router = $this->container->get('router');

        /** @var DataRecord $datarecord */
        $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);

        $datatype = $datarecord->getDataType();
        $datatype_id = $datatype->getId();


        // ----------------------------------------
        // Always bypass cache in dev mode
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;

        // Grab the cached version of the requested datatype
        $datatype_data = $memcached->get($memcached_prefix.'.cached_datatype_'.$datatype_id);
        if ($bypass_cache || $datatype_data == false)
            $datatype_data = self::getDatatypeData($em, self::getDatatreeArray($em, $bypass_cache), $datatype_id, $bypass_cache);

        // Grab the cached version of the requested datarecord
        $datarecord_data = $memcached->get($memcached_prefix.'.cached_datarecord_'.$datarecord_id);
        if ($bypass_cache || $datarecord_data == false)
            $datarecord_data = self::getDatarecordData($em, $datarecord_id, $bypass_cache);


//print '<pre>'.print_r($datatype_data, true).'</pre>'; exit();
//print '<pre>'.print_r($datarecord_data, true).'</pre>'; exit();

        // ----------------------------------------
        // Need to build an array to store the data
        $data = array(
            'default_sort_value' => $datarecord->getSortfieldValue(),
            'publicDate' => $datarecord->getPublicDate(),
        );

        foreach ($datatype_data[$datatype_id]['themes'] as $theme_id => $theme) {
            if ($theme['themeType'] == 'table') {
                $data[$theme_id] = array();

                $theme_element = $theme['themeElements'][0];    // only ever one theme element for a table theme
                foreach ($theme_element['themeDataFields'] as $display_order => $tdf) {

                    $df = $tdf['dataField'];
                    $dr = $datarecord_data[$datarecord_id];
                    $render_plugin = $df['dataFieldMeta']['renderPlugin'];

                    $df_id = $tdf['dataField']['id'];
                    $df_value = '';

                    if ($render_plugin['id'] !== 1) {
                        // Run the render plugin for this datafield
                        try {
                            $plugin = $this->get($render_plugin['pluginClassName']);
                            $df_value = $plugin->execute($tdf['dataField'], $datarecord_data[$datarecord_id], $render_plugin, 'table');
                        }
                        catch (\Exception $e) {
                            throw new \Exception( 'Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datafield '.$df['id'].' Datarecord '.$dr['id'].': '.$e->getMessage() );
                        }
                    }
                    else {
                        // Locate this datafield's value from the datarecord array
                        $df_typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                        $drf = $dr['dataRecordFields'][$df_id];

                        switch ($df_typeclass) {
                            case 'Boolean':
                                $df_value = $drf['boolean'][0]['value'];
                                if ($df_value == 1)
                                    $df_value = 'YES';
                                else
                                    $df_value = '';
                                break;
                            case 'IntegerValue':
                                $df_value = $drf['integerValue'][0]['value'];
                                break;
                            case 'DecimalValue':
                                $df_value = $drf['decimalValue'][0]['value'];
                                break;
                            case 'LongText':
                                $df_value = $drf['longText'][0]['value'];
                                break;
                            case 'LongVarchar':
                                $df_value = $drf['longVarchar'][0]['value'];
                                break;
                            case 'MediumVarchar':
                                $df_value = $drf['mediumVarchar'][0]['value'];
                                break;
                            case 'ShortVarchar':
                                $df_value = $drf['shortVarchar'][0]['value'];
                                break;
                            case 'DatetimeValue':
                                $df_value = $drf['datetimeValue'][0]->format('Y-m-d');
                                if ($df_value == '-0001-11-30')
                                    $df_value = '0000-00-00';
                                break;

                            case 'File':
                                if ( isset($drf['file'][0]) ) {
                                    $file = $drf['file'][0];    // should only ever be one file in here anyways

                                    $url = $router->generate( 'odr_file_download', array('file_id' => $file['id']) );
                                    $df_value = '<a href='.$url.'>'.$file['originalFileName'].'</a>';
                                }
                                break;

                            case 'Radio':
                                foreach ($drf['radioSelection'] as $ro_id => $rs) {
                                    if ( $rs['selected'] == 1 ) {
                                        $df_value = $rs['radioOption']['optionName'];
                                        break;
                                    }
                                }
                                break;
                        }
                    }

                    $data[$theme_id][$display_order] = array('id' => $df_id, 'value' => $df_value);
                }
            }
        }

        // Store the resulting array back in memcached before returning it
        $memcached->set($memcached_prefix.'.datarecord_table_data_'.$datarecord_id, $data, 0);
        return $data;
    }


    /**
     * Re-renders and returns a given ShortResults version of a given DataRecord's html.
     * @deprecated
     * 
     * @param Request $request
     * @param integer $datarecord_id The database id of the DataRecord...
     * @param integer $theme_id      The database id of the Theme to use when rendering this DataRecord
     * @param string $template_name  unused
     * 
     * @return string
     */
    public function Short_GetDisplayData(Request $request, $datarecord_id, $theme_id = 2, $template_name = 'default')
    {
        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        // --------------------
        // Attempt to get the user
        $user = 'anon.';
        $token = $this->container->get('security.context')->getToken(); // token will be NULL when called from thh command line
        if ($token != NULL)
            $user = $token->getUser();

        // If this function is being called without a user, grab the 'system' user
        if ($user === 'anon.')
            $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find(3);   // TODO - need an actual system user...
        // --------------------
        /** @var User $user */

        $theme_element = null;

        /** @var Theme $theme */
        $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);

        /** @var DataRecord $datarecord */
        $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
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
     * @deprecated
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
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

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
            $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find(3);   // TODO - need an actual system user...
        }
        // --------------------
        /** @var User $user */

        $theme_element = null;

        /** @var Theme $theme */
        $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);

        /** @var DataRecord $datarecord */
        $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
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
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $datarecord_id
     * @param Request $request
     *
     * @return string
     */
    protected function XML_GetDisplayData($em, $datarecord_id, Request $request)
    {
        try {
            // ----------------------------------------
            // Grab necessary objects
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // All of these should already exist
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            $datatype = $datarecord->getDataType();
            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy(array('dataType' => $datatype->getId(), 'themeType' => 'master'));


            // ----------------------------------------
            // Always bypass cache if in dev mode?
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;


            // Grab all datarecords "associated" with the desired datarecord...
            $associated_datarecords = $memcached->get($memcached_prefix.'.associated_datarecords_for_'.$datarecord_id);
            if ($bypass_cache || $associated_datarecords == false) {
                $associated_datarecords = self::getAssociatedDatarecords($em, array($datarecord_id));

//print '<pre>'.print_r($associated_datarecords, true).'</pre>';  exit();

                $memcached->set($memcached_prefix.'.associated_datarecords_for_'.$datarecord_id, $associated_datarecords, 0);
            }


            // Grab the cached versions of all of the associated datarecords, and store them all at the same level in a single array
            $datarecord_array = array();
            foreach ($associated_datarecords as $num => $dr_id) {
                $datarecord_data = $memcached->get($memcached_prefix.'.cached_datarecord_'.$dr_id);
                if ($bypass_cache || $datarecord_data == false)
                    $datarecord_data = self::getDatarecordData($em, $dr_id, true);

                foreach ($datarecord_data as $dr_id => $data)
                    $datarecord_array[$dr_id] = $data;
            }

//print '<pre>'.print_r($datarecord_array, true).'</pre>';  exit();

            // ----------------------------------------
            //
            $datatree_array = self::getDatatreeArray($em, $bypass_cache);

            // Grab all datatypes associated with the desired datarecord
            $associated_datatypes = array();
            foreach ($datarecord_array as $dr_id => $dr) {
                $dt_id = $dr['dataType']['id'];

                if (!in_array($dt_id, $associated_datatypes))
                    $associated_datatypes[] = $dt_id;
            }

            // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
            $datatype_array = array();
            foreach ($associated_datatypes as $num => $dt_id) {
                $datatype_data = $memcached->get($memcached_prefix.'.cached_datatype_'.$dt_id);
                if ($bypass_cache || $datatype_data == false)
                    $datatype_data = self::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

                foreach ($datatype_data as $dt_id => $data)
                    $datatype_array[$dt_id] = $data;
            }

//print '<pre>'.print_r($datatype_array, true).'</pre>';  exit();


            // ----------------------------------------
            // Determine which template to use for rendering
            $baseurl = $this->container->getParameter('site_baseurl');
            $template = 'ODRAdminBundle:XMLExport:xml_ajax.html.twig';

            // Render the DataRecord
            $using_metadata = true;
            $templating = $this->get('templating');
            $html = $templating->render(
                $template,
                array(
                    'datatype_array' => $datatype_array,
                    'datarecord_array' => $datarecord_array,
                    'theme_id' => $theme->getId(),

                    'initial_datatype_id' => $datatype->getId(),
                    'initial_datarecord_id' => $datarecord->getId(),

                    'using_metadata' => $using_metadata,
                    'baseurl' => $baseurl,
                )
            );

            return $html;
        }
        catch (\Exception $e) {
            throw new \Exception( $e->getMessage() );
        }
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
     * TODO - rework to use the datatype/datarecord arrays instead of querying everything?
     *
     * Ensures the given datarecord and all its child datarecords have datarecordfield entries for all datafields they contain.
     * 
     * @param DataRecord $datarecord
     *
     * @return boolean
     */
    public function verifyExistence($datarecord)
    {
        // Don't do anything to a provisioned datarecord
        if ($datarecord->getProvisioned() == true)
            return false;

$start = microtime(true);
$debug = true;
$debug = false;

if ($debug)
    print '<pre>';

        // ----------------------------------------
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        // Attempt to get the user
        $user = 'anon.';
        $token = $this->container->get('security.context')->getToken(); // token will be NULL when called from the command line
        if ($token != NULL)
            $user = $token->getUser();

        // If this function is being called without a user, grab the 'system' user
        if ($user === 'anon.')
            $user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User')->find(3);   // TODO - need an actual system user...
        /** @var User $user */


        // ----------------------------------------
        // Verify the existence of all fields in this datatype/datarecord first
        self::verifyExistence_worker($em, $user, $datarecord, $debug);


        // ----------------------------------------
        // Verify the existence of all fields in all the child datarecords
        $query = $em->createQuery(
            'SELECT dr
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
            WHERE dr.grandparent = :grandparent AND dr.id != :datarecord AND dr.provisioned = false
            AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters( array('grandparent' => $datarecord->getId(), 'datarecord' => $datarecord->getId()) );
        $childrecords = $query->getResult();

        foreach ($childrecords as $childrecord)
            self::verifyExistence_worker($em, $user, $childrecord, $debug);


        // ----------------------------------------
        // Verify the existence of all fields in all the linked datarecords
        $query = $em->createQuery(
           'SELECT descendant
            FROM ODRAdminBundle:DataRecord AS ancestor
            JOIN ODRAdminBundle:LinkedDataTree AS dt WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataRecord AS descendant WITH dt.descendant = descendant
            WHERE ancestor = :ancestor AND descendant.provisioned = false
            AND ancestor.deletedAt IS NULL AND dt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( array('ancestor' => $datarecord->getId()) );
        $linked_datarecords = $query->getResult();

        foreach ($linked_datarecords as $linked_datarecord)
            self::verifyExistence_worker($em, $user, $linked_datarecord, $debug);


if ($debug) {
    print 'verifyExistence() completed in '.(microtime(true) - $start)."\n";
    print '</pre>';
}

        // empty return
        return true;
    }

    /**
     * Ensures the given datarecord has datarecordfield entries for all datafields it contains.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param DataRecord $datarecord
     * @param boolean $debug
     *
     * @return boolean
     */
    private function verifyExistence_worker($em, $user, $datarecord, $debug)
    {
        // Don't do anything to a provisioned datarecord
        if ( $datarecord->getProvisioned() == true )
            return false;

        // Track whether we need to flush
        $made_change = false;
        $datatype = $datarecord->getDataType();

$start = microtime(true);
if ($debug)
    print "\n---------------\nattempting to verify datarecord ".$datarecord->getId()." of datatype ".$datatype->getId()."...\n";


        $datarecordfield_array = array();
        foreach ($datarecord->getDataRecordFields() as $datarecordfield) {
            /** @var DataRecordFields $datarecordfield */
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
            /** @var DataFields $datafield */
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
            /** @var DataRecordFields $datarecordfield */
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
            if ($type_class == 'Image')
                self::ODR_checkImageSizes($em, $user, $datafield);
        }

if ($debug)
    print "\ndatarecord ".$datarecord->getId()." of datatype ".$datatype->getId()." has been verified in ".(microtime(true) - $start)."\n";

        // Only flush if changes were made
        if ($made_change)
            $em->flush();

        // empty return
        return true;
    }


    /**
     * Ensures both ImageSizes entities for the given datafield exist.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the creation of this entity
     * @param DataFields $datafield
     */
    public function ODR_checkImageSizes($em, $user, $datafield)
    {
        // Attempt to load both ImageSize entities from the database
        $query = $em->createQuery(
           'SELECT image_size
            FROM ODRAdminBundle:ImageSizes AS image_size
            WHERE image_size.dataFields = :datafield
            AND image_size.deletedAt IS NULL'
        )->setParameters( array('datafield' => $datafield->getId()) );
        $results = $query->getArrayResult();

        // Determine if either are missing
        $has_original = false;
        $has_thumbnail = false;

        foreach ($results as $num => $result) {
            $original = $result['original'];
            $image_type = $result['imagetype'];

            if ( $original == true )
                $has_original = true;
            if ( $original == null && $image_type == 'thumbnail' )
                $has_thumbnail = true;
        }

        if (!$has_original) {
            // Create an ImageSize entity for the original image
            $query =
               'INSERT INTO odr_image_sizes (data_fields_id, size_constraint)
                SELECT * FROM (SELECT :datafield AS df_id, :size_constraint AS size_constraint) AS tmp
                WHERE NOT EXISTS (
                    SELECT id FROM odr_image_sizes WHERE data_fields_id = :datafield AND size_constraint = :size_constraint AND deletedAt IS NULL
                ) LIMIT 1;';
            $params = array('datafield' => $datafield->getId(), 'size_constraint' => 'none');

            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);

            // Reload the newly created ImageSize entity
            /** @var ImageSizes $image_size */
            $image_size = $em->getRepository('ODRAdminBundle:ImageSizes')->findOneBy( array('dataFields' => $datafield->getId(), 'size_constraint' => 'none') );

            // Set and save the rest of the properties
            $image_size->setWidth(0);
            $image_size->setHeight(0);
            $image_size->setMinWidth(1024);
            $image_size->setMinHeight(768);
            $image_size->setMaxWidth(0);
            $image_size->setMaxHeight(0);
            $image_size->setFieldType( $datafield->getFieldType() );
            $image_size->setCreated( new \DateTime() );
            $image_size->setCreatedBy($user);
            $image_size->setUpdated( new \DateTime() );
            $image_size->setUpdatedBy($user);
            $image_size->setImagetype(null);
            $image_size->setOriginal(1);
            $em->persist($image_size);
            $em->flush($image_size);
            $em->refresh($image_size);
        }

        if (!$has_thumbnail) {
            // Create an ImageSize entity for the thumbnail
            $query =
               'INSERT INTO odr_image_sizes (data_fields_id, size_constraint)
                SELECT * FROM (SELECT :datafield AS df_id, :size_constraint AS size_constraint) AS tmp
                WHERE NOT EXISTS (
                    SELECT id FROM odr_image_sizes WHERE data_fields_id = :datafield AND size_constraint = :size_constraint AND deletedAt IS NULL
                ) LIMIT 1;';
            $params = array('datafield' => $datafield->getId(), 'size_constraint' => 'both');
            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);

            // Reload the newly created ImageSize entity
            $image_size = $em->getRepository('ODRAdminBundle:ImageSizes')->findOneBy( array('dataFields' => $datafield->getId(), 'size_constraint' => 'both') );

            // Set and save the rest of the properties
            $image_size->setWidth(500);
            $image_size->setHeight(375);
            $image_size->setMinWidth(500);
            $image_size->setMinHeight(375);
            $image_size->setMaxWidth(500);
            $image_size->setMaxHeight(375);
            $image_size->setFieldType( $datafield->getFieldType() );
            $image_size->setCreated( new \DateTime() );
            $image_size->setCreatedBy($user);
            $image_size->setUpdated( new \DateTime() );
            $image_size->setUpdatedBy($user);
            $image_size->setOriginal(0);
            $image_size->setImagetype('thumbnail');
            $em->persist($image_size);
            $em->flush($image_size);
            $em->refresh($image_size);
        }

    }


    /**
     * Returns errors encounted while processing a Symfony Form object as a string.
     * 
     * @param \Symfony\Component\Form\Form $form
     * 
     * @return string
     */
    protected function ODR_getErrorMessages(\Symfony\Component\Form\Form $form)
    {
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
     * @deprecated
     *
     * @param User $user
     * @param Theme $theme
     * @param DataType $datatype                 The datatype to build the tree from.
     * @param ThemeElement $target_theme_element If reloading a 'fieldarea' the theme_element to be reloaded, null otherwise.
     * @param \Doctrine\ORM\EntityManager $em                        
     * @param boolean $is_link                   Whether $datatype is the descendent side of a linked datatype in this context.
     * @param boolean $top_level                 Whether $datatype is a top-level datatype or not.
     * @param boolean $short_form                If true, don't recurse...used for SearchTemplate, ShortResults, and TextResults.
     *
     * @param boolean $debug                     Whether to print debug information or not
     * @param integer $indent                    How "deep" in the tree this function is, effectively...used to print out tabs so debugging output looks nicer
     *
     * @return array
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
        $tree['multiple_allowed'] = 0;
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
        /** @var ThemeDataType $theme_datatype */
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
            /** @var ThemeElement $theme_element */
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
                /** @var ThemeElementField $theme_element_field */
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
                    /** @var ThemeDataField $theme_datafield */
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
                       'SELECT dtm.is_link AS is_link, dtm.multiple_allowed AS multiple_allowed
                        FROM ODRAdminBundle:DataTree AS dt
                        JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                        WHERE dt.ancestor = :ancestor AND dt.descendant = :descendant
                        AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                    )->setParameters( array('ancestor' => $datatype, 'descendant' => $childtype) );
                    $result = $query->getResult();

                    // TODO - empty query results?

                    $tree['has_childtype'] = 1;
                    $top_level = 0;

                    $is_link = 0;
                    if ($result[0]['is_link'] == true)
                        $is_link = 1;
    
                    $multiple_allowed = 0;
                    if ($result[0]['multiple_allowed'] == true)
                        $multiple_allowed = 1;

                    $ted_child['datatype'] = self::buildDatatypeTree($user, $theme, $childtype, null, $em, $is_link, $top_level, $short_form, $debug, $indent+2);
                    $ted_child['datatype']['multiple_allowed'] = $multiple_allowed;
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
     * @deprecated
     *
     * @param DataRecord $datarecord      The datarecord to build the tree from.
     * @param \Doctrine\ORM\EntityManager $em                 
     * @param User $user                  The user to use for building forms.
     * @param boolean $short_form         If true, don't recurse...used for SearchTemplate, ShortResults, and TextResults.
     * @param boolean $use_render_plugins 
     * @param boolean $public_only        If true, don't render non-public items...if false, render everything
     *
     * @param boolean $debug              Whether to print debug information or not
     * @param integer $indent             How "deep" in the tree this function is, effectively...used to print out tabs so debugging output looks nicer
     *
     * @return array
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
                /** @var DataRecordFields $datarecordfield */
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
                FROM ODRAdminBundle:DataRecord AS dr
                JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
                WHERE dr.parent = :datarecord AND dr.id != :datarecord_id AND dr.provisioned = false
                AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $datarecord->getId(), 'datarecord_id' => $datarecord->getId()) );
            $results = $query->getResult();
            foreach ($results as $num => $child_datarecord) {
                /** @var DataRecord $child_datarecord */
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
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                JOIN ODRAdminBundle:DataType AS dt WITH descendant.dataType = dt
                WHERE ldt.ancestor = :datarecord AND descendant.provisioned = false
                AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $datarecord->getId()) );
            $results = $query->getResult();
            foreach ($results as $num => $linked_datarecord) {
                /** @var DataRecord $linked_datarecord */
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
                    /** @var DataRecord $child_datarecord */
                    $child_datarecord = $child_tree[0]['datarecord'];
                    $datatype = $child_datarecord->getDataType();
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
     * @deprecated
     *
     * @param \Doctrine\ORM\EntityManager $em                      
     * @param User $user                       The user to use when rendering this form object
     * @param DataRecord $datarecord           
     * @param DataFields $datafield
     * @param DataRecordFields $datarecordfield
     *
     * @param boolean $debug                   Whether to print out debug info or not
     * @param integer $indent
     *
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
//            $form_obj->setUpdatedBy($user);
            switch($type_class) {
                case 'File':
//                    $form_obj->setGraphable('0');
                    break;
                case 'Image':
                    $form_obj->setOriginal('0');
                    break;
            }
        }

        $form = $this->createForm( new $form_classname($em), $form_obj );
        return $form->createView();
    }


    /**
     * Gets all layout information required for the given datatype in array format
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datatree_array
     * @param integer $datatype_id
     * @param boolean $force_rebuild
     *
     * @return array
     */
    protected function getDatatypeData($em, $datatree_array, $datatype_id, $force_rebuild = false)
    {
/*
$debug = true;
$debug = false;
$timing = true;
$timing = false;

$t0 = $t1 = null;
if ($timing)
    $t0 = microtime(true);
*/

        // If datatype data exists in memcached and user isn't demanding a fresh version, return that
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        $cached_datatype_data = $memcached->get($memcached_prefix.'.cached_datatype_'.$datatype_id);
        if ( !($force_rebuild || $cached_datatype_data == false) )
            return $cached_datatype_data;


        // Otherwise...get all non-layout data for a given grandparent datarecord
        $query = $em->createQuery(
           'SELECT
                t, tm,
                dt, dtm, dt_rp, dt_rpi, dt_rpo, dt_rpm, dt_rpf, dt_rpm_df,
                te, tem,
                tdf, df, ro, rom,
                dfm, ft, df_rp, df_rpi, df_rpo, df_rpm,
                tdt, c_dt

            FROM ODRAdminBundle:dataType AS dt
            LEFT JOIN dt.dataTypeMeta AS dtm

            LEFT JOIN dt.themes AS t
            LEFT JOIN t.themeMeta AS tm

            LEFT JOIN dtm.renderPlugin AS dt_rp
            LEFT JOIN dt_rp.renderPluginInstance AS dt_rpi
            LEFT JOIN dt_rpi.renderPluginOptions AS dt_rpo
            LEFT JOIN dt_rpi.renderPluginMap AS dt_rpm
            LEFT JOIN dt_rpm.renderPluginFields AS dt_rpf
            LEFT JOIN dt_rpm.dataField AS dt_rpm_df

            LEFT JOIN t.themeElements AS te
            LEFT JOIN te.themeElementMeta AS tem

            LEFT JOIN te.themeDataFields AS tdf
            LEFT JOIN tdf.dataField AS df
            LEFT JOIN df.radioOptions AS ro
            LEFT JOIN ro.radioOptionsMeta AS rom

            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN dfm.fieldType AS ft

            LEFT JOIN dfm.renderPlugin AS df_rp
            LEFT JOIN df_rp.renderPluginInstance AS df_rpi
            LEFT JOIN df_rpi.renderPluginOptions AS df_rpo
            LEFT JOIN df_rpi.renderPluginMap AS df_rpm

            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.dataType AS c_dt

            WHERE
                dt.id = :datatype_id AND (dt_rpi IS NULL OR dt_rpi.dataType = :datatype_id)
                AND t.deletedAt IS NULL AND dt.deletedAt IS NULL AND te.deletedAt IS NULL
            ORDER BY dt.id, t.id, tem.displayOrder, te.id, tdf.displayOrder, df.id, rom.displayOrder, ro.id'
        )->setParameters( array('datatype_id' => $datatype_id) );

        //LEFT JOIN df_rp.renderPluginFields AS df_rpf
//print $query->getSQL();

        $datatype_data = $query->getArrayResult();
/*
if ($timing) {
    $t1 = microtime(true);
    $diff = $t1 - $t0;
    print 'getLayoutData('.$datatype_id.')'."\n".'query execution in: '.$diff."\n";
}
*/

        // The entity -> entity_metadata relationships have to be one -> many from a database perspective, even though there's only supposed to be a single non-deleted entity_metadata object for each entity
        // Therefore, the preceeding query generates an array that needs to be slightly flattened in a few places
        foreach ($datatype_data as $dt_num => $dt) {
            // Flatten datatype meta
            $dtm = $dt['dataTypeMeta'][0];
            $datatype_data[$dt_num]['dataTypeMeta'] = $dtm;

            // Flatten theme_meta of each theme, and organize by theme id instead of a random number
            $new_theme_array = array();
            foreach ($dt['themes'] as $t_num => $theme) {

                $theme_id = $theme['id'];

                $tm = $theme['themeMeta'][0];
                $theme['themeMeta'] = $tm;

                // Flatten theme_element_meta of each theme_element
                foreach ($theme['themeElements'] as $te_num => $te) {
                    $tem = $te['themeElementMeta'][0];
                    $theme['themeElements'][$te_num]['themeElementMeta'] = $tem;

                    // Flatten datafield_meta of each datafield
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        $dfm = $tdf['dataField']['dataFieldMeta'][0];
                        $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['dataFieldMeta'] = $dfm;

                        // Flatten radio options if it exists
                        foreach ($tdf['dataField']['radioOptions'] as $ro_num => $ro) {
                            $rom = $ro['radioOptionsMeta'][0];
                            $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['radioOptions'][$ro_num]['radioOptionsMeta'] = $rom;
                        }
                        if ( count($tdf['dataField']['radioOptions']) == 0 )
                            unset( $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['radioOptions'] );
                    }

                    // Attach the is_link property to each of the theme_datatype entries
                    foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                        $child_datatype_id = $tdt['dataType']['id'];
                        if ( isset($datatree_array['linked_from'][$child_datatype_id]) && $datatree_array['linked_from'][$child_datatype_id] == $datatype_id )
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['is_link'] = 1;
                        else
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['is_link'] = 0;

                        if ( isset($datatree_array['multiple_allowed'][$child_datatype_id]) && $datatree_array['multiple_allowed'][$child_datatype_id] == $datatype_id )
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['multiple_allowed'] = 1;
                        else
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['multiple_allowed'] = 0;
                    }

                    // Easier on twig if these arrays simply don't exist if nothing is in them...
                    if ( count($te['themeDataFields']) == 0 )
                        unset( $theme['themeElements'][$te_num]['themeDataFields'] );
                    if ( count($te['themeDataType']) == 0 )
                        unset( $theme['themeElements'][$te_num]['themeDataType'] );
                }

                $new_theme_array[$theme_id] = $theme;
            }

            unset( $datatype_data[$dt_num]['themes'] );
            $datatype_data[$dt_num]['themes'] = $new_theme_array;
        }

        // Organize by datatype id
        $formatted_datatype_data = array();
        foreach ($datatype_data as $num => $dt_data) {
            $dt_id = $dt_data['id'];

            $formatted_datatype_data[$dt_id] = $dt_data;
        }

        $memcached->set($memcached_prefix.'.cached_datatype_'.$datatype_id, $formatted_datatype_data, 0);
        return $formatted_datatype_data;
    }


    /**
     * Runs a single database query to get all non-layout data for a given grandparent datarecord.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $grandparent_datarecord_id
     * @param boolean $force_rebuild
     *
     * @return array
     */
    protected function getDatarecordData($em, $grandparent_datarecord_id, $force_rebuild = false)
    {
/*
$debug = true;
$debug = false;
$timing = true;
$timing = false;

$t0 = $t1 = null;
if ($timing)
    $t0 = microtime(true);
*/
        // If datarecord data exists in memcached and user isn't demanding a fresh version, return that
        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        $cached_datarecord_data = $memcached->get($memcached_prefix.'.cached_datarecord_'.$grandparent_datarecord_id);
        if ( !($force_rebuild || $cached_datarecord_data == false) )
            return $cached_datarecord_data;


        // Otherwise...get all non-layout data for a given grandparent datarecord
        $query = $em->createQuery(
           'SELECT
               dr, drm, p_dr, gp_dr,
               dt,
               drf, e_f, e_fm, e_f_cb,
               e_i, e_im, e_ip, e_ipm, e_is, e_ip_cb,
               e_b, e_iv, e_dv, e_lt, e_lvc, e_mvc, e_svc, e_dtv, rs, ro,
               e_b_cb, e_iv_cb, e_dv_cb, e_lt_cb, e_lvc_cb, e_mvc_cb, e_svc_cb, e_dtv_cb, rs_cb,
               df

            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.dataRecordMeta AS drm
            LEFT JOIN dr.parent AS p_dr
            LEFT JOIN dr.grandparent AS gp_dr

            LEFT JOIN dr.dataType AS dt

            LEFT JOIN dr.dataRecordFields AS drf
            LEFT JOIN drf.file AS e_f
            LEFT JOIN e_f.fileMeta AS e_fm
            LEFT JOIN e_f.createdBy AS e_f_cb

            LEFT JOIN drf.image AS e_i
            LEFT JOIN e_i.imageMeta AS e_im
            LEFT JOIN e_i.parent AS e_ip
            LEFT JOIN e_ip.imageMeta AS e_ipm
            LEFT JOIN e_i.imageSize AS e_is
            LEFT JOIN e_ip.createdBy AS e_ip_cb

            LEFT JOIN drf.boolean AS e_b
            LEFT JOIN e_b.createdBy AS e_b_cb
            LEFT JOIN drf.integerValue AS e_iv
            LEFT JOIN e_iv.createdBy AS e_iv_cb
            LEFT JOIN drf.decimalValue AS e_dv
            LEFT JOIN e_dv.createdBy AS e_dv_cb
            LEFT JOIN drf.longText AS e_lt
            LEFT JOIN e_lt.createdBy AS e_lt_cb
            LEFT JOIN drf.longVarchar AS e_lvc
            LEFT JOIN e_lvc.createdBy AS e_lvc_cb
            LEFT JOIN drf.mediumVarchar AS e_mvc
            LEFT JOIN e_mvc.createdBy AS e_mvc_cb
            LEFT JOIN drf.shortVarchar AS e_svc
            LEFT JOIN e_svc.createdBy AS e_svc_cb
            LEFT JOIN drf.datetimeValue AS e_dtv
            LEFT JOIN e_dtv.createdBy AS e_dtv_cb
            LEFT JOIN drf.radioSelection AS rs
            LEFT JOIN rs.createdBy AS rs_cb
            LEFT JOIN rs.radioOption AS ro

            LEFT JOIN drf.dataField AS df

            WHERE
                dr.grandparent = :grandparent_id
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND df.deletedAt IS NULL
                AND (e_i IS NULL OR e_i.original = 0)'
        )->setParameters(array('grandparent_id' => $grandparent_datarecord_id));

//print $query->getSQL();

        $datarecord_data = $query->getArrayResult();
/*
if ($debug) {
    print '<pre>';
    print_r($datarecord_data);
    print '</pre>';
    exit();
}
if ($timing) {
    $t1 = microtime(true);
    $diff = $t1 - $t0;
    print 'getDatarecordData('.$grandparent_datarecord_id.')'."\n".'query execution in: '.$diff."\n";
}
*/
        // The entity -> entity_metadata relationships have to be one -> many from a database perspective, even though there's only supposed to be a single non-deleted entity_metadata object for each entity
        // Therefore, the preceeding query generates an array that needs to be slightly flattened in a few places
        foreach ($datarecord_data as $dr_num => $dr) {
            // Flatten datarecord_meta
            $drm = $dr['dataRecordMeta'][0];
            $datarecord_data[$dr_num]['dataRecordMeta'] = $drm;

            // Flatten datafield_meta of each datarecordfield, and organize by datafield id instead of some random number
            $new_drf_array = array();
            foreach ($dr['dataRecordFields'] as $drf_num => $drf) {

                $df_id = $drf['dataField']['id'];
                unset( $drf['dataField'] );

                // Flatten file metadata and get rid of encrypt_key
                foreach ($drf['file'] as $file_num => $file) {
                    unset( $drf['file'][$file_num]['encrypt_key'] ); // TODO - should encrypt_key actually remain in the array?

                    $fm = $file['fileMeta'][0];
                    $drf['file'][$file_num]['fileMeta'] = $fm;

                    // Get rid of all private/non-essential information in the createdBy association
                    $drf['file'][$file_num]['createdBy'] = self::cleanUserData( $drf['file'][$file_num]['createdBy'] );
                }

                // Flatten image metadata and get rid of both the thumbnail's and the parent's encrypt keys
                foreach ($drf['image'] as $image_num => $image) {
                    unset( $drf['image'][$image_num]['encrypt_key'] );
                    unset( $drf['image'][$image_num]['parent']['encrypt_key'] ); // TODO - should encrypt_key actually remain in the array?

                    unset( $drf['image'][$image_num]['imageMeta'] );
                    $im = $image['parent']['imageMeta'][0];
                    $drf['image'][$image_num]['parent']['imageMeta'] = $im;

                    // Get rid of all private/non-essential information in the createdBy association
                    $drf['image'][$image_num]['parent']['createdBy'] = self::cleanUserData( $drf['image'][$image_num]['parent']['createdBy'] );
                }

                // Scrub all user information from the rest of the array
                $keys = array('boolean', 'integerValue', 'decimalValue', 'longText', 'longVarchar', 'mediumVarchar', 'shortVarchar', 'datetimeValue');
                foreach ($keys as $typeclass) {
                    if ( count($drf[$typeclass]) > 0 )
                        $drf[$typeclass][0]['createdBy'] = self::cleanUserData( $drf[$typeclass][0]['createdBy'] );
                }

                // Organize radio selections by radio option id
                $new_rs_array = array();
                foreach ($drf['radioSelection'] as $rs_num => $rs) {
                    $rs['createdBy'] = self::cleanUserData( $rs['createdBy'] );

                    $ro_id = $rs['radioOption']['id'];
                    $new_rs_array[$ro_id] = $rs;
                }

                $drf['radioSelection'] = $new_rs_array;
                $new_drf_array[$df_id] = $drf;
            }

            unset( $datarecord_data[$dr_num]['dataRecordFields'] );
            $datarecord_data[$dr_num]['dataRecordFields'] = $new_drf_array;
        }

        // Organize by datarecord id...DO NOT even attenpt to make this array recursive
        $formatted_datarecord_data = array();
        foreach ($datarecord_data as $num => $dr_data) {
            $dr_id = $dr_data['id'];

            $formatted_datarecord_data[$dr_id] = $dr_data;
        }

        $memcached->set($memcached_prefix.'.cached_datarecord_'.$grandparent_datarecord_id, $formatted_datarecord_data, 0);
        return $formatted_datarecord_data;
    }


    /**
     * Removes all private/non-essential user info from an array generated by self::getDatarecordData() or self::getDatatypeData()
     *
     * @param array $user_data
     *
     * @return array
     */
    private function cleanUserData($user_data)
    {
        foreach ($user_data as $key => $value) {
            if ($key !== 'username' && $key !== 'email' && $key !== 'firstName' && $key !== 'lastName'/* && $key !== 'institution' && $key !== 'position'*/)
                unset( $user_data[$key] );
        }

        return $user_data;
    }


    /**
     * Builds and returns a list of all child and linked datatype ids related to the given datatype id.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param int[] $datatype_ids
     * @param boolean $include_links
     *
     * @return int[]
     */
    protected function getAssociatedDatatypes($em, $datatype_ids, $include_links = true)
    {
        // Locate all datatypes that are either children of or linked to the datatypes in $datatype_ids
        $results = array();
        if ($include_links) {
            $query = $em->createQuery(
               'SELECT descendant.id AS descendant_id
                FROM ODRAdminBundle:DataTree AS dt
                LEFT JOIN dt.dataTreeMeta AS dtm
                LEFT JOIN dt.ancestor AS ancestor
                LEFT JOIN dt.descendant AS descendant
                WHERE ancestor.id IN (:ancestor_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('ancestor_ids' => $datatype_ids) );
            $results = $query->getArrayResult();
        }
        else {
            $query = $em->createQuery(
               'SELECT descendant.id AS descendant_id
                FROM ODRAdminBundle:DataTree AS dt
                LEFT JOIN dt.dataTreeMeta AS dtm
                LEFT JOIN dt.ancestor AS ancestor
                LEFT JOIN dt.descendant AS descendant
                WHERE dtm.is_link = :is_link AND ancestor.id IN (:ancestor_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('is_link' => 0, 'ancestor_ids' => $datatype_ids) );
            $results = $query->getArrayResult();
        }

        // Flatten the resulting array...
        $child_datatype_ids = array();
        foreach ($results as $num => $result)
            $child_datatype_ids[] = $result['descendant_id'];

        // If child datatypes were found, also see if those child datatypes have children of their own
        if ( count($child_datatype_ids) > 0 )
            $child_datatype_ids = array_merge( $child_datatype_ids, self::getAssociatedDatatypes($em, $child_datatype_ids, $include_links) );

        // Return an array of the requested datatype ids and their children
        $associated_datatypes = array_unique( array_merge($child_datatype_ids, $datatype_ids) );
        return $associated_datatypes;
    }


    /**
     * Builds and returns a list of all child and linked datarecords of the given datarecord id.
     * Due to recursive interaction with self::getLinkedDatarecords(), this function doesn't attempt to store results in the cache.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param int[] $grandparent_ids
     *
     * @return int[]
     */
    protected function getAssociatedDatarecords($em, $grandparent_ids)
    {
        // Locate all datarecords that are children of the datarecords listed in $grandparent_ids
        $query = $em->createQuery(
           'SELECT dr.id AS id
            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.grandparent AS grandparent
            WHERE grandparent.id IN (:grandparent_ids)
            AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( array('grandparent_ids' => $grandparent_ids) );
        $results = $query->getArrayResult();

        // Flatten the results array
        $datarecord_ids = array();
        foreach ($results as $result)
            $datarecord_ids[] = $result['id'];

        // Get all children and datarecords linked to all the datarecords in $datarecord_ids
        $linked_datarecord_ids = self::getLinkedDatarecords($em, $datarecord_ids);

        // Don't want any duplicate datarecord ids...
        $associated_datarecord_ids = array_unique( array_merge($grandparent_ids, $linked_datarecord_ids) );

        return $associated_datarecord_ids;
    }


    /**
     * Builds and returns a list of all datarecords linked to from the provided datarecord ids.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer[] $ancestor_ids
     *
     * @return integer[]
     */
    private function getLinkedDatarecords($em, $ancestor_ids)
    {
        // Locate all datarecords that are linked to from any datarecord listed in $datarecord_ids
        $query = $em->createQuery(
           'SELECT descendant.id AS descendant_id
            FROM ODRAdminBundle:LinkedDataTree AS ldt
            JOIN ldt.ancestor AS ancestor
            JOIN ldt.descendant AS descendant
            WHERE ancestor.id IN (:ancestor_ids)
            AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( array('ancestor_ids' => $ancestor_ids) );
        $results = $query->getArrayResult();

        // Flatten the results array
        $linked_datarecord_ids = array();
        foreach ($results as $result)
            $linked_datarecord_ids[] = $result['descendant_id'];

        // If there were datarecords found, get all of their associated child/linked datarecords
        $associated_datarecord_ids = array();
        if ( count($linked_datarecord_ids) > 0 )
            $associated_datarecord_ids = self::getAssociatedDatarecords($em, $linked_datarecord_ids);

        // Don't want any duplicate datarecord ids...
        $linked_datarecord_ids = array_unique( array_merge($linked_datarecord_ids, $associated_datarecord_ids) );

        return $linked_datarecord_ids;
    }
}
