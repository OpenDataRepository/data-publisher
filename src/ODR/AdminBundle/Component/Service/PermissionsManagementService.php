<?php

/**
 * Open Data Repository Data Publisher
 * Permissions Management Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores most of the code related to permission arrays for users/groups.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
// Other
use Doctrine\ORM\EntityManager;
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
     * @var DatatreeInfoService
     */
    private $dti_service;

    /**
     * @var SearchAPIService
     */
    private $search_api_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * PermissionsManagementService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatatreeInfoService $datatree_info_service
     * @param SearchAPIService $search_api_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatreeInfoService $datatree_info_service,
        SearchAPIService $search_api_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dti_service = $datatree_info_service;
        $this->search_api_service = $search_api_service;
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
            $complete_datarecord_list = $this->search_api_service->performSearch(
                $datatype,
                $search_key,
                array(), // empty user permissions array since searching as super admin
                true,    // want to return the complete datarecord list
                array(), // complete datarecord lists can't be sorted
                array(),
                true     // search as super admin, so no filtering takes place
            );

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
        if ( $datatype->isPublic() )
            return true;

        // Otherwise, the datatype is non-public
        // If the user isn't logged in, they can't view the datatype
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

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
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

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
        if ( $datarecord->isPublic() )
            return true;

        // Otherwise, the datarecord is non-public
        // ...if the user isn't logged in, they can't view the datarecord
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

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
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

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
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

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
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

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
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

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


    /**
     * Returns whether the given user can change the public status of the given Datarecord.  This
     * doesn't cover the ability to change public status of files/images/radio options/tags...those
     * are still controlled by the can_edit_datafield permission.
     * If the user has this permission, it's implied they can edit the Datarecord.
     *
     * Users with this permission are able to...
     *  - change public status of this datarecord
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     *
     * @return bool
     */
    public function canChangePublicStatus($user, $datarecord)
    {
        // If the user isn't logged in, they can't change public status
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

        // Otherwise, the user is logged in...ensure they can edit the datarecord first
        if ( !self::canEditDatarecord($user, $datarecord) )
            return false;

        $datatype = $datarecord->getDataType();
        $datatype_permissions = self::getDatatypePermissions($user);

        if ( isset($datatype_permissions[ $datatype->getId() ])
            && isset($datatype_permissions[ $datatype->getId() ]['dr_public'])
        ) {
            // User has the can_change_public_status permission
            return true;
        }
        else {
            // User does not have the can_change_public_status permission
            return false;
        }
    }


    // TODO - implement a permission specifically for linking datarecords?

    // TODO - implement the "can_design_datatype" permission?  it's currently controlled by the "is_datatype_admin" permission...


    /**
     * Returns whether the given user is considered an admin of the given Datatype.  If the user
     * has this permission, then they automatically have permission to view the Datatype.
     *
     * Users with this permission are able to...
     *  - run CSV Imports for this datatype
     *  - modify the "master" theme for a datatype
     *  - change public status of datafields for this datatype
     *  - change public status of the datatype itself
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
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

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
        if ( $datafield->isPublic() )
            return true;

        // If the user isn't logged in, they can't view a non-public Datafield
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

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
     * Returns whether the given user can edit the given Datafield.  The Datarecord parameter is
     * technically optional...but should be passed in if at all possible.  Without it, the return
     * value of of this function CAN NOT take datarecord_restriction into consideration.
     *
     * Users with this permission are able to...
     *  - change the content of this datafield
     *  - upload/delete files/images from this datafield
     *  - change public status of files/images in this datafield
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param DataRecord|null $datarecord
     *
     * @return bool
     */
    public function canEditDatafield($user, $datafield, $datarecord = null)
    {
        // If the user isn't logged in, they can't edit any Datafield
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

        // Ensure the user has the "dr_edit" permission for this Datatype first...
        if ( !self::canEditDatatype($user, $datafield->getDataType()) )
            return false;

        // ...and if a Datarecord was specified...
        if ( !is_null($datarecord) ) {
            // ...ensure they can edit this specific Datarecord as well
            if ( !self::canEditDatarecord($user, $datarecord) )
                return false;
        }


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

    // TODO - should there be a permission to be able to change public status of files/images?  (would technically work for radio options/tags too...)

    // TODO - does it make sense for "can_view_datarecord" to control viewing non-public files/images, or does that need its own permission?

    /**
     * Returns whether the given user can view or download the given File.
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
        if ( $file->isPublic() )
            return true;

        // If the file is non-public, it shouldn't be viewable unless logged in...
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

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
        if ( $image->isPublic() )
            return true;

        // If the image is non-public, it shouldn't be viewable unless logged in...
        if ( $user === "anon." || $user == null )
            return false;
        // If the user is a superadmin, then they automatically have all permissions
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return true;

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
            if ($user_permissions !== false)
                return $user_permissions;


            // ----------------------------------------
            // If this point is reached, the user's permissions arrays need to be rebuilt
            $user_permissions = array('datatypes' => array(), 'datafields' => array());

            if ( $user->hasRole('ROLE_SUPER_ADMIN') ) {
                // Super admins have permissions for all undeleted datatypes and datafields by default
                $query = $this->em->createQuery(
                   'SELECT dt.id AS dt_id, df.id AS df_id
                    FROM ODRAdminBundle:DataType dt
                    LEFT JOIN ODRAdminBundle:DataFields df WITH df.dataType = dt
                    WHERE (df.id IS NULL OR df.deletedAt IS NULL)
                    AND dt.deletedAt IS NULL'
                );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $dt_id = $result['dt_id'];
                    $df_id = $result['df_id'];

                    if ( !isset($user_permissions['datatypes'][$dt_id]) ) {
                        $user_permissions['datatypes'][$dt_id]['dt_view'] = 1;
                        $user_permissions['datatypes'][$dt_id]['dr_view'] = 1;
                        $user_permissions['datatypes'][$dt_id]['dr_add'] = 1;
                        $user_permissions['datatypes'][$dt_id]['dr_delete'] = 1;
                        $user_permissions['datatypes'][$dt_id]['dr_public'] = 1;
//                        $user_permissions['datatypes'][$dt_id]['dt_design'] = 1;
                        $user_permissions['datatypes'][$dt_id]['dt_admin'] = 1;
                        $user_permissions['datatypes'][$dt_id]['dr_edit'] = 1;
                    }

                    // $df_id will be null when a datatype has no datafields
                    if ( !is_null($df_id) ) {
                        $user_permissions['datafields'][$df_id]['view'] = 1;
                        $user_permissions['datafields'][$df_id]['edit'] = 1;
                    }
                }
            }
            else {
                // User is not a super-admin...have to load all groups the user belongs to, and
                //  compute the union of permissions

                // To make things easier on Doctrine's hydrator, load the GroupDatatypePermission
                //  entries separately from the GroupDatafieldPermission entries
                $query = $this->em->createQuery(
                   'SELECT dt.id AS dt_id, gdtp AS dt_permissions
                    FROM ODRAdminBundle:UserGroup ug
                    JOIN ODRAdminBundle:Group g WITH ug.group = g
                    LEFT JOIN ODRAdminBundle:GroupDatatypePermissions gdtp WITH gdtp.group = g
                    LEFT JOIN ODRAdminBundle:DataType dt WITH gdtp.dataType = dt
                    WHERE ug.user = :user_id
                    AND ug.deletedAt IS NULL AND g.deletedAt IS NULL
                    AND gdtp.deletedAt IS NULL AND dt.deletedAt IS NULL'
                )->setParameters( array('user_id' => $user_id) );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $dt_id = $result['dt_id'];
                    $gdtp = $result['dt_permissions'];

                    // Don't store permissions for deleted datatypes
                    if ( is_null($dt_id) )
                        continue;

                    if ( $gdtp['can_view_datatype'] )
                        $user_permissions['datatypes'][$dt_id]['dt_view'] = 1;
                    if ( $gdtp['can_view_datarecord'] )
                        $user_permissions['datatypes'][$dt_id]['dr_view'] = 1;
                    if ( $gdtp['can_add_datarecord'] )
                        $user_permissions['datatypes'][$dt_id]['dr_add'] = 1;
                    if ( $gdtp['can_delete_datarecord'] )
                        $user_permissions['datatypes'][$dt_id]['dr_delete'] = 1;
                    if ( $gdtp['can_change_public_status'] )
                        $user_permissions['datatypes'][$dt_id]['dr_public'] = 1;
//                if ( $gdtp['can_design_datatype'] )
//                    $user_permissions['datatypes'][$dt_id]['dt_design'] = 1;
                    if ( $gdtp['is_datatype_admin'] )
                        $user_permissions['datatypes'][$dt_id]['dt_admin'] = 1;

                    // Additionally, if the user has these permissions...
                    if ( $gdtp['is_datatype_admin'] || $gdtp['can_change_public_status'] ) {
                        // ...then they're always able to view the record's edit page, even if no datafields exist
                        $user_permissions['datatypes'][$dt_id]['dr_edit'] = 1;
                    }
                }


                // Ensure that datarecord_restrictions get stored
                $query = $this->em->createQuery(
                   'SELECT dt.id AS dt_id, gm.datarecord_restriction
                    FROM ODRAdminBundle:UserGroup ug
                    JOIN ODRAdminBundle:Group g WITH ug.group = g
                    LEFT JOIN ODRAdminBundle:GroupMeta gm WITH gm.group = g
                    LEFT JOIN ODRAdminBundle:DataType dt WITH g.dataType = dt
                    WHERE ug.user = :user_id AND gm.datarecord_restriction IS NOT NULL
                    AND ug.deletedAt IS NULL AND g.deletedAt IS NULL AND gm.deletedAt IS NULL
                    AND dt.deletedAt IS NULL'
                )->setParameters( array('user_id' => $user_id) );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $dt_id = $result['dt_id'];
                    $restriction = $result['datarecord_restriction'];

                    // Don't store permissions for deleted datatypes
                    if ( is_null($dt_id) )
                        continue;

                    $user_permissions['datatypes'][$dt_id]['datarecord_restriction'] = $restriction;
                }


                // To make things easier on Doctrine's hydrator, load the GroupDatafieldPermission
                //  entries separately from the GroupDatatypePermission entries
                $query = $this->em->createQuery(
                   'SELECT dt.id AS dt_id, df.id AS df_id, gdfp AS df_permissions
                    FROM ODRAdminBundle:UserGroup ug
                    JOIN ODRAdminBundle:Group g WITH ug.group = g
                    LEFT JOIN ODRAdminBundle:GroupDatafieldPermissions gdfp WITH gdfp.group = g
                    LEFT JOIN ODRAdminBundle:DataFields df WITH gdfp.dataField = df
                    LEFT JOIN ODRAdminBundle:DataType dt WITH df.dataType = dt
                    WHERE ug.user = :user_id
                    AND ug.deletedAt IS NULL AND g.deletedAt IS NULL
                    AND gdfp.deletedAt IS NULL AND df.deletedAt IS NULL AND dt.deletedAt IS NULL'
                )->setParameters( array('user_id' => $user_id) );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $df_id = $result['df_id'];
                    $dt_id = $result['dt_id'];
                    $gdfp = $result['df_permissions'];

                    // Don't store permissions for deleted datafields/datatypes
                    if ( is_null($df_id) || is_null($dt_id) )
                        continue;

                    if ( $gdfp['can_view_datafield'] )
                        $user_permissions['datafields'][$df_id]['view'] = 1;

                    if ( $gdfp['can_edit_datafield'] ) {
                        $user_permissions['datafields'][$df_id]['edit'] = 1;

                        // If the user is able to edit a datafield, ensure they can view the record's
                        //  edit page
                        $user_permissions['datatypes'][$dt_id]['dr_edit'] = 1;
                    }
                }

                // If child datatypes have the "dr_edit" permission, ensure their parents do as well
                $datatree_array = $this->dti_service->getDatatreeArray();

                foreach ($user_permissions['datatypes'] as $dt_id => $gdtp) {
                    // For each child datatype the user has permissions for...
                    if ( isset($datatree_array['descendant_of'][$dt_id])
                        && $datatree_array['descendant_of'][$dt_id] !== ''
                    ) {
                        // ...if the user can edit the child datatype...
                        if ( isset($gdtp['dr_edit']) ) {
                            // ...then ensure the user can also view the edit page for each of the
                            //  child's ancestor datatypes
                            $parent_dt_id = $dt_id;
                            while ( isset($datatree_array['descendant_of'][$parent_dt_id])
                                && $datatree_array['descendant_of'][$parent_dt_id] !== ''
                            ) {
                                $parent_dt_id = $datatree_array['descendant_of'][$parent_dt_id];
                                $user_permissions['datatypes'][$parent_dt_id]['dr_edit'] = 1;
                            }
                        }
                    }
                }
            }


            // ----------------------------------------
            // Store the final permissions array back in the cache
            $this->cache_service->set('user_'.$user_id.'_permissions', $user_permissions);

            // Return the permissions for all groups this user belongs to
            return $user_permissions;
        }
        catch (\Exception $e) {
            throw new ODRException( $e->getMessage() );
        }
    }


    /**
     * Returns an array with the relevant datatype/datafield permissions for a single group.
     *
     * Caching the results of this really isn't useful.  It's generally going to be faster for
     * getUserPermissionsArray() to use a constant 3 queries total, instead of potentially running
     * one query for every group a user is a member of.
     *
     * Other than the user permissions arrays, the only part of ODR that directly cares about the
     * datatype/datafield permissions is the interface that gets used to set group permissions.
     *
     * @param integer $group_id
     *
     * @return array
     */
    public function getGroupPermissionsArray($group_id)
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
                // Ensure this permission doesn't belong to a deleted datatype
                if ( is_null($permission['dataType']) )
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
                if ($permission['can_change_public_status'])
                    $datatype_permissions[$dt_id]['dr_public'] = 1;
//                if ($permission['can_design_datatype'])
//                    $datatype_permissions[$dt_id]['dt_design'] = 1;
                if ($permission['is_datatype_admin'])
                    $datatype_permissions[$dt_id]['dt_admin'] = 1;
            }

            // Build the permissions list for datafields
            foreach ($group['groupDatafieldPermissions'] as $num => $permission) {
                // Ensure this permission doesn't belong to a datafield from a deleted datatype
                if ( is_null($permission['dataField']) || is_null($permission['dataField']['dataType']) )
                    continue;

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
     * The arrays passed to this function must not be stacked.
     *
     * @param array &$datatype_array {@link DatabaseInfoService::getDatatypeArray()}
     * @param array &$datarecord_array {@link DatarecordInfoService::getDatarecordArray()}
     * @param array $permissions_array {@link self::getUserPermissionsArray()}
     */
    public function filterByGroupPermissions(&$datatype_array, &$datarecord_array, $permissions_array)
    {
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
            if ( isset($datatype_permissions[$dt_id]['dt_view']) )
                $can_view_datatype[$dt_id] = true;
            else
                $can_view_datatype[$dt_id] = false;

            // Store whether the user can view non-public datarecords of each datatype
            if ( isset($datatype_permissions[$dt_id]['dr_view']) )
                $can_view_datarecord[$dt_id] = true;
            else
                $can_view_datarecord[$dt_id] = false;
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

                // ...also pre-emptively remove all datarecords of that datatype
                foreach ($datarecord_array as $dr_id => $dr) {
                    if ($dt_id == $dr['dataType']['id'])
                        unset( $datarecord_array[$dr_id] );
                }

                // No sense checking anything else for this datatype, skip to the next one
                continue;
            }

            // Otherwise, the user is allowed to see this datatype
            // Need to filter out datafields the user isn't allowed to view...
            foreach ($dt['dataFields'] as $df_id => $df) {
                // Determine whether the user can view the datafield or not
                $can_view_datafield = false;
                if ( isset($datafield_permissions[$df_id]['view']) )
                    $can_view_datafield = true;

                // If the user doesn't have the 'can_view_datafield' permission for that datafield...
                if ( $df['dataFieldMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00'
                    && !$can_view_datafield
                ) {
                    // ...then remove it from the layout
                    unset( $datatype_array[$dt_id]['dataFields'][$df_id] );
                    $datafields_to_remove[$df_id] = 1;
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

                // No sense checking anything else for this datarecord, skip to the next one
                continue;
            }

            // Otherwise, the user is allowed to view this datarecord
            // Iterate through this datarecords datafield entries...
            foreach ($dr['dataRecordFields'] as $df_id => $drf) {

                // ...and remove the datafield if the user doesn't have the ability to view it
                if ( isset($datafields_to_remove[$df_id]) ) {
                    unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id] );

                    // No sense checking file/image public status, skip to the next datafield
                    continue;
                }

                // ...also need to remove files the user isn't allowed to see
                // TODO - does it make sense for "can_view_datarecord" to control viewing non-public files, or does this need its own permission?
                foreach ($drf['file'] as $file_num => $file) {
                    if ( $file['fileMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00'
                        && !$can_view_datarecord[$dt_id]
                    ) {
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id]['file'][$file_num] );
                    }
                }

                // ...also need to remove images the user isn't allowed to see
                // TODO - does it make sense for "can_view_datarecord" to control viewing non-public images, or does this need its own permission?
                foreach ($drf['image'] as $image_num => $image) {
                    if (
                        isset($image['parent']['imageMeta']['publicDate'])
                        && $image['parent']['imageMeta']['publicDate'] != null
                        && $image['parent']['imageMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00'
                        && !$can_view_datarecord[$dt_id]
                    ) {
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id]['image'][$image_num] );
                    }
                }
            }
        }
    }
}
