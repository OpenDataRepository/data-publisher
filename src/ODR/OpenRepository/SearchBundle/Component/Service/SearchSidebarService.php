<?php

/**
 * Open Data Repository Data Publisher
 * Search Sidebar Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains functions to help build the search sidebar.
 */

namespace ODR\OpenRepository\SearchBundle\Component\Service;

// Entities
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
// Services
use FOS\UserBundle\Doctrine\UserManager;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class SearchSidebarService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var UserManager
     */
    private $user_manager;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * SearchSidebarService constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatatypeInfoService $datatype_info_service
     * @param PermissionsManagementService $permissions_service
     * @param UserManager $user_manager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatatypeInfoService $datatype_info_service,
        PermissionsManagementService $permissions_service,
        UserManager $user_manager,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dti_service = $datatype_info_service;
        $this->pm_service = $permissions_service;
        $this->user_manager = $user_manager;

        $this->logger = $logger;
    }


    /**
     * Returns a cached datatype array that has been filtered by the user's permissions.
     *
     * @param ODRUser $user
     * @param int $target_datatype_id
     *
     * @return array
     */
    public function getSidebarDatatypeArray($user, $target_datatype_id) {
        // Need to load the cached version of this datatype, along with any linked datatypes it has
        $datatype_array = $this->dti_service->getDatatypeArray($target_datatype_id, true);

        // ...then filter the array to just what the user can see
        $datarecord_array = array();
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);
        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // ...then sort the datafields of each datatype by name in the interest of making them easier
        //  to find
        foreach ($datatype_array as $dt_id => $dt) {
            uasort($datatype_array[$dt_id]['dataFields'], function ($a, $b) {
                $a_name = $a['dataFieldMeta']['fieldName'];
                $b_name = $b['dataFieldMeta']['fieldName'];

                return strcmp($a_name, $b_name);
            });
        }

        return $datatype_array;
    }


    /**
     * Takes a cached datatype array and the top-level datatype id, and returns another array that
     * organizes the child/linked datatypes into a different format.
     *
     * @param array $datatype_array
     * @param int $target_datatype_id
     *
     * @return array
     */
    public function getSidebarDatatypeRelations($datatype_array, $target_datatype_id) {
        $datatree_array = $this->dti_service->getDatatreeArray();
        $datatype_list = array(
            'child_datatypes' => array(),
            'linked_datatypes' => array(),
        );
        foreach ($datatype_array as $dt_id => $datatype_data) {
            // Don't want the top-level datatype in this array
            if ($dt_id === $target_datatype_id)
                continue;

            // Locate this particular datatype's grandparent id...
            $gp_dt_id = $this->dti_service->getGrandparentDatatypeId($dt_id, $datatree_array);

            if ($gp_dt_id === $target_datatype_id) {
                // If it's the same as the target datatype being searched on, then it's a child
                //  datatype
                $datatype_list['child_datatypes'][] = $dt_id;
            }
            else {
                // Otherwise, it's a linked datatype (or a child of a linked datatype)
                $datatype_list['linked_datatypes'][] = $dt_id;
            }
        }

        return $datatype_list;
    }


    /**
     * Given a user and a filtered datatype array, this function returns a list of all users that
     * can edit/add/delete the datarecords belonging to the datatypes in the array.  Super admins
     * are included and labelled, as well.  This list is used so that the search sidebar can
     * correctly populate the createdBy/modifiedBy fields.
     *
     * @param ODRUser $user
     * @param array $datatype_array
     *
     * @return array
     */
    public function getSidebarUserList($user, $datatype_array)
    {
        // Don't display any other users when the current user isn't logged in
        if ( $user == 'anon.' )
            return array();

        // Otherwise, the user needs to be able to edit/add/delete datarecords from at least one of
        //  the datatypes in the array...
        $datatype_permissions = $this->pm_service->getDatatypePermissions($user);

        $editable_datatypes = array();
        foreach ($datatype_array as $dt_id => $dt_data) {
            if ( isset($datatype_permissions[$dt_id]) ) {
                if ( isset($datatype_permissions[$dt_id]['dr_edit'])
                    || isset($datatype_permissions[$dt_id]['dr_add'])
                    || isset($datatype_permissions[$dt_id]['dr_delete'])
                ) {
                    $editable_datatypes[] = $dt_id;
                }
            }
        }

        // If the user doesn't have any of the relevant permissions, then don't show them any users
        if ( empty($editable_datatypes) )
            return array();


        // ----------------------------------------
        // Load the details for all of the users
        // TODO - should this be changed to also include deleted users?
        /** @var ODRUser[] $all_users */
        $all_users = $this->user_manager->findUsers();

        $user_lookup = array();
        $super_admins = array();
        foreach ($all_users as $u) {
            if ( $u->isEnabled() ) {
                if ($u->hasRole('ROLE_SUPER_ADMIN'))
                    $super_admins[$u->getId()] = $u->getUserString().' (Super Admin)';
                else
                    $user_lookup[$u->getId()] = $u->getUserString();
            }
        }


        // ----------------------------------------
        // Need to find all users that can currently (and used to be able to) modify these datatypes
        $user_list = array();

        $conn = $this->em->getConnection();
        $params = array('datatype_ids' => $editable_datatypes);
        $types = array('datatype_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY);


        // Two tables to check...do the GroupDatatypePermissions table first...
        // NOTE - using gdtp.data_type_id instead of g.data_type_id to get all datatype ids instead
        //  of just top-level datatype ids
        $query =
           'SELECT ug.user_id AS user_id, gdtp.data_type_id AS dt_id
            FROM odr_user_group AS ug
            JOIN odr_group AS g ON ug.group_id = g.id
            JOIN odr_group_datatype_permissions AS gdtp ON gdtp.group_id = g.id
            WHERE g.data_type_id IN (:datatype_ids)
            AND (gdtp.can_add_datarecord = 1 OR gdtp.can_delete_datarecord = 1)
            AND gdtp.deletedAt IS NULL AND g.deletedAt IS NULL';
        $results = $conn->executeQuery($query, $params, $types);

        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $user_id = $result['user_id'];

            if ( !isset($user_list[$dt_id]) )
                $user_list[$dt_id] = $super_admins;
            if ( isset($user_lookup[$user_id]) )
                $user_list[$dt_id][$user_id] = $user_lookup[$user_id];
        }

        // ...then do the GroupDatafieldPermissions table
        // NOTE - using df.data_type_id instead of g.data_type_id to get all datatype ids instead
        //  of just top-level datatype ids
        $query =
           'SELECT ug.user_id AS user_id, df.data_type_id AS dt_id
            FROM odr_user_group AS ug
            JOIN odr_group AS g ON ug.group_id = g.id
            JOIN odr_group_datafield_permissions AS gdfp ON gdfp.group_id = g.id
            JOIN odr_data_fields df ON gdfp.data_field_id = df.id
            WHERE g.data_type_id IN (:datatype_ids)
            AND gdfp.can_edit_datafield = 1
            AND df.deletedAt IS NULL AND gdfp.deletedAt IS NULL AND g.deletedAt IS NULL';
        $results = $conn->executeQuery($query, $params, $types);

        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $user_id = $result['user_id'];

            if ( !isset($user_list[$dt_id]) )
                $user_list[$dt_id] = $super_admins;
            if ( isset($user_lookup[$user_id]) )
                $user_list[$dt_id][$user_id] = $user_lookup[$user_id];
        }


        // ----------------------------------------
        // Sort each sublist of users so they're easier to look at
        foreach ($user_list as $dt_id => $users) {
            $tmp = $users;
            asort($tmp, SORT_FLAG_CASE | SORT_STRING);    // case-insenstive sort
            $user_list[$dt_id] = $tmp;
        }

        return $user_list;
    }
}
