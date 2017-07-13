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
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Forms
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

            if ( !isset($post['datatype_id']) || !isset($post['theme_id']) || !isset($post['draw']) || !isset($post['start']) )
                throw new ODRBadRequestException();

            $odr_tab_id = '';
            if ( isset($post['odr_tab_id']) && trim($post['odr_tab_id']) !== '' )
                $odr_tab_id = $post['odr_tab_id'];

            $datatype_id = intval( $post['datatype_id'] );
            $theme_id = intval( $post['theme_id'] );
            $draw = intval( $post['draw'] );    // intval() because of recommendation by datatables documentation
            $start = intval( $post['start'] );

            $session = $request->getSession();

            // Need to deal with requests for a sorted table...
            $sort_column = 0;
            $sort_dir = 'asc';
            if ( isset($post['order']) && isset($post['order']['0']) ) {
                $sort_column = $post['order']['0']['column'];
                $sort_dir = strtoupper( $post['order']['0']['dir'] );
            }

            // Deal with page_length changes...
            $length = intval( $post['length'] );
            if ($odr_tab_id !== '') {
                $stored_tab_data = array();
                if ( $session->has('stored_tab_data') )
                    $stored_tab_data = $session->get('stored_tab_data');
                $old_length = '';

                // Save page_length for this tab if different or doesn't exist
                if ( isset($stored_tab_data[$odr_tab_id]) && isset($stored_tab_data[$odr_tab_id]['page_length']) )
                    $old_length = $stored_tab_data[$odr_tab_id]['page_length'];
                else
                    $stored_tab_data[$odr_tab_id] = array();

                if ( $length !== $old_length ) {
                    $stored_tab_data[$odr_tab_id]['page_length'] = $length;
                    $session->set('stored_tab_data', $stored_tab_data);
                }
            }

            // search_key is optional
            $search_key = '';
            if ( isset($post['search_key']) )
                $search_key = urldecode($post['search_key']);   // Symfony doesn't automatically decode this since it's not coming through a GET request


            // ----------------------------------------
            // Get Entity Manager and setup objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');
            if ($theme->getDataType()->getId() !== $datatype->getId() || $theme->getThemeType() !== 'table')
                throw new ODRBadRequestException();


            // Determine whether user is logged in or not
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = array();
            $datafield_permissions = array();
            if ( $user !== 'anon.' ) {
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];
                $datafield_permissions = $user_permissions['datafields'];
            }

            // TODO - user permissions?

            // ----------------------------------------
