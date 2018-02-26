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
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\TableThemeHelperService;
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
            $sort_dir = 'ASC';
            if ( isset($post['order']) && isset($post['order']['0']) ) {
                $sort_column = intval( $post['order']['0']['column'] );
                $sort_dir = strtoupper( $post['order']['0']['dir'] );
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
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
            $datatype_permissions = $pm_service->getDatatypePermissions($user);
            $datafield_permissions = $pm_service->getDatafieldPermissions($user);

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

            $datarecord_list = '';
            if ( $search_key == '' ) {
                // Theoretically this isn't called during regular operation of ODR anymore, but keeping around just in case

                // Grab the sorted list of datarecords for this datatype
                $list = $dti_service->getSortedDatarecordList($datatype->getId());
                // Convert the list into a comma-separated string
                $datarecord_list = array_keys($list);
                $datarecord_list = implode(',', $datarecord_list);
            }
            else {
                // Get all datarecords from the search key...these are already sorted
                $data = parent::getSavedSearch($em, $user, $datatype_permissions, $datafield_permissions, $datatype->getId(), $search_key, $request);
                $datarecord_list = $data['datarecord_list'];

                // Don't check for a redirect here, right?  would need to modify datatables.js to deal with it... TODO

                // Convert the comma-separated list into an array
//                if ( $data['redirect'] == false && trim($datarecord_list) !== '')
//                    $list = explode(',', $datarecord_list);
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
            if ($sort_dir == 'DESC')
                $sort_ascending = false;


            // If the sorting criteria has changed for the lists of datarecord ids...
            if ( $odr_tab_service->hasSortCriteriaChanged($odr_tab_id, $sort_df_id, $sort_dir) ) {
                // ...then wipe the existing datarecord lists so they can get rebuilt
                $odr_tab_service->clearDatarecordLists($odr_tab_id);

                // Might as well store the new criteria they will be using here
                $odr_tab_service->setSortCriteria($odr_tab_id, $sort_df_id, $sort_dir);
            }


            // -----------------------------------
            // Going to need this later...
            $restricted_datarecord_list = $pm_service->getDatarecordRestrictionList($user, $datatype);

            // Store the list of datarecord ids that the user can view in their session for this tab
            // TODO - move this into the tab helper service once searching is a service
            if ( is_null($odr_tab_service->getViewableDatarecordList($odr_tab_id)) ) {
                // Get an array of datarecord ids sorted by the given datafield in the given sort
                //  direction, filtered to include only the datarecord ids in $datarecord_list
                $dr_list = $dti_service->sortDatarecordsByDatafield($sort_df_id, $sort_ascending, $datarecord_list);

                // Convert $dr_list from  $dr_id => $sort_value  into a comma-separated list
                $dr_list = implode(',', array_keys($dr_list) );
                // Store the comma-separated list for later use
                $odr_tab_service->setViewableDatarecordList($odr_tab_id, $dr_list);
            }

            if ( $pm_service->canEditDatatype($user, $datatype) && is_null($odr_tab_service->getEditableDatarecordList($odr_tab_id)) ) {
                if ( !is_null($restricted_datarecord_list) ) {
                    // Get an array of datarecord ids sorted by the given datafield in the given sort
                    //  direction, filtered to include only the datarecord ids in the edit-restricted
                    //  datarecord list
                    $dr_list = $dti_service->sortDatarecordsByDatafield($sort_df_id, $sort_ascending, $restricted_datarecord_list);

                    // Convert $dr_list from  $dr_id => $sort_value  into a comma-separated list
                    $dr_list = implode(',', array_keys($dr_list) );
                    // Store the comma-separated list for later use
                    $odr_tab_service->setEditableDatarecordList($odr_tab_id, $dr_list);
                }
                else {
                    // No datarecord restriction, so just use the viewable list
                    $dr_list = $odr_tab_service->getViewableDatarecordList($odr_tab_id);
                    $odr_tab_service->setEditableDatarecordList($odr_tab_id, $dr_list);
                }
            }


            // The viewable/editable datarecord lists stored in the user's session are now
            //  guaranteed to match the sorting criteria specified by the datatables plugin


            // -----------------------------------
            // Determine whether the user wants to only display datarecords they can edit
            $cookies = $request->cookies;
            $only_display_editable_datarecords = true;
            if ( $cookies->has('datatype_'.$datatype->getId().'_editable_only') )
                $only_display_editable_datarecords = $cookies->get('datatype_'.$datatype->getId().'_editable_only');

            // If a datarecord restriction exists, and the user only wants to display editable datarecords...
            $editable_only = true;
            if ( !is_null($restricted_datarecord_list) && !$only_display_editable_datarecords )
                $editable_only = false;

            // Determine the correct list of datarecords to use for rendering
            if ($can_edit_datatype && $editable_only)
                $datarecord_list = $odr_tab_service->getEditableDatarecordList($odr_tab_id);
            else
                $datarecord_list = $odr_tab_service->getViewableDatarecordList($odr_tab_id);
            $datarecord_list = explode(',', $datarecord_list);

            // Exploding an empty string results in a nearly empty array...
            if ( isset($datarecord_list[0]) && $datarecord_list[0] === '' )
                $datarecord_list = array();


            // Save how many datarecords there are in total...this list is already filtered to contain
            //  just the public datarecords if the user lacks the relevant view permission
            $datarecord_count = count($datarecord_list);

            // Reduce datarecord_list to just the list that will get rendered
            $datarecord_list = array_slice($datarecord_list, $start, $page_length);


            // -----------------------------------
            // Convert the list of editable datarecords into a $dr_id => $num format
            $editable_datarecord_list = $odr_tab_service->getEditableDatarecordList($odr_tab_id);
            if ( !is_null($editable_datarecord_list) && $editable_datarecord_list !== '' ) {
                $editable_datarecord_list = explode(',', $editable_datarecord_list);
                $editable_datarecord_list = array_flip($editable_datarecord_list);
            }
            else {
                // Convert empty string into array for twig purposes
                $editable_datarecord_list = array();
            }


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
