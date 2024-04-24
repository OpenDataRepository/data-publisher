<?php

/**
 * Open Data Repository Data Publisher
 * Search Sidebar Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains functions to help manage the search sidebar.
 */

namespace ODR\OpenRepository\SearchBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\SidebarLayout;
use ODR\AdminBundle\Entity\SidebarLayoutPreferences;
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Other
use Doctrine\ORM\EntityManager;
use FOS\UserBundle\Doctrine\UserManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\HttpFoundation\Session\Session;


class SearchSidebarService
{

    /**
     * This value is stored as a bitfield in the database, because the user could potentially want
     * a layout to be the "default" for multiple contexts.
     *
     * @var string[]
     */
    const PAGE_TYPES = array(
        1 => 'searching',
        2 => 'linking',
    );

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var DatabaseInfoService
     */
    private $database_info_service;

    /**
     * @var DatatreeInfoService
     */
    private $datatree_info_service;

    /**
     * @var PermissionsManagementService
     */
    private $permissions_service;

    /**
     * @var UserManager
     */
    private $user_manager;

    /**
     * @var Session
     */
    private $session;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * SearchSidebarService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatabaseInfoService $database_info_service
     * @param DatatreeInfoService $datatree_info_service
     * @param PermissionsManagementService $permissions_service
     * @param UserManager $user_manager
     * @param Session $session
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatabaseInfoService $database_info_service,
        DatatreeInfoService $datatree_info_service,
        PermissionsManagementService $permissions_service,
        UserManager $user_manager,
        Session $session,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->database_info_service = $database_info_service;
        $this->datatree_info_service = $datatree_info_service;
        $this->permissions_service = $permissions_service;
        $this->user_manager = $user_manager;
        $this->session = $session;
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
        $datatype_array = $this->database_info_service->getDatatypeArray($target_datatype_id, true);