//            $datarecord_count = 0;
            $list = array();
            if ( $search_key == '' ) {
                // Grab the sorted list of datarecords for this datatype
                $list = parent::getSortedDatarecords($datatype);

                // TODO - reaaaaaaaaallly need to get the above method to change its return type depending on user needs
                $list = explode(',', $list);
            }
            else {
                // Get all datarecords from the search key
                $data = parent::getSavedSearch($em, $user, $datatype_permissions, $datafield_permissions, $datatype->getId(), $search_key, $request);
//                $encoded_search_key = $data['encoded_search_key'];
                $datarecord_list = $data['datarecord_list'];

                // Don't check for a redirect here, right?  would need to modify datatables.js to deal with it... TODO

                // Convert the comma-separated list into an array
                if ( $data['redirect'] == false && trim($datarecord_list) !== '')
                    $list = explode(',', $datarecord_list);
            }

            // Save how many records total there are before filtering...
            $datarecord_count = count($list);


            // ----------------------------------------
            if ($sort_column >= 2) {    // column 0 is datarecord id, column 1 is default sort column...
                // Adjust datatables column number to datafield display order number
                $sort_column -= 2;

                // Need the typeclass of the datafield first
                $query = $em->createQuery(
                   'SELECT df.id AS df_id, ft.typeClass AS type_class
                    FROM ODRAdminBundle:ThemeElement AS te
                    JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
                    JOIN ODRAdminBundle:DataFields AS df WITH tdf.dataField = df
                    JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                    JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
                    WHERE te.theme = :theme_id AND tdf.displayOrder = :display_order
                    AND te.deletedAt IS NULL AND tdf.deletedAt IS NULL AND df.deletedAt IS NULL'
                )->setParameters( array('theme_id' => $theme->getId(), 'display_order' => $sort_column) );
                $results = $query->getArrayResult();
                $typeclass = $results[0]['type_class'];
                $datafield_id = $results[0]['df_id'];

                // Because the drf entry and the storage entity may not exist, pre-initialize the array of datarecord ids and values so datarecords with non-existent drf/storage entities don't get dropped from this sorting
                $query_results = array();
                foreach ($list as $num => $dr_id)
                    $query_results[$dr_id] = '';


                if ($typeclass == 'Radio') {
                    // Get the list of radio options
                    $query = $em->createQuery(
                       'SELECT rom.optionName AS option_name, dr.id AS dr_id
                        FROM ODRAdminBundle:RadioOptions AS ro
                        JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOption = ro
                        JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.radioOption = ro
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH rs.dataRecordFields = drf
                        JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                        WHERE dr.id IN (:datarecords) AND drf.dataField = :datafield_id AND rs.selected = 1
                        AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL AND rs.deletedAt IS NULL
                        AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
                    )->setParameters( array('datarecords' => $list, 'datafield_id' => $datafield_id) );
                    $results = $query->getArrayResult();

                    // Build an array so php can sort the list
                    foreach ($results as $num => $result) {
                        $option_name = $result['option_name'];
                        $dr_id = $result['dr_id'];

                        // Since this is a single select/radio datafield, there will be at most one option per datarecord id here...wouldn't be the case if a multiple select/radio datafield
                        // Therefore, using the datarecord id as a key is perfectly viable here
                        $query_results[$dr_id] = $option_name;
                    }
                }
                else if ($typeclass == 'File') {
                    // Get the list of file names...have to left join the file table because datarecord id is required, but there may not always be a file uploaded
                    $query = $em->createQuery(
                       'SELECT fm.originalFileName AS file_name, dr.id AS dr_id
                        FROM ODRAdminBundle:DataRecord AS dr
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                        LEFT JOIN ODRAdminBundle:File AS f WITH f.dataRecordFields = drf
                        LEFT JOIN ODRAdminBundle:FileMeta AS fm WITH fm.file = f
                        WHERE dr.id IN (:datarecords) AND drf.dataField = :datafield_id
                        AND f.deletedAt IS NULL AND fm.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
                    )->setParameters( array('datarecords' => $list, 'datafield_id' => $datafield_id) );
                    $results = $query->getArrayResult();

                    // Build an array from the query results so php can sort it
                    foreach ($results as $num => $result) {
                        $dr_id = $result['dr_id'];
                        $filename = $result['file_name'];

                        $query_results[$dr_id] = $filename;
                    }
                }
                else {
                    // Get the list of datarecords and their values
                    $query = $em->createQuery(
                       'SELECT dr.id AS dr_id, e.value AS value
                        FROM ODRAdminBundle:DataRecord AS dr
                        JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                        JOIN ODRAdminBundle:'.$typeclass.' AS e WITH e.dataRecordFields = drf
                        WHERE dr.id IN (:datarecords) AND drf.dataField = :datafield_id
                        AND dr.deletedAt IS NULL AND e.deletedAt IS NULL AND drf.deletedAt IS NULL'
                    )->setParameters( array('datarecords' => $list, 'datafield_id' => $datafield_id) );
                    $results = $query->getArrayResult();

                    // Build an array from the query results so php can sort it
                    foreach ($results as $num => $result) {
                        $dr_id = $result['dr_id'];
                        $value = $result['value'];

                        if ($typeclass == 'IntegerValue') {
                            $value = intval($value);
                        }
                        else if ($typeclass == 'DecimalValue') {
                            $value = floatval($value);
                        }
                        else if ($typeclass == 'DatetimeValue') {
                            $value = $value->format('Y-m-d');
                            if ($value == '9999-12-31')
                                $value = '';
                        }

                        $query_results[$dr_id] = $value;
                    }
                }

                // Get PHP to sort the list of datarecords
                if ($typeclass == 'IntegerValue' || $typeclass == 'DecimalValue') {
                    // PHP can sort integer/decimal values directly
                    if ($sort_dir == 'DESC')
                        arsort($query_results);
                    else
                        asort($query_results);
                }
                else {
                    // Text values need to use "natural" sorting
                    natsort($query_results);

                    if ($sort_dir == 'DESC')
                        $query_results = array_reverse($query_results, true);
                }

                // Redo the list of datarecords based on the sorted order
                $list = array();
                foreach ($query_results as $dr_id => $value)
                    $list[] = $dr_id;
            }

            // Only save the subset of records pointed to by the $start and $length values
            $datarecord_list = array();
            for ($index = $start; $index < ($start + $length); $index++) {
                if ( !isset($list[$index]) )
                    break;

                $datarecord_list[] = $list[$index];
            }


            // ----------------------------------------
            // Save the sorted list of datarecords in the user's session
            if ($odr_tab_id !== '') {
                $stored_tab_data = array();
                if ( $session->has('stored_tab_data') )
                    $stored_tab_data = $session->get('stored_tab_data');

                if ( !isset($stored_tab_data[$odr_tab_id]) )
                    $stored_tab_data[$odr_tab_id] = array();

                $stored_tab_data[$odr_tab_id]['datarecord_list'] = implode(',', $list);
                $session->set('stored_tab_data', $stored_tab_data);
//print_r($stored_tab_data);
            }


            // ----------------------------------------
            // Get the rows that will fulfill the request
            $data = array();
            if ( $datarecord_count > 0 )
                $data = parent::renderTextResultsList($em, $datarecord_list, $theme, $request);

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
            $source = 0xa1955869;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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
            $session = $request->getSession();

            // Grab data from post...
            $post = $_POST;

            // Don't want to store the tab_id as part of the datatables state array
            $odr_tab_id = $post['odr_tab_id'];
            unset( $post['odr_tab_id'] );

            // Save the sorted list of datarecords in the user's session
            $stored_tab_data = array();
            if ( $session->has('stored_tab_data') )
                $stored_tab_data = $session->get('stored_tab_data');

            if ( !isset($stored_tab_data[$odr_tab_id]) )
                $stored_tab_data[$odr_tab_id] = array();

            $stored_tab_data[$odr_tab_id]['state'] = $post;
            $session->set('stored_tab_data', $stored_tab_data);
        }
        catch (\Exception $e) {
            $source = 0x25baf2e3;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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
            $session = $request->getSession();

            // Grab data from post...
            $post = $_POST;

            if ( isset($post['odr_tab_id']) ) {
                $odr_tab_id = $post['odr_tab_id'];

                // Grab the requested state object from the user's session
                $stored_tab_data = array();
                if ( $session->has('stored_tab_data') )
                    $stored_tab_data = $session->get('stored_tab_data');

                if ( !isset($stored_tab_data[$odr_tab_id]) )
                    $stored_tab_data[$odr_tab_id] = array();

                $state = array();
                if ( isset($stored_tab_data[$odr_tab_id]['state']) )
                    $state = $stored_tab_data[$odr_tab_id]['state'];

                $return = $state;
            }
            else {
                $return = array();
            }
        }
        catch (\Exception $e) {
            $source = 0xbb8573dc;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
