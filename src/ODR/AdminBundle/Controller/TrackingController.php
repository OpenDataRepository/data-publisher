<?php

/**
 * Open Data Repository Data Publisher
 * Tracking Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller holds functions for tracking user edits
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use FOS\UserBundle\Doctrine\UserManager;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;
use Doctrine\DBAL\Connection as DBALConnection;


class TrackingController extends ODRCustomController
{

    // TODO - move the datafield history stuff from EditController into here?

    // TODO - add another controller action so users can approach from the context of "what changes have been made to this field?"
    // TODO - in this case, $display_datarecord_metadata would be false, since you don't care about datarecord stuff when looking at a specific field


    // TODO - rig the search system so it works within the modal system, in order to set datarecord criteria without going back to the search page

    // TODO - shortcuts to go to a datarecord's view/edit page from this list?  need to know the grandparent id...

    // TODO - make a tutorial to indicate you can multisort columns?
    // TODO - ...datatables.js can already multisort since the data is in-browser (hold shift when clicking a column)


    // It's pretty easy to create criteria that attempt to return the entire database...if the
    //  total of rows returned by the queries exceeds this number, all subsequent queries will be
    //  skipped.  The final number of rows displayed by the page may be less than this, depending
    //  on how many useless rows get filtered out.
    // For reference, a value of 25k still ends up taking close to 10 seconds total to load and for
    //  datatables.js to format
    const ROWS_SOFT_LIMIT = 25000;


    /**
     * Opens ODR's tracking page, and initializes it to display all changes to the given datarecord
     * over the past month that the calling user is allowed to view.
     *
     * @param integer $datarecord_id
     * @param Request $request
     *
     * @return Response
     */
    public function trackdatarecordchangesAction($datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatarecordInfoService $datarecord_info_service */
            $datarecord_info_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $grandparent_datarecord = $datarecord->getGrandparent();
            if ($grandparent_datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $grandparent_datatype = $grandparent_datarecord->getDataType();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datafield_permissions = $permissions_service->getDatafieldPermissions($user);

            // Ensure user has permissions to be doing this
            if (!$permissions_service->canEditDatarecord($user, $datarecord))
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // User is approaching this from the context of "what changes were made to this record?"
            // As such, displaying when datarecords were created/deleted/etc is useful
            $display_datarecord_metadata = true;

            // Need a couple reference time ranges...
            $today = new \DateTime();
            $month_ago = (new \DateTime())->sub(new \DateInterval("P1M"));
            $week_ago = (new \DateTime())->sub(new \DateInterval("P7D"));

            // Default to displaying results from all datafields in this datatype that the user
            //  can edit
            $dt_array = $database_info_service->getDatatypeArray($grandparent_datatype->getId(), false);    // don't want links

            $datafield_ids = array();
            foreach ($dt_array as $dt_id => $dt_data) {
                foreach ($dt_data['dataFields'] as $df_id => $df_data) {
                    // No sense having markdown fields in this
                    if ( $df_data['dataFieldMeta']['fieldType']['typeClass'] !== 'Markdown' ) {
                        if ( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['edit']) )
                            $datafield_ids[] = $df_id;
                    }
                }
            }
            $datafield_ids = implode(',', $datafield_ids);

            // Display this datarecord's grandparent datatype on the page
            $target_datatype_id = $grandparent_datatype->getId();
            $target_datatype_name = $grandparent_datatype->getLongName();

            // Also need to display this datarecord's name on the page...
            $dr_array = $datarecord_info_service->getDatarecordArray($grandparent_datarecord->getId(), false);    // don't want links
            $datarecord_name = $dr_array[$grandparent_datarecord->getId()]['nameField_value'];

            // Generate the HTML required for a header
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Tracking:tracking_wrapper.html.twig',
                    array(
                        'target_datatype_id' => $target_datatype_id,
                        'target_datatype_name' => $target_datatype_name,
                        'target_datafield_ids' => $datafield_ids,

                        'target_datarecord_id' => $grandparent_datarecord->getId(),
                        'target_datarecord_name' => $datarecord_name,
                        'datatype_id_restriction' => $grandparent_datatype->getId(),

                        'month_ago' => $month_ago->format("Y-m-d"),
                        'week_ago' => $week_ago->format("Y-m-d"),
                        'today' => $today->format("Y-m-d"),

                        'display_datarecord_metadata' => $display_datarecord_metadata,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x369e24dc;
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
     * Opens ODR's tracking page, and initializes it to display all changes over the past month to
     * datarecords matching matching the given search key.
     *
     * @param string $search_key
     * @param Request $request
     *
     * @return Response
     */
    public function tracksearchresultchangesAction($search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatatreeInfoService $datatree_info_service */
            $datatree_info_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // Ensure the search key is valid before attempting to decode it...
            if ( $search_key === '' )
                throw new ODRBadRequestException();
            $search_key_service->validateSearchKey($search_key);
            $search_params = $search_key_service->decodeSearchKey($search_key);

            // Because the search key is valid, it will always have an entry for the datatype id
            $datatype_id = $search_params['dt_id'];


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            $grandparent_datatype = $datatype->getGrandparent();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $permissions_service->getUserPermissionsArray($admin);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            // Technically doesn't need to be this restricted, but it matches with the rest of the
            //  controller actions this way
            $editable_datatypes = array();
            foreach ($datatype_permissions as $dt_id => $dt_permission) {
                if ( isset($dt_permission['dr_edit']) && $dt_permission['dr_edit'] == 1 ) {
                    $editable_datatypes[] = $dt_id;
                }
            }

            // If the user can't edit any datatype, then they're not allowed to use this action
            if ( empty($editable_datatypes) )
                throw new ODRForbiddenException();
            // --------------------

            // Filter the search key so the user can't search on stuff they can't see, and silently
            //  replace the original search key if necessary
            $search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);
            // TODO - should this throw an error or redirect instead?


            // ----------------------------------------
            // User is approaching this from the context of "what changes were made to these records?"
            // As such, displaying when datarecords were created/deleted/etc is useful
            $display_datarecord_metadata = true;

            // Need a couple reference time ranges...
            $today = new \DateTime();
            $month_ago = (new \DateTime())->sub(new \DateInterval("P1M"));
            $week_ago = (new \DateTime())->sub(new \DateInterval("P7D"));

            // Default to displaying results from all datafields in this datatype that the user
            //  can edit
            $dt_array = $database_info_service->getDatatypeArray($grandparent_datatype->getId(), true);    // need to have linked datatypes
            $datatree_array = $datatree_info_service->getDatatreeArray();

            $datafield_ids = array();
            foreach ($dt_array as $dt_id => $dt_data) {
                foreach ($dt_data['dataFields'] as $df_id => $df_data) {
                    // No sense having markdown fields in this
                    if ( $df_data['dataFieldMeta']['fieldType']['typeClass'] !== 'Markdown' ) {
                        if ( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['edit']) ) {
                            // For the purposes of tracking changes, only want the datafields that
                            //  belong to the grandparent datatype and its children, and that the
                            //  user can edit
                            $gdt_id = $datatree_info_service->getGrandparentDatatypeId($dt_id, $datatree_array);
                            if ( $grandparent_datatype->getId() === $gdt_id )
                                $datafield_ids[] = $df_id;
                        }
                    }
                }
            }
            $datafield_ids = implode(',', $datafield_ids);

            // Display this datarecord's grandparent datatype on the page
            $target_datatype_id = $grandparent_datatype->getId();
            $target_datatype_name = $grandparent_datatype->getLongName();


            // Display a more human-readable version of the search key on the page
            $readable_search_key = $search_key_service->getReadableSearchKey($search_key);

            // Generate the HTML required for a header
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Tracking:tracking_wrapper.html.twig',
                    array(
                        'search_key' => $search_key,
                        'readable_search_key' => $readable_search_key,

                        'target_datatype_id' => $target_datatype_id,
                        'target_datatype_name' => $target_datatype_name,
                        'target_datafield_ids' => $datafield_ids,

                        'datatype_id_restriction' => $grandparent_datatype->getId(),

                        'month_ago' => $month_ago->format("Y-m-d"),
                        'week_ago' => $week_ago->format("Y-m-d"),
                        'today' => $today->format("Y-m-d"),

                        'display_datarecord_metadata' => $display_datarecord_metadata,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x1e9ed213;
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
     * Opens ODR's tracking page, and initializes it to display all non-layout changes to the given
     * datatype over the past month that the calling user is allowed to view.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function trackdatatypechangesAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            $grandparent_datatype = $datatype->getGrandparent();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datafield_permissions = $permissions_service->getDatafieldPermissions($user);

            // Ensure user has permissions to be doing this
            if (!$permissions_service->canEditDatatype($user, $datatype))
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // User is approaching this from the context of "what changes were made to all records in this datatype?"
            // As such, displaying when datarecords were created/deleted/etc is useful
            $display_datarecord_metadata = true;

            // Need a couple reference time ranges...
            $today = new \DateTime();
            $month_ago = (new \DateTime())->sub(new \DateInterval("P1M"));
            $week_ago = (new \DateTime())->sub(new \DateInterval("P7D"));

            // Default to displaying results from all datafields in this datatype that the user
            //  can edit
            $dt_array = $database_info_service->getDatatypeArray($grandparent_datatype->getId(), false);    // don't want links

            $datafield_ids = array();
            foreach ($dt_array as $dt_id => $dt_data) {
                foreach ($dt_data['dataFields'] as $df_id => $df_data) {
                    // No sense having markdown fields in this
                    if ( $df_data['dataFieldMeta']['fieldType']['typeClass'] !== 'Markdown' ) {
                        if ( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['edit']) )
                            $datafield_ids[] = $df_id;
                    }
                }
            }
            $datafield_ids = implode(',', $datafield_ids);

            // Display this datarecord's grandparent datatype on the page
            $target_datatype_id = $grandparent_datatype->getId();
            $target_datatype_name = $grandparent_datatype->getLongName();


            // Generate the HTML required for a header
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Tracking:tracking_wrapper.html.twig',
                    array(
                        'target_datatype_id' => $target_datatype_id,
                        'target_datatype_name' => $target_datatype_name,
                        'target_datafield_ids' => $datafield_ids,

                        'month_ago' => $month_ago->format("Y-m-d"),
                        'week_ago' => $week_ago->format("Y-m-d"),
                        'today' => $today->format("Y-m-d"),

                        'display_datarecord_metadata' => $display_datarecord_metadata,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xe40edc75;
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
     * Opens ODR's tracking page, and initializes it to display all changes that the target user has
     * made over the past month that the calling user is allowed to view.
     *
     * @param integer $target_user_id
     * @param Request $request
     *
     * @return Response
     */
    public function trackuserchangesAction($target_user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var ODRUser $target_user */
            $target_user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($target_user_id);
            if ($target_user == null)
                throw new ODRNotFoundException('User');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $permissions_service->getDatatypePermissions($admin);

            // Determine whether the user is an admin for any datatype
            $valid_datatype_ids = array();
            foreach ($datatype_permissions as $dt_id => $dt_permission) {
                if ( isset($dt_permission['dt_admin']) && $dt_permission['dt_admin'] == 1 )
                    $valid_datatype_ids[$dt_id] = 1;
            }

            // User needs to be an admin of at least one datatype before they can track user changes
            if ( empty($valid_datatype_ids) )
                throw new ODRForbiddenException();
            // --------------------


            // The following query shouldn't be run when the target is a super admin...it'll always
            //  throw the exeption because the target user isn't a member of any group in the database
            if ( !$target_user->hasRole('ROLE_SUPER_ADMIN') ) {
                // Determine which groups the target user has been a member of...
                $query =
                   'SELECT g.data_type_id AS dt_id
                    FROM odr_user_group AS ug
                    JOIN odr_group AS g ON ug.group_id = g.id
                    WHERE ug.user_id = :user_id';
                $params = array('user_id' => $target_user->getId());

                $conn = $em->getConnection();
                $results = $conn->executeQuery($query, $params);

                // ...because the admin user can only track changes made by users who are members in
                //  groups for datatypes that the admin user has the is_datatype_admin permission
                $found = false;
                foreach ($results as $result) {
                    $dt_id = $result['dt_id'];
                    if (isset($valid_datatype_ids[$dt_id])) {
                        $found = true;
                        break;
                    }
                }

                if (!$found)
                    throw new ODRForbiddenException();
            }


            // ----------------------------------------
            // User is approaching this from the context of "what changes has this user made?"
            // As such, displaying when datarecords were created/deleted/etc is useful
            $display_datarecord_metadata = true;

            // Need a couple reference time ranges...
            $today = new \DateTime();
            $month_ago = (new \DateTime())->sub(new \DateInterval("P1M"));
            $week_ago = (new \DateTime())->sub(new \DateInterval("P7D"));

            // Generate the HTML required for a header
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Tracking:tracking_wrapper.html.twig',
                    array(
                        'target_user_id' => $target_user->getId(),
                        'target_user_name' => $target_user->getUserString(),

                        'month_ago' => $month_ago->format("Y-m-d"),
                        'week_ago' => $week_ago->format("Y-m-d"),
                        'today' => $today->format("Y-m-d"),

                        'display_datarecord_metadata' => $display_datarecord_metadata,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xd264cd70;
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
     * Receives and validates a POST request of tracking filter criteria, then returns a table of
     * all changes to ODR's storage entities (i.e. ShortVarchar, File, RadioSelection) that match
     * the criteria.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function trackchangesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $request->request->all();
//print_r($post);  exit();

            // Always need to have these...
            if ( !isset($post['start_date']) || !isset($post['end_date']) )
                throw new ODRBadRequestException();


            // Convert the given date strings into Datetime objects
            // The "|" character causes the H:i:s portion to default to 00:00:00
            $start = \DateTime::createFromFormat("Y-m-d|", $post['start_date']);
            $start_errors = \DateTime::getLastErrors();
            if ( $start_errors['error_count'] > 0 )
                throw new ODRBadRequestException("Invalid start date");
            $end = \DateTime::createFromFormat("Y-m-d|", $post['end_date']);
            $end_errors = \DateTime::getLastErrors();
            if ( $end_errors['error_count'] > 0 )
                throw new ODRBadRequestException("Invalid end date");

            // End date needs to be incremented by one day...because of the mysql BETWEEN condition,
            //  an end date of "2020-01-01" won't actually return any changes made on 1 Jan 2020
            $end->add(new \DateInterval("P1D"));

            // Ensure start date is before end date...
            $interval = date_diff($start, $end);
            if ($interval->invert === 1)
                throw new ODRBadRequestException('Start date must be before end date');


            // Extract this as well
            $display_datarecord_metadata = false;
            if ( isset($post['display_datarecord_metadata']) )
                $display_datarecord_metadata = true;
            $simple = false;
            if ( isset($post['simple']) )
                $simple = true;

            // Also need to have at least one of these...
            $target_datarecord_id = null;
            if ( isset($post['target_datarecord_id']) && trim($post['target_datarecord_id']) !== '')
                $target_datarecord_id = $post['target_datarecord_id'];
            $target_search_key = null;
            if ( isset($post['target_search_key']) && trim($post['target_search_key']) !== '')
                $target_search_key = $post['target_search_key'];
            $target_datafield_ids = null;
            if ( isset($post['target_datafield_ids']) && trim($post['target_datafield_ids']) !== '' )
                $target_datafield_ids = $post['target_datafield_ids'];
            $target_user_ids = null;
            if ( isset($post['target_user_ids']) && trim($post['target_user_ids']) !== '' )
                $target_user_ids = $post['target_user_ids'];

            // Don't want to throw an error when no criteria is listed...
//            if (is_null($target_datafield_ids) && is_null($target_search_key) && is_null($target_datarecord_id) && is_null($target_user_ids))
//                throw new ODRBadRequestException();


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $grandparent_datatype */
            $grandparent_datatype = null;
            /** @var DataRecord|null $grandparent_datarecord */
            $grandparent_datarecord = null;

            if ( !is_null($target_datarecord_id) ) {
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($target_datarecord_id);
                if ($datarecord == null)
                    throw new ODRNotFoundException('Datarecord');
                $grandparent_datarecord = $datarecord->getGrandparent();

                if ($datarecord->getDataType()->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datatype');
                $grandparent_datatype = $datarecord->getDataType()->getGrandparent();
            }

            if ( !is_null($target_search_key) ) {
                $search_key_service->validateSearchKey($target_search_key);
                $search_params = $search_key_service->decodeSearchKey($target_search_key);

                // Since the search key is valid, it will always have a datatype id in there
                $dt_id = $search_params['dt_id'];

                /** @var DataType $datatype */
                $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($dt_id);
                if ($datatype == null)
                    throw new ODRNotFoundException('Datatype');
                $grandparent_datatype = $datatype->getGrandparent();
            }


            if ( !is_null($grandparent_datatype) ) {
                if ($grandparent_datatype->getDeletedAt() != null)
                    throw new ODRNotFoundException('Datatype');
            }


            // Typically, there would be some sort of verification done on the target_datafield_ids
            //  or the target_user_ids that were passed in via post, but the code that builds the
            //  criteria arrays silently ignores datafields/users the calling user isn't allowed to
            //  see

            // Instead, just check that the ids are positive integers, if any were provided
            if ( !is_null($target_datafield_ids) ) {
                $target_datafield_ids = explode(',', $target_datafield_ids);
                foreach ($target_datafield_ids as $num => $df_id) {
                    if ( !is_numeric($df_id) || intval($df_id) < 1 )
                        throw new ODRBadRequestException('Invalid datafield id');
                    else
                        $target_datafield_ids[$num] = intval($df_id);
                }
            }
            if ( !is_null($target_user_ids) ) {
                $target_user_ids = explode(',', $target_user_ids);
                foreach ($target_user_ids as $num => $u_id) {
                    if ( !is_numeric($u_id) || intval($u_id) < 1 )
                        throw new ODRBadRequestException('Invalid user id');
                    else
                        $target_user_ids[$num] = intval($u_id);
                }
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $permissions_service->getUserPermissionsArray($admin);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            // Need to determine which datatypes the user is allowed to edit...
            $editable_datatypes = array();
            foreach ($datatype_permissions as $dt_id => $dt_permission) {
                if ( isset($dt_permission['dr_edit']) && $dt_permission['dr_edit'] == 1 ) {
                    $editable_datatypes[] = $dt_id;
                }
            }

            // If the user can't edit any datatype, then they're not allowed to use this action
            if ( empty($editable_datatypes) )
                throw new ODRForbiddenException();
            // --------------------


            if ( !is_null($target_search_key) ) {
                // Filter the search key so the user can't search on stuff they can't see, and
                //  silently replace the original search key if necessary
                $target_search_key = $search_api_service->filterSearchKeyForUser($datatype, $target_search_key, $user_permissions);
                $search_params = $search_key_service->decodeSearchKey($target_search_key);
                // TODO - should this throw an error or redirect instead?
            }


            // ----------------------------------------
            // It's pretty easy to create criteria that attempt to return the entire database...
            //  if the total number of rows returned by the queries exceeds this number, all
            //  subsequent queries will be skipped.  The final number of rows displayed by the
            //  page may be less than this, depending on how many useless rows get filtered out.
            // For reference, a value of 25k still ends up taking close to 10 seconds total to
            //  load and for datatables.js to format
            $row_count = 0;

            // Only perform a search when there's some criteria set...just a date range is unacceptable
            $no_criteria = true;

            $history = array();
            $dr_created_deleted_history = array();
            $names = array();
            $child_dr_lookup = array();

            if ( !is_null($target_datarecord_id) || !is_null($target_search_key)
                || !is_null($target_user_ids) || !is_null($target_datafield_ids)
            ) {
                // Build an array of all the criteria that got passed in...
                $no_criteria = false;
                $criteria = array();

                // Need to save datatype ids so that the datarecord created/deleted/public_status
                //  changes are found...
                $datatype_ids = array();

                // Save the date range that contains the changes the user is interested in...
                if ( !is_null($start) ) {
                    $criteria['start_date'] = $start;
                    $criteria['end_date'] = $end;
                }

                // If a single datarecord was specified, save that
                if ( !is_null($grandparent_datarecord) ) {
                    $criteria['grandparent_datarecord_ids'] = array($grandparent_datarecord->getId());
                }
                // If a search key was specified, load all grandparent datarecords that match the
                //  search...
                if ( !is_null($target_search_key) ) {
                    // ...but only if something other than the datatype id was specified
                    if ( count($search_params) > 1 ) {
                        $criteria['grandparent_datarecord_ids'] = $search_api_service->performSearch(
                            $grandparent_datatype,
                            $target_search_key,
                            $user_permissions
                        );    // this only returns grandparent datarecord ids
                    }

                    // If just the datatype id was specified, then the search will match "any"
                    //  datarecord...so the criteria might as well not specify any datarecord ids
                    //  in that case
                }

                // Save which users the results should be filtered by
                if ( !is_null($target_user_ids) )
                    $criteria['target_user_ids'] = $target_user_ids;

                // Save which datafields the results shold be filtered by
                $datafield_ids = array();
                if ( !is_null($target_datafield_ids) )
                    $datafield_ids = $target_datafield_ids;

                if ( !is_null($grandparent_datatype) ) {
                    // If no datafields were specified, then default to all datafields of this datatype
                    //  when possible
                    $datatype_ids = array( $grandparent_datatype->getId() );
                }
                else {
                    // Otherwise, run a query to load which datatypes those datafields belong to
                    $datatype_ids = self::getDatafieldOwners($em, $datafield_ids);
                }


                // ----------------------------------------
                // NOTE - When $criteria['datatype_ids'] is empty, then self::getDatarecordChanges()
                //  won't return anything...the other functions ignore that array entry in favor
                //  of the array or datafields returned by self::getDatafieldTypeclasses()
                // ----------------------------------------

                // The user directly controls whether they want to see datarecord created/deleted/etc
                if ( $display_datarecord_metadata ) {
                    // If yes, then ensure there's something in $criteria['datatype_ids']
                    if ( !isset($criteria['datatype_ids']) || empty($criteria['datatype_ids']) ) {
                        if ( is_null($target_datafield_ids) ) {
                            // No datafields set, so the user is probably looking for all changes
                            //  made by a user...should use the list of editable datatypes for that
                            $criteria['datatype_ids'] = $editable_datatypes;
                        }
                        else {
                            // Otherwise, only interested in the datarecord created/deleted/etc
                            //  entries that are releated to the rest of the criteria
                            $criteria['datatype_ids'] = $datatype_ids;
                        }
                    }
                }
                else {
                    // If no, then ensure there's nothing in $criteria['datatype_ids']
                    $criteria['datatype_ids'] = array();
                }


                // ----------------------------------------
                // Need to locate typeclasses for all datafields being searched on
                // This function is also where permissions are applied...datafields the user isn't
                //  allowed to edit are filtered out
                $datafields_by_typeclass = self::getDatafieldTypeclasses($em, $datafield_permissions, $datafield_ids, $datatype_ids);


                // ----------------------------------------
                // The functions are split apart to be both easier to read and easier on the database
                // The $history array can't be passed by reference to each of the functions because
                //  they typically need to filter out senseless or useless data...
                $text_number_changes = self::getTextNumberChanges($em, $datafields_by_typeclass, $criteria, $row_count);
                $file_image_changes = self::getFileImageChanges($em, $datafields_by_typeclass, $criteria, $row_count);
                $radio_tag_changes = self::getRadioTagChanges($em, $datafields_by_typeclass, $criteria, $row_count);
                $xyz_changes = self::getXYZValueChanges($em, $datafields_by_typeclass, $criteria, $row_count);

                // Also need to get a list of datarecords that were created/deleted under this criteria
                $dr_created_deleted_history = self::getDatarecordChanges($em, $criteria, $row_count);

                // Combine all of the datafield-level changes...
//                if ( $simple )
//                    $history = self::combineArraysSimple($text_number_changes, $file_image_changes, $radio_tag_changes, $xyz_changes);
//                else
                    $history = self::combineArrays($text_number_changes, $file_image_changes, $radio_tag_changes, $xyz_changes);

                // ...and locate the grandparents of all child records, so the table can identify them
                //  by their grandparent instead of themselves
                $child_dr_lookup = self::getChildDrLookup($em, $history);

                // Need to convert all the ids into names...
                $names = self::getNames($em, $history, $dr_created_deleted_history, $child_dr_lookup);

                if ( $simple )
                    $history = self::combineArraysSimple($text_number_changes, $file_image_changes, $radio_tag_changes, $xyz_changes);
            }


            // ----------------------------------------
            // Render and return a table of all the data
            $rows_exceeded = false;
            if ( $row_count > self::ROWS_SOFT_LIMIT )
                $rows_exceeded = true;

            $template_name = 'ODRAdminBundle:Tracking:tracking_data.html.twig';
            if ( $simple )
                $template_name = 'ODRAdminBundle:Tracking:tracking_data_simple.html.twig';

            $return['d'] = array(
                'html' => $templating->render(
                    $template_name,
                    array(
                        'no_criteria' => $no_criteria,
                        'history' => $history,
                        'dr_history' => $dr_created_deleted_history,

                        'names' => $names,
                        'child_dr_lookup' => $child_dr_lookup,
                    )
                ),
                'rows_exceeded' => $rows_exceeded,
            );
        }
        catch (\Exception $e) {
            $source = 0x8db8f8f5;
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
     * Helper function that converts all given datafields (or all datafields in the given datatypes)
     * into an array ordered by typeclass.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datafield_permissions
     * @param array $datafield_ids
     * @param array $datatype_ids
     *
     * @return array
     */
    private function getDatafieldTypeclasses($em, $datafield_permissions, $datafield_ids, $datatype_ids = array())
    {
        // If datafields are listed, then ignore anything in the datatype list
        if ( !empty($datafield_ids) )
            $datatype_ids = array();

        // These are the only fieldtypes that can be searched like this...
        $datafields_by_typeclass = array(
            'Boolean' => array(),
            'IntegerValue' => array(),
            'DecimalValue' => array(),
            'ShortVarchar' => array(),
            'MediumVarchar' => array(),
            'LongVarchar' => array(),
            'LongText' => array(),
            'DatetimeValue' => array(),
            'File' => array(),
            'Image' => array(),
            'Radio' => array(),
            'Tag' => array(),
            'XYZData' => array(),
        );

        // Load all datafields in the datafield/datatype list, excluding those which belong to
        //  template or metadata datatypes
        $query =
           'SELECT df.id AS df_id, ft.type_class AS typeclass
            FROM odr_data_type dt
            JOIN odr_data_fields df ON df.data_type_id = dt.id
            JOIN odr_data_fields_meta dfm ON dfm.data_field_id = df.id
            JOIN odr_field_type ft ON dfm.field_type_id = ft.id
            WHERE dt.is_master_type = 0 AND dt.metadata_for_id IS NULL
            AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL';
        if ( !empty($datafield_ids) )
            $query .= ' AND df.id IN (:datafield_ids)';
        if ( !empty($datatype_ids) )
            $query .= ' AND dt.grandparent_id IN (:datatype_ids)';

        $params = array();
        $types = array();

        if ( !empty($datafield_ids) ) {
            $params['datafield_ids'] = $datafield_ids;
            $types['datafield_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        }

        if ( !empty($datatype_ids) ) {
            $params['datatype_ids'] = $datatype_ids;
            $types['datatype_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        }

        $conn = $em->getConnection();
        $results = $conn->executeQuery($query, $params, $types);

        foreach ($results as $result) {
            $df_id = intval($result['df_id']);
            $typeclass = $result['typeclass'];

            // The user making this request might not be a datatype admin, so they need to be
            //  prevented from viewing changes to datafields they can't edit
            if ( isset($datafield_permissions[$df_id]['edit']) ) {
                // Ignore typeclasses that aren't listed above (e.g. Markdown)
                if ( isset($datafields_by_typeclass[$typeclass]) )
                    $datafields_by_typeclass[$typeclass][] = $df_id;
            }
        }

        return $datafields_by_typeclass;
    }


    /**
     * Executes a query to find which datatypes the given datafields belong to.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datafield_ids
     *
     * @return array
     */
    private function getDatafieldOwners($em, $datafield_ids)
    {
        $query =
           'SELECT DISTINCT df.data_type_id AS dt_id
            FROM odr_data_fields df
            WHERE df.id IN (:datafield_ids)';
        $params = array(
            'datafield_ids' => $datafield_ids
        );
        $types = array(
            'datafield_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY
        );

        $conn = $em->getConnection();
        $results = $conn->executeQuery($query, $params, $types);

        $datatype_ids = array();
        foreach ($results as $result) {
            $dt_id = intval($result['dt_id']);
            $datatype_ids[] = $dt_id;
        }

        return $datatype_ids;
    }


    /**
     * Executes a series of queries on ODR's text/number/date fields to find all changes made to
     * them, filtered by the given criteria.  (i.e. changes within this date range, changes made by
     * a specific user, etc)
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datafields_by_typeclass
     * @param array $criteria
     * @param int $row_count
     *
     * @return array
     */
    private function getTextNumberChanges($em, $datafields_by_typeclass, $criteria, &$row_count)
    {
        // For every text/number/boolean field...
        $typeclass_map = array(
            'Boolean' => 'odr_boolean',
            'IntegerValue' => 'odr_integer_value',
            'DecimalValue' => 'odr_decimal_value',
            'ShortVarchar' => 'odr_short_varchar',
            'MediumVarchar' => 'odr_medium_varchar',
            'LongVarchar' => 'odr_long_varchar',
            'LongText' => 'odr_long_text',
            'DatetimeValue' => 'odr_datetime_value'
        );

        $conn = $em->getConnection();

        $history = array();
        foreach ($datafields_by_typeclass as $typeclass => $df_list) {
            // Don't do anything if no datafields are listed...
            if ( empty($df_list) )
                continue;

            // Don't do anything if this isn't the correct query for the typeclass...
            if ( !isset($typeclass_map[$typeclass]) )
                continue;
            $table_name = $typeclass_map[$typeclass];

            // NOTE - anything that attempts to use a datatype_id completely wrecks query speed
            // NOTE - not joining the datafield table seems to help speed a bit
            // NOTE - have to use the created property, since the updated property is changed when an entity is deleted
            $query =
               'SELECT dr.data_type_id AS dt_id, dr.id AS dr_id, e.data_field_id AS df_id,
	                e.id AS id, e.value AS value, e.created AS updated, e.createdBy AS updatedBy
                FROM '.$table_name.' e
                JOIN odr_data_record_fields drf ON e.data_record_fields_id = drf.id
                JOIN odr_data_record dr ON drf.data_record_id = dr.id
                WHERE dr.deletedAt IS NULL AND drf.deletedAt IS NULL
                AND e.data_field_id IN (:datafield_ids)';
            if ( isset($criteria['start_date']) )
                $query .= ' AND e.created BETWEEN :start_date AND :end_date';
            if ( isset($criteria['target_user_ids']) )
                $query .= ' AND e.createdBy IN (:target_user_ids)';
            if ( isset($criteria['grandparent_datarecord_ids']) )
                $query .= ' AND dr.grandparent_id IN (:grandparent_datarecord_ids)';
            $query .= ' ORDER BY e.created';


            // Always going to have a list of datafield ids...
            $params = array(
                'datafield_ids' => $df_list,
            );
            $types = array(
                'datafield_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY,
            );

            if ( isset($criteria['start_date']) ) {
                $params['start_date'] = ($criteria['start_date'])->format("Y-m-d H:i:s");
                $params['end_date'] = ($criteria['end_date'])->format("Y-m-d H:i:s");
            }

            if ( isset($criteria['target_user_ids']) ) {
                $params['target_user_ids'] = $criteria['target_user_ids'];
                $types['target_user_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
            }

            if ( isset($criteria['grandparent_datarecord_ids']) ) {
                $params['grandparent_datarecord_ids'] = $criteria['grandparent_datarecord_ids'];
                $types['grandparent_datarecord_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
            }

            // Don't execute this query if the previous queries have processed more than the soft
            //  limit placed on rows
            if ( $row_count > self::ROWS_SOFT_LIMIT )
                continue;

            $results = $conn->executeQuery($query, $params, $types);
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $dr_id = $result['dr_id'];
                $df_id = $result['df_id'];
                $id = $result['id'];
                $value = $result['value'];
                $updated = $result['updated'];
                $updatedBy = $result['updatedBy'];

                if ( !isset($history[$dt_id]) )
                    $history[$dt_id] = array();
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();
                if ( !isset($history[$dt_id][$dr_id][$df_id]) )
                    $history[$dt_id][$dr_id][$df_id] = array();
                if ( !isset($history[$dt_id][$dr_id][$df_id][$id]) )
                    $history[$dt_id][$dr_id][$df_id][$id] = array();

                $history[$dt_id][$dr_id][$df_id][$id][$updated] = array(
                    'value' => $value,
                    'updatedBy' => $updatedBy,
                );

                // Increment the number of rows that have been processed
                $row_count++;
                if ( $row_count > self::ROWS_SOFT_LIMIT )
                    break;
            }
        }

        // Filter out entries where there's not actually any difference and return the final array
        return self::filterTextNumberChanges($history, $datafields_by_typeclass);
    }


    /**
     * Executes a series of queries on ODR's file/image fields to find all uploads, deletions, and
     * public status changes made to them, filtered by the given criteria.  (i.e. changes within
     * this date range, changes made by a specific user, etc)
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datafields_by_typeclass
     * @param array $criteria
     * @param int $row_count
     *
     * @return array
     */
    private function getFileImageChanges($em, $datafields_by_typeclass, $criteria, &$row_count)
    {
        // For every file/image field...
        $typeclass_map = array(
            'File' => 'odr_file',
            'Image' => 'odr_image',
        );

        $conn = $em->getConnection();

        $history = array();
        foreach ($datafields_by_typeclass as $typeclass => $df_list) {
            // Don't do anything if no datafields are listed...
            if ( empty($df_list) )
                continue;

            // Don't do anything if this isn't the correct query for the typeclass...
            if ( !isset($typeclass_map[$typeclass]) )
                continue;

            // NOTE - anything that attempts to use a datatype_id completely wrecks query speed
            // NOTE - not joining the datafield table seems to help speed a bit
            $table_name = $typeclass_map[$typeclass];
            $join_id = 'file_id';
            if ($typeclass === 'Image')
                $join_id = 'image_id';


            // ----------------------------------------
            // One query to determine when a file/image was uploaded...
            $query_created =
               'SELECT dr.data_type_id AS dt_id, dr.id AS dr_id, e.data_field_id AS df_id,
                    e.id AS file_id, e.created AS created, e.createdBy AS createdBy,
                    em.original_file_name AS filename
                FROM '.$table_name.'_meta AS em
                JOIN '.$table_name.' AS e ON em.'.$join_id.' = e.id
                JOIN odr_data_record_fields AS drf ON e.data_record_fields_id = drf.id
                JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
                WHERE dr.deletedAt IS NULL AND drf.deletedAt IS NULL
                AND e.data_field_id IN (:datafield_ids)';
            if ( $typeclass === 'Image' )
                $query_created .= ' AND e.original = 1';
            if ( isset($criteria['start_date']) )
                $query_created .= ' AND e.created BETWEEN :start_date AND :end_date';
            if ( isset($criteria['target_user_ids']) )
                $query_created .= ' AND e.createdBy IN (:target_user_ids)';
            if ( isset($criteria['grandparent_datarecord_ids']) )
                $query_created .= ' AND dr.grandparent_id IN (:grandparent_datarecord_ids)';
            $query_created .= ' ORDER BY e.created';
            // ----------------------------------------
            // ----------------------------------------
            // ...another query to determine when public date got changed...
            // NOTE - have to use the created property, since the updated property is changed when an entity is deleted
            $query_updated =
               'SELECT dr.data_type_id AS dt_id, dr.id AS dr_id, e.data_field_id AS df_id,
                    e.id AS file_id, em.created AS updated, em.createdBy AS updatedBy, em.public_date AS public_date,
                    em.original_file_name AS filename
                FROM '.$table_name.'_meta AS em
                JOIN '.$table_name.' AS e ON em.'.$join_id.' = e.id
                JOIN odr_data_record_fields AS drf ON e.data_record_fields_id = drf.id
                JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
                WHERE dr.deletedAt IS NULL AND drf.deletedAt IS NULL
                AND e.data_field_id IN (:datafield_ids)';
            if ( $typeclass === 'Image' )
                $query_updated .= ' AND e.original = 1';
            if ( isset($criteria['start_date']) )
                $query_updated .= ' AND em.created BETWEEN :start_date AND :end_date';
            if ( isset($criteria['target_user_ids']) )
                $query_updated .= ' AND em.createdBy IN (:target_user_ids)';
            if ( isset($criteria['grandparent_datarecord_ids']) )
                $query_updated .= ' AND dr.grandparent_id IN (:grandparent_datarecord_ids)';
            $query_updated .= ' ORDER BY em.created';
            // ----------------------------------------
            // ----------------------------------------
            // ...and a final query to determine when a file/image got deleted
            $query_deleted =
               'SELECT dr.data_type_id AS dt_id, dr.id AS dr_id, e.data_field_id AS df_id,
                    e.id AS file_id, e.deletedAt AS deletedAt, e.deletedBy AS deletedBy,
                    em.original_file_name AS filename
                FROM '.$table_name.'_meta AS em
                JOIN '.$table_name.' AS e ON em.'.$join_id.' = e.id
                JOIN odr_data_record_fields AS drf ON e.data_record_fields_id = drf.id
                JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
                WHERE dr.deletedAt IS NULL AND drf.deletedAt IS NULL
                AND e.data_field_id IN (:datafield_ids)';
            if ( $typeclass === 'Image' )
                $query_deleted .= ' AND e.original = 1';
            if ( isset($criteria['start_date']) )
                $query_deleted .= ' AND e.deletedAt BETWEEN :start_date AND :end_date';
            if ( isset($criteria['target_user_ids']) )
                $query_deleted .= ' AND e.deletedBy IN (:target_user_ids)';
            if ( isset($criteria['grandparent_datarecord_ids']) )
                $query_deleted .= ' AND dr.grandparent_id IN (:grandparent_datarecord_ids)';
            $query_deleted .= ' ORDER BY e.deletedAt';
            // ----------------------------------------


            // ----------------------------------------
            // Always going to have a list of datafield ids...
            $params = array(
                'datafield_ids' => $df_list,
            );
            $types = array(
                'datafield_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY,
            );

            if ( isset($criteria['start_date']) ) {
                $params['start_date'] = ($criteria['start_date'])->format("Y-m-d H:i:s");
                $params['end_date'] = ($criteria['end_date'])->format("Y-m-d H:i:s");
            }

            if ( isset($criteria['target_user_ids']) ) {
                $params['target_user_ids'] = $criteria['target_user_ids'];
                $types['target_user_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
            }

            if ( isset($criteria['grandparent_datarecord_ids']) ) {
                $params['grandparent_datarecord_ids'] = $criteria['grandparent_datarecord_ids'];
                $types['grandparent_datarecord_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
            }


            // ----------------------------------------
            //
            $queries = array(
                'created' => $query_created,
                'updated' => $query_updated,
                'deleted' => $query_deleted,
            );

            foreach ($queries as $query_type => $query) {
                // Don't execute this query if the previous queries have processed more than the soft
                //  limit placed on rows
                if ( $row_count > self::ROWS_SOFT_LIMIT )
                    continue;

                $results = $conn->executeQuery($query, $params, $types);

                foreach ($results as $result) {
                    $dt_id = $result['dt_id'];
                    $dr_id = $result['dr_id'];
                    $df_id = $result['df_id'];
                    $file_id = $result['file_id'];
                    $filename = $result['filename'];

                    $date = null;
                    $user_id = null;
                    switch ($query_type) {
                        case 'created':
                            $date = $result['created'];
                            $user_id = $result['createdBy'];
                            break;
                        case 'updated':
                            $date = $result['updated'];
                            $user_id = $result['updatedBy'];
                            break;
                        case 'deleted':
                            $date = $result['deletedAt'];
                            $user_id = $result['deletedBy'];
                            break;
                    }

                    if ( !isset($history[$dt_id]) )
                        $history[$dt_id] = array();
                    if ( !isset($history[$dt_id][$dr_id]) )
                        $history[$dt_id][$dr_id] = array();
                    if ( !isset($history[$dt_id][$dr_id][$df_id]) )
                        $history[$dt_id][$dr_id][$df_id] = array();
                    if ( !isset($history[$dt_id][$dr_id][$df_id][$file_id]) )
                        $history[$dt_id][$dr_id][$df_id][$file_id] = array();

                    switch ($query_type) {
                        case 'created':
                            $history[$dt_id][$dr_id][$df_id][$file_id][$date]['filename'] = $filename;
                            $history[$dt_id][$dr_id][$df_id][$file_id][$date]['uploadedBy'] = $user_id;
                            break;
                        case 'updated':
                            $history[$dt_id][$dr_id][$df_id][$file_id][$date]['filename'] = $filename;
                            $history[$dt_id][$dr_id][$df_id][$file_id][$date]['updatedBy'] = $user_id;
                            $history[$dt_id][$dr_id][$df_id][$file_id][$date]['public_date'] = $result['public_date'];
                            break;
                        case 'deleted':
                            $history[$dt_id][$dr_id][$df_id][$file_id][$date]['filename'] = $filename;
                            $history[$dt_id][$dr_id][$df_id][$file_id][$date]['deletedBy'] = $user_id;
                            break;
                    }

                    // Increment the number of rows that have been processed
                    $row_count++;
                    if ( $row_count > self::ROWS_SOFT_LIMIT )
                        break;
                }
            }
        }

        // Filter out entries where there's not actually any difference and return the final array
        return self::filterFileImageChanges($history);
    }


    /**
     * Executes a series of queries on ODR's radio/tag fields to find all changes made to them,
     * filtered by the given criteria.  (i.e. changes within this date range, changes made by a
     * specific user, etc)
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datafields_by_typeclass
     * @param array $criteria
     * @param int $row_count
     *
     * @return array
     */
    private function getRadioTagChanges($em, $datafields_by_typeclass, $criteria, &$row_count)
    {
        // For every radio/tag field...
        $typeclass_map = array(
            'Radio' => '',
            'Tag' => '',
        );

        $conn = $em->getConnection();

        $history = array();
        foreach ($datafields_by_typeclass as $typeclass => $df_list) {
            // Don't do anything if no datafields are listed...
            if ( empty($df_list) )
                continue;

            // Don't do anything if this isn't the correct query for the typeclass...
            if ( !isset($typeclass_map[$typeclass]) )
                continue;

            // NOTE - anything that attempts to use a datatype_id completely wrecks query speed
            // NOTE - not joining the datafield table seems to help speed a bit
            // NOTE - have to use the created property, since the updated property is changed when an entity is deleted
            $query = '';
            if ($typeclass === 'Radio') {
                $query =
                   'SELECT dr.data_type_id AS dt_id, dr.id AS dr_id, ro.data_fields_id AS df_id,
                        ro.id AS id, ro.option_name AS name,
                        rs.selected AS selected, rs.created AS updated, rs.createdBy AS updatedBy
                    FROM odr_radio_selection AS rs
                    JOIN odr_radio_options AS ro ON rs.radio_option_id = ro.id
                    JOIN odr_data_record_fields AS drf ON rs.data_record_fields_id = drf.id
                    JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
                    WHERE dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND ro.deletedAt IS NULL
                    AND ro.data_fields_id IN (:datafield_ids)';
                if ( isset($criteria['start_date']) )
                    $query .= ' AND rs.created BETWEEN :start_date AND :end_date';
                if ( isset($criteria['target_user_ids']) )
                    $query .= ' AND rs.createdBy IN (:target_user_ids)';
                if ( isset($criteria['grandparent_datarecord_ids']) )
                    $query .= ' AND dr.grandparent_id IN (:grandparent_datarecord_ids)';
                $query .= ' ORDER BY rs.created';
            }
            else {
                $query =
                   'SELECT dr.data_type_id AS dt_id, dr.id AS dr_id, t.data_fields_id AS df_id,
                        t.id AS id, t.tag_name AS name,
                        ts.selected AS selected, ts.created AS updated, ts.createdBy AS updatedBy
                    FROM odr_tag_selection AS ts
                    JOIN odr_tags AS t ON ts.tag_id = t.id
                    JOIN odr_data_record_fields AS drf ON ts.data_record_fields_id = drf.id
                    JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
                    WHERE dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND t.deletedAt IS NULL
                    AND t.data_fields_id IN (:datafield_ids)';
                if ( isset($criteria['start_date']) )
                    $query .= ' AND ts.created BETWEEN :start_date AND :end_date';
                if ( isset($criteria['target_user_ids']) )
                    $query .= ' AND ts.createdBy IN (:target_user_ids)';
                if ( isset($criteria['grandparent_datarecord_ids']) )
                    $query .= ' AND dr.grandparent_id IN (:grandparent_datarecord_ids)';
                $query .= ' ORDER BY ts.created';
            }

            // ----------------------------------------
            // Always going to have a list of datafield ids...
            $params = array(
                'datafield_ids' => $df_list,
            );
            $types = array(
                'datafield_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY,
            );

            if ( isset($criteria['start_date']) ) {
                $params['start_date'] = ($criteria['start_date'])->format("Y-m-d H:i:s");
                $params['end_date'] = ($criteria['end_date'])->format("Y-m-d H:i:s");
            }

            if ( isset($criteria['target_user_ids']) ) {
                $params['target_user_ids'] = $criteria['target_user_ids'];
                $types['target_user_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
            }

            if ( isset($criteria['grandparent_datarecord_ids']) ) {
                $params['grandparent_datarecord_ids'] = $criteria['grandparent_datarecord_ids'];
                $types['grandparent_datarecord_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
            }


            // ----------------------------------------
            // Don't execute this query if the previous queries have processed more than the soft
            //  limit placed on rows
            if ( $row_count > self::ROWS_SOFT_LIMIT )
                continue;

            $results = $conn->executeQuery($query, $params, $types);
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $dr_id = $result['dr_id'];
                $df_id = $result['df_id'];

                $id = $result['id'];
                $name = $result['name'];
                $selected = $result['selected'];
                $updated = $result['updated'];
                $updatedBy = $result['updatedBy'];

                if ( !isset($history[$dt_id]) )
                    $history[$dt_id] = array();
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();
                if ( !isset($history[$dt_id][$dr_id][$df_id]) )
                    $history[$dt_id][$dr_id][$df_id] = array();
                if ( !isset($history[$dt_id][$dr_id][$df_id][$id]) )
                    $history[$dt_id][$dr_id][$df_id][$id] = array();

                $history[$dt_id][$dr_id][$df_id][$id][$updated] = array(
                    'name' => $name,
                    'selected' => $selected,
                    'selectedBy' => $updatedBy,
                );

                // Increment the number of rows that have been processed
                $row_count++;
                if ( $row_count > self::ROWS_SOFT_LIMIT )
                    break;
            }
        }

        // Filter out entries where there's not actually any difference and return the final array
        return self::filterRadioTagChanges($history);
    }


    /**
     * Executes a series of queries on ODR's XYZData fields to find all changes made to them,
     * filtered by the given criteria.  (i.e. changes within this date range, changes made by a
     * specific user, etc)
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datafields_by_typeclass
     * @param array $criteria
     * @param int $row_count
     *
     * @return array
     */
    private function getXYZValueChanges($em, $datafields_by_typeclass, $criteria, &$row_count)
    {
        $conn = $em->getConnection();

        $history = array();
        foreach ($datafields_by_typeclass as $typeclass => $df_list) {
            // Don't do anything if no datafields are listed...
            if ( empty($df_list) )
                continue;

            // Don't do anything if this isn't the correct query for the typeclass...
            if ( $typeclass !== 'XYZData' )
                continue;

            $query =
               'SELECT dr.data_type_id AS dt_id, dr.id AS dr_id, e.data_field_id AS df_id, e.id AS id,
                    e.x_value, e.y_value, e.z_value,
                    e.created AS updated, e.createdBy AS updatedBy
                FROM odr_xyz_data AS e
                JOIN odr_data_record_fields AS drf ON e.data_record_fields_id = drf.id
                JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
                WHERE dr.deletedAt IS NULL AND drf.deletedAt IS NULL
                AND e.data_field_id IN (:datafield_ids)';
            if (isset($criteria['start_date']))
                $query .= ' AND e.created BETWEEN :start_date AND :end_date';
            if (isset($criteria['target_user_ids']))
                $query .= ' AND e.createdBy IN (:target_user_ids)';
            if (isset($criteria['grandparent_datarecord_ids']))
                $query .= ' AND dr.grandparent_id IN (:grandparent_datarecord_ids)';
            $query .= ' ORDER BY e.created';


            // ----------------------------------------
            // Always going to have a list of datafield ids...
            $params = array(
                'datafield_ids' => $df_list,
            );
            $types = array(
                'datafield_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY,
            );

            if ( isset($criteria['start_date']) ) {
                $params['start_date'] = ($criteria['start_date'])->format("Y-m-d H:i:s");
                $params['end_date'] = ($criteria['end_date'])->format("Y-m-d H:i:s");
            }

            if ( isset($criteria['target_user_ids']) ) {
                $params['target_user_ids'] = $criteria['target_user_ids'];
                $types['target_user_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
            }

            if ( isset($criteria['grandparent_datarecord_ids']) ) {
                $params['grandparent_datarecord_ids'] = $criteria['grandparent_datarecord_ids'];
                $types['grandparent_datarecord_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
            }


            // ----------------------------------------
            // Don't execute this query if the previous queries have processed more than the soft
            //  limit placed on rows
            if ( $row_count > self::ROWS_SOFT_LIMIT )
                continue;

            $results = $conn->executeQuery($query, $params, $types);
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $dr_id = $result['dr_id'];
                $df_id = $result['df_id'];
                $e_id = $result['id'];

                $x_value = $result['x_value'];
                $y_value = $result['y_value'];
                $z_value = $result['z_value'];
                $updated = $result['updated'];        // NOTE: these two are actually created/createdBy
                $updatedBy = $result['updatedBy'];

                if ( !isset($history[$dt_id]) )
                    $history[$dt_id] = array();
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();
                if ( !isset($history[$dt_id][$dr_id][$df_id]) )
                    $history[$dt_id][$dr_id][$df_id] = array();
                if ( !isset($history[$dt_id][$dr_id][$df_id][$e_id]) )
                    $history[$dt_id][$dr_id][$df_id][$e_id] = array();

                $history[$dt_id][$dr_id][$df_id][$e_id][$updated] = array(
                    'x_value' => $x_value,
                    'y_value' => $y_value,
                    'z_value' => $z_value,
                    'updated' => $updated,
                    'updatedBy' => $updatedBy,
                );

                // Increment the number of rows that have been processed
                $row_count++;
                if ( $row_count > self::ROWS_SOFT_LIMIT )
                    break;
            }
        }

        // Filter out entries where there's not actually any difference and return the final array
        return self::filterXYZDataChanges($history);
    }


    /**
     * Executes a series of queries to locate datarecords created/deleted based on the given
     * criteria (i.e. created/deleted within this date range, or by a specific user, etc)
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $criteria
     * @param int $row_count
     *
     * @return array
     */
    private function getDatarecordChanges($em, $criteria, &$row_count)
    {
        $history = array();

        // ----------------------------------------
        $created_query =
           'SELECT dr.data_type_id AS dt_id, dr.id AS dr_id,
                dr.created AS created, dr.createdBy AS createdBy
            FROM odr_data_record AS dr
            JOIN odr_data_type AS dt ON dr.data_type_id = dt.id
            WHERE dt.deletedAt IS NULL AND dt.grandparent_id IN (:datatype_ids)';
        if ( isset($criteria['start_date']) )
            $created_query .= ' AND dr.created BETWEEN :start_date AND :end_date';
        if ( isset($criteria['target_user_ids']) )
            $created_query .= ' AND dr.createdBy IN (:target_user_ids)';
        if ( isset($criteria['grandparent_datarecord_ids']) )
            $created_query .= ' AND dr.grandparent_id IN (:grandparent_datarecord_ids)';
        // ----------------------------------------
        // ----------------------------------------
        $deleted_query =
           'SELECT dr.data_type_id AS dt_id, dr.id AS dr_id,
                dr.deletedAt AS deletedAt, dr.deletedBy AS deletedBy
            FROM odr_data_record dr
            JOIN odr_data_type AS dt ON dr.data_type_id = dt.id
            WHERE dt.deletedAt IS NULL AND dt.grandparent_id IN (:datatype_ids)';
        if ( isset($criteria['start_date']) )
            $deleted_query .= ' AND dr.deletedAt BETWEEN :start_date AND :end_date';
        if ( isset($criteria['target_user_ids']) )
            $deleted_query .= ' AND dr.deletedBy IN (:target_user_ids)';
        if ( isset($criteria['grandparent_datarecord_ids']) )
            $deleted_query .= ' AND dr.grandparent_id IN (:grandparent_datarecord_ids)';
        // ----------------------------------------
        // ----------------------------------------
        $public_query =
           'SELECT dr.data_type_id AS dt_id, dr.id AS dr_id,
               drm.public_date AS public_date, drm.created AS updated, drm.createdBy AS updatedBy
            FROM odr_data_record_meta drm
            JOIN odr_data_record dr ON drm.data_record_id = dr.id
            JOIN odr_data_type AS dt ON dr.data_type_id = dt.id
            WHERE dt.deletedAt IS NULL AND dt.grandparent_id IN (:datatype_ids)';
        if ( isset($criteria['start_date']) )
            $public_query .= ' AND drm.created BETWEEN :start_date AND :end_date';
        if ( isset($criteria['target_user_ids']) )
            $public_query .= ' AND drm.createdBy IN (:target_user_ids)';
        if ( isset($criteria['grandparent_datarecord_ids']) )
            $public_query .= ' AND dr.grandparent_id IN (:grandparent_datarecord_ids)';
        // ----------------------------------------

        // ----------------------------------------
        //
        $params = array(
            'datatype_ids' => $criteria['datatype_ids'],
        );
        $types = array(
            'datatype_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY,
        );

        if ( isset($criteria['start_date']) ) {
            $params['start_date'] = ($criteria['start_date'])->format("Y-m-d H:i:s");
            $params['end_date'] = ($criteria['end_date'])->format("Y-m-d H:i:s");
        }

        if ( isset($criteria['target_user_ids']) ) {
            $params['target_user_ids'] = $criteria['target_user_ids'];
            $types['target_user_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        }

        if ( isset($criteria['grandparent_datarecord_ids']) ) {
            $params['grandparent_datarecord_ids'] = $criteria['grandparent_datarecord_ids'];
            $types['grandparent_datarecord_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        }


        // ----------------------------------------
        //
        $queries = array(
            'created' => $created_query,
            'deleted' => $deleted_query,
            'public' => $public_query,
        );

        $conn = $em->getConnection();
        foreach ($queries as $query_type => $query) {
            // Don't execute this query if the previous queries have processed more than the soft
            //  limit placed on rows
            if ( $row_count > self::ROWS_SOFT_LIMIT )
                continue;

            $results = $conn->executeQuery($query, $params, $types);

            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $dr_id = $result['dr_id'];

                if ( !isset($history[$dt_id]) )
                    $history[$dt_id] = array();
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();

                switch ($query_type) {
                    case 'created':
                        $history[$dt_id][$dr_id]['created'] = array(
                            'date' => $result['created'],
                            'createdBy' => $result['createdBy']
                        );
                        break;
                    case 'public':
                        if ( !isset($history[$dt_id][$dr_id]['updated']) )
                            $history[$dt_id][$dr_id]['updated'] = array();

                        $date = $result['updated'];
                        $history[$dt_id][$dr_id]['updated'][$date] = array(
                            'public_date' => $result['public_date'],
                            'updatedBy' => $result['updatedBy']
                        );
                        break;
                    case 'deleted':
                        $history[$dt_id][$dr_id]['deleted'] = array(
                            'date' => $result['deletedAt'],
                            'deletedBy' => $result['deletedBy']
                        );
                        break;
                }

                // Increment the number of rows that have been processed
                $row_count++;
                if ( $row_count > self::ROWS_SOFT_LIMIT )
                    break;
            }
        }

        return self::filterDatarecordChanges($history);
    }


    /**
     * Takes a text/number change array, and filters out both empty initial values and sequential
     * duplicate values.
     *
     * @param array $history
     *
     * @return array
     */
    private function filterTextNumberChanges($history, $datafields_by_typeclass)
    {
        // Most fields can filter out the empty string if it's their first entry
        // Boolean fields need to filter out "0" if it's the first entry
        $boolean_fields = array();
        foreach ($datafields_by_typeclass['Boolean'] as $num => $df_id)
            $boolean_fields[$df_id] = 1;

        foreach ($history as $dt_id => $dt_data) {
            foreach ($dt_data as $dr_id => $dr_data) {
                foreach ($dr_data as $df_id => $entities) {
                    // Need to keep track of the value for the previous entity
                    $prev_entity = null;
                    foreach ($entities as $entity_id => $changes) {
                        // There should only ever be one entry in $changes because of how storage
                        //  entities work in ODR...
                        foreach ($changes as $date => $data) {
                            if ( is_null($prev_entity) ) {
                                // First entry for this datafield...
                                if ( isset($boolean_fields[$df_id]) && $data['value'] === "0") {
                                    // ...boolean value is empty, so get rid of it
                                    unset( $history[$dt_id][$dr_id][$df_id][$entity_id][$date] );
                                }
                                else if ($data['value'] !== '') {
                                    // ...value was not empty, so save data
                                    $prev_entity = $data;
                                }
                                else {
                                    // ...value is empty, so get rid of it
                                    unset( $history[$dt_id][$dr_id][$df_id][$entity_id][$date] );
                                }
                            }
                            else if ($prev_entity['value'] === $data['value']) {
                                // If the more recent value is identical to its previous value, then
                                //  pretend the more recent value doesn't exist and get rid of it
                                unset( $history[$dt_id][$dr_id][$df_id][$entity_id][$date] );
                            }
                            else {
                                // The more recent value is different than the previous value...reset
                                //  in case there's an even more recent value stored...
                                $prev_entity = $data;
                            }
                        }

                        // No point preserving the storage entity if nothing has ever changed in it
                        if ( empty($history[$dt_id][$dr_id][$df_id][$entity_id]) )
                            unset( $history[$dt_id][$dr_id][$df_id][$entity_id] );
                    }

                    // No point preserving the datafield if nothing has ever changed in it
                    if ( empty($history[$dt_id][$dr_id][$df_id]) )
                        unset( $history[$dt_id][$dr_id][$df_id] );
                }

                // No point preserving the datarecord if nothing has ever changed in it
                if ( empty($history[$dt_id][$dr_id]) )
                    unset( $history[$dt_id][$dr_id] );
            }

            // No point preserving the datatype if nothing has ever changed in it
            if ( empty($history[$dt_id]) )
                unset( $history[$dt_id] );
        }

        return $history;
    }


    /**
     * Takes a file/image change array, and filters out...nothing, right now...
     *
     * @param array $history
     *
     * @return array
     */
    private function filterFileImageChanges($history)
    {
        // TODO - filter instances when a file is deleted but replaced by a new file with the same name?
        // TODO - filter out cases where a file/image was public, then set to non-public, then right back to public? and vice versa
        // TODO - filter out image rotations?  it's not trivial, since the only link between the old and the new file is the timestamp

        // Don't actually have anything to filter out...
        return $history;
    }


    /**
     * Takes a radio/tag change array, and filters out initial "unselected" values.
     *
     * @param array $history
     *
     * @return array
     */
    private function filterRadioTagChanges($history)
    {
        // Doesn't make any sense for the first entry to be mentioning an unselected option/tag
        foreach ($history as $dt_id => $dt_data) {
            foreach ($dt_data as $dr_id => $dr_data) {
                foreach ($dr_data as $df_id => $entities) {
                    foreach ($entities as $entity_id => $changes) {
                        foreach ($changes as $date => $data) {
                            if ($data['selected'] == 0) {
                                // Oldest entry is the option/tag being "unselected"...since they should be
                                //  "unselected" by default, there's no point preserving this entry
                                unset( $history[$dt_id][$dr_id][$df_id][$entity_id][$date] );
                            }
                            else {
                                // Stop looking when encountering the first time the option/tag is "selected"
                                break;
                            }
                        }

                        // No point preserving the entity if nothing has ever changed in it
                        if ( empty($history[$dt_id][$dr_id][$df_id][$entity_id]) )
                            unset( $history[$dt_id][$dr_id][$df_id][$entity_id] );
                    }

                    // No point preserving the datafield if nothing has ever changed in it
                    if ( empty($history[$dt_id][$dr_id][$df_id]) )
                        unset( $history[$dt_id][$dr_id][$df_id] );
                }

                // No point preserving the datarecord if nothing has ever changed in it
                if ( empty($history[$dt_id][$dr_id]) )
                    unset( $history[$dt_id][$dr_id] );
            }

            // No point preserving the datatype if nothing has ever changed in it
            if ( empty($history[$dt_id]) )
                unset( $history[$dt_id] );
        }

        return $history;
    }


    /**
     * Takes an XYZData change array, and filters out both empty initial values and sequential
     *  duplicate values.
     *
     * @param array $history
     *
     * @return array
     */
    private function filterXYZDataChanges($history)
    {
        // Due to XYZData having a pile of entities for a single datarecordfield, the array structure
        //  kind of becomes excessive...
        $new_history = array();

        foreach ($history as $dt_id => $dt_data) {
            foreach ($dt_data as $dr_id => $dr_data) {
                foreach ($dr_data as $df_id => $entities) {
                    // There isn't really a "previous entity" to keep track against, since there's
                    //  a pile of them...
//                    $prev_entity = null;
                    foreach ($entities as $entity_id => $changes) {
                        foreach ($changes as $updated => $data) {
                            if ( is_null($data['x_value']) && is_null($data['y_value']) && is_null($data['z_value']) ) {
                                // Oldest entry has all the values being set to null...no point
                                //  preserving this entry
//                                unset( $history[$dt_id][$dr_id][$df_id][$entity_id][$date] );
                            }
                            else {
                                // First entry is not all null, save it
                                if ( !isset($new_history[$dt_id]) )
                                    $new_history[$dt_id] = array();
                                if ( !isset($new_history[$dt_id][$dr_id]) )
                                    $new_history[$dt_id][$dr_id] = array();
                                if ( !isset($new_history[$dt_id][$dr_id][$df_id]) )
                                    $new_history[$dt_id][$dr_id][$df_id] = array(0 => array());  // not saving entity ids, so automatically create an entry
                                if ( !isset($new_history[$dt_id][$dr_id][$df_id][0][$updated]) )
                                    $new_history[$dt_id][$dr_id][$df_id][0][$updated] = array('updatedBy' => $data['updatedBy']);
                            }
                        }

//                        // No point preserving the entity if nothing has ever changed in it
//                        if ( empty($history[$dt_id][$dr_id][$df_id][$entity_id]) )
//                            unset( $history[$dt_id][$dr_id][$df_id][$entity_id] );
                    }

//                    // No point preserving the datafield if nothing has ever changed in it
//                    if ( empty($history[$dt_id][$dr_id][$df_id]) )
//                        unset( $history[$dt_id][$dr_id][$df_id] );
                }

//                // No point preserving the datarecord if nothing has ever changed in it
//                if ( empty($history[$dt_id][$dr_id]) )
//                    unset( $history[$dt_id][$dr_id] );
            }

//            // No point preserving the datatype if nothing has ever changed in it
//            if ( empty($history[$dt_id]) )
//                unset( $history[$dt_id] );
        }

        return $new_history;
    }


    /**
     * Takes a datarecord change array, and filters out initial "non-public" values.
     *
     * @param array $history
     *
     * @return array
     */
    private function filterDatarecordChanges($history)
    {
        // Doesn't make any sense for the first public-status entry to say the datarecord is non-public
        foreach ($history as $dt_id => $dt_data) {
            foreach ($dt_data as $dr_id => $dr_data) {
                // If the "updated" entry doesn't exist, then there's nothing to filter
                if ( !isset($dr_data['updated']) )
                    continue;

                // Need to keep track of previous "updated" entries
                $prev_update = null;
                foreach ($dr_data['updated'] as $date => $data) {
                    if ( is_null($prev_update) ) {
                        // First public_status change for this datarecord...
                        if ( $data['public_date'] === "2200-01-01 00:00:00" ) {
                            // If the datarecord was created in this time range, then ignore the
                            //  initial "datarecord is non-public" entry
                            if ( isset($history[$dt_id][$dr_id]['created'])
                                && $history[$dt_id][$dr_id]['created']['date'] === $date
                            ) {
                                unset( $history[$dt_id][$dr_id]['updated'][$date] );
                            }
                            else {
                                // Otherwise, want to preserve this "datarecord is non-public" entry
                                $prev_update = $data;
                            }
                        }
                        else {
                            // Datarecord has been set to public
                            $prev_update = $data;
                        }
                    }
                    else if ( $prev_update['public_date'] === $data['public_date'] ) {
                        // If the more recent value is identical to its previous value, then
                        //  pretend the more recent value doesn't exist and get rid of it
                        unset( $history[$dt_id][$dr_id]['updated'][$date] );
                    }
                    else {
                        // The more recent value is different than the previous value...reset
                        //  in case there's an even more recent value stored...
                        $prev_update = $data;
                    }

                    // No point preserving the updated entry if nothing has ever changed in it
                    if ( empty($history[$dt_id][$dr_id]['updated']) )
                        unset( $history[$dt_id][$dr_id]['updated'] );
                }

                // No point preserving the datarecord if nothing has ever changed in it
                if ( empty($history[$dt_id][$dr_id]) )
                    unset( $history[$dt_id][$dr_id] );
            }

            // No point preserving the datatype if nothing has ever changed in it
            if ( empty($history[$dt_id]) )
                unset( $history[$dt_id] );
        }

        return $history;
    }


    /**
     * Combines the text/number, file/image, radio/tag, and xyzdata arrays into a single array.
     *
     * @param array $text_number_changes
     * @param array $file_image_changes
     * @param array $radio_tag_changes
     * @param array $xyz_changes
     *
     * @return array
     */
    private function combineArrays($text_number_changes, $file_image_changes, $radio_tag_changes, $xyz_changes)
    {
        $history = array();

        foreach ($text_number_changes as $dt_id => $dt_data) {
            // Create an entry for the datatype if it doesn't exist...
            if ( !isset($history[$dt_id]) )
                $history[$dt_id] = array();

            foreach ($dt_data as $dr_id => $dr_data) {
                // Create an entry for the datarecord if it doesn't exist...
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();

                // Copy all the history data into this datarecord entry
                foreach ($dr_data as $df_id => $data)
                    $history[$dt_id][$dr_id][$df_id] = $data;
            }
        }
        foreach ($file_image_changes as $dt_id => $dt_data) {
            // Create an entry for the datatype if it doesn't exist...
            if ( !isset($history[$dt_id]) )
                $history[$dt_id] = array();

            foreach ($dt_data as $dr_id => $dr_data) {
                // Create an entry for the datarecord if it doesn't exist...
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();

                // Copy all the history data into this datarecord entry
                foreach ($dr_data as $df_id => $data)
                    $history[$dt_id][$dr_id][$df_id] = $data;
            }
        }
        foreach ($radio_tag_changes as $dt_id => $dt_data) {
            // Create an entry for the datatype if it doesn't exist...
            if ( !isset($history[$dt_id]) )
                $history[$dt_id] = array();

            foreach ($dt_data as $dr_id => $dr_data) {
                // Create an entry for the datarecord if it doesn't exist...
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();

                // Copy all the history data into this datarecord entry
                foreach ($dr_data as $df_id => $data)
                    $history[$dt_id][$dr_id][$df_id] = $data;
            }
        }
        foreach ($xyz_changes as $dt_id => $dt_data) {
            // Create an entry for the datatype if it doesn't exist...
            if ( !isset($history[$dt_id]) )
                $history[$dt_id] = array();

            foreach ($dt_data as $dr_id => $dr_data) {
                // Create an entry for the datarecord if it doesn't exist...
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();

                // Copy all the history data into this datarecord entry
                foreach ($dr_data as $df_id => $data)
                    $history[$dt_id][$dr_id][$df_id] = $data;
            }
        }

        return $history;
    }


    /**
     * Combines the text/number, file/image, radio/tag, and xyzdata arrays into a single array, but
     * ignores the individual datafields in favor of one entry per datarecord
     *
     * @param array $text_number_changes
     * @param array $file_image_changes
     * @param array $radio_tag_changes
     * @param array $xyz_changes
     *
     * @return array
     */
    private function combineArraysSimple($text_number_changes, $file_image_changes, $radio_tag_changes, $xyz_changes)
    {
        $history = array();

        foreach ($text_number_changes as $dt_id => $dt_data) {
            // Create an entry for the datatype if it doesn't exist...
            if ( !isset($history[$dt_id]) )
                $history[$dt_id] = array();

            foreach ($dt_data as $dr_id => $dr_data) {
                // Create an entry for the datarecord if it doesn't exist...
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();

                // Copy just the date into the history entry
                foreach ($dr_data as $df_id => $df_data) {
                    foreach ($df_data as $entity_id => $entity_data) {
                        foreach ($entity_data as $date => $data) {
                            $date = explode(' ', $date)[0];
                            $history[$dt_id][$dr_id][$date] = $data['updatedBy'];
                        }
                    }
                }
            }
        }
        foreach ($file_image_changes as $dt_id => $dt_data) {
            // Create an entry for the datatype if it doesn't exist...
            if ( !isset($history[$dt_id]) )
                $history[$dt_id] = array();

            foreach ($dt_data as $dr_id => $dr_data) {
                // Create an entry for the datarecord if it doesn't exist...
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();

                // Copy just the date into the history entry
                foreach ($dr_data as $df_id => $df_data) {
                    foreach ($df_data as $entity_id => $entity_data) {
                        foreach ($entity_data as $date => $data) {
                            $date = explode(' ', $date)[0];
                            if ( isset($data['updatedBy']) )
                                $history[$dt_id][$dr_id][$date] = $data['updatedBy'];
                            else if ( isset($data['deletedBy']) )
                                $history[$dt_id][$dr_id][$date] = $data['deletedBy'];
                        }
                    }
                }
            }
        }
        foreach ($radio_tag_changes as $dt_id => $dt_data) {
            // Create an entry for the datatype if it doesn't exist...
            if ( !isset($history[$dt_id]) )
                $history[$dt_id] = array();

            foreach ($dt_data as $dr_id => $dr_data) {
                // Create an entry for the datarecord if it doesn't exist...
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();

                // Copy just the date into the history entry
                foreach ($dr_data as $df_id => $df_data) {
                    foreach ($df_data as $entity_id => $entity_data) {
                        foreach ($entity_data as $date => $data) {
                            $date = explode(' ', $date)[0];
                            $history[$dt_id][$dr_id][$date] = $data['selectedBy'];
                        }
                    }
                }
            }
        }
        foreach ($xyz_changes as $dt_id => $dt_data) {
            // Create an entry for the datatype if it doesn't exist...
            if ( !isset($history[$dt_id]) )
                $history[$dt_id] = array();

            foreach ($dt_data as $dr_id => $dr_data) {
                // Create an entry for the datarecord if it doesn't exist...
                if ( !isset($history[$dt_id][$dr_id]) )
                    $history[$dt_id][$dr_id] = array();

                // Copy just the date into the history entry
                foreach ($dr_data as $df_id => $df_data) {
                    foreach ($df_data as $entity_id => $entity_data) {
                        foreach ($entity_data as $date => $data) {
                            $date = explode(' ', $date)[0];
                            $history[$dt_id][$dr_id][$date] = $data['updatedBy'];
                        }
                    }
                }
            }
        }

        return $history;
    }


    /**
     * The arrays of changes are currently organized by various entity ids...so in order to make the
     * output readable, additional database queries are needed to find the human-friendly names of
     * the various entities.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $history
     * @param array $datarecord_created_deleted_history
     * @param array $child_dr_lookup
     *
     * @return array
     */
    private function getNames($em, $history, $datarecord_created_deleted_history, $child_dr_lookup)
    {
        $ids = array(
            'dt_ids' => array(),
            'dr_ids' => array(),
            'df_ids' => array(),
            'user_ids' => array()
        );

        // Locate all ids from the main changes array...
        foreach ($history as $dt_id => $dt_data) {
            $ids['dt_ids'][$dt_id] = 1;
            foreach ($dt_data as $dr_id => $dr_data) {
                $ids['dr_ids'][$dr_id] = 1;
                foreach ($dr_data as $df_id => $entities) {
                    $ids['df_ids'][$df_id] = 1;
                    foreach ($entities as $entity_id => $dates) {
                        foreach ($dates as $date => $data) {
                            // Order is important...uploadedBy should be before updatedBy, and
                            //  deletedBy should be last
                            if ( isset($data['uploadedBy']) ) {
                                // file/image field uploaded
                                $uploader_id = $data['uploadedBy'];
                                $ids['user_ids'][$uploader_id] = 1;
                            }
                            else if ( isset($data['selectedBy']) ) {
                                // radio/tag field
                                $selector_id = $data['selectedBy'];
                                $ids['user_ids'][$selector_id] = 1;
                            }
                            else if ( isset($data['updatedBy']) ) {
                                // text/number field change, or file/image public status change
                                $updater_id = $data['updatedBy'];
                                $ids['user_ids'][$updater_id] = 1;
                            }
                            else /*if ( isset($data['deletedBy']) )*/ {
                                // file/image deletion
                                // NOTE - this is at the end because older entries in the database aren't
                                // NOTE - guaranteed to have a deletedBy value
                                $deleter_id = $data['deletedBy'];
                                $ids['user_ids'][$deleter_id] = 1;
                            }
                        }
                    }
                }
            }
        }

        // ...and also locate all relevant ids from the datarecord created/deleted history array
        foreach ($datarecord_created_deleted_history as $dt_id => $dt_data) {
            $ids['dt_ids'][$dt_id] = 1;
            foreach ($dt_data as $dr_id => $dr_data) {
                $ids['dr_ids'][$dr_id] = 1;

                if ( isset($dr_data['created']) ) {
                    $createdBy = $dr_data['created']['createdBy'];
                    $ids['user_ids'][$createdBy] = 1;
                }
                if ( isset($dr_data['deleted']) ) {
                    $deletedBy = $dr_data['deleted']['deletedBy'];
                    $ids['user_ids'][$deletedBy] = 1;
                }
                if ( isset($dr_data['updated']) ) {
                    foreach ($dr_data['updated'] as $date => $data) {
                        $updatedBy = $data['updatedBy'];
                        $ids['user_ids'][$updatedBy] = $updatedBy;
                    }
                }
            }
        }

        // ...and all ids from the child datarecord lookup array
        foreach ($child_dr_lookup as $dr_id => $gdr_id) {
            $ids['dr_ids'][$gdr_id] = 1;
        }


        // ----------------------------------------
        $names = array();

        // datatypes...
        $query = $em->createQuery(
           'SELECT dt.id AS dt_id, dtm.longName AS dt_name,
                gdt.id AS gdt_id, gdtm.longName AS gdt_name
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
            JOIN ODRAdminBundle:DataType AS gdt WITH dt.grandparent = gdt
            JOIN ODRAdminBundle:DataTypeMeta AS gdtm WITH gdtm.dataType = gdt
            WHERE dt IN (:datatype_ids)
            AND dtm.deletedAt IS NULL AND gdtm.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => array_keys($ids['dt_ids'])) );
        $results = $query->getArrayResult();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $dt_name = $result['dt_name'];
            $gdt_id = $result['gdt_id'];
            $gdt_name = $result['gdt_name'];

            if ( $dt_id === $gdt_id )
                $names['datatypes'][$dt_id] = $dt_name;
            else
                $names['datatypes'][$dt_id] = $dt_name.' ('.$gdt_name.')';
        }

        // datafields...
        $query = $em->createQuery(
           'SELECT df.id AS df_id, dfm.fieldName AS df_name
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
            WHERE df IN (:datafield_ids) AND dfm.deletedAt IS NULL'
        )->setParameters( array('datafield_ids' => array_keys($ids['df_ids'])) );
        $results = $query->getArrayResult();
        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $df_name = $result['df_name'];

            $names['datafields'][$df_id] = $df_name;
        }

        // users...
        $query = $em->createQuery(
           'SELECT u
            FROM ODROpenRepositoryUserBundle:User AS u
            WHERE u IN (:user_ids)'
        )->setParameters( array('user_ids' => array_keys($ids['user_ids'])) );
        $results = $query->getResult();    // intentionally NOT getArrayResult()
        foreach ($results as $user) {
            /** @var ODRUser $user */
            $user_id = $user->getId();
            $names['users'][$user_id] = $user->getUserString();
        }


        // datarecords are more involved...need to figure out the nameFields first
        $query = $em->createQuery(
           'SELECT df.id AS df_id
            FROM ODRAdminBundle:DataTypeSpecialFields AS dtsf
            JOIN ODRAdminBundle:DataFields AS df WITH dtsf.dataField = df
            WHERE dtsf.dataType IN (:datatype_ids) AND dtsf.field_purpose = :field_purpose
            AND dtsf.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters(
            array(
                'datatype_ids' => array_keys($ids['dt_ids']),
                'field_purpose' => DataTypeSpecialFields::NAME_FIELD
            )
        );
        $results = $query->getArrayResult();

        $namefield_ids = array();
        foreach ($results as $result)
            $namefield_ids[] = $result['df_id'];

        // Now that the namefields are known, find their values for all the datarecords in $history
        $query =
           'SELECT drf.data_record_id AS dr_id, drf.data_field_id AS df_id,
	            iv.value AS iv_value, dv.value AS dv_value,
	            sv.value AS sv_value, mv.value AS mv_value, lv.value AS lv_value,
	            ro.option_name AS ro_name
            FROM odr_data_record_fields drf
            LEFT JOIN odr_integer_value iv ON (iv.data_record_fields_id = drf.id AND iv.deletedAt IS NULL)
            LEFT JOIN odr_decimal_value dv ON (dv.data_record_fields_id = drf.id AND dv.deletedAt IS NULL)
            LEFT JOIN odr_short_varchar sv ON (sv.data_record_fields_id = drf.id AND sv.deletedAt IS NULL)
            LEFT JOIN odr_medium_varchar mv ON (mv.data_record_fields_id = drf.id AND mv.deletedAt IS NULL)
            LEFT JOIN odr_long_varchar lv ON (lv.data_record_fields_id = drf.id AND lv.deletedAt IS NULL)
            LEFT JOIN odr_radio_selection rs ON (rs.data_record_fields_id = drf.id AND rs.deletedAt IS NULL AND rs.selected = 1)
            LEFT JOIN odr_radio_options ro ON (rs.radio_option_id = ro.id AND ro.deletedAt IS NULL)
            WHERE drf.data_record_id IN (:datarecord_ids) AND drf.data_field_id IN (:namefield_ids)
            AND drf.deletedAt IS NULL';
        $params = array(
            'datarecord_ids' => array_keys($ids['dr_ids']),
            'namefield_ids' => $namefield_ids
        );
        $types = array(
            'datarecord_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY,
            'namefield_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY,
        );

        $conn = $em->getConnection();
        $results = $conn->executeQuery($query, $params, $types);
        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $value = null;
            if ( !is_null($result['iv_value']) )
                $value = $result['iv_value'];
            else if ( !is_null($result['dv_value']) )
                $value = $result['dv_value'];
            else if ( !is_null($result['sv_value']) )
                $value = $result['sv_value'];
            else if ( !is_null($result['mv_value']) )
                $value = $result['mv_value'];
            else if ( !is_null($result['lv_value']) )
                $value = $result['lv_value'];
            else if ( !is_null($result['ro_name']) )
                $value = $result['ro_name'];

            if ( !isset($names['datarecords'][$dr_id]) )
                $names['datarecords'][$dr_id] = $value;
            else
                $names['datarecords'][$dr_id] .= ' '.$value;
        }

        return $names;
    }


    /**
     * Returns a lookup array to turn child datarecord ids into grandparent ids, so that the names
     * and links that get rendered for the child datarecords are actually useful
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $history
     *
     * @return array
     */
    private function getChildDrLookup($em, $history)
    {
        $dr_list = array();
        foreach ($history as $dt_id => $dt_data) {
            foreach ($dt_data as $dr_id => $dr_data) {
                $dr_list[] = $dr_id;
            }
        }

        // Just want the datarecord id and its grandparent id, but doctrine demands a table join
        //  to get that info...faster to just use native SQL
        $query =
           'SELECT dr.id AS dr_id, dr.grandparent_id AS gdr_id
            FROM odr_data_record dr
            WHERE dr.id IN (?) AND dr.id != dr.grandparent_id
            AND dr.deletedAt IS NULL';
        $parameters = array(1 => $dr_list);
        $types = array(1 => DBALConnection::PARAM_INT_ARRAY);

        $conn = $em->getConnection();
        $results = $conn->executeQuery($query, $parameters, $types);

        $dr_lookup = array();
        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $gdr_id = $result['gdr_id'];

            $dr_lookup[$dr_id] = $gdr_id;
        }

        return $dr_lookup;
    }


    /**
     * Returns a list of datafields for the purposes of selecting tracking criteria, filtered to
     * those that the calling user can edit.
     *
     * @param string $datatype_id_restriction
     * @param Request $request
     *
     * @return Response
     */
    public function getdatafieldselectorAction($datatype_id_restriction, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType|null $datatype_restriction */
            $datatype_restriction = null;
            if ( $datatype_id_restriction !== '' ) {
                $datatype_restriction = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id_restriction);
                if ($datatype_restriction == null)
                    throw new ODRNotFoundException('Datatype');
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $permissions_service->getDatatypePermissions($admin);
            $datafield_permissions = $permissions_service->getDatafieldPermissions($admin);

            $editable_datatypes = array();
            if ( is_null($datatype_restriction) ) {
                // No datatype restriction provided, determine which datatypes the user is allowed
                //  to edit...
                foreach ($datatype_permissions as $dt_id => $dt_permission) {
                    if ( isset($dt_permission['dr_edit']) && $dt_permission['dr_edit'] == 1 ) {
                        $editable_datatypes[] = $dt_id;
                    }
                }

                // If the user can't edit any datatype, then they're not allowed to use this action
                if ( empty($editable_datatypes) )
                    throw new ODRForbiddenException();
            }
            else {
                // Otherwise, the user needs to be able to edit the provided datatype
                if ( !$permissions_service->canEditDatatype($admin, $datatype_restriction) )
                    throw new ODRForbiddenException();

                // Only going to load fields belonging to the provided datatype
                $dt_array = $database_info_service->getDatatypeArray($datatype_restriction->getGrandparent()->getId(), false);    // don't want links
                foreach ($dt_array as $dt_id => $dt_data) {
                    if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dr_edit']) ) {
                        $editable_datatypes[] = $dt_id;
                    }
                }
            }
            // --------------------


            // Get a list of all datafields, excluding those from template/metadata datatypes
            $query =
               'SELECT gdtm.long_name AS gdt_name, dtm.long_name AS dt_name,
                    df.id AS df_id, dfm.field_name AS df_name, dfm.field_type_id AS ft_id
                FROM odr_data_type AS gdt
                JOIN odr_data_type_meta AS gdtm ON gdtm.data_type_id = gdt.id
                JOIN odr_data_type AS dt ON dt.grandparent_id = gdt.id
                JOIN odr_data_type_meta AS dtm ON dtm.data_type_id = dt.id
                JOIN odr_data_fields df ON df.data_type_id = dt.id
                JOIN odr_data_fields_meta dfm ON dfm.data_field_id = df.id
                WHERE dt.id IN (:datatype_ids)
                AND dt.is_master_type = 0 AND dt.metadata_for_id IS NULL
                AND gdt.deletedAt IS NULL AND gdtm.deletedAt IS NULL
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL';
            $params = array( 'datatype_ids' => $editable_datatypes );
            $types = array( 'datatype_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY );

            $conn = $em->getConnection();
            $results = $conn->executeQuery($query, $params, $types);

            $list = array();
            foreach ($results as $result) {
                $top_level_dt_name = $result['gdt_name'];
                $dt_name = $result['dt_name'];
                $df_id = $result['df_id'];
                $df_name = $result['df_name'];
                $ft_id = $result['ft_id'];

                // Ignore markdown fields
                if ( $ft_id == 17 )
                    continue;

                // Only save datafields that the user can edit
                if ( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['edit']) ) {
                    if ( !isset($list[$top_level_dt_name]) )
                        $list[$top_level_dt_name] = array();
                    if ( !isset($list[$top_level_dt_name][$dt_name]) )
                        $list[$top_level_dt_name][$dt_name] = array();

                    $list[$top_level_dt_name][$dt_name][$df_id] = $df_name;
                }
            }


            // ----------------------------------------
            // Make things a bit easer to debug and on datatables.js...
            // Sort the top-level datatype list by name
            ksort($list, SORT_FLAG_CASE | SORT_STRING);    // case-insenstive sort

            foreach ($list as $top_level_dt_name => $dt_list) {
                // Sort each datatype list by name...
                $tmp = $dt_list;
                ksort($tmp, SORT_FLAG_CASE | SORT_STRING);    // case-insenstive sort
                $list[$top_level_dt_name] = $tmp;

                foreach ($dt_list as $dt_name => $df_list) {
                    // Sort each datafield list by name...
                    $tmp = $df_list;
                    asort($tmp, SORT_FLAG_CASE | SORT_STRING);    // case-insenstive sort
                    $list[$top_level_dt_name][$dt_name] = $tmp;
                }
            }


            // ----------------------------------------
            // Render the list of users
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Tracking:tracking_dialog_datafield_selection.html.twig',
                    array(
                        'list' => $list,
                        'datatype_restriction' => $datatype_restriction,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x399f2e50;
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
     * Returns a list of users for the purposes of selecting tracking criteria, filtered to those
     * that the calling user can see.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function getuserselectorAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var UserManager $user_manager */
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $permissions_service->getDatatypePermissions($user);

            // Need to determine which datatypes the user is allowed to edit...
            $editable_datatypes = array();
            foreach ($datatype_permissions as $dt_id => $dt_permission) {
                if ( isset($dt_permission['dr_edit']) && $dt_permission['dr_edit'] == 1 ) {
                    $editable_datatypes[] = $dt_id;
                }
            }

            // If the user can't edit any datatype, then they're not allowed to use this action
            if ( empty($editable_datatypes) )
                throw new ODRForbiddenException();
            // --------------------


            // Load all the users
            /** @var ODRUser[] $user_list */
            $user_list = $user_manager->findUsers();    // twig will filter out deleted users, if needed

            // For convenience, this list of users should be filtered down to those that can also
            //  (or used to be able to) edit the datatypes that the calling user can edit.  It's not
            //  really a security risk if this doesn't happen though.

            // Super admins should always be able to see the entire list of users here...
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') ) {
                // Can't just use the PermissionsManagementService...need to also display users
                // which are deleted or can no longer edit the same datatypes
                $query =
                   'SELECT DISTINCT(ug.user_id)
                    FROM odr_user_group AS ug
                    JOIN odr_group AS g ON ug.group_id = g.id
                    JOIN odr_group_datafield_permissions AS gdfp ON gdfp.group_id = g.id
                    WHERE gdfp.can_edit_datafield = 1 AND g.data_type_id IN (:datatype_ids)';
                $params = array('datatype_ids' => $editable_datatypes);
                $types = array('datatype_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY);

                $conn = $em->getConnection();
                $results = $conn->executeQuery($query, $params, $types);

                $allowed_users = array();
                foreach ($results as $result)
                    $allowed_users[ $result['user_id'] ] = 1;

                foreach ($user_list as $num => $target_user) {
                    // Filter out all users that can't edit the same datatypes as the calling user
                    // Super Admins are intentionally left in the list
                    if ( !isset($allowed_users[$target_user->getId()]) && !$target_user->hasRole('ROLE_SUPER_ADMIN') )
                        unset( $user_list[$num] );
                }
            }


            // ----------------------------------------
            // Render the list of users
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Tracking:tracking_dialog_user_selection.html.twig',
                    array(
                        'user_list' => $user_list,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0x0370c9b3;
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
     * TODO
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function tracklayoutchangesAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new ODRNotImplementedException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');

        }
        catch (\Exception $e) {
            $source = 0xe756bb89;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }
}
