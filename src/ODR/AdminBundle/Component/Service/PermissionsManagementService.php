<?php

namespace ODR\AdminBundle\Component\Service;

// Controllers/Classes
use ODR\OpenRepository\SearchBundle\Controller\DefaultController as SearchController;
use ODR\AdminBundle\Controller\ODRCustomController as ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\Boolean AS ODRBoolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileChecksum;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupDatafieldPermissions;
use ODR\AdminBundle\Entity\GroupDatatypePermissions;
use ODR\AdminBundle\Entity\GroupMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageChecksum;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginFields;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\TrackedError;
use ODR\OpenRepository\UserBundle\Entity\User;
use ODR\AdminBundle\Entity\UserGroup;
// Forms
// Symfony

use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Doctrine\ORM\EntityManager;


/**
 * Created by PhpStorm.
 * User: nate
 * Date: 10/14/16
 * Time: 11:59 AM
 */
class PermissionsManagementService
{


    /**
     * @var mixed
     */
    private $logger;

    private $user;
    private $created_datatypes;

    /**
     * @var mixed
     */
    private $container;

    public function __construct(Container $container, EntityManager $entity_manager, $logger) {
        $this->container = $container;
        $this->em = $entity_manager;
        $this->logger = $logger;
    }



    /**
     * Ensures the given user is in the given group.
     *
     * @param User $user
     * @param Group $group
     * @param User $admin_user
     *
     * @return UserGroup
     */
    public function createUserGroup($user, $group, $admin_user)
    {
        // Check to see if the user already belongs to this group
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
            // If an existing user_group entry was found, return it and don't do anything else
            foreach ($results as $num => $ug)
                return $ug;
        }
        else {
            // ...otherwise, create a new user_group entry
            $user_group = new UserGroup();
            $user_group->setUser($user);
            $user_group->setGroup($group);

            $user_group->setCreatedBy($admin_user);

            $this->em->persist($user_group);
            $this->em->flush();
        }

