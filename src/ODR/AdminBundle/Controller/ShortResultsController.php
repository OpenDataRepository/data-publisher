<?php

/**
* Open Data Repository Data Publisher
* ShortResults Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The ShortResults controller handles requests to view lists of
* datarecords in either ShortResults or TextResults format.  This
* controller also handles requests to reload the ShortResults variant
* of a single datarecord, for when the user wishes to immediately
* view a ShortReseults variant that was not in memcached.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\File;
// Forms
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\ShortVarcharForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Cookie;


class ShortResultsController extends ODRCustomController
{

    /**
     * Returns the ShortResults version of all DataRecords for a given DataType, with pagination if necessary.
     *
     * @param integer $datatype_id
     * @param string $target Whether to load the Results or the Record version of the DataRecord when the ShortResults version is clicked.
     * @param integer $offset Which page of DataRecords to render.
     * @param Request $request
     *
     * @return a Symfony JSON response containing HTML TODO
     */
    public function listAction($datatype_id, $target, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $search_slug = '';

        try {
            // Get Entity Manager and setup objects
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $templating = $this->get('templating');

            // Load entity manager and repositories
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');

            // Grab datatype
            $datatype = $repo_datatype->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            $search_slug = $datatype->getSearchSlug();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = array();

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
                $user_permissions = parent::getPermissionsArray($user->getId(), $request);

                // If datatype is not public and user doesn't have view permissions, they can't view
                if ( !$datatype->isPublic() && !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'view' ])) )
                    return parent::permissionDeniedError('view');
            }
            // --------------------


            // -----------------------------------
            // Count how many DataRecords would be displayed
            $datarecord_str = '';
            if ( $user === 'anon.' ) {      // <-- 'anon.' indicates no user logged in...
                // Build a query to only get ids of public datarecords
                $query = $em->createQuery(
                   'SELECT dr.id
                    FROM ODRAdminBundle:DataRecord dr
                    WHERE dr.publicDate NOT LIKE :date AND dr.dataType = :dataType AND dr.deletedAt IS NULL'
                )->setParameters( array('date' => "2200-01-01 00:00:00", 'dataType' => $datatype) );
                $results = $query->getResult();

                // Flatten the array
                $subset_str = '';
                foreach ($results as $num => $result)
                    $subset_str .= $result['id'].',';
                $subset_str = substr($subset_str, 0, strlen($subset_str)-1);

                // Grab the sorted list of only the public DataRecords for this DataType
                $datarecord_str = parent::getSortedDatarecords($datatype, $subset_str);
            }
            else {
                // Grab a sorted list of all DataRecords for this DataType
                $datarecord_str = parent::getSortedDatarecords($datatype);
            }

            // ----------------------------------
            // If no datarecords are viewable according to previous step, ensure explode() doesn't create a single array entry
            $datarecord_list = array();
            if ( trim($datarecord_str) !== '' )
                $datarecord_list = explode(',', $datarecord_str);


            // -----------------------------------
            // Bypass list entirely if only one datarecord
            if ( count($datarecord_list) == 1 ) {
                $datarecord_id = $datarecord_list[0];

                // Can't use $this->redirect, because it won't update the hash...
                $return['r'] = 2;
                if ($target == 'results')
                    $return['d'] = array( 'url' => $this->generateURL('odr_results_view', array('datarecord_id' => $datarecord_id)) );
                else if ($target == 'record')
                    $return['d'] = array( 'url' => $this->generateURL('odr_record_edit', array('datarecord_id' => $datarecord_id)) );

                $response = new Response(json_encode($return));
                $response->headers->set('Content-Type', 'application/json');
                return $response;
            }

            
            // -----------------------------------
            // TODO - THIS IS A TEMP FIX
            if ($datatype->getUseShortResults() == 1)
                $theme = $repo_theme->find(2);  // shortresults
            else
                $theme = $repo_theme->find(4);  // textresults


            // Render and return the page
            $search_key = '';
            $path_str = $this->generateUrl('odr_shortresults_list', array('datatype_id' => $datatype->getId(), 'target' => $target));   // TODO - this system needs to be reworked too...asdf;lkj

            $html = parent::renderList($datarecord_list, $datatype, $theme, $user, $path_str, $target, $search_key, $offset, $request);

             $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $html,
            );
        }
        catch (\Exception $e) {
            $search_slug = '';

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x1283830028 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        if ($search_slug != '') {
            $response->headers->setCookie(new Cookie('prev_searched_datatype', $search_slug));
        }
        return $response;  
    }


    /**
     * Returns the ShortResults/TextResults version of this datarecord...triggered when the user clicks a "reload html for this datarecord" button after a cache failure
     * TODO - currently never given the option to reload a textresults entry...they always exist from a user's point of view
     *
     * @param integer $datarecord_id Which datarecord needs to render ShortResults/TextResults for
     * @param string $force          ..currently, whether to load ShortResults, TextResults, or whatever is default for the DataType
     * @param Request $request
     *
     * @return a Symfony JSON response containing the HTML of the Short/Textresult re-render
     */
    public function reloadAction($datarecord_id, $force, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Current User
            $user = $this->container->get('security.context')->getToken()->getUser();
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $templating = $this->get('templating');

            $em = $this->getDoctrine()->getManager();
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                return parent::deletedEntityError('DataRecord');
            $datatype = $datarecord->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');


            // Attempt to load the datarecord from the cache...
            $html = '';
            $data = null;
//            if ($force == 'short')
                $data = $memcached->get($memcached_prefix.'.data_record_short_form_'.$datarecord->getId());
/*
            else if ($force == 'text')
                $data = $memcached->get($memcached_prefix.'.data_record_short_text_form_'.$datarecord->getId());
            else if ($datatype->getUseShortResults() == 1)
                $data = $memcached->get($memcached_prefix.'.data_record_short_form_'.$datarecord->getId());
            else
                $data = $memcached->get($memcached_prefix.'.data_record_short_text_form_'.$datarecord->getId());
*/

            // No caching in dev environment
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $data = null;

            if ($data == null/* || $data['revision'] < $datatype->getRevision()*/) {
                // ...otherwise, ensure all the entities exist before rendering and caching the short form of the DataRecord
                parent::verifyExistence($datatype, $datarecord);
//                if ($force == 'short') {
                    $html = parent::Short_GetDisplayData($request, $datarecord->getId());
                    $data = array( 'revision' => $datatype->getRevision(), 'html' => $html );
                    $memcached->set($memcached_prefix.'.data_record_short_form_'.$datarecord->getId(), $data, 0);
/*
                }
                else if ($force == 'text') {
                    $html = parent::Text_GetDisplayData($request, $datarecord->getId());
                    $data = array( 'revision' => $datatype->getRevision(), 'html' => $html );
                    $memcached->set($memcached_prefix.'.data_record_short_text_form_'.$datarecord->getId(), $data, 0);
                }
                else if ($datatype->getUseShortResults() == 1) {
                    $html = parent::Short_GetDisplayData($request, $datarecord->getId());
                    $data = array( 'revision' => $datatype->getRevision(), 'html' => $html );
                    $memcached->set($memcached_prefix.'.data_record_short_form_'.$datarecord->getId(), $data, 0);
                }
                else {
                    $html = parent::Text_GetDisplayData($request, $datarecord->getId());
                    $data = array( 'revision' => $datatype->getRevision(), 'html' => $html );
                    $memcached->set($memcached_prefix.'.data_record_short_text_form_'.$datarecord->getId(), $data, 0);
                }
*/
                // Update all cache entries for this datarecord
                $options = array();
                parent::updateDatarecordCache($datarecord->getId(), $options);
            }
            else {
                // If the memcache entry exists, grab the html
                $html = $data['html'];
            }

            $return['d'] = array(
//                'force' => $force,
//                'use_shortresults' => $datatype->getUseShortResults(),
                'datarecord_id' => $datarecord_id,
                'html' => $html,
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x178823602 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO - this was a sitemap function
     * Returns a JSON object containing an array of all DataRecord IDs and the contents of their unique (or sort) datafield.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return TODO
     */
    public function recordlistAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'json';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $datatype = $repo_datatype->find($datatype_id);

            // Attempt to locate a datafield we can use to 'name' the DataRecord...try the name field first
            $skip = false;
            $datafield = $datatype->getNameField();
            if ($datafield === null) {
                // name field failed, try the sort field
                $datafield = $datatype->getSortField();

                if ($datafield === null) {
                    $return['d'] = 'Error!';
                    $skip = true;
                }
            }

            // If the attempt to locate the 'name' DataRecord didn't fail
            if (!$skip) {
                // Grab all DataRecords of this DataType
                $datarecords = $repo_datarecord->findByDataType($datatype);

                // Grab the IDs and 'names' of all the DataRecords
                $type_class = $datafield->getFieldType()->getTypeClass();
                $data = array();
                foreach ($datarecords as $dr) {
                    // 
                    $drf = $repo_datarecordfield->findOneBy( array('dataRecord' => $dr->getId(), 'dataField' => $datafield->getId()) );
//                    $entity = parent::loadFromDataRecordField($drf, $type_class);
//                    $name = $entity->getValue();
                    $name = $drf->getAssociatedEntity()->getValue();

                    //
                    $data[$dr->getId()] = $name;
                }

                $return['d'] = $data;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x122280082 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    /**
     * TODO - this was a sitemap function
     * Builds and returns...TODO
     * 
     * @param Integer $datatype_id
     * @param string $datatype_name
     * @param Request $request
     * 
     * @return TODO
     */
    public function mapAction($datatype_id, $datatype_name, Request $request)
    {
        $return = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $templating = $this->get('templating');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Grab the desired datarecord
            $datatype = $repo_datatype->find($datatype_id);
            $datarecords = $repo_datarecord->findBy( array('dataType' => $datatype->getId()) );

            // Grab the short form HTML of each of the datarecords to be displayed
            $user = 'anon.';
            $cache_html = '';
            foreach ($datarecords as $datarecord) {
                if (/*$user !== 'anon.' || */$datarecord->isPublic()) {                 // <-- 'anon.' indicates no user logged in, though i believe this action is only accessed when somebody is logged in

                    // Attempt to load the datarecord from the cache...
                    $html = $memcached->get($memcached_prefix.'.data_record_short_form_'.$datarecord->getId());

                    // No caching in dev environment
                    if ($this->container->getParameter('kernel.environment') === 'dev')
                        $html = null;

                    if ($html != null) {
                        // ...if the html exists, append to the current list and continue
                        $cache_html .= $html;
                    }
                    else {
                        // ...otherwise, ensure all the entities exist before rendering the short form of the DataRecord
                        parent::verifyExistence($datatype, $datarecord);
                        $html = parent::Short_GetDisplayData($request, $datarecord->getId());

                        // Cache the html
                        $memcached->set($memcached_prefix.'.data_record_short_form_'.$datarecord->getId(), $html, 0);

                        $cache_html .= $html;
                    }
                }
            }

            // Render the javascript redirect
            $prefix = '/app_dev.php/search#';
            $redirect_str = $this->generateUrl( 'odr_shortresults_list', array('datatype_id' => $datatype_id, 'target' => 'results') );
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


    /**
     * Takes an AJAX request from the jQuery DataTables plugin and builds an array of TextResults rows for the plugin to display.
     * @see http://datatables.net/manual/server-side
     *
     * @param Request $request
     *
     * @return a JSON array TODO
     */
    public function datatablesrowrequestAction(Request $request)
    {
        $return = array();
        $return['data'] = '';

        try {
            $session = $request->getSession();

            // Grab data from post...
            $post = $_POST;
//print_r($post);
//return;

            $datatype_id = intval( $post['datatype_id'] );
            $draw = intval( $post['draw'] );    // intval because of recommendation by datatables documentation
            $start = intval( $post['start'] );

            // Need to deal with requests for a sorted table...
            $sort_column = 0;
            $sort_dir = 'asc';
            if ( isset($post['order']) && isset($post['order']['0']) ) {
                $sort_column = $post['order']['0']['column'];
                $sort_dir = strtoupper( $post['order']['0']['dir'] );
            }

            // Deal with pagelength changes...
            $length = intval( $post['length'] );
//            $session->set('shortresults_page_length', $length);

            // search_key is optional
            $search_key = '';
            if ( isset($post['search_key']) )
                $search_key = urldecode($post['search_key']);   // apparently have to decode this because it's coming through a POST request instead of a GET?


            // Get Entity Manager and setup objects
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            $datatype = $repo_datatype->find($datatype_id);

            $datarecord_count = 0;
            $list = array();
            if ( $search_key == '' ) {
                // Grab the sorted list of datarecords for this datatype
                $list = parent::getSortedDatarecords($datatype);

                // TODO - reaaaaaaaaallly need to get the above method to change its return type depending on user needs
                $list = explode(',', $list);
            }
            else {
                // ----------------------------------------
                // Get all datarecords from the search key
                // TODO - The following block is practically duplicated in multiple places
                $logged_in = true;
                $encoded_search_key = '';
                $datarecords = '';
                $session = $request->getSession();
                if ($search_key !== '') {
                    $search_controller = $this->get('odr_search_controller', $request);
                    $search_controller->setContainer($this->container);

                    if ( !$session->has('saved_searches') ) {
                        // no saved searches at all for some reason, redo the search with the given search key...
                        $search_controller->performSearch($search_key, $request);
                    }

                    // Grab the list of saved searches and attempt to locate the desired search
                    $saved_searches = $session->get('saved_searches');
                    $search_checksum = md5($search_key);

                    if ( !isset($saved_searches[$search_checksum]) ) {
                        // no saved search for this query, redo the search...
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
                    $datarecords = $search_params['datarecords'];
                    $encoded_search_key = $search_params['encoded_search_key'];
                }

                if ( trim($datarecords) !== '')
                    $list = explode(',', $datarecords);

/*
                // If the user is attempting to view a datarecord from a search that returned no results...
                if ($encoded_search_key !== '' && $datarecords === '') {
                    // ...redirect to "no results found" page
                    return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
                }
*/

            }


            // Save how many records total there are before filtering...
            $datarecord_count = count($list);

            if ($sort_column >= 2) {
                // adjust datatables column number to datafield display order number
                $sort_column--;

                // Need the typeclass of the datafield first
                $query = $em->createQuery(
                   'SELECT ft.typeClass AS type_class
                    FROM ODRAdminBundle:DataFields AS df
                    JOIN ODRAdminBundle:FieldType AS ft WITH df.fieldType = ft
                    WHERE df.dataType = :datatype AND df.displayOrder = :display_order
                    AND df.deletedAt IS NULL AND ft.deletedAt IS NULL'
                )->setParameters( array('datatype' => $datatype_id, 'display_order' => $sort_column) );
                $results = $query->getArrayResult();
                $typeclass = $results[0]['type_class'];


                if ($typeclass == 'Radio') {
                    // Get the list of radio options
                    $query = $em->createQuery(
                       'SELECT ro.optionName AS option_name, dr.id AS dr_id
                        FROM ODRAdminBundle:RadioOptions AS ro
                        JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.radioOption = ro
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH rs.dataRecordFields = drf
                        JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                        JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                        WHERE dr.id IN (:datarecords) AND df.displayOrder = :display_order AND rs.selected = 1'
                    )->setParameters( array('datarecords' => $list, 'display_order' => $sort_column) );
                    $results = $query->getArrayResult();

                    // Build an array so php can sort the list
                    $tmp = array();
                    foreach ($results as $num => $data) {
                        $option_name = $data['option_name'];
                        $datarecord_id = $data['dr_id'];

                        $key = $option_name.'_'.$datarecord_id;
                        $tmp[ $key ] = $datarecord_id;
                    }

                    // 
                    if ($sort_dir == 'DESC')
                        krsort($tmp);
                    else
                        ksort($tmp);

                    // Convert back into a list of datarecord ids for printing
                    $list = array();
                    foreach ($tmp as $key => $dr_id)
                        $list[] = $dr_id;
                }
                else if ($typeclass == 'File') {
                    // Get the list of file names...have to left join the file table because datarecord id is required, but there may not always be a file uploaded
                    $query = $em->createQuery(
                       'SELECT f.originalFileName AS file_name, dr.id AS dr_id
                        FROM ODRAdminBundle:DataRecord AS dr
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                        JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                        LEFT JOIN ODRAdminBundle:File AS f WITH f.dataRecordFields = drf
                        WHERE dr.id IN (:datarecords) AND df.displayOrder = :display_order
                        AND f.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND df.deletedAt IS NULL
                        ORDER BY f.originalFileName '.$sort_dir
                    )->setParameters( array('datarecords' => $list, 'display_order' => $sort_column) );
                    $results = $query->getArrayResult();

                    // TODO - sort in php instead of SQL?
                    // Redo the list of datarecords based on the sorted order
                    $list = array();
                    foreach ($results as $num => $result) {
                        $list[] = $result['dr_id'];
                    }
                }
                else {
                    // Get SQL to sort the list of datarecords
                    $query = $em->createQuery(
                       'SELECT dr.id AS dr_id
                        FROM ODRAdminBundle:DataRecord AS dr
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                        JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                        JOIN ODRAdminBundle:'.$typeclass.' AS e WITH e.dataRecordFields = drf
                        WHERE dr.id IN (:datarecords) AND df.displayOrder = :display_order
                        AND dr.deletedAt IS NULL AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND df.deletedAt IS NULL
                        ORDER BY e.value '.$sort_dir
                    )->setParameters( array('datarecords' => $list, 'display_order' => $sort_column) );
                    $results = $query->getArrayResult();

                    // TODO - sort in php instead of SQL?
                    // Redo the list of datarecords based on the sorted order
                    $list = array();
                    foreach ($results as $num => $result) {
                        $list[] = $result['dr_id'];
                    }
                }
            }

            // Only save the subset of records pointed to by the $start and $length values
            $datarecord_list = array();
            for ($index = $start; $index < ($start + $length); $index++) {
                if ( !isset($list[$index]) )
                    break;

                $datarecord_list[] = $list[$index];
            }

            // Get the rows that will fulfill the request
            $data = array();
            if ( $datarecord_count > 0 )
                $data = parent::renderTextResultsList($datarecord_list, $datatype, $request);

            // Build the json array to return to the datatables request
            $json = array(
                'draw' => $draw,
                'recordsTotal' => $datarecord_count,
                'recordsFiltered' => $datarecord_count,
                'data' => $data,
            );
            $return = $json;

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x122280082 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