        // ...then filter the array to just what the user can see
        $datarecord_array = array();
        $user_permissions = $this->permissions_service->getUserPermissionsArray($user);
        $this->permissions_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

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
        $datatree_array = $this->datatree_info_service->getDatatreeArray();
        $datatype_list = array(
            'child_datatypes' => array(),
            'linked_datatypes' => array(),
        );
        foreach ($datatype_array as $dt_id => $datatype_data) {
            // Don't want the top-level datatype in this array
            if ($dt_id === $target_datatype_id)
                continue;

            // Locate this particular datatype's grandparent id...
            $gp_dt_id = $this->datatree_info_service->getGrandparentDatatypeId($dt_id, $datatree_array);

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
        $datatype_permissions = $this->permissions_service->getDatatypePermissions($user);

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


    /**
     * Twig can technically figure out which radio options/tags are selected or unselected, but
     * it's irritating to do so...it's easier to use php to do this.
     *
     * @param array $datatype_array
     * @param array $search_params
     */
    public function fixSearchParamsOptionsAndTags($datatype_array, &$search_params)
    {
        foreach ($datatype_array as $dt_id => $dt) {
            foreach ($dt['dataFields'] as $df_id => $df) {
                // Only interested in radio/tag datafields...
                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                if ( $typeclass == 'Radio' || $typeclass == 'Tag' ) {
                    if ( isset($search_params[$df_id]) ) {
                        // ...and only when the search criteria involves them
                        $ids = explode(',', $search_params[$df_id]);

                        // The search key uses a '-' prefix before the option/tag id to indicate
                        //  the user only wants results where those are unselected
                        $selected_ids = array();
                        $unselected_ids = array();
                        foreach ($ids as $id) {
                            if ( strpos($id, '-') !== false )
                                $unselected_ids[] = substr($id, 1);
                            else
                                $selected_ids[] = $id;
                        }

                        // It's easier for twig if the selected options/tags are kept separate from
                        //  the unselected options/tags
                        $search_params[$df_id] = array(
                            'selected' => $selected_ids,
                            'unselected' => $unselected_ids
                        );
                    }
                }
            }
        }
    }


    /**
     * This function is currently only used to guard against a user trying to use a deleted
     * sidebar layout in their session...
     *
     * @return int[]
     */
    public function getSidebarLayoutIds()
    {
        // If list of layout ids exists in redis, return that
        $sidebar_layout_ids = $this->cache_service->get('sidebar_layout_ids');
        if ( $sidebar_layout_ids !== false && count($sidebar_layout_ids) > 0 )
            return $sidebar_layout_ids;


        // ----------------------------------------
        // Otherwise, rebuild the list of sidebar layouts
        $query = $this->em->createQuery(
           'SELECT sl.id AS layout_id
            FROM ODRAdminBundle:SidebarLayout AS sl
            WHERE sl.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $sidebar_layout_ids = array();
        foreach ($results as $result)
            $sidebar_layout_ids[] = $result['layout_id'];


        // ----------------------------------------
        // Store the list in the cache and return
        $this->cache_service->set('sidebar_layout_ids', $sidebar_layout_ids);
        return $sidebar_layout_ids;
    }


    /**
     * Returns basic information on all sidebar layouts for this datatype that the user is allowed to see.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     *
     * @return array
     */
    public function getAvailableSidebarLayouts($user, $datatype)
    {
        // Not guaranteed to have a logged-in user...
        $user_id = 0;
        if ($user !== 'anon.')
            $user_id = $user->getId();

        // Get all sidebar layouts for this datatype
        $query = $this->em->createQuery(
           'SELECT sl, slm, slp,
                partial sl_cb.{id, username, email, firstName, lastName}
            FROM ODRAdminBundle:SidebarLayout AS sl
            JOIN sl.createdBy AS sl_cb
            JOIN sl.layoutMeta AS slm
            LEFT JOIN sl.sidebarLayoutPreferences AS slp WITH (slp.createdBy = :user_id)
            WHERE sl.dataType = :datatype_id
            sl.deletedAt IS NULL AND slm.deletedAt IS NULL AND slp.deletedAt IS NULL
            ORDER BY slm.displayOrder, slm.layoutName'
        )->setParameters(
            array(
                'datatype_id' => $datatype->getId(),
                'user_id' => $user_id,    // Only get layout preference entries belonging to the user calling the function
            )
        );
        $results = $query->getArrayResult();

        // Filter the list of sidebar layouts based on what the user is allowed to see
        $is_datatype_admin = $this->permissions_service->isDatatypeAdmin($user, $datatype);
        $filtered_layouts = array();
        foreach ($results as $layout) {
            // Easier to extract some of these properties from the array...
            $layout_meta = $layout['sidebarLayoutMeta'][0];
            $layout_preferences = null;
            if ( isset($layout['sidebarLayoutPreferences'][0]) )
                $layout_preferences = $layout['sidebarLayoutPreferences'][0];

            $created_by = $layout['createdBy'];
            $user_string = $created_by['email'];
            if ( isset($created_by['firstName']) && isset($created_by['lastName']) && $created_by['firstName'] !== '' )
                $user_string = $created_by['firstName'].' '.$created_by['lastName'];


            // Only allow user to use the layout if it's shared, or they created it, or they're an
            //  admin of this datatype
            if ( $layout_meta['shared']
                || $user_id == $created_by['id']
                || $is_datatype_admin
            ) {
                // layouts can be defaults for multiple page_types...
                $default_for_labels = array();
                $default_for = $layout_meta['defaultFor'];
                foreach (self::PAGE_TYPES as $bit => $label) {
                    if ( $default_for & $bit )
                        $default_for_labels[] = ucfirst( str_replace('_', ' ', $label) );
                }

                // Users' preferred layouts are independent of whether the layout is default for a
                //  given page_type...
                $user_preference_labels = array();
                if ( !is_null($layout_preferences) ) {
                    $user_default_for = $layout_preferences['defaultFor'];
                    foreach (self::PAGE_TYPES as $bit => $label) {
                        if ( $user_default_for & $bit )
                            $user_preference_labels[] = ucfirst( str_replace('_', ' ', $label) );
                    }
                }

                // TODO - don't really want another dialog just for selecting a different sidebar layout...
                $layout_record = array(
                    'id' => $layout['id'],
                    'name' => $layout_meta['templateName'],
                    'description' => $layout_meta['templateDescription'],
                    'is_shared' => $layout_meta['shared'],
                    'display_order' => $layout_meta['displayOrder'],
                    'layout_type' => $layout_type,
                    'is_table_layout' => $layout_meta['isTablelayout'],
                    'created_by' => $created_by['id'],
                    'created_by_name' => $user_string,

                    'default_for' => $default_for_labels,
                    'user_preference_for' => $user_preference_labels,
                );

                $filtered_layouts[] = $layout_record;
            }
        }

        return $filtered_layouts;
    }


    /**
     * Attempts to return the id of the user's preferred sidebar layout for the given datatype.
     *
     * @param string|ODRUser $user  Either 'anon.' or an ODRUser object
     * @param integer $datatype_id
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     *
     * @return int|null null when the user doesn't have a sidebar layout preference
     */
    public function getPreferredSidebarLayoutId($user, $datatype_id, $page_type)
    {
        // Look in the user's session first...
        $layout_id = self::getSessionSidebarLayoutId($datatype_id, $page_type);
        if ( !is_null($layout_id) ) {
            // Going to need a list of all layouts...
            $top_level_layouts = self::getSidebarLayoutIds();
            if ( in_array($layout_id, $top_level_layouts) ) {
                // If the layout isn't deleted, return its id
                return $layout_id;
            }
            else {
                // Otherwise, user shouldn't be using it
                self::resetSessionSidebarLayoutId($datatype_id, $page_type);

                // Continue looking for the next-best layout to use
            }
        }

        // If nothing was found in the user's session, then see if the user has a preference already
        //  stored in the database for this page_type...
        if ($user !== 'anon.') {
            $layout_preference = self::getUserSidebarLayoutPreference($user, $datatype_id, $page_type);
            if ( !is_null($layout_preference) ) {
                // ...set it as their current session layout to avoid database lookups
                $layout = $layout_preference->getSidebarLayout();
                self::setSessionSidebarLayoutId($datatype_id, $page_type, $layout->getId());

                // ...return the id of the layout
                return $layout->getId();
            }
        }

        // If the user doesn't have a preference in the database, then see if the datatype has a
        //  default layout for this page_type...
        $layout = self::getDefaultSidebarLayout($datatype_id, $page_type);
        if ( !is_null($layout) ) {
            // ...set it as their current session layout to avoid database lookups
            self::setSessionSidebarLayoutId($datatype_id, $page_type, $layout->getId());

            // ...return the id of the layout
            return $layout->getId();
        }

        // If the user doesn't have a preference and the datatype doesn't have a custom layout,
        //  then return null to use the master theme as a default
        return null;
    }


    /**
     * Return a datatype's default sidebar layout for this page_type, as set by a datatype admin.
     *
     * @param integer $datatype_id
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     *
     * @return SidebarLayout|null null when the datatype doesn't have a custom sidebar layout
     */
    public function getDefaultSidebarLayout($datatype_id, $page_type)
    {
        // Ensure the provided page_type is valid
        $page_type_id = array_search($page_type, self::PAGE_TYPES);
        if ( $page_type_id === false )
            throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0x259eb569);


        // ----------------------------------------
        // Query the database for the default top-level theme for this datatype
        // NOTE: using native SQL, because Doctrine apparently hates the '&' operator
        $query =
           'SELECT sl.id
            FROM odr_sidebar_layout AS sl
            JOIN odr_sidebar_layout_meta AS slm ON slm.sidebar_layout_id = sl.id
            WHERE sl.data_type_id = :datatype_id AND (slm.default_for & :page_type_id)
            AND sl.deletedAt IS NULL AND slm.deletedAt IS NULL';
        $params = array(
            'datatype_id' => $datatype_id,
            'page_type_id' => $page_type_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        // Should only be one...
        foreach ($results as $result) {
            $sl_id = $result['id'];

            /** @var SidebarLayout $sl */
            $sl = $this->em->getRepository('ODRAdminBundle:SidebarLayout')->find($sl_id);
            return $sl;
        }

        // If the query matched nothing, then return null instead
        return null;
    }


    /**
     * Returns the id of the sidebar layout the user has selected for this current datatype/page_type
     * combo for their current session.
     *
     * @param integer $datatype_id
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     *
     * @return int|null null when the user doesn't have a sidebar layout preference set
     */
    public function getSessionSidebarLayoutId($datatype_id, $page_type)
    {
        // Ensure the provided page_type is valid
        if ( !in_array($page_type, self::PAGE_TYPES) )
            throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0xd802ad96);


        // If the user has changed any layout for their current session...
        if ( $this->session->has('session_sidebar_layouts') ) {
            $session_layouts = $this->session->get('session_sidebar_layouts');

            // ...see if they have a layout for this datatype/page_type combo
            if ( isset($session_layouts[$datatype_id][$page_type]) )
                return $session_layouts[$datatype_id][$page_type];
        }

        // Otherwise, no session layout...return null
        return null;
    }


    /**
     * Stores a specific layout id as the user's preferred sidebar layout for this datatype/page_type
     * combo for this session.
     *
     * @param integer $datatype_id
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     * @param integer $layout_id
     */
    public function setSessionSidebarLayoutId($datatype_id, $page_type, $layout_id)
    {
        // Ensure the provided page_type is valid
        if ( !in_array($page_type, self::PAGE_TYPES) )
            throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0x54638516);


        // Load any existing session layouts
        $session_layouts = array();
        if ( $this->session->has('session_sidebar_layouts') )
            $session_layouts = $this->session->get('session_sidebar_layouts');

        if ( !isset($session_layouts[$datatype_id]) )
            $session_layouts[$datatype_id] = array();

        // Save the layout choice in the session
        $session_layouts[$datatype_id][$page_type] = $layout_id;
        $this->session->set('session_sidebar_layouts', $session_layouts);
    }