        return $user_group;
    }


    /**
     * Create a new Group for users of the given datatype.
     *
     * @param User $user
     * @param DataType $datatype               An id for a top-level datatype
     * @param string $initial_purpose          One of 'admin', 'edit_all', 'view_all', 'view_only', or ''
     *
     * @return array
     */
    public function createGroup($user, $datatype, $initial_purpose = '')
    {
        $datatype_info_service = $this->container->get('odr.datatype_info_service');
        // ----------------------------------------
        // Create the Group entity
        $group = new Group();
        $group->setDataType($datatype);
        $group->setPurpose($initial_purpose);

        $group->setCreatedBy($user);

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

        $this->em->persist($group_meta);
        $this->em->flush();
        $this->em->refresh($group_meta);


        // ----------------------------------------
        // Need to keep track of which datatypes are top-level
        $top_level_datatypes = $datatype_info_service->getTopLevelDatatypes();

        // Create the initial datatype permission entries
        $include_links = false;
        $associated_datatypes = $datatype_info_service->getAssociatedDatatypes(array($datatype->getId()), $include_links);   // TODO - if datatypes are eventually going to be undeleteable, then this needs to also return deleted child datatypes
//print_r($associated_datatypes);

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
            else if ( $initial_purpose == 'view_only' || in_array($dt_id, $top_level_datatypes) )
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
            // Permissons are stored in memcached to allow other parts of the server to force a rebuild of any user's permissions
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            // Grab all users
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var User[] $user_list */
            $user_list = $user_manager->findUsers();

            // Locate those with super-admin permissions...
            foreach ($user_list as $u) {
                if ( $u->hasRole('ROLE_SUPER_ADMIN') ) {
                    // ...add the super admin to this new admin group
                    self::createUserGroup($u, $group, $user);

                    // ...delete the cached list of permissions for each super-admin belongs to
                    $redis->del($redis_prefix.'.user_'.$u->getId().'_permissions');
                }
            }
        }

        return array('group' => $group, 'group_meta' => $group_meta);
    }

    /**
     * Creates GroupDatatypePermissions for all groups when a new datatype is created.
     *
     * @param User $user
     * @param DataType $datatype
     */
    public function createGroupsForDatatype($user, $datatype)
    {
        $datatype_info_service = $this->container->get('odr.datatype_info_service');

        $datatype_id = $datatype->getId();

        // Determine if is top level
        $is_top_level = false;
        $datatree_array = $datatype_info_service->getDatatreeArray(true);
        $grandparent_datatype_id = $datatype_info_service->getGrandparentDatatypeId($datatree_array, $datatype_id);
        if($grandparent_datatype_id == $datatype_id) {
            $is_top_level = true;
        }

        // Locate all groups for this datatype's grandparent
        $repo_group = $this->em->getRepository('ODRAdminBundle:Group');
        $groups = false;

        /** @var Group[] $groups */
        if ($is_top_level) {
            $groups = $repo_group->findBy( array('dataType' => $datatype->getId()) );
            if ($groups == false) {
                // Determine whether this datatype has the default groups already...
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


                // Now that the default groups exist, reload them
                $groups = $repo_group->findBy( array('dataType' => $datatype_id) );
            }
        }
        else {
            // Load all groups belonging to the grandparent datatype
            $groups = $repo_group->findBy( array('dataType' => $grandparent_datatype_id) );

            // If this function is called to create groups for a top-level datatype,
            // the following section wouldn't be needed since it's already covered by
            // in self::createGroup()...

            // ----------------------------------------
            // Going to need these later...
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');


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
                    $group_permissions = $datatype_info_service->getRedisData(($redis->get($redis_prefix.'.group_'.$group->getId().'_permissions')));
                    if ($group_permissions != false) {
                        $group_permissions['datatypes'][$datatype_id] = $cache_update;
                        $redis->set($redis_prefix.'.group_'.$group->getId().'_permissions', gzcompress(serialize($group_permissions)));
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
                $redis->del($redis_prefix.'.user_'.$user_id.'_permissions');
        }
    }


    /**
     * Creates GroupDatafieldPermissions for all groups when a new datafield is created, and updates existing cache entries for groups and users with the new datafield.
     *
     * @param User $user
     * @param DataFields $datafield
     */
    public function createGroupsForDatafield($user, $datafield)
    {
        $datatype_info_service = $this->container->get('odr.datatype_info_service');

        // ----------------------------------------
        // Going to need these later...
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');


        // ----------------------------------------
        // Locate this datafield's datatype's grandparent
        $datatree_array = $datatype_info_service->getDatatreeArray();
        $datatype_id = $datafield->getDataType()->getId();
        $grandparent_datatype_id = $datatype_info_service->getGrandparentDatatypeId($datatree_array, $datatype_id);

        // Locate all groups for this datatype's grandparent
        /** @var Group[] $groups */
        $groups = $this->em->getRepository('ODRAdminBundle:Group')->findBy( array('dataType' => $grandparent_datatype_id) );
        // Unless something has gone wrong previously, there should always be results in this


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
                $group_permissions = $datatype_info_service->getRedisData(($redis->get($redis_prefix.'.group_'.$group->getId().'_permissions')));
                if ($group_permissions != false) {
                    if ( !isset($group_permissions['datafields'][$datatype_id]) )
                        $group_permissions['datafields'][$datatype_id] = array();

                    $group_permissions['datafields'][$datatype_id][$datafield->getId()] = $cache_update;
                    $redis->set($redis_prefix.'.group_'.$group->getId().'_permissions', gzcompress(serialize($group_permissions)));
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
            $redis->del($redis_prefix.'.user_'.$user_id.'_permissions');
    }



    /**
     * Given a group's permission arrays, filter the provided datarecord/datatype arrays so twig doesn't render anything they're not supposed to see.
     *
     * @param array &$datatype_array    @see self::getDatatypeArray()
     * @param array &$datarecord_array  @see self::getDatarecordArray()
     * @param array $permissions_array  @see self::getUserPermissionsArray()
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
            if ( isset($datatype_permissions[ $dt_id ]) && isset($datatype_permissions[ $dt_id ][ 'dt_view' ]) )
                $can_view_datatype[$dt_id] = true;
            else
                $can_view_datatype[$dt_id] = false;

            if ( isset($datatype_permissions[ $dt_id ]) && isset($datatype_permissions[ $dt_id ][ 'dr_view' ]) )
                $can_view_datarecord[$dt_id] = true;
            else
                $can_view_datarecord[$dt_id] = false;
        }


        // For each datatype in the provided array...
        foreach ($datatype_array as $dt_id => $dt) {

            // If there was no datatype permission entry for this datatype, have it default to false
            if ( !isset($can_view_datatype[$dt_id]) )
                $can_view_datatype[$dt_id] = false;

            // If datatype is non-public and user does not have the 'can_view_datatype' permission, then remove the datatype from the array
            if ( $dt['dataTypeMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datatype[$dt_id] ) {
                unset( $datatype_array[$dt_id] );

                // Also remove all datarecords of that datatype
                foreach ($datarecord_array as $dr_id => $dr) {
                    if ($dt_id == $dr['dataType']['id'])
                        unset( $datarecord_array[$dr_id] );
                }

                // No sense checking anything else for this datatype, skip to the next one
                continue;
            }

            // Otherwise, the user is allowed to see this datatype...
            foreach ($dt['themes'] as $theme_id => $theme) {
                foreach ($theme['themeElements'] as $te_num => $te) {

                    // For each datafield in this theme element...
                    if ( isset($te['themeDataFields']) ) {
                        foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                            $df_id = $tdf['dataField']['id'];

                            // If the user doesn't have the 'can_view_datafield' permission for that datafield...
                            if ( $tdf['dataField']['dataFieldMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00'
                                && !( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['view']) )
                            ) {
                                // ...remove it from the layout
                                unset( $datatype_array[$dt_id]['themes'][$theme_id]['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField'] );  // leave the theme_datafield entry on purpose
                                $datafields_to_remove[$df_id] = 1;
                            }
                        }
                    }
                }
            }
        }
    }
}