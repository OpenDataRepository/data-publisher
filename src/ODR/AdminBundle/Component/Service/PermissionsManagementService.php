<?php

/**
 * Open Data Repository Data Publisher
 * Permissions Management Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores most of the code related to permission arrays for users/groups, as well as
 * the DQL to to create new groups for datatypes/datafields.
 *
 * Creation of groups when datatypes/datafields are being copied from a master template are handled
 * inside the CreateDatatypeService.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\UserGroup;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
// Other
use Doctrine\ORM\EntityManager;
use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Bridge\Monolog\Logger;


class PermissionsManagementService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var SearchAPIService
     */
    private $search_api_service;

    /**
     * @var UserManagerInterface
     */
    private $user_manager;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * PermissionsManagementService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatatypeInfoService $datatypeInfoService
     * @param SearchAPIService $searchAPIService
     * @param UserManagerInterface $user_manager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatypeInfoService $datatypeInfoService,
        SearchAPIService $searchAPIService,
        UserManagerInterface $user_manager,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dti_service = $datatypeInfoService;
        $this->search_api_service = $searchAPIService;
        $this->user_manager = $user_manager;
        $this->logger = $logger;
    }


    /**
     * Returns the provided user's cached datatype permissions array.
     *
     * @param ODRUser $user
     *
     * @return array
     */
    public function getDatatypePermissions($user)
    {
        if ($user === "anon." || $user == null)
            return array();

        $user_permissions = self::getUserPermissionsArray($user);
        return $user_permissions['datatypes'];
    }


    /**
     * Returns the provided user's cached datafield permissions array.
     *
     * @param ODRUser $user
     *
     * @return array
     */
    public function getDatafieldPermissions($user)
    {
        if ($user === "anon." || $user == null)
            return array();

        $user_permissions = self::getUserPermissionsArray($user);
        return $user_permissions['datafields'];
    }


    /**
     * Returns a comma-separated list of datarecords that this user's datarecord_restrictions
     * allow them to edit.  Returns null if the user doesn't have a datarecord_restriction for
     * this datatype.
     *
     * TODO - merge multiple datarecord restrictions together?
     *
     * @param ODRUser $user
     * @param Datatype $datatype
     *
     * @return null|array
     */
    public function getDatarecordRestrictionList($user, $datatype)
    {
        // Users which aren't logged in don't have additional datarecord restrictions
        if ($user === "anon." || $user == null)
            return null;

        $datatype_permissions = self::getDatatypePermissions($user);
        if ( isset($datatype_permissions[ $datatype->getId() ]['datarecord_restriction']) ) {
            // ...this further restriction is stored as an encoded search key in the database
            $search_key = $datatype_permissions[ $datatype->getId() ]['datarecord_restriction'];

            // Don't need to validate or filter the search key...search as a super-admin
            $search_result = $this->search_api_service->performSearch(
                $datatype,
                $search_key,
                array(), // empty user permissions array since searching as super admin
                0,       // use default sort order for datatype
                true,    // sort ascending by default
                true     // search as super admin, so no filtering takes place
            );

            $complete_datarecord_list = $search_result['complete_datarecord_list'];

            return $complete_datarecord_list;
        }

        // No datarecord restriction, return null
        return null;
    }


    /**
     * Returns whether the given user can view the given Datatype.
     *
     * Users with this permission are able to...
     *  - view non-public datatypes
     *
     * @param ODRUser $user
     * @param DataType $datatype
     *
     * @return bool
     */
    public function canViewDatatype($user, $datatype)
    {
        // If the datatype is public, then it can always be viewed
        if ($datatype->isPublic())
            return true;

        // Otherwise, the datatype is non-public
        // If the user isn't logged in, they can't view the datatype
        if ($user === "anon.")
            return false;

        // Otherwise, the user is logged in
        $datatype_permissions = self::getDatatypePermissions($user);

        if ( isset($datatype_permissions[ $datatype->getId() ])
            && isset($datatype_permissions[ $datatype->getId() ]['dt_view'])
        ) {
            // User has the can_view_datatype permission
            return true;
        }
        else {
            // User does not have the can_view_datatype permission
            return false;
        }
    }


    /**
     * Returns whether the given user can view non-public Datarecords in this Datatype.  If the user
     * has this permission, then they automatically have permission to view the Datatype.
     *
     * Users with this permission are able to...
     *  - view non-public datatypes (due to automatically having the "can_view_datatype" permission)
     *  - view non-public datarecords
     *  - view non-public files/images (if they are also able to view the datafield itself)
     *
     * @param ODRUser $user
     * @param DataType $datatype
     *
     * @return bool
     */
    public function canViewNonPublicDatarecords($user, $datatype)
    {
        // If the user isn't logged in, they can't view non-public datarecords
        if ($user === "anon.")
            return false;

        // Otherwise, the user is logged in
        $datatype_permissions = self::getDatatypePermissions($user);

        if ( isset($datatype_permissions[ $datatype->getId() ])
            && isset($datatype_permissions[ $datatype->getId() ]['dr_view'])
        ) {
            // TODO - add datarecord_restriction to this?

            // User has the can_view_datarecord permission
            return true;
        }
        else {
            // User does not have the can_view_datarecord permission
            return false;
        }
    }


    /**
     * Returns whether the given user can view the given Datarecord.  If the user has this
     * permission, then they automatically have permission to view the Datatype.
     *
     * Users with this permission are able to...
     *  - view non-public datatypes (due to automatically having the "can_view_datatype" permission)
     *  - view non-public datarecords
     *  - view non-public files/images (if they are also able to view the datafield itself)
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     *
     * @return bool
     */
    public function canViewDatarecord($user, $datarecord)
    {
        // If the datarecord is public, then it can be viewed (assuming user can also view datatype)
        if ($datarecord->isPublic())
            return true;

        // Otherwise, the datarecord is non-public
        // ...if the user isn't logged in, they can't view the datarecord
        if ($user === "anon.")
            return false;

        // Otherwise, the user is logged in
        $datatype_permissions = self::getDatatypePermissions($user);

        $datatype = $datarecord->getDataType();
        if ( isset($datatype_permissions[ $datatype->getId() ])
            && isset($datatype_permissions[ $datatype->getId() ]['dr_view'])
        ) {
            // TODO - add datarecord_restriction to this?

            // User has the can_view_datarecord permission
            return true;
        }
        else {
            // User does not have the can_view_datarecord permission
            return false;
        }
    }


    /**
     * Returns whether the given user can create a new Datarecord for this Datatype.  If the user
     * has this permission, then they automatically have permission to view the Datatype.
     *
     * Users with this permission are able to...
     *  - create new datarecords for this datatype
     *
     * TODO - eventually work a datarecord restriction into this?  would have to auto-set datarecord properties and/or datafield contents to match...
     *
     * @param ODRUser $user
     * @param DataType $datatype
     *
     * @return bool
     */
    public function canAddDatarecord($user, $datatype)
    {
        // If the user isn't logged in, they can't add new datarecords
        if ($user === "anon.")
            return false;

        // Otherwise, the user is logged in
        $datatype_permissions = self::getDatatypePermissions($user);

        if ( isset($datatype_permissions[ $datatype->getId() ])
            && isset($datatype_permissions[ $datatype->getId() ]['dr_add'])
        ) {
            // User has the can_add_datarecord permission
            return true;
        }
        else {
            // User does not have the can_add_datarecord permission
            return false;
        }
    }


    /**
     * Returns whether the user can edit any datarecords of this datatype.  A return value of true
     * DOES NOT mean that the user can edit all datarecords of this datatype...there could be a
     * further restriction.  See self::getDatarecordRestrictionList()
     *
     * @param ODRUser $user
     * @param Datatype $datatype
     *
     * @return bool
     */
    public function canEditDatatype($user, $datatype)
    {
        // If the user isn't logged in, they can't edit datarecords
        if ($user === "anon.")
            return false;

        // Otherwise, the user is logged in
        $datatype_permissions = self::getDatatypePermissions($user);

        // The user needs to be able to view the datatype before they can edit it...
        if ( !self::canViewDatatype($user, $datatype) )
            return false;

        if ( isset($datatype_permissions[ $datatype->getId() ])
            && isset($datatype_permissions[ $datatype->getId() ]['dr_edit'])
        ) {
            // User can edit datarecords for this datatype, assuming they have at least one
            //  can_edit_datafield permission and a datarecord restriction doesn't get in the way
            return true;
        }
        else {
            // User can't edit any datarecords for this datatype
            return false;
        }
    }


    /**
     * Returns whether the given user can edit this Datarecord.  If the user has this permission,
     * then they automatically have permission to view the Datatype.
     *
     * This permission isn't directly stored in the database, but is automatically granted when the
     * user has the "can_edit_datafield" permission for at least one Datafield in this Datarecord's
     * Datatype.  It is also granted if the user has the "can_edit_datafield" permission for any of
     * this Datatype's children.
     *
     * Users with this permission are able to...
     *  - access the edit page of this datarecord
     *  - add/remove linked datarecords to this datarecord (assuming other permissions exist)
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     *
     * @return bool
     */
    public function canEditDatarecord($user, $datarecord)
    {
        // If the user isn't logged in, they can't edit datarecords
        if ($user === "anon.")
            return false;

        // Otherwise, the user is logged in
        $datatype_permissions = self::getDatatypePermissions($user);

        // The user needs to be able to view the datarecord before they can edit it...
        if ( !self::canViewDatarecord($user, $datarecord) )
            return false;

        $datatype = $datarecord->getDataType();
        if ( isset($datatype_permissions[ $datatype->getId() ])
            && isset($datatype_permissions[ $datatype->getId() ]['dr_edit'])
        ) {
            // User has the correct permission to edit datarecords of this datatype, however there
            //  might be a further restriction on which datarecords they're allowed to edit...
            $restricted_datarecord_list = self::getDatarecordRestrictionList($user, $datatype);
            if ( !is_null($restricted_datarecord_list) ) {
                if ( in_array($datarecord->getId(), $restricted_datarecord_list) )
                    return true;
                else
                    return false;
            }
            else {
                // User has the can_edit_datarecord permission, no other restrictions to worry about
                return true;
            }
        }
        else {
            // User does not have the can_edit_datarecord permission
            return false;
        }
    }


    /**
     * Returns whether the given user can delete a Datarecord in the given Datatype.  If the user
     * has this permission, then they automatically have permission to view the Datatype.
     *
     * Users with this permission are able to...
     *  - deleting existing datarecords of this datatype
     *
     * TODO - Eventually need the ability to allow/deny based on a search result?  Implementing this would allow people to be restricted to deleting datarecords they created, for instance...
     *
     * @param ODRUser $user
     * @param DataType $datatype
     *
     * @return bool
     */
    public function canDeleteDatarecord($user, $datatype)
    {
        // If the user isn't logged in, they can't delete any datarecords
        if ($user === "anon.")
            return false;

        // Otherwise, the user is logged in
        $datatype_permissions = self::getDatatypePermissions($user);

        if ( isset($datatype_permissions[ $datatype->getId() ])
            && isset($datatype_permissions[ $datatype->getId() ]['dr_delete'])
        ) {
            // User has the can_delete_datarecord permission
            return true;
        }
        else {
            // User does not have the can_delete_datarecord permission
            return false;
        }
    }


    // TODO - implement a permission specifically for linking datarecords?  or modify linking datarecords to use the "can_add/delete_datarecord" permissions?

    // TODO - implement some kind of "can_change_public_status" permission?  currently need to have the "is_datatype_admin" to change public status...

    // TODO - implement the "can_design_datatype" permission?  it's currently controlled by the "is_datatype_admin" permission...


    /**
     * Returns whether the given user is considered an admin of the given Datatype.  If the user
     * has this permission, then they automatically have permission to view the Datatype.
     *
     * Users with this permission are able to...
     *  - run CSV Imports for this datatype
     *  - modify the "master" theme for a datatype
     *  - change public status of datarecords for this datatype
     *  - create/modify/delete user groups for this datatype
     *
     * @param ODRUser $user
     * @param DataType $datatype
     *
     * @return bool
     */
    public function isDatatypeAdmin($user, $datatype)
    {
        // If the user isn't logged in, they aren't considered a datatype admin
        if ($user === "anon.")
            return false;

        // Otherwise, the user is logged in
        $datatype_permissions = self::getDatatypePermissions($user);

        if ( isset($datatype_permissions[ $datatype->getId() ])
            && isset($datatype_permissions[ $datatype->getId() ]['dt_admin'])
        ) {
            // User has the is_datatype_admin permission
            return true;
        }
        else {
            // User does not have the is_datatype_admin permission
            return false;
        }
    }


    /**
     * Returns whether the given user can view the given Datafield.  The caller MUST check whether
     * the user is permitted to view the Datarecord as well.
     *
     * Users with this permission are able to...
     *  - always see this datafield (must be logged-in, has no effect if datafield is public)
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     *
     * @return bool
     */
    public function canViewDatafield($user, $datafield)
    {
        // If the datafield is public, then it can always be viewed
        if ($datafield->isPublic())
            return true;

        // If the user isn't logged in, they can't view a non-public Datafield
        if ($user === "anon.")
            return false;

        // Otherwise, the user is logged in
        $datafield_permissions = self::getDatafieldPermissions($user);

        if ( isset($datafield_permissions[ $datafield->getId() ])
            && isset($datafield_permissions[ $datafield->getId() ]['view'])
        ) {
            // User has the can_view_datafield permission
            return true;
        }
        else {
            // User does not have the can_view_datafield permission
            return false;
        }
    }


    /**
     * Returns whether the given user can edit the given Datafield for the given Datarecord.
     *
     * Users with this permission are able to...
     *  - change the content of this datafield
     *  - upload/delete files/images from this datafield
     *  - change public status of files/images in this datafield
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param DataRecord $datarecord
     *
     * @return bool
     */
    public function canEditDatafield($user, $datafield, $datarecord)
    {
        // If the user isn't logged in, they can't edit any Datafield
        if ($user === "anon.")
            return false;

        // The user is only allowed to edit this Datafield if they can also edit the Datarecord
        if ( !self::canEditDatarecord($user, $datarecord) )
            return false;

        // Otherwise, the user is logged in and able to edit the Datarecord
        $datafield_permissions = self::getDatafieldPermissions($user);

        if ( isset($datafield_permissions[ $datafield->getId() ])
            && isset($datafield_permissions[ $datafield->getId() ]['edit'])
        ) {
            // User has the can_edit_datafield permission
            return true;
        }
        else {
            // User does not have the can_edit_datafield permission
            return false;
        }
    }


    /**
     * Returns whether the given user can view or download the given File.
     *
     * TODO - this really should have its own permission...
     *
     * @param ODRUser $user
     * @param File $file
     *
     * @return bool
     */
    public function canViewFile($user, $file)
    {
        // If the user can't view the datafield/datarecord the file has been uploaded to, then they
        //  also can't view the file...
        if ( !self::canViewDatafield($user, $file->getDataField())
            || !self::canViewDatarecord($user, $file->getDataRecord())
        ) {
            return false;
        }

        // If user can view the datafield/datarecord, then they're always able to view public files...
        if ($file->isPublic())
            return true;

        // If the file is non-public, it shouldn't be viewable unless logged in...
        if ($user === "anon.")
            return false;

        // Otherwise, the user is logged in...
        $datatype_permissions = self::getDatatypePermissions($user);

        $datatype = $file->getDataRecord()->getDataType();
        if ( isset($datatype_permissions[ $datatype->getId() ])
            && isset($datatype_permissions[ $datatype->getId() ]['dr_view'])
        ) {
            // User has the can_view_datarecord permission
            return true;
        }
        else {
            // User does not have the can_view_datarecord permission
            return false;
        }
    }


    /**
     * Returns whether the given user can view or download the given Image.
     *
     * TODO - this really should have its own permission...
     *
     * @param ODRUser $user
     * @param Image $image
     *
     * @return bool
     */
    public function canViewImage($user, $image)
    {
        // If the user can't view the datafield/datarecord the image has been uploaded to, then they
        //  also can't view the file...
        if ( !self::canViewDatafield($user, $image->getDataField())
            || !self::canViewDatarecord($user, $image->getDataRecord())
        ) {
            return false;
        }

        // If user can view the datafield/datarecord, then they're always able to view public images...
        if ($image->isPublic())
            return true;

        // If the image is non-public, it shouldn't be viewable unless logged in...
        if ($user === "anon.")
            return false;

        // Otherwise, the user is logged in...
        $datatype_permissions = self::getDatatypePermissions($user);

        $datatype = $image->getDataRecord()->getDataType();
        if ( isset($datatype_permissions[ $datatype->getId() ])
            && isset($datatype_permissions[ $datatype->getId() ]['dr_view'])
        ) {
            // User has the can_view_datarecord permission
            return true;
        }
        else {
            // User does not have the can_view_datarecord permission
            return false;
        }
    }


    /**
     * Gets and returns the permissions array for the given user.
     *
     * @param ODRUser $user
     *
     * @throws ODRException
     *
     * @return array
     */
    public function getUserPermissionsArray($user)
    {
        try {
            // Users that aren't logged in don't have permissions
            if ($user == null || $user === 'anon.') {
                return array(
                    'datatypes' => array(),
                    'datafields' => array()
                );
            }

            // Permissions are cached per user to allow other parts of ODR can force a rebuild
            //  whenever they make a change that would invalidate the user's permissions
            $user_id = $user->getId();
            $user_permissions = $this->cache_service->get('user_'.$user_id.'_permissions');
            if ($user_permissions != false)
                return $user_permissions;


            // ----------------------------------------
            // If this point is reached, the user's permissions arrays need to be rebuilt
            // Determine which groups the user belongs to
            $query = $this->em->createQuery(
               'SELECT g.id AS group_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODRAdminBundle:Group AS g WITH ug.group = g
                WHERE ug.user = :user_id
                AND ug.deletedAt IS NULL AND g.deletedAt IS NULL'
            )->setParameters( array('user_id' => $user_id) );
            $results = $query->getArrayResult();

            $user_groups = array();
            foreach ($results as $result)
                $user_groups[] = $result['group_id'];


            // ----------------------------------------
            // Attempt to load the cached permissions for each group the user belongs to
            $group_permissions = array();
            foreach ($user_groups as $num => $group_id) {
                // Attempt to load the permissions for this group
                $permissions = $this->cache_service->get('group_'.$group_id.'_permissions');
                if ($permissions == false) {
                    $permissions = self::rebuildGroupPermissionsArray($group_id);
                    $this->cache_service->set('group_'.$group_id.'_permissions', $permissions);
                }

                $group_permissions[$group_id] = $permissions;
            }

//exit( '<pre>'.print_r($group_permissions, true).'</pre>' );

            // ----------------------------------------
            // The permissions need to be combined into a single array per datatype or datafield
            $user_permissions = array('datatypes' => array(), 'datafields' => array());

            foreach ($group_permissions as $group_id => $group_permission) {

                // Store permissions for datatypes...
                foreach ($group_permission['datatypes'] as $dt_id => $dt_permissions) {
                    foreach ($dt_permissions as $permission => $num)
                        $user_permissions['datatypes'][$dt_id][$permission] = 1;

                    // If the user is an admin for the datatype, ensure they're allowed to edit datarecords of the datatype
                    // TODO - shouldn't ODRGroupController ensure this check is unnecessary?
                    if ( isset($user_permissions['datatypes'][$dt_id]['dt_admin']) )
                        $user_permissions['datatypes'][$dt_id]['dr_edit'] = 1;
                }

                // Store permissions for datafields...
                foreach ($group_permission['datafields'] as $dt_id => $datafields) {
                    foreach ($datafields as $df_id => $df_permissions) {
                        if ( isset($df_permissions['view']) )
                            $user_permissions['datafields'][$df_id]['view'] = 1;

                        if ( isset($df_permissions['edit']) ) {
                            $user_permissions['datafields'][$df_id]['edit'] = 1;
                            $user_permissions['datatypes'][$dt_id]['dr_edit'] = 1;
                        }
                    }
                }

                // If it exists, store a restriction on which datarecords this permission applies to
                if(isset($group_permission['top_level_datatype_id'])) {
                    $top_level_dt_id = $group_permission['top_level_datatype_id'];
                    if ( isset($group_permission['datarecord_restriction']) && $group_permission['datarecord_restriction'] !== '' )
                        $user_permissions['datatypes'][$top_level_dt_id]['datarecord_restriction'] = $group_permission['datarecord_restriction'];
                }

                // TODO - how to handle multiple datarecord_restrictions on the same datatype?
            }

            // If child datatypes have the "dr_edit" permission, ensure their parents do as well
            $datatree_array = $this->dti_service->getDatatreeArray();

            foreach ($user_permissions['datatypes'] as $dt_id => $dt_permissions) {
                if ( isset($dt_permissions['dr_edit']) ) {

                    $parent_datatype_id = $dt_id;
                    while(
                        isset($datatree_array['descendant_of'][$parent_datatype_id])
                        && $datatree_array['descendant_of'][$parent_datatype_id] !== ''
                    ) {
                        $parent_datatype_id = $datatree_array['descendant_of'][$parent_datatype_id];
                        $user_permissions['datatypes'][$parent_datatype_id]['dr_edit'] = 1;
                    }
                }
            }

//exit( '<pre>'.print_r($user_permissions, true).'</pre>' );

            // Store the final permissions array back in the cache
            $this->cache_service->set('user_'.$user_id.'_permissions', $user_permissions);

            // ----------------------------------------
            // Return the permissions for all groups this user belongs to
            return $user_permissions;
        }
        catch (\Exception $e) {
            throw new ODRException( $e->getMessage() );
        }
    }


    /**
     * Rebuilds the cached version of a group's datatype/datafield permissions array
     *
     * @param integer $group_id
     *
     * @return array
     */
    public function rebuildGroupPermissionsArray($group_id)
    {
        // Load all permission entities from the database for the given group
        $query = $this->em->createQuery(
           'SELECT partial g.{id, purpose}, partial gm.{id, datarecord_restriction},
            partial g_dt.{id},
            gdtp, partial dt.{id},
            gdfp, partial df.{id}, partial df_dt.{id}

            FROM ODRAdminBundle:Group AS g
            JOIN g.groupMeta AS gm
            JOIN g.dataType AS g_dt

            LEFT JOIN g.groupDatatypePermissions AS gdtp
            LEFT JOIN gdtp.dataType AS dt

            LEFT JOIN g.groupDatafieldPermissions AS gdfp
            LEFT JOIN gdfp.dataField AS df
            LEFT JOIN df.dataType AS df_dt

            WHERE g.id = :group_id
            AND g.deletedAt IS NULL AND gm.deletedAt IS NULL
            AND gdtp.deletedAt IS NULL AND gdfp.deletedAt IS NULL
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND df_dt.deletedAt IS NULL'
        )->setParameters( array('group_id' => $group_id) );
        $results = $query->getArrayResult();
//exit( '<pre>'.print_r($results, true).'</pre>' );

        // Read the query result to find...
        $top_level_datatype_id = '';
        $datarecord_restriction = '';
        $datatype_permissions = array();
        $datafield_permissions = array();

        foreach ($results as $group) {
            // Store which datatype this group belongs to (can different from which group it affects)
            $top_level_datatype_id = $group['dataType']['id'];

            // Store datarecord restriction only if this is a custom group
            if ($group['purpose'] === '')
                $datarecord_restriction = $group['groupMeta'][0]['datarecord_restriction'];

            // Build the permissions list for datatypes
            foreach ($group['groupDatatypePermissions'] as $num => $permission) {
                if ( !isset($permission['dataType']['id']) )
                    continue;

                $dt_id = $permission['dataType']['id'];
                $datatype_permissions[$dt_id] = array();

                if ($permission['can_view_datatype'])
                    $datatype_permissions[$dt_id]['dt_view'] = 1;
                if ($permission['can_view_datarecord'])
                    $datatype_permissions[$dt_id]['dr_view'] = 1;
                if ($permission['can_add_datarecord'])
                    $datatype_permissions[$dt_id]['dr_add'] = 1;
                if ($permission['can_delete_datarecord'])
                    $datatype_permissions[$dt_id]['dr_delete'] = 1;
//                if ($permission['can_design_datatype'])
//                    $datatype_permissions[$dt_id]['dt_design'] = 1;
                if ($permission['is_datatype_admin'])
                    $datatype_permissions[$dt_id]['dt_admin'] = 1;
            }

            // Build the permissions list for datafields
            foreach ($group['groupDatafieldPermissions'] as $num => $permission) {
                $dt_id = $permission['dataField']['dataType']['id'];
                if ( !isset($datafield_permissions[$dt_id]) )
                    $datafield_permissions[$dt_id] = array();

                $df_id = $permission['dataField']['id'];
                $datafield_permissions[$dt_id][$df_id] = array();

                if ($permission['can_view_datafield'])
                    $datafield_permissions[$dt_id][$df_id]['view'] = 1;
                if ($permission['can_edit_datafield'])
                    $datafield_permissions[$dt_id][$df_id]['edit'] = 1;
            }
        }

        // ----------------------------------------
        // Return the final array
        return array(
            'top_level_datatype_id' => $top_level_datatype_id,
            'datarecord_restriction' => $datarecord_restriction,
            'datatypes' => $datatype_permissions,
            'datafields' => $datafield_permissions,
        );
    }


    /**
     * Given a group's permission arrays, filter the provided datarecord/datatype arrays so twig
     * doesn't end up rendering anything they're not supposed to see.
     *
     * @param array &$datatype_array    @see DatatypeInfoService::getDatatypeArray()
     * @param array &$datarecord_array  @see DatarecordInfoService::getDatarecordArray()
     * @param array $permissions_array  @see self::getUserPermissionsArray()
     */
    public function filterByGroupPermissions(&$datatype_array, &$datarecord_array, $permissions_array)
    {
$debug = true;
$debug = false;

if ($debug)
    print '----- permissions filter -----'."\n";

        // Save relevant permissions...
        $datatype_permissions = array();
        if ( isset($permissions_array['datatypes']) )
            $datatype_permissions = $permissions_array['datatypes'];
        $datafield_permissions = array();
        if ( isset($permissions_array['datafields']) )
            $datafield_permissions = $permissions_array['datafields'];


        $can_view_datatype = array();
        $can_view_datarecord = array();
        $datafields_to_remove = array();
        foreach ($datatype_array as $dt_id => $dt) {
            // Store whether the user can view each of the datatypes
            if ( isset($datatype_permissions[$dt_id])
                && isset($datatype_permissions[$dt_id]['dt_view'])
            ) {
                $can_view_datatype[$dt_id] = true;
            }
            else {
                $can_view_datatype[$dt_id] = false;
            }

            // Store whether the user can view non-public datarecords of each datatype
            if ( isset($datatype_permissions[$dt_id])
                && isset($datatype_permissions[ $dt_id ][ 'dr_view' ])
            ) {
                $can_view_datarecord[$dt_id] = true;
            }
            else {
                $can_view_datarecord[$dt_id] = false;
            }
        }


        // ----------------------------------------
        // For each datatype in the provided array...
        foreach ($datatype_array as $dt_id => $dt) {

            // If there was no datatype permission entry for this datatype, have it default to false
            if ( !isset($can_view_datatype[$dt_id]) )
                $can_view_datatype[$dt_id] = false;

            // If datatype is non-public and user does not have the 'can_view_datatype' permission...
            if ( $dt['dataTypeMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00'
                && !$can_view_datatype[$dt_id]
            ) {
                // ...then remove the datatype from the list of things for twig to render
                unset( $datatype_array[$dt_id] );
if ($debug)
    print 'removed non-public datatype '.$dt_id."\n";

                // ...also pre-emptively remove all datarecords of that datatype
                foreach ($datarecord_array as $dr_id => $dr) {
                    if ($dt_id == $dr['dataType']['id'])
                        unset( $datarecord_array[$dr_id] );
if ($debug)
    print ' -- removed datarecord '.$dr_id."\n";
                }

                // No sense checking anything else for this datatype, skip to the next one
                continue;
            }

            // Otherwise, the user is allowed to see this datatype
            // Need to filter out datafields the user isn't allowed to view...
            foreach ($dt['dataFields'] as $df_id => $df) {
                // Determine whether the user can view the datafield or not
                $can_view_datafield = false;
                if ( isset($datafield_permissions[$df_id])
                    && isset($datafield_permissions[$df_id]['view'])
                ) {
                    $can_view_datafield = true;
                }

                // If the user doesn't have the 'can_view_datafield' permission for that datafield...
                if ( $df['dataFieldMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00'
                    && !$can_view_datafield
                ) {
                    // ...then remove it from the layout
                    unset( $datatype_array[$dt_id]['dataFields'][$df_id] );
                    $datafields_to_remove[$df_id] = 1;
if ($debug)
    print 'removed datafield '.$df_id.' from datatype '.$dt_id."\n";
                }
            }
        }


        // ----------------------------------------
        // Also need to go through the datarecord array and remove all datarecords and datafields
        //  that the user isn't allowed to see
        foreach ($datarecord_array as $dr_id => $dr) {
            // Save datatype id of this datarecord
            $dt_id = $dr['dataType']['id'];

            // If there was no datatype permission entry for this datatype, have it default to false
            if ( !isset($can_view_datarecord[$dt_id]) )
                $can_view_datarecord[$dt_id] = false;

            // If the datarecord is non-public and user doesn't have the 'can_view_datarecord' permission...
            if ( $dr['dataRecordMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00'
                && !$can_view_datarecord[$dt_id]
            ) {
                // ...then remove the datarecord from the array
                unset( $datarecord_array[$dr_id] );
if ($debug)
    print 'removed non-public datarecord '.$dr_id."\n";

                // No sense checking anything else for this datarecord, skip to the next one
                continue;
            }

            // Otherwise, the user is allowed to view this datarecord
            // Iterate through this datarecords datafield entries...
            foreach ($dr['dataRecordFields'] as $df_id => $drf) {

                // ...and remove the datafield if the user doesn't have the ability to view it
                if ( isset($datafields_to_remove[$df_id]) ) {
                    unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id] );
if ($debug)
    print 'removed datafield '.$df_id.' from datarecord '.$dr_id."\n";

                    // No sense checking file/image public status, skip to the next datafield
                    continue;
                }

                // ...also need to remove files the user isn't allowed to see
                // TODO - this needs its own permission instead of using "can_view_datarecord"
                foreach ($drf['file'] as $file_num => $file) {
                    if ( $file['fileMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00'
                        && !$can_view_datarecord[$dt_id]
                    ) {
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id]['file'][$file_num] );
if ($debug)
    print 'removed non-public file '.$file['id'].' from datarecord '.$dr_id.' datatype '.$dt_id."\n";
                    }
                }

                // ...also need to remove images the user isn't allowed to see
                // TODO - this needs its own permission instead of using "can_view_datarecord"
                foreach ($drf['image'] as $image_num => $image) {
                    if ( $image['parent']['imageMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00'
                        && !$can_view_datarecord[$dt_id]
                    ) {
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id]['image'][$image_num] );
if ($debug)
    print 'removed non-public image '.$image['parent']['id'].' from datarecord '.$dr_id.' datatype '.$dt_id."\n";
                    }
                }
            }
        }
    }


    /**
     * Ensures the given user is in the given group.
     *
     * @param ODRUser $user
     * @param Group $group
     * @param ODRUser $admin_user
     * @param bool $delay_flush
     * @param bool $check_group
     *
     * @return UserGroup
     */
    public function createUserGroup($user, $group, $admin_user, $delay_flush = false, $check_group = true)
    {
        // Check to see if the User already belongs to this Group
        // This will be bypassed in the case of newly created groups.
        if($check_group) {
            $query = $this->em->createQuery(
                'SELECT ug
            FROM ODRAdminBundle:UserGroup AS ug
            WHERE ug.user = :user_id AND ug.group = :group_id
            AND ug.deletedAt IS NULL'
            )->setParameters( array('user_id' => $user->getId(), 'group_id' => $group->getId()) );

            /** @var UserGroup[] $results */
            $results = $query->getResult();

            $user_group = null;
            if ( count($results) > 0 ) {
                // If an existing UserGroup entity was found, return it and don't do anything else
                // TODO This works but is strange....
                foreach ($results as $num => $ug)
                    return $ug;
            }
        }

        // ...otherwise, create a new UserGroup entity
        $user_group = new UserGroup();
        $user_group->setUser($user);
        $user_group->setGroup($group);
        $user_group->setCreatedBy($admin_user);

        // Ensure the "in-memory" versions of both the User and Group entities know about the new UserGroup entity
        $group->addUserGroup($user_group);
        $user->addUserGroup($user_group);

        // Save all changes
        $this->em->persist($user_group);
        if (!$delay_flush)
            $this->em->flush();

        return $user_group;
    }

    /**
     * Create a new Group for users of the given datatype.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param string $initial_purpose          One of 'admin', 'edit_all', 'view_all', 'view_only', or ''
     *
     * @return Group
     */
    public function createGroup($user, $datatype, $initial_purpose = '')
    {
        // ----------------------------------------
        // Groups should only be attached to top-level datatypes...child datatypes inherit groups
        //  from their parent
        if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
            throw new ODRBadRequestException('Child Datatypes are not allowed to have groups of their own.');


        // ----------------------------------------
        // Create the Group entity
        $group = new Group();
        $group->setDataType($datatype);
        $group->setPurpose($initial_purpose);
        $group->setCreatedBy($user);

        // Ensure the "in-memory" version of $datatype knows about the new group
        $datatype->addGroup($group);

        $this->em->persist($group);
        $this->em->flush();
        $this->em->refresh($group);


        // Create the GroupMeta entity
        $group_meta = new GroupMeta();
        $group_meta->setGroup($group);
        if ($initial_purpose == 'admin') {
            $group_meta->setGroupName('Default Group - Admin');
            $group_meta->setGroupDescription('Users in this default Group are always allowed to view and edit all Datarecords, modify all layouts, and change permissions of any User with regards to this Datatype.');
        }
        else if ($initial_purpose == 'edit_all') {
            $group_meta->setGroupName('Default Group - Editor');
            $group_meta->setGroupDescription('Users in this default Group can always both view and edit all Datarecords and Datafields of this Datatype.');
        }
        else if ($initial_purpose == 'view_all') {
            $group_meta->setGroupName('Default Group - View All');
            $group_meta->setGroupDescription('Users in this default Group always have the ability to see non-public Datarecords and Datafields of this Datatype, but cannot make any changes.');
        }
        else if ($initial_purpose == 'view_only') {
            $group_meta->setGroupName('Default Group - View');
            $group_meta->setGroupDescription('Users in this default Group are always able to see public Datarecords and Datafields of this Datatype, though they cannot make any changes.  If the Datatype is public, then adding Users to this Group is meaningless.');
        }
        else {
            $group_meta->setGroupName('New user group for '.$datatype->getShortName());
            $group_meta->setGroupDescription('');
        }

        $group_meta->setCreatedBy($user);
        $group_meta->setUpdatedBy($user);

        // Ensure the "in-memory" version of the new group knows about its meta entry
        $group->addGroupMetum($group_meta);

        $this->em->persist($group_meta);
        $this->em->flush();
        $this->em->refresh($group_meta);


        // ----------------------------------------
        // Locate the ids of all children of this top-level datatype
        $query = $this->em->createQuery(
           'SELECT dt.id AS dt_id
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.grandparent = :datatype_id
            AND dt.deletedAt IS NULL'    // TODO - if datatypes are eventually going to be undeleteable, then this needs to also return deleted child datatypes
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        // Flatten the array
        $associated_datatypes = array();
        foreach ($results as $result)
            $associated_datatypes[] = $result['dt_id'];


        // Build a single INSERT INTO query to add GroupDatatypePermissions entries for this top-level datatype and for each of its children
        $query_str = '
            INSERT INTO odr_group_datatype_permissions (
                group_id, data_type_id,
                can_view_datatype, can_view_datarecord, can_add_datarecord, can_delete_datarecord, can_design_datatype, is_datatype_admin,
                created, createdBy, updated, updatedBy
            )
            VALUES ';

        $has_datatypes = false;
        foreach ($associated_datatypes as $num => $dt_id) {
            $has_datatypes = true;

            if ($initial_purpose == 'admin')
                $query_str .= '("'.$group->getId().'", "'.$dt_id.'", "1", "1", "1", "1", "1", "1", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
            else if ($initial_purpose == 'edit_all')
                $query_str .= '("'.$group->getId().'", "'.$dt_id.'", "1", "1", "1", "1", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
            else if ($initial_purpose == 'view_all')
                $query_str .= '("'.$group->getId().'", "'.$dt_id.'", "1", "1", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
            else if ( $initial_purpose == 'view_only' || $dt_id === $datatype->getGrandparent()->getId() )
                $query_str .= '("'.$group->getId().'", "'.$dt_id.'", "1", "0", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
            else
                $query_str .= '("'.$group->getId().'", "'.$dt_id.'", "0", "0", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
        }

        if ($has_datatypes) {
            // Get rid of the trailing comma and replace with a semicolon
            $query_str = substr($query_str, 0, -2).';';
            $conn = $this->em->getConnection();
            $rowsAffected = $conn->executeUpdate($query_str);
        }

        // ----------------------------------------
        // Create the initial datafield permission entries
        $this->em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted datafields
        $query = $this->em->createQuery(
           'SELECT df.id AS df_id, df.deletedAt AS df_deletedAt
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
            WHERE dt.id IN (:datatype_ids)'
        )->setParameters( array('datatype_ids' => $associated_datatypes) );
        $results = $query->getArrayResult();
        $this->em->getFilters()->enable('softdeleteable');
//print_r($results);  exit();

        // Build a single INSERT INTO query to add GroupDatafieldPermissions entries for all datafields of this top-level datatype and its children
        $query_str = '
            INSERT INTO odr_group_datafield_permissions (
                group_id, data_field_id,
                can_view_datafield, can_edit_datafield,
                created, createdBy, updated, updatedBy, deletedAt
            )
            VALUES ';

        $has_datafields = false;
        foreach ($results as $result) {
            $has_datafields = true;
            $df_id = $result['df_id'];
            $df_deletedAt = $result['df_deletedAt'];

            // Want to also store GroupDatafieldPermission entries for deleted datafields, in case said datafields get undeleted later...
            $deletedAt = "NULL";
            if ( !is_null($df_deletedAt) )
                $deletedAt = '"'.$df_deletedAt->format('Y-m-d H:i:s').'"';

            if ($initial_purpose == 'admin' || $initial_purpose == 'edit_all')
                $query_str .= '("'.$group->getId().'", "'.$df_id.'", "1", "1", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'", '.$deletedAt.'),'."\n";
            else if ($initial_purpose == 'view_all')
                $query_str .= '("'.$group->getId().'", "'.$df_id.'", "1", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'", '.$deletedAt.'),'."\n";
            else
                $query_str .= '("'.$group->getId().'", "'.$df_id.'", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'", '.$deletedAt.'),'."\n";
        }

        if ($has_datafields) {
            // Get rid of the trailing comma and replace with a semicolon
            $query_str = substr($query_str, 0, -2).';';
            $conn = $this->em->getConnection();
            $rowsAffected = $conn->executeUpdate($query_str);
        }

        // ----------------------------------------
        // Automatically add super-admin users to new default "admin" groups
        if ($initial_purpose == 'admin') {
            /** @var ODRUser[] $user_list */
            $user_list = $this->user_manager->findUsers();    // disabled users set to ROLE_USER, so doesn't matter if they're in the list

            // Locate those with super-admin permissions...
            foreach ($user_list as $u) {
                if ( $u->hasRole('ROLE_SUPER_ADMIN') ) {
                    // ...add the super admin to this new admin group
                    self::createUserGroup($u, $group, $user);

                    // ...delete the cached list of permissions for each super-admin belongs to
                    $this->cache_service->delete('user_'.$u->getId().'_permissions');
                }
            }
        }

        return $group;
    }


    /**
     * Creates GroupDatatypePermissions for all groups when a new datatype is created.
     *
     * @throws ODRException
     *
     * @param ODRUser $user
     * @param DataType $datatype
     */
    public function createGroupsForDatatype($user, $datatype)
    {
        // Store whether this is a top-level datatype or not
        $datatype_id = $datatype->getId();
        $grandparent_datatype_id = $datatype->getGrandparent()->getId();

        $is_top_level = true;
        if ($datatype_id != $grandparent_datatype_id)
            $is_top_level = false;

        // Locate all groups for this datatype's grandparent
        $repo_group = $this->em->getRepository('ODRAdminBundle:Group');

        /** @var Group[] $groups */
        if ($is_top_level) {
            // Create any default groups the top-level datatype is currently missing...
            $admin_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'admin') );
            if ($admin_group == false)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'admin');

            $edit_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'edit_all') );
            if ($edit_group == false)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'edit_all');

            $view_all_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'view_all') );
            if ($view_all_group == false)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'view_all');

            $view_only_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'view_only') );
            if ($view_only_group == false)
                self::createGroup($datatype->getCreatedBy(), $datatype, 'view_only');
        }
        else {
            // Load all groups belonging to the grandparent datatype
            $groups = $repo_group->findBy( array('dataType' => $grandparent_datatype_id) );
            if ($groups == false)
                throw new ODRException('createGroupsForDatatype(): grandparent datatype '.$grandparent_datatype_id.' has no groups for child datatype '.$datatype->getId().' to copy from.');

            // Ensure the grandparent datatype has all of its default groups
            $has_admin = $has_edit = $has_view_all = $has_view_only = false;
            foreach ($groups as $group) {
                if ($group->getPurpose() == 'admin')
                    $has_admin = true;
                if ($group->getPurpose() == 'edit_all')
                    $has_edit = true;
                if ($group->getPurpose() == 'view_all')
                    $has_view_all = true;
                if ($group->getPurpose() == 'view_only')
                    $has_view_only = true;
            }

            if (!$has_admin || !$has_edit || !$has_view_all || !$has_view_only)
                throw new ODRException('createGroupsForDatatype(): grandparent datatype '.$grandparent_datatype_id.' is missing a default group for child datatype '.$datatype->getId().' to copy from.');


            // Load the list of groups and users this INSERT INTO query will affect
            $group_list = array();
            foreach ($groups as $group)
                $group_list[] = $group->getId();

            $query = $this->em->createQuery(
               'SELECT DISTINCT(u.id) AS user_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE ug.group IN (:groups)
                AND ug.deletedAt IS NULL'
            )->setParameters( array('groups' => $group_list) );
            $results = $query->getArrayResult();

            $user_list = array();
            foreach ($results as $result)
                $user_list[] = $result['user_id'];


            // ----------------------------------------
            // Build a single INSERT INTO query to add a GroupDatatypePermissions entry for this child datatype to all groups found previously
            $query_str = '
                INSERT INTO odr_group_datatype_permissions (
                    group_id, data_type_id,
                    can_view_datatype, can_view_datarecord, can_add_datarecord, can_delete_datarecord, can_design_datatype, is_datatype_admin,
                    created, createdBy, updated, updatedBy
                )
                VALUES ';

            foreach ($groups as $group) {
                // Default permissions depend on the original purpose of this group...
                $initial_purpose = $group->getPurpose();

                $cache_update = null;
                if ($initial_purpose == 'admin') {
                    $query_str .= '("'.$group->getId().'", "'.$datatype_id.'", "1", "1", "1", "1", "1", "1", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                    $cache_update = array('dt_view' => 1, 'dr_view' => 1, 'dr_add' => 1, 'dr_delete' => 1,/* 'dt_design' => 1,*/ 'dt_admin' => 1);
                }
                else if ($initial_purpose == 'edit_all') {
                    $query_str .= '("'.$group->getId().'", "'.$datatype_id.'", "1", "1", "1", "1", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                    $cache_update = array('dt_view' => 1, 'dr_view' => 1, 'dr_add' => 1, 'dr_delete' => 1);
                }
                else if ($initial_purpose == 'view_all') {
                    $query_str .= '("'.$group->getId().'", "'.$datatype_id.'", "1", "1", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                    $cache_update = array('dt_view' => 1, 'dr_view' => 1);
                }
                else if ($initial_purpose == 'view_only' ) {
                    $query_str .= '("'.$group->getId().'", "'.$datatype_id.'", "1", "0", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                    $cache_update = array('dt_view' => 1);
                }
                else {
                    $query_str .= '("'.$group->getId().'", "'.$datatype_id.'", "0", "0", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                    /* no need to update the cache entry with this */
                }

                if ($cache_update != null) {
                    // Immediately update group permissions with the new datatype, if a cached version of those permissions exists
                    $group_permissions = $this->cache_service->get('group_'.$group->getId().'_permissions');
                    if ($group_permissions != false) {
                        $group_permissions['datatypes'][$datatype_id] = $cache_update;
                        $this->cache_service->set('group_'.$group->getId().'_permissions', $group_permissions);
                    }
                }
            }

            // Get rid of the trailing comma and replace with a semicolon
            $query_str = substr($query_str, 0, -2).';';
            $conn = $this->em->getConnection();
            $rowsAffected = $conn->executeUpdate($query_str);


            // ----------------------------------------
            // Delete all permission entries for each affected user...
            // Not updating cached entry because it's a combination of all group permissions, and would take as much work to figure out what all to change as it would to just rebuild it
            foreach ($user_list as $user_id)
                $this->cache_service->delete('user_'.$user_id.'_permissions');
        }
    }


    /**
     * Creates GroupDatafieldPermissions for all groups when a new datafield is created, and updates existing cache entries for groups and users with the new datafield.
     *
     * @throws ODRException
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     */
    public function createGroupsForDatafield($user, $datafield)
    {
        // ----------------------------------------
        // Locate this datafield's datatype's grandparent
        $datatype_id = $datafield->getDataType()->getId();
        $grandparent_datatype_id = $datafield->getDataType()->getGrandparent()->getId();

        // Locate all groups for this datatype's grandparent
        /** @var Group[] $groups */
        $groups = $this->em->getRepository('ODRAdminBundle:Group')->findBy( array('dataType' => $grandparent_datatype_id) );
        // Unless something has gone wrong previously, there should always be results in this
        if ($groups == false)
            throw new ODRException('createGroupsForDatatype(): grandparent datatype '.$grandparent_datatype_id.' has no groups for datafield '.$datafield->getId().' to copy from.');

        // ----------------------------------------
        // Load the list of groups and users this INSERT INTO query will affect
        $group_list = array();
        foreach ($groups as $group)
            $group_list[] = $group->getId();

        $query = $this->em->createQuery(
           'SELECT DISTINCT(u.id) AS user_id
            FROM ODRAdminBundle:UserGroup AS ug
            JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
            WHERE ug.group IN (:groups)
            AND ug.deletedAt IS NULL'
        )->setParameters( array('groups' => $group_list) );
        $results = $query->getArrayResult();

        $user_list = array();
        foreach ($results as $result)
            $user_list[] = $result['user_id'];


        // ----------------------------------------
        // Build a single INSERT INTO query to add a GroupDatafieldPermissions entry for this datafield to all groups found previously
        $query_str = '
            INSERT INTO odr_group_datafield_permissions (
                group_id, data_field_id,
                can_view_datafield, can_edit_datafield,
                created, createdBy, updated, updatedBy
            )
            VALUES ';
        foreach ($groups as $group) {
            $initial_purpose = $group->getPurpose();

            $cache_update = null;
            if ($initial_purpose == 'admin' || $initial_purpose == 'edit_all') {
                $query_str .= '("'.$group->getId().'", "'.$datafield->getId().'", "1", "1", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                $cache_update = array('view' => 1, 'edit' => 1);
            }
            else if ($initial_purpose == 'view_all') {
                $query_str .= '("'.$group->getId().'", "'.$datafield->getId().'", "1", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                $cache_update = array('view' => 1);
            }
            else {
                $query_str .= '("'.$group->getId().'", "'.$datafield->getId().'", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                /* no need to update the cache entry with this */
            }

            if ($cache_update != null) {
                // Immediately update group permissions with the new datafield, if a cached version of those permissions exists
                $group_permissions = $this->cache_service->get('group_'.$group->getId().'_permissions');
                if ($group_permissions != false) {
                    if ( !isset($group_permissions['datafields'][$datatype_id]) )
                        $group_permissions['datafields'][$datatype_id] = array();

                    $group_permissions['datafields'][$datatype_id][$datafield->getId()] = $cache_update;
                    $this->cache_service->set('group_'.$group->getId().'_permissions', $group_permissions);
                }
            }
        }

        // Get rid of the trailing comma and replace with a semicolon
        $query_str = substr($query_str, 0, -2).';';
        $conn = $this->em->getConnection();
        $rowsAffected = $conn->executeUpdate($query_str);


        // ----------------------------------------
        // Delete all permission entries for each affected user...
        // Not updating cached entry because it's a combination of all group permissions, and would take as much work to figure out what all to change as it would to just rebuild it
        foreach ($user_list as $user_id)
            $this->cache_service->delete('user_'.$user_id.'_permissions');
    }
}