    /**
     * Clears the user's preferred sidebar layouts for this datatype for this session.  If a page_type
     * is specified, then only the preference for that page_type is cleared.
     *
     * @param integer $datatype_id
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     */
    public function resetSessionSidebarLayoutId($datatype_id, $page_type = '')
    {
        if ( $page_type !== '' ) {
            // Ensure the provided page_type is valid
            if ( !in_array($page_type, self::PAGE_TYPES) )
                throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0x8f6f393f);
        }

        // Load any existing session layouts
        $session_layouts = array();
        if ( $this->session->has('session_sidebar_layouts') )
            $session_layouts = $this->session->get('session_sidebar_layouts');

        // If the page type was not set, then just unset anything stored for this datatype
        if ( $page_type === '' )
            unset( $session_layouts[$datatype_id] );
        else
            unset( $session_layouts[$datatype_id][$page_type] );

        // Save back to session
        $this->session->set("session_sidebar_layouts", $session_layouts);
    }


    /**
     * Returns a sidebarLayoutPreferences object containing the user's preferred sidebar layout for
     * the given datatype, if there is one.
     *
     * @param ODRUser $user
     * @param integer $datatype_id
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     *
     * @return SidebarLayoutPreferences|null
     */
    public function getUserSidebarLayoutPreference($user, $datatype_id, $page_type)
    {
        // Anonymous users don't have sidebar layout preferences
        if ($user === 'anon.')
            return null;

        // Ensure the provided page_type is valid
        $page_type_id = array_search($page_type, self::PAGE_TYPES);
        if ( $page_type_id === false )
            throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0xd8089f6b);


        // ----------------------------------------
        // Determine whether the user already has a preferred layout for this page_type
        // NOTE: using native SQL, because Doctrine apparently hates the '&' operator
        $query =
           'SELECT slp.id
            FROM odr_sidebar_layout_preferences AS slp
            JOIN odr_sidebar_layout AS t ON slp.sidebar_layout_id = sl.id
            JOIN odr_data_type AS dt ON sl.data_type_id = dt.id
            WHERE dt.id = :datatype_id
            AND slp.createdBy = :user_id AND (slp.default_for & :page_type_id)
            AND slp.deletedAt IS NULL AND sl.deletedAt IS NULL AND dt.deletedAt IS NULL';
        $params = array(
            'datatype_id' => $datatype_id,
            'user_id' => $user->getId(),
            'page_type_id' => $page_type_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        // Should only be one...
        foreach ($results as $result) {
            $slp_id = $result['id'];

            /** @var SidebarLayoutPreferences $slp */
            $slp = $this->em->getRepository('ODRAdminBundle:LayoutPreferences')->find($slp_id);
            return $slp;
        }

        // If the query matched nothing, then return null instead
        return null;
    }


    /**
     * Sets the given sidebar layout as a default for the provided user.  Any other layout in the same
     * "category" is marked as "not default".
     *
     * @param ODRUser $user
     * @param SidebarLayout $layout
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     *
     * @return SidebarLayoutPreferences
     */
    public function setUserSidebarLayoutPreference($user, $layout, $page_type)
    {
        // Ensure the provided page_type is valid
        $page_type_id = array_search($page_type, self::PAGE_TYPES);
        if ( $page_type_id === false )
            throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0x3848883c);

        // Determine whether the user already has a preferred layout for this page_type...
        // NOTE: using native SQL, because Doctrine apparently hates the '&' operator
        $query =
           'SELECT slp.id
            FROM odr_sidebar_layout_preferences AS slp
            JOIN odr_sidebar_layout AS sl ON slp.sidebar_layout_id = sl.id
            JOIN odr_data_type AS dt ON sl.data_type_id = dt.id
            WHERE dt.id = :datatype_id
            AND slp.createdBy = :user_id AND (slp.default_for & :page_type_id)
            AND slp.deletedAt IS NULL AND sl.deletedAt IS NULL AND dt.deletedAt IS NULL';
        $params = array(
            'datatype_id' => $layout->getDataType()->getId(),
            'user_id' => $user->getId(),
            'page_type_id' => $page_type_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        foreach ($results as $result) {
            // ...if any exist, then mark them as "not default" since there's going to be a new default
            $slp_id = $result['id'];

            /** @var SidebarLayoutPreferences $slp */
            $slp = $this->em->getRepository('ODRAdminBundle:LayoutPreferences')->find($slp_id);

            // Unset the bit for this page_type and save it back into the field
            $bitfield_value = $slp->getDefaultFor();
            $bitfield_value -= $page_type_id;
            $slp->setDefaultFor($bitfield_value);

            $this->em->persist($slp);
        }


        // ----------------------------------------
        // Attempt to locate the sidebarLayoutPreferences entry for the given layout/User pair
        $slp = $this->em->getRepository('ODRAdminBundle:SidebarLayoutPreferences')->findOneBy(
            array(
                'sidebarLayout' => $layout->getId(),
                'createdBy' => $user->getId(),
            )
        );

        // If one doesn't exist, create it
        if ($slp == null) {
            $slp = new SidebarLayoutPreferences();
            $slp->setCreatedBy($user);
            $slp->setSidebarLayout($layout);
            $slp->setDefaultFor(0);
        }

        // Mark this sidebarLayoutPreference entry as the one the user wants to use
        $bitfield_value = $slp->getDefaultFor();
        $bitfield_value += $page_type_id;
        $slp->setDefaultFor($bitfield_value);
        $slp->setUpdatedBy($user);

        // Done with the modifications
        $this->em->persist($slp);
        $this->em->flush();
        $this->em->refresh($slp);

        return $slp;
    }
}
