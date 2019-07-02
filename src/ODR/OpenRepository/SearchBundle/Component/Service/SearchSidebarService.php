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
     * @var Logger
     */
    private $logger;


    /**
     * SearchSidebarService constructor.
     *
     * @param EntityManager $entityManager
     * @param DatatypeInfoService $datatypeInfoService
     * @param PermissionsManagementService $permissionsManagementService
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entityManager,
        DatatypeInfoService $datatypeInfoService,
        PermissionsManagementService $permissionsManagementService,
        Logger $logger
    ) {
        $this->em = $entityManager;
        $this->dti_service = $datatypeInfoService;
        $this->pm_service = $permissionsManagementService;
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
     * Returns an array of user ids and usernames based on the datatypes that $admin_user can see,
     * so that the search page can populate createdBy/updatedBy fields correctly
     *
     * @param ODRUser $user
     *
     * @return array
     */
    public function getSidebarUserList($user)
    {
        // Determine if the user has the permissions required to see anybody in the created/modified by search fields
        $datatype_permissions = $this->pm_service->getDatatypePermissions($user);

        $relevant_permissions = array();
        foreach ($datatype_permissions as $datatype_id => $up) {
            if ( (isset($up['dr_edit']) && $up['dr_edit'] == 1)
                || (isset($up['dr_delete']) && $up['dr_delete'] == 1)
                || (isset($up['dr_add']) && $up['dr_add'] == 1)
            ) {
                $relevant_permissions[ $datatype_id ] = $up;
            }
        }

        if ( $user == 'anon.' || count($relevant_permissions) == 0 ) {
            // Not logged in, or has none of the required permissions
            return array();
        }


        // Otherwise, locate users to populate the created/modified by boxes with
        // Get a list of datatypes the user is allowed to access
        $datatype_list = array();
        foreach ($relevant_permissions as $dt_id => $tmp)
            $datatype_list[] = $dt_id;

        // Get all other users which can view that list of datatypes
        $query = $this->em->createQuery(
           'SELECT u.id, u.username, u.email, u.firstName, u.lastName
            FROM ODROpenRepositoryUserBundle:User AS u
            JOIN ODRAdminBundle:UserGroup AS ug WITH ug.user = u
            JOIN ODRAdminBundle:Group AS g WITH ug.group = g
            JOIN ODRAdminBundle:GroupDatatypePermissions AS gdtp WITH gdtp.group = g
            JOIN ODRAdminBundle:GroupDatafieldPermissions AS gdfp WITH gdfp.group = g
            WHERE u.enabled = 1 AND g.dataType IN (:datatypes) AND (gdtp.can_add_datarecord = 1 OR gdtp.can_delete_datarecord = 1 OR gdfp.can_edit_datafield = 1)
            GROUP BY u.id'
        )->setParameters( array('datatypes' => $datatype_list) );   // purposefully getting ALL users, including the ones that are deleted
        $results = $query->getArrayResult();

        // Convert them into a list of users that the admin user is allowed to search by
        $user_list = array();
        foreach ($results as $user) {
            $username = '';
            if ( is_null($user['firstName']) || $user['firstName'] === '' )
                $username = $user['email'];
            else
                $username = $user['firstName'].' '.$user['lastName'];

            $user_list[ $user['id'] ] = $username;
        }

        return $user_list;
    }
}
