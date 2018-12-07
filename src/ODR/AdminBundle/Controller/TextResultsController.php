<?php

/**
 * Open Data Repository Data Publisher
 * TextResults Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The textresults controller handles the selection of Datafields that are
 * displayed by the jQuery Datatables plugin, in addition to ajax
 * communication with the Datatables plugin for display of data and state storage.
 *
 * @see https://www.datatables.net/
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\TableThemeHelperService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class TextResultsController extends ODRCustomController
{

    /**
     * Takes an AJAX request from the jQuery DataTables plugin and builds an array of TextResults rows for the plugin to display.
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

            // Need to also deal with requests for a sorted table...
            $sort_column = 0;
            $sort_dir = 'asc';
            if ( isset($post['order']) && isset($post['order']['0']) ) {
                $sort_column = intval( $post['order']['0']['column'] );
                $sort_dir = strtolower( $post['order']['0']['dir'] );
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
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // Store whether the user is permitted to edit at least one datarecord for this datatype
            $can_edit_datatype = $pm_service->canEditDatatype($user, $datatype);

            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Save changes to the page_length unless viewing a search results table meant for
            //  linking datarecords...
            if ($odr_tab_id !== '')
                $odr_tab_service->setPageLength($odr_tab_id, $page_length);

            // Determine whether the user has a restriction on which datarecords they can edit
            $restricted_datarecord_list = $pm_service->getDatarecordRestrictionList($user, $datatype);
            $has_search_restriction = false;
            if ( !is_null($restricted_datarecord_list) )
                $has_search_restriction = true;

            // Determine whether the user wants to only display datarecords they can edit
            $cookies = $request->cookies;
            $only_display_editable_datarecords = true;
            if ( $cookies->has('datatype_'.$datatype->getId().'_editable_only') )
                $only_display_editable_datarecords = $cookies->get('datatype_'.$datatype->getId().'_editable_only');

            // If a datarecord restriction exists, and the user only wants to display editable datarecords...
            $editable_only = false;
            if ( $can_edit_datatype && !is_null($restricted_datarecord_list) && $only_display_editable_datarecords )
                $editable_only = true;


            $original_datarecord_list = array();
            $datarecord_list = array();
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

                // No problems, so continue on
            }


            // ----------------------------------------
            // If the datarecord lists don't exist in the user's session, then they need to get created
            // If the sorting criteria changed, then the datarecord lists need to get rebuilt
            $sort_df_id = 0;
            if ( !is_null($datatype->getSortField()) )
                $sort_df_id = $datatype->getSortField()->getId();

            if ($sort_column >= 2) {    // column 0 is datarecord id, column 1 is default sort column...
                // Locate the datafield pointed to by $sort_column
                $sort_column -= 2;
                $df = $tth_service->getDatafieldAtColumn($user, $datatype->getId(), $theme->getId(), $sort_column);
                $sort_df_id = $df['id'];
            }

            // Convert sort direction into a boolean for later...
            $sort_ascending = true;
            if ($sort_dir == 'desc')
                $sort_ascending = false;


            // If linking, this array should be empty
            $editable_datarecord_list = array();

            if ($odr_tab_id !== '') {
                // This is for a search page

                // ----------------------------------------
                // If the sorting criteria has changed for the lists of datarecord ids...
                if ( $odr_tab_service->hasSortCriteriaChanged($odr_tab_id, $sort_df_id, $sort_dir) ) {
                    // ...then store the new criteria they will be using here
                    $odr_tab_service->setSortCriteria($odr_tab_id, $sort_df_id, $sort_dir);
                }

                // Get an array of datarecord ids sorted by the given datafield in the given sort
                //  direction, filtered to include only the datarecord ids in $datarecord_list
                $sort_criteria = $odr_tab_service->getSortCriteria($odr_tab_id);
                if ( is_null($sort_criteria) ) {
                    if (is_null($datatype->getSortField())) {
                        // ...this datarecord list is currently ordered by id
                        $odr_tab_service->setSortCriteria($odr_tab_id, 0, 'asc');
                    }
                    else {
                        // ...this datarecord list is ordered by whatever the sort datafield for this datatype is
                        $sort_df_id = $datatype->getSortField()->getId();
                        $odr_tab_service->setSortCriteria($odr_tab_id, $sort_df_id, 'asc');
                    }
                }
                else {
                    // Load the criteria from the user's session
                    $sort_df_id = $sort_criteria['datafield_id'];
                    if ($sort_criteria['sort_direction'] === 'desc')
                        $sort_ascending = false;
                }

                $search_results = $search_api_service->performSearch($datatype, $search_key, $user_permissions, $sort_df_id, $sort_ascending);
                $original_datarecord_list = $search_results['grandparent_datarecord_list'];


                // ----------------------------------------
                // Determine the correct lists of datarecords to use for rendering...
                $viewable_datarecord_list = array();
                // The editable list needs to be in ($dr_id => $num) format for twig
                $editable_datarecord_list = array();
                if ($can_edit_datatype) {
                    if (!$has_search_restriction) {
                        // ...user doesn't have a restriction list, so the editable list is the same as the
                        //  viewable list
                        $viewable_datarecord_list = $original_datarecord_list;
                        $editable_datarecord_list = array_flip($original_datarecord_list);
                    }
                    else if (!$editable_only) {
                        // ...user has a restriction list, but wants to see all datarecords that match the
                        //  search
                        $viewable_datarecord_list = $original_datarecord_list;

                        // Doesn't matter if the editable list of datarecords has more than the
                        //  viewable list of datarecords
                        $editable_datarecord_list = array_flip($restricted_datarecord_list);
                    }
                    else {
                        // ...user has a restriction list, and only wants to see the datarecords they are
                        //  allowed to edit
                        $datarecord_list = $original_datarecord_list;

                        // array_flip() + isset() is orders of magnitude faster than repeated calls to in_array()
                        $editable_datarecord_list = array_flip($restricted_datarecord_list);
                        foreach ($datarecord_list as $num => $dr_id) {
                            if (!isset($editable_datarecord_list[$dr_id]))
                                unset($datarecord_list[$num]);
                        }

                        // Both the viewable and the editable lists are based off the intersection of the
                        //  search results and the restriction list
                        $viewable_datarecord_list = array_values($datarecord_list);
                        $editable_datarecord_list = array_flip($viewable_datarecord_list);
                    }
                }
                else {
                    // ...otherwise, just use the list of datarecords that was passed in
                    $viewable_datarecord_list = $original_datarecord_list;

                    // User can't edit anything in the datatype, leave the editable datarecord list empty
                }
            }
            else {
                // This is for a linking page...don't need to do anything special here
                $search_results = $search_api_service->performSearch($datatype, $search_key, $user_permissions);
                $original_datarecord_list = $search_results['grandparent_datarecord_list'];

                $viewable_datarecord_list = $original_datarecord_list;
            }


            // ----------------------------------------
            // Save how many datarecords there are in total...this list is already filtered to contain
            //  just the public datarecords if the user lacks the relevant view permission
            $datarecord_count = count($viewable_datarecord_list);

            // Reduce datarecord_list to just the list that will get rendered
            $datarecord_list = array_slice($viewable_datarecord_list, $start, $page_length);


            // ----------------------------------------
            // Get the rows that will fulfill the datatables request
            $data = array();
            if ( $datarecord_count > 0 )
                $data = $tth_service->getRowData($user, $datarecord_list, $datatype->getId(), $theme->getId());

            // Build the json array to return to the datatables request
            $json = array(
                'draw' => $draw,
                'recordsTotal' => $datarecord_count,
                'recordsFiltered' => $datarecord_count,
                'data' => $data,
                'editable_datarecord_list' => $editable_datarecord_list,
            );
            $return = $json;
        }
        catch (\Exception $e) {
            $source = 0xa1955869;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');

            // Don't want to store the tab_id as part of the datatables state array
            if ( !isset($post['odr_tab_id']) )
                throw new ODRBadRequestException('invalid request');

            $odr_tab_id = $post['odr_tab_id'];
            unset( $post['odr_tab_id'] );


            // Get any existing data for this tab
            $tab_data = $odr_tab_service->getTabData($odr_tab_id);
            if ( is_null($tab_data) )
                $tab_data = array();

            // Update the state variable in this tab's data
            $tab_data['state'] = $post;
            $odr_tab_service->setTabData($odr_tab_id, $tab_data);
        }
        catch (\Exception $e) {
            $source = 0x25baf2e3;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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

            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');

            if ( !isset($post['odr_tab_id']) )
                throw new ODRBadRequestException('invalid request');

            $odr_tab_id = $post['odr_tab_id'];

            // Determine whether the requested state array exists in the user's session...
            $tab_data = $odr_tab_service->getTabData($odr_tab_id);
            if ( is_null($tab_data) || !isset($tab_data['state']) ) {
                // ...doesn't exist, just return an empty array
                $return = array();
            }
            else {
                // ...return the requested state array
                $return = $tab_data['state'];
            }
        }
        catch (\Exception $e) {
            $source = 0xbb8573dc;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
