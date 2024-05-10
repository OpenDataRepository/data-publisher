<?php

/**
 * Open Data Repository Data Publisher
 * TextResults Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The textresults controller handles the selection of Datafields that are displayed by the jQuery
 * Datatables plugin, in addition to ajax communication with the Datatables plugin for display of
 * data and state storage.
 *
 * @see https://www.datatables.net/
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\TableThemeHelperService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;


class TextResultsController extends ODRCustomController
{

    /**
     * Takes an AJAX request from the jQuery DataTables plugin and builds an array of TextResults
     * rows for the plugin to display.  This function does not get used when the layout is configured
     * to display up to 10k rows at once.
     * @see http://datatables.net/manual/server-side
     *
     * @param Request $request
     *
     * @return Response
     */
    public function datatablesrowrequestAction(Request $request)
    {
        $return = array();
        $return['data'] = '';

        try {
            // ----------------------------------------
            // Symfony firewall won't permit GET requests to reach this point
            $post = $request->request->all();

            if ( !isset($post['datatype_id'])
                || !isset($post['theme_id'])
                || !isset($post['draw'])
                || !isset($post['start'])
                || !isset($post['length'])
                || !isset($post['search_key'])
            ) {
                throw new ODRBadRequestException();
            }

            $datatype_id = intval( $post['datatype_id'] );
            $theme_id = intval( $post['theme_id'] );
            $draw = intval( $post['draw'] );    // intval() because of recommendation by datatables documentation
            $start = intval( $post['start'] );
            $page_length = intval( $post['length'] );
            $search_key = $post['search_key'];


            // ----------------------------------------
            // Need to also deal with requests for a sorted table...
            $sort_cols = array();
            $sort_dirs = array();
            if ( isset($post['order']) ) {
                foreach ($post['order'] as $num => $data) {
                    $sort_cols[$num] = intval($data['column']);
                    $sort_dirs[$num] = strtolower($data['dir']);
                }
            }


            // The tab id won't be in the post request if this is to get rows for linking datarecords
            // Don't want changes made to that secondary table to overwrite values saved for the
            //  actual search results table for that tab
            $odr_tab_id = '';
            if ( isset($post['odr_tab_id']) && trim($post['odr_tab_id']) !== '' )
                $odr_tab_id = trim( $post['odr_tab_id'] );


            // ----------------------------------------
            // Get Entity Manager and setup objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var TableThemeHelperService $tth_service */
            $tth_service = $this->container->get('odr.table_theme_helper_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            if ($theme->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException();


            // ----------------------------------------
            // Determine whether user is logged in or not
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // Store whether the user is permitted to edit at least one datarecord for this datatype
            $can_edit_datatype = $pm_service->canEditDatatype($user, $datatype);

            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Search result pages display all datarecords that the user can see which match matched
            //  a search they did...
            $original_datarecord_list = array();

            // While it's only rarely used, ODR does provide the ability for users to filter search
            //  results down from "all records the user can view"...
            $viewable_datarecord_list = array();

            // ...to "only records the user can edit".  This only happens when the user has a
            //  "datarecord restriction" in place...
            $editable_datarecord_list = array();

            // ...so determine whether the user has such a restriction
            $restricted_datarecord_list = $pm_service->getDatarecordRestrictionList($user, $datatype);
            $has_search_restriction = false;
            if ( !is_null($restricted_datarecord_list) )
                $has_search_restriction = true;

            // ...then determine whether the user wants to only display datarecords they can edit
            $cookies = $request->cookies;
            $only_display_editable_datarecords = true;
            if ( $cookies->has('datatype_'.$datatype->getId().'_editable_only') )
                $only_display_editable_datarecords = $cookies->get('datatype_'.$datatype->getId().'_editable_only');

            // If a datarecord restriction exists, and the user only wants to display records they
            //  can edit, then save that preference
            $editable_only = false;
            if ( $can_edit_datatype && !is_null($restricted_datarecord_list) && $only_display_editable_datarecords )
                $editable_only = true;


            // The list of datarecords to actually display on the page is based off these preferences
            $datarecord_list = array();


            // ----------------------------------------
            // Save changes to the page_length unless viewing a search results table meant for
            //  linking datarecords...
            if ($odr_tab_id !== '')
                $odr_tab_service->setPageLength($odr_tab_id, $page_length);

            if ( $search_key == '' ) {
                // Theoretically this won't happen during regular operation of ODR anymore, but
                //  keeping around just in case

                // Grab the sorted list of datarecords for this datatype
                $list = $sort_service->getSortedDatarecordList($datatype->getId());
                // Convert the list into a comma-separated string
                $original_datarecord_list = array_keys($list);
            }
            else {
                // Ensure the search key is valid first
                $search_key_service->validateSearchKey($search_key);

                // TODO - don't need to check for a redirect here?  this is only called via AJAX from an already valid search results page, right?
                // Determine whether the user is allowed to view this search key
//                $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);
//                if ($filtered_search_key !== $search_key) {
                    // User can't view the results of this search key, redirect to the one they can view
//                    return $search_redirect_service->redirectToFilteredSearchResult($user, $filtered_search_key, $search_theme_id);
//                }

                // No problems, so ensure the tab refers to the given search key
                $expected_search_key = $odr_tab_service->getSearchKey($odr_tab_id);
                if ( $expected_search_key !== $search_key )
                    $odr_tab_service->setSearchKey($odr_tab_id, $search_key);
            }
            $search_params = $search_key_service->decodeSearchKey($search_key);


            // ----------------------------------------
            // If the datarecord lists don't exist in the user's session, then they need to get created
            // If the sorting criteria changed, then the datarecord lists need to get rebuilt
            $sort_datafields = array();
            $sort_directions = array();

            if ( empty($sort_cols) || ( count($sort_cols) === 1 && $sort_cols[0] < 2 ) ) {
                // datatables.js isn't using a sort column, or is using the default sort column
                //  ...column 0 being datarecord id, column 1 being the default sort column

                /* do nothing so the rest of ODR uses the datatype's default sorting */
            }
            else {
                // Determine which datafield(s) datatables.js is currently using as its sort column(s)
                foreach ($sort_cols as $display_order => $col) {
                    $col -= 2;
                    $df = $tth_service->getDatafieldAtColumn($user, $datatype->getId(), $theme->getId(), $col);

                    $sort_datafields[$display_order] = $df['id'];
                    $sort_directions[$display_order] = $sort_dirs[$display_order];
                }
            }

            if ($odr_tab_id !== '') {
                // This is for a search page

                // ----------------------------------------
                // If the sorting criteria has changed for the lists of datarecord ids...
                if ( $odr_tab_service->hasSortCriteriaChanged($odr_tab_id, $sort_datafields, $sort_directions) ) {
                    // ...then change (or delete) the criteria stored in the user's session
                    $odr_tab_service->setSortCriteria($odr_tab_id, $sort_datafields, $sort_directions);
                    $odr_tab_service->clearSearchResults($odr_tab_id);
                }

                // Need to ensure a sort criteria is set for this tab, otherwise the table plugin
                //  will display stuff in a different order
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
                if ($can_edit_datatype) {
                    if (!$has_search_restriction) {
                        // ...user doesn't have a restriction list, so the editable list is the same
                        //  as the viewable list
                        $viewable_datarecord_list = $original_datarecord_list;
                        $editable_datarecord_list = array_flip($original_datarecord_list);
                    }
                    else if (!$editable_only) {
                        // ...user has a restriction list, but wants to see all datarecords that
                        //  match the search
                        $viewable_datarecord_list = $original_datarecord_list;

                        // Doesn't matter if the editable list of datarecords has more than the
                        //  viewable list of datarecords
                        $editable_datarecord_list = array_flip($restricted_datarecord_list);
                    }
                    else {
                        // ...user has a restriction list, and only wants to see the datarecords
                        //  they are allowed to edit
                        $datarecord_list = $original_datarecord_list;

                        // array_flip() + isset() is orders of magnitude faster than repeated calls
                        //  to in_array()
                        $editable_datarecord_list = array_flip($restricted_datarecord_list);
                        foreach ($datarecord_list as $num => $dr_id) {
                            if ( !isset($editable_datarecord_list[$dr_id]) )
                                unset( $datarecord_list[$num] );
                        }

                        // Both the viewable and the editable lists are based off the intersection
                        //  of the search results and the restriction list
                        $viewable_datarecord_list = array_values($datarecord_list);
                        $editable_datarecord_list = array_flip($viewable_datarecord_list);
                    }
                }
                else {
                    // ...otherwise, just use the list of datarecords that was passed in
                    $viewable_datarecord_list = $original_datarecord_list;

                    // User can't edit anything in the datatype, leave the editable record list empty
                }
            }
            else {
                // This is for a linking page...don't need to do anything special here
                $original_datarecord_list = $search_api_service->performSearch(
                    $datatype,
                    $search_key,
                    $user_permissions
                );    // this only returns grandparent datarecords
                $viewable_datarecord_list = $original_datarecord_list;
            }


            // ----------------------------------------
            // Save how many datarecords there are in total...this list is already filtered to contain
            //  just the public datarecords if the user lacks the relevant view permission
            $datarecord_count = count($viewable_datarecord_list);

            // Reduce datarecord_list to just the list that will get rendered
            $datarecord_list = array_slice($viewable_datarecord_list, $start, $page_length);


            // -----------------------------------
            // Determine where on the page to scroll to if possible
            $session = $this->get('session');
            $scroll_target = '';
            if ($session->has('scroll_target')) {
                $scroll_target = $session->get('scroll_target');
                if ($scroll_target !== '') {
                    // Don't scroll to someplace on the page if the datarecord isn't displayed
                    if ( !in_array($scroll_target, $datarecord_list) )
                        $scroll_target = '';

                    // Null out the scroll target in the session so it only works once
                    $session->set('scroll_target', '');
                }
            }


            // ----------------------------------------
            // Get the rows that will fulfill the datatables request
            $data = array();
            if ( $datarecord_count > 0 ) {
                $data = $tth_service->getRowData($user, $datarecord_list, $datatype->getId(), $theme->getId());

                // It's impossible for this function to determine the correct order these datarecords
                //  should be in based on the values in their datafields...fortunately, the search
                //  system has already done this, and $data is already in the correct order
                foreach ($data as $sort_order => $dr_data)
                    $data[$sort_order][1] = $sort_order;
            }

            // Build the json array to return to the datatables request
            $json = array(
                'draw' => $draw,
                'recordsTotal' => $datarecord_count,
                'recordsFiltered' => $datarecord_count,
                'data' => $data,
                'editable_datarecord_list' => $editable_datarecord_list,
                'scroll_target' => $scroll_target,
            );
            $return = $json;
        }
        catch (\Exception $e) {
            $source = 0xa1955869;
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
     * Saves a datatables state object in Symfony's session object
     *
     * @param Request $request
     *
     * @return Response
     */
    public function datatablesstatesaveAction(Request $request)
    {
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab data from post...
            $post = $request->request->all();

            // Don't want to store the tab_id as part of the datatables state array
            if ( !isset($post['odr_tab_id']) )
                throw new ODRBadRequestException('invalid request');

            $odr_tab_id = $post['odr_tab_id'];
            unset( $post['odr_tab_id'] );

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');

            // Get any existing data for this tab
            $tab_data = $odr_tab_service->getTabData($odr_tab_id);
            if ( is_null($tab_data) )
                $tab_data = array();

            // Update the state variable in this tab's data
            $tab_data['state'] = $post;
            $odr_tab_service->setTabData($odr_tab_id, $tab_data);


            // ----------------------------------------
            // Saving the "short" version of the datatables state object doesn't need to do anything else...
            if ( $post['layout_type'] === 'long' ) {
                // ...but since the "long" version doesn't call self::datatablesrowrequestAction(),
                //  this controller action needs to do the work to save any sort changes requested
                $datatype_id = intval( $post['datatype_id'] );
                $theme_id = intval( $post['theme_id'] );

                // Need to also deal with requests for a sorted table...
                $sort_cols = array();
                $sort_dirs = array();
                if ( isset($post['order']) ) {
                    foreach ($post['order'] as $num => $data) {
                        $sort_cols[$num] = intval($data[0]);
                        $sort_dirs[$num] = strtolower($data[1]);
                    }
                }

                // If the datarecord lists don't exist in the user's session, then they need to get created
                // If the sorting criteria changed, then the datarecord lists need to get rebuilt
                $sort_datafields = array();
                $sort_directions = array();

                if ( empty($sort_cols) || ( count($sort_cols) === 1 && $sort_cols[0] < 2 ) ) {
                    // datatables.js isn't using a sort column, or is using the default sort column
                    //  ...column 0 being datarecord id, column 1 being the default sort column

                    /* do nothing so the rest of ODR uses the datatype's default sorting */
                }
                else {
                    /** @var TableThemeHelperService $tth_service */
                    $tth_service = $this->container->get('odr.table_theme_helper_service');

                    /** @var ODRUser $user */
                    $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

                    // Determine which datafield(s) datatables.js is currently using as its sort column(s)
                    foreach ($sort_cols as $display_order => $col) {
                        $col -= 2;
                        $df = $tth_service->getDatafieldAtColumn($user, $datatype_id, $theme_id, $col);

                        $sort_datafields[$display_order] = $df['id'];
                        $sort_directions[$display_order] = $sort_dirs[$display_order];
                    }
                }

                // If the sorting criteria has changed for the lists of datarecord ids...
                if ( $odr_tab_service->hasSortCriteriaChanged($odr_tab_id, $sort_datafields, $sort_directions) ) {
                    // ...then change (or delete) the criteria stored in the user's session
                    $odr_tab_service->setSortCriteria($odr_tab_id, $sort_datafields, $sort_directions);
                    $odr_tab_service->clearSearchResults($odr_tab_id);
                }

                // NOTE: rebuilding the search results list shouldn't be required here...the other
                //  controller actions can rebuild it when they need to use it
            }
        }
        catch (\Exception $e) {
            $source = 0x25baf2e3;
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
     * Loads and returns a datatables state object from Symfony's session
     *
     * @param Request $request
     *
     * @return Response
     */
    public function datatablesstateloadAction(Request $request)
    {
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab data from post...
            $post = $request->request->all();
            if ( !isset($post['odr_tab_id']) )
                throw new ODRBadRequestException('invalid request');

            $odr_tab_id = $post['odr_tab_id'];

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');

            // The datatables.js instance used for the search results page needs to know both its
            //  previous state (to restore the table when returning from Display/Edit mode), and
            //  also needs to know any sort_criteria used for the tab
            $return = array('state' => array(), 'sort_criteria' => array());

            $tab_data = $odr_tab_service->getTabData($odr_tab_id);
            if ( !is_null($tab_data) ) {
                // Since the tab data exists, extract and return the 'state' and 'sort_criteria' arrays
                //  to datatables.js if possible
                if ( isset($tab_data['state']) )
                    $return['state'] = $tab_data['state'];
                if ( isset($tab_data['sort_criteria']) )
                    $return['sort_criteria'] = $tab_data['sort_criteria'];
            }
        }
        catch (\Exception $e) {
            $source = 0xbb8573dc;
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
     * Deletes a datatables state object from Symfony's session
     * TODO - transfer settings from old to new tab?
     *
     * @param string $odr_tab_id
     * @param Request $request
     *
     * @return Response
     */
    public function datatablesstatedestroyAction($odr_tab_id, Request $request)
    {
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $session = $request->getSession();

            // Locate the sorted list of datarecords in the user's session
            if ( $session->has('stored_tab_data') ) {
                $stored_tab_data = $session->get('stored_tab_data');

                if ( isset($stored_tab_data[$odr_tab_id]) ) {
                    unset( $stored_tab_data[$odr_tab_id] );
                    $session->set('stored_tab_data', $stored_tab_data);
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x9c3bb094;
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
     * Loads a list of the remote records for a specific datatype that currently link to a given local
     * datarecord...the local datarecord could be on either the ancestor side or the descendant side
     * of this link.
     *
     * Intentionally split from LinkController::getlinkabledatarecordsAction()...changing the theme
     * used by this table could change the columns...and since datatables.js (understandably) can't
     * handle a change in the number of columns, it's better to just completely replace the table.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function loadlinkeddatarecordstableAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            $post = $request->request->all();
            if ( !isset($post['local_datarecord_id'])
                || !isset($post['ancestor_datatype_id'])
                || !isset($post['descendant_datatype_id'])
                || !isset($post['remote_theme_id'])
            ) {
                throw new ODRBadRequestException('Invalid Form');
            }

            $local_datarecord_id = $post['local_datarecord_id'];
            $ancestor_datatype_id = $post['ancestor_datatype_id'];
            $descendant_datatype_id = $post['descendant_datatype_id'];
            $theme_id = $post['remote_theme_id'];

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            /** @var DatatreeInfoService $datatree_info_service */
            $datatree_info_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var TableThemeHelperService $table_theme_helper_service */
            $table_theme_helper_service = $this->container->get('odr.table_theme_helper_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // Grab the datatypes from the database
            /** @var DataRecord $local_datarecord */
            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ($local_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $local_datatype = $local_datarecord->getDataType();
            if ($local_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Local Datatype');


            /** @var DataType $ancestor_datatype */
            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ($ancestor_datatype == null)
                throw new ODRNotFoundException('Ancestor Datatype');

            /** @var DataType $descendant_datatype */
            $descendant_datatype = $repo_datatype->find($descendant_datatype_id);
            if ($descendant_datatype == null)
                throw new ODRNotFoundException('Descendant Datatype');

            // Ensure a link exists from ancestor to descendant datatype
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array(
                    'ancestor' => $ancestor_datatype->getId(),
                    'descendant' => $descendant_datatype->getId()
                )
            );
            if ($datatree == null)
                throw new ODRNotFoundException('DataTree');


            /** @var Theme $remote_theme */
            $remote_theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($remote_theme == null)
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$permissions_service->canViewDatatype($user, $ancestor_datatype) )
                throw new ODRForbiddenException();

            // TODO - create a new permission specifically for linking/unlinking datarecords?
            if ( !$permissions_service->canEditDatarecord($user, $local_datarecord) )
                throw new ODRForbiddenException();

            if ( !$permissions_service->canViewDatatype($user, $descendant_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Determine which datatype we're trying to create a link with
            $local_datarecord_is_ancestor = false;
            $local_datatype = $local_datarecord->getDataType();
            $remote_datatype = null;
            if ($local_datatype->getId() == $ancestor_datatype_id) {
                $remote_datatype = $repo_datatype->find($descendant_datatype_id);   // Linking to a remote datarecord from this datarecord
                $local_datarecord_is_ancestor = true;
            }
            else {
                $remote_datatype = $repo_datatype->find($ancestor_datatype_id);     // Getting a remote datarecord to link to this datarecord
                $local_datarecord_is_ancestor = false;
            }
            /** @var DataType $remote_datatype */

            // Ensure the remote datatype has a suitable theme...
            if ( $remote_datatype->getSetupStep() != DataType::STATE_OPERATIONAL )
                throw new ODRBadRequestException('Unable to link to Remote Datatype');

            if ( $remote_theme->getDataType()->getId() !== $remote_datatype->getId() )
                throw new ODRBadRequestException('Invalid Theme');


            // ----------------------------------------
            // Store whether the link allows multiple records or not
            $allow_multiple_links = $datatree_info_service->allowsMultipleLinkedDatarecords($ancestor_datatype->getid(), $descendant_datatype->getId());

            // Grab all datarecords currently linked to the local_datarecord
            $linked_datarecords = array();
            if ($local_datarecord_is_ancestor) {
                // local_datarecord is on the ancestor side of the link
                $query = $em->createQuery(
                   'SELECT descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS ancestor
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor = :local_datarecord AND descendant.dataType = :remote_datatype
                    AND descendant.provisioned = false
                    AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'local_datarecord' => $local_datarecord->getId(),
                        'remote_datatype' => $remote_datatype->getId()
                    )
                );
                $results = $query->getArrayResult();

                foreach ($results as $num => $data) {
                    $descendant_id = $data['descendant_id'];
                    if ( $descendant_id == null || trim($descendant_id) == '' )
                        continue;

                    $linked_datarecords[ $descendant_id ] = 1;
                }
            }
            else {
                // local_datarecord is on the descendant side of the link
                $query = $em->createQuery(
                   'SELECT ancestor.id AS ancestor_id
                    FROM ODRAdminBundle:DataRecord AS descendant
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = descendant
                    JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                    WHERE descendant = :local_datarecord AND ancestor.dataType = :remote_datatype
                    AND ancestor.provisioned = false
                    AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'local_datarecord' => $local_datarecord->getId(),
                        'remote_datatype' => $remote_datatype->getId()
                    )
                );
                $results = $query->getArrayResult();

                foreach ($results as $num => $data) {
                    $ancestor_id = $data['ancestor_id'];
                    if ( $ancestor_id == null || trim($ancestor_id) == '' )
                        continue;

                    $linked_datarecords[ $ancestor_id ] = 1;
                }
            }


            // ----------------------------------------
            // Convert the list of linked datarecords into a slightly different format so the datatables plugin can use it
            $datarecord_list = array();
            foreach ($linked_datarecords as $dr_id => $value)
                $datarecord_list[] = $dr_id;

            // Load the table data for each of the datarecords that need to get displayed
            $data = $table_theme_helper_service->getRowData($user, $datarecord_list, $remote_datatype->getId(), $remote_theme->getId(), true);    // prepend a third column of data

            // Determine the column names for the datatables plugin
            $column_data = $table_theme_helper_service->getColumnNames($user, $remote_datatype->getId(), $remote_theme->getId());
            // Prepend a third column to the list to make datatables' life easier
            $column_str = '{"title":"Link to this record","visible":true,"searchable":false},'.$column_data['column_names'];

            $tmp = json_decode('['.substr($column_str, 0, -1).']', true);
            $column_names = array();
            foreach ($tmp as $num => $col)
                $column_names[] = $col['title'];

            // Return the data for this request
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Link:link_datarecord_form_table.html.twig',
                    array(
                        'data' => $data,
                        'column_str' => $column_str,
                        'column_names' => $column_names,

                        'theme_id' => $remote_theme->getId(),
                        'linked_datarecords' => $linked_datarecords,
                        'allow_multiple_links' => $allow_multiple_links,
                        'local_datarecord_is_ancestor' => $local_datarecord_is_ancestor,

                        'local_datatype' => $local_datatype,
                        'remote_datatype' => $remote_datatype,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x549b133f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
