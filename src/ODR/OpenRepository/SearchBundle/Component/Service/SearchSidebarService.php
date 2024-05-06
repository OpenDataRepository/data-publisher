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
use ODR\AdminBundle\Entity\SidebarLayoutMap;
use ODR\AdminBundle\Entity\SidebarLayoutPreferences;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
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
     * @param PermissionsManagementService $permissions_service
     * @param UserManager $user_manager
     * @param Session $session
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatabaseInfoService $database_info_service,
        PermissionsManagementService $permissions_service,
        UserManager $user_manager,
        Session $session,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->database_info_service = $database_info_service;
        $this->permissions_service = $permissions_service;
        $this->user_manager = $user_manager;
        $this->session = $session;
        $this->logger = $logger;
    }


    /**
     * Returns an array with the data required to build the Search Sidebar.
     *
     * The returned array differs slightly depending on whether a sidebar layout is specified...
     * see {@link self::constructSidebarLayoutArray()} and {@link self::constructDefaultSidebarArray()}
     *
     * @param ODRUser $user
     * @param int $target_datatype_id
     * @param array $search_params Can be empty, but should be provided when the sidebar is going to
     *                             be rendered with a search.  If provided and $sidebar_layout_id is
     *                             set, then also ensures any referenced datafields exist in the
     *                             array this function returns
     * @param int|null $sidebar_layout_id If set, then only include the fields from the given layout
     * @param boolean $fallback If true, then return the "master" sidebar layout when the requested
     *                          sidebar layout is unusable
     *
     * @return array
     */
    public function getSidebarDatatypeArray($user, $target_datatype_id, &$search_params, $sidebar_layout_id = null, $fallback = true)
    {
        // Need to load the cached version of this datatype, along with any linked datatypes it has
        $datatype_array = $this->database_info_service->getDatatypeArray($target_datatype_id, true);    // do need links

        // ...then filter the array to just what the user can see
        $datarecord_array = array();
        $user_permissions = $this->permissions_service->getUserPermissionsArray($user);
        $this->permissions_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // NOTE: letting twig handle the "searchable" flag attached to the datafields for now
        // ...this would be the place to do it in PHP though

        $sidebar_array = array();
        if ( !is_null($sidebar_layout_id) ) {
            // If a sidebar layout is specified, then verify whether the user can actually use
            //  the requested layout...
            $is_datatype_admin = isset( $datatype_permissions[$target_datatype_id]['dt_admin'] );
            $sidebar_layout_array = self::canUseLayout($user, $sidebar_layout_id, $is_datatype_admin);

            // The sidebar layout may not have datafields in it...
            $has_datafields = false;
            $has_always_display_fields = false;
            if ( !empty($sidebar_layout_array[$sidebar_layout_id]['sidebarLayoutMap']) ) {
                $has_datafields = true;

                // ...if it does have datafields, then check whether there's at least one field in
                //  the 'always display' category
                $sidebar_layout_map = $sidebar_layout_array[$sidebar_layout_id]['sidebarLayoutMap'];
                foreach ($sidebar_layout_map as $df_id => $df) {
                    if ( $df['category'] === SidebarLayoutMap::ALWAYS_DISPLAY ) {
                        $has_always_display_fields = true;
                        break;
                    }
                }
            }

            if ( (!$has_datafields || !$has_always_display_fields) && $fallback ) {
                // If the sidebar layout has no datafields attached to it and falling back to the
                //  "master" layout makes sense...then do nothing here
            }
            else {
                // In every other situation, build an array to use for the sidebar
                $sidebar_array = self::constructSidebarLayoutArray($datatype_array, $sidebar_layout_array, $search_params);
            }
        }

        if ( empty($sidebar_array) ) {
            // If a sidebar layout was not specified, or the user was unable to use the requested
            //  layout, then fall back to the default for the datatype
            $sidebar_array = self::constructDefaultSidebarArray($datatype_array);
        }

        if ( !empty($search_params) ) {
            // If a set of initial search params was provided, then ensure the correct radio options
            //  and tags get selected
            self::fixSearchParamsOptionsAndTags($sidebar_array, $search_params);
        }

        return $sidebar_array;
    }


    /**
     * Returns an array of SidebarLayout data if the user can use the requested sidebar layout,
     * or returns an empty array otherwise.
     *
     * @param ODRUser $user
     * @param int $sidebar_layout_id
     * @param bool $is_datatype_admin
     * @return array
     */
    private function canUseLayout($user, $sidebar_layout_id, $is_datatype_admin)
    {
        // This function isn't guaranteed to be called with a logged-in user
        $user_id = 0;
        if ( $user instanceof ODRUser )
            $user_id = $user->getId();

        $query = $this->em->createQuery(
           'SELECT
                sl, slm, sl_map,
                partial sl_map_df.{id}, partial sl_map_df_dt.{id},
                partial sl_cb.{id}
            FROM ODRAdminBundle:SidebarLayout AS sl
            LEFT JOIN sl.sidebarLayoutMeta AS slm
            LEFT JOIN sl.sidebarLayoutMap AS sl_map
            LEFT JOIN sl_map.dataField AS sl_map_df
            LEFT JOIN sl_map_df.dataType AS sl_map_df_dt
            LEFT JOIN sl.createdBy AS sl_cb
            WHERE sl = :sidebar_layout_id
            AND sl.deletedAt IS NULL AND slm.deletedAt IS NULL
            AND sl_map.deletedAt IS NULL AND sl_map_df.deletedAt IS NULL AND sl_map_df_dt.deletedAt IS NULL'
        )->setParameters( array('sidebar_layout_id' => $sidebar_layout_id) );
        $results = $query->getArrayResult();

        $sidebar_array = array();
        foreach ($results as $sl_num => $sl) {
            // Only allow user to view if the layout is shared, or they created it, or they're an
            //  admin of this datatype
            if ( !$is_datatype_admin && $sl['sidebarLayoutMeta'][0]['shared'] === false && $sl['createdBy']['id'] !== $user_id ) {
                // ...they're not allowed to use the layout
                throw new ODRForbiddenException('', 0xc96d55dc);
            }
            // Don't want the user info in here
            unset( $sl['createdBy'] );

            // Want the sidebar layout to be wrapped with its id
            $sidebar_array[$sidebar_layout_id] = $sl;
            // The sidebar layout meta entry should not be wrapped
            $sidebar_array[$sidebar_layout_id]['sidebarLayoutMeta'] = $sl['sidebarLayoutMeta'][0];

            // Going to replace the sidebarLayoutMap entry...
            unset( $sidebar_array[$sidebar_layout_id]['sidebarLayoutMap'] );
            if ( isset($sl['sidebarLayoutMap']) ) {
                foreach ($sl['sidebarLayoutMap'] as $sl_map_num => $sl_map) {
                    // ...by organizing it via the datafield id
                    $df = $sl_map['dataField'];
                    // Need to compensate for the "general search" input
                    $df_id = 0;
                    if ( !is_null($df) && isset($df['id']) )
                        $df_id = $df['id'];

                    // Organize the sidebarLayoutMap entries by the datafield id instead of a random number
                    $sidebar_array[$sidebar_layout_id]['sidebarLayoutMap'][$df_id] = $sl_map;

                    // NOTE: no point getting more than the datafield/datafieldMeta ids here...need
                    //  the renderPluginInstance info too, and that's already been built elsewhere for
                    //  the cached datatype array
                }
            }
        }

        return $sidebar_array;
    }


    /**
     * Creates an array of data to render the search sidebar with, based off the given sidebar
     * layout array.  The cached_datatype_array only has the datatypes of the datafields in the
     * 'always_display' and 'extended_display' arrays.
     *
     * The array looks like this:
     * <pre>
     * array(
     *     'layout_array' => <sidebar_layout_array>,
     *     'datatype_array' => <cached_datatype_array>,
     *     'always_display' => array(
     *         <df_id> => <cached_df_array>,
     *         ...
     *     ),
     *     'extended_display' => array(
     *         <df_id> => <cached_df_array>,
     *         ...
     *     ),
     * )
     * </pre>
     *
     * @param array $datatype_array
     * @param array $sidebar_layout_array
     * @param array $search_params {@link self::getSidebarDatatypeArray()}
     * @return array
     */
    private function constructSidebarLayoutArray($datatype_array, $sidebar_layout_array, $search_params)
    {
        // Should only be one entry in the array...
        $sl_array = array();
        foreach ($sidebar_layout_array as $sl_id => $sl)
            $sl_array = $sl;

        $sidebar_array = array(
            'layout_array' => $sl_array,
            'datatype_array' => $datatype_array,
            'always_display' => array(),
            'extended_display' => array(),
        );

        // Copy the info for each datafield in the layout from the cached datatype array
        if ( isset($sl_array['sidebarLayoutMap']) ) {
            foreach ($sl_array['sidebarLayoutMap'] as $df_id => $sl_map) {
                $category = 'never_display';
                if ( $sl_map['category'] === SidebarLayoutMap::ALWAYS_DISPLAY )
                    $category = 'always_display';
                else if ( $sl_map['category'] === SidebarLayoutMap::EXTENDED_DISPLAY )
                    $category = 'extended_display';

                // If this is the entry for the "general search" input...
                if ($df_id === 0) {
                    // ...
                    $sidebar_array[$category][$df_id] = array(
                        'id' => 0,
                        'displayOrder' => $sl_map['displayOrder']
                    );

                    // Skip to the next datafield
                    continue;
                }

                // Otherwise, need to shuffle around some arrays
                $dt_id = $sl_map['dataField']['dataType']['id'];

                if ( $category !== 'never_display' ) {
                    $sidebar_array[$category][$df_id] = $datatype_array[$dt_id]['dataFields'][$df_id];
                    $sidebar_array[$category][$df_id]['displayOrder'] = $sl_map['displayOrder'];
                    $sidebar_array[$category][$df_id]['dataType'] = $sl_map['dataField']['dataType'];
                }
            }
        }

        // If a set of search params is provided, then need to ensure the datafield exists in the
        //  sidebar layout
        if ( !empty($search_params) ) {
            foreach ($search_params as $key => $value) {
                if ( $key === 'gen' ) {
                    // Search params have a "general search" term...
                    if ( !(isset($sidebar_array['always_display'][0]) || isset($sidebar_array['extended_display'][0])) ) {
                        // ...the "general search" input isn't already in the sidebar layout, add it
                        $sidebar_array['extended_display'][0] = array(
                            'id' => 0,
                            'displayOrder' => 999
                        );
                    }
                }
                else {
                    // Search params have a datafield in there...
                    $df_id = null;
                    if ( is_numeric($key) ) {
                        // Search terms for most datafields are denoted by a single numeric key...
                        $df_id = intval($key);
                    }
                    else {
                        $pieces = explode('_', $key);
                        if ( is_numeric($pieces[0]) && count($pieces) === 2 ) {
                            // ...search terms for DatetimeValues or the public_status/quality for
                            //  a File/Image field are "<df_id>_<something>"
                            $df_id = intval($pieces[0]);
                        }

                        // There are other potential keys for a search param, but ignoring them
                        //  for the moment...
                    }

                    if ( !is_null($df_id) && !(isset($sidebar_array['always_display'][$df_id]) || isset($sidebar_array['extended_display'][$df_id])) ) {
                        // The datafield from the search param isn't already in the sidebar layout
                        foreach ($datatype_array as $dt_id => $dt) {
                            // Need to find the datatype this datafield belongs to...
                            if ( isset($dt['dataFields'][$df_id]) ) {
                                // ...so it can receive an entry for in the sidebar array
                                $sidebar_array['extended_display'][$df_id] = $dt['dataFields'][$df_id];
                                $sidebar_array['extended_display'][$df_id]['displayOrder'] = 999;
                                $sidebar_array['extended_display'][$df_id]['dataType'] = array('id' => $dt_id);
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Sort the datafields in each category by their display order
        if ( !empty($sidebar_array['always_display']) && count($sidebar_array['always_display']) > 1 ) {
            uasort($sidebar_array['always_display'], function ($a, $b) {
                $a_display_order = $a['displayOrder'];
                $b_display_order = $b['displayOrder'];

                return $a_display_order <=> $b_display_order;
            });
        }
        if ( !empty($sidebar_array['extended_display']) && count($sidebar_array['extended_display']) > 1  ) {
            uasort($sidebar_array['extended_display'], function ($a, $b) {
                $a_display_order = $a['displayOrder'];
                $b_display_order = $b['displayOrder'];

                return $a_display_order <=> $b_display_order;
            });
        }

        return $sidebar_array;
    }


    /**
     * Creates an array of data to render the search sidebar with...without any layout data, the
     * function resorts to building said array based on the cached datatype array.
     *
     * The array looks like this:
     * <pre>
     * array(
     *     'layout_array' => array(),    // empty array on purpose
     *     'datatype_array' => <cached_datatype_array>,
     *     'always_display' => array(
     *         0 => array()    // placeholder for the "general search" input
     *     ),
     *     'extended_display' => array(),    // no sense duplicating the datatype array
     * )
     * </pre>
     *
     * Note that this is different from the array in {@link self::constructSidebarLayoutArray()}
     *
     * @param array $datatype_array
     * @return array
     */
    private function constructDefaultSidebarArray($datatype_array)
    {
        // By default, the sidebar contains all searchable datafields from all datatypes...so sort
        //  them by name in the interest of making them easier to find
        foreach ($datatype_array as $dt_id => $dt) {
            uasort($datatype_array[$dt_id]['dataFields'], function ($a, $b) {
                $a_name = $a['dataFieldMeta']['fieldName'];
                $b_name = $b['dataFieldMeta']['fieldName'];

                return strcmp($a_name, $b_name);
            });
        }

        // Need to add a back-reference to the datatype in each of its datafields
        foreach ($datatype_array as $dt_id => $dt) {
            foreach ($dt['dataFields'] as $df_id => $df)
                $datatype_array[$dt_id]['dataFields'][$df_id]['dataType']['id'] = $dt_id;
        }

        $sidebar_array = array(
            'layout_array' => array(),    // empty array is interpreted as not using a layout
            'datatype_array' => $datatype_array,
            'always_display' => array(
                0 => array()    // placeholder for the general search field
            ),
            'extended_display' => array()    // no sense duplicating the datatype array
        );

        return $sidebar_array;
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
     * it's incredibly irritating...easier to use php.
     *
     * @param array $sidebar_array
     * @param array $search_params
     */
    public function fixSearchParamsOptionsAndTags($sidebar_array, &$search_params)
    {
        // This function operates on the radioOption/tag lists, so need the datatype array
        $dt_list = $sidebar_array['datatype_array'];
        foreach ($dt_list as $num => $dt) {
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
           'SELECT sl, slm, slp, partial sl_dfm.{id},
                partial sl_cb.{id, username, email, firstName, lastName}
            FROM ODRAdminBundle:SidebarLayout AS sl
            JOIN sl.createdBy AS sl_cb
            JOIN sl.sidebarLayoutMeta AS slm
            LEFT JOIN sl.sidebarLayoutMap AS sl_dfm
            LEFT JOIN sl.sidebarLayoutPreferences AS slp WITH (slp.createdBy = :user_id)
            WHERE sl.dataType = :datatype_id
            AND sl.deletedAt IS NULL AND slm.deletedAt IS NULL
            AND sl_dfm.deletedAt IS NULL AND slp.deletedAt IS NULL
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
        foreach ($results as $sidebar_layout) {
            // Easier to extract some of these properties from the array...
            $sidebar_layout_meta = $sidebar_layout['sidebarLayoutMeta'][0];
            $sidebar_layout_preferences = null;
            if ( isset($sidebar_layout['sidebarLayoutPreferences'][0]) )
                $sidebar_layout_preferences = $sidebar_layout['sidebarLayoutPreferences'][0];

            $created_by = $sidebar_layout['createdBy'];
            $user_string = $created_by['email'];
            if ( isset($created_by['firstName']) && isset($created_by['lastName']) && $created_by['firstName'] !== '' )
                $user_string = $created_by['firstName'].' '.$created_by['lastName'];


            // Only allow user to use the layout if it's shared, or they created it, or they're an
            //  admin of this datatype
            if ( $sidebar_layout_meta['shared']
                || $user_id == $created_by['id']
                || $is_datatype_admin
            ) {
                // layouts can be defaults for multiple page_types...
                $default_for_labels = array();
                $default_for = $sidebar_layout_meta['defaultFor'];
                foreach (self::PAGE_TYPES as $bit => $label) {
                    if ( $default_for & $bit )
                        $default_for_labels[] = ucfirst( str_replace('_', ' ', $label) );
                }

                // Users' preferred layouts are independent of whether the layout is default for a
                //  given page_type...
                $user_preference_labels = array();
                if ( !is_null($sidebar_layout_preferences) ) {
                    $user_default_for = $sidebar_layout_preferences['defaultFor'];
                    foreach (self::PAGE_TYPES as $bit => $label) {
                        if ( $user_default_for & $bit )
                            $user_preference_labels[] = ucfirst( str_replace('_', ' ', $label) );
                    }
                }

                // Having an empty sidebar layout is a little more serious than having an empty theme...
                $is_empty = false;
                if ( !isset($sidebar_layout['sidebarLayoutMap']) || empty($sidebar_layout['sidebarLayoutMap']) )
                    $is_empty = true;


                // Would prefer if this didn't use yet another dialog, but there's just too much
                //  useful information that needs displaying...
                $sidebar_layout_record = array(
                    'id' => $sidebar_layout['id'],
                    'name' => $sidebar_layout_meta['layoutName'],
                    'description' => $sidebar_layout_meta['layoutDescription'],
                    'is_shared' => $sidebar_layout_meta['shared'],
                    'is_empty' => $is_empty,
//                    'display_order' => $sidebar_layout_meta['displayOrder'],
//                    'layout_type' => $sidebar_layout_type,
//                    'is_table_layout' => $sidebar_layout_meta['isTablelayout'],
                    'created_by' => $created_by['id'],
                    'created_by_name' => $user_string,

                    'default_for' => $default_for_labels,
                    'user_preference_for' => $user_preference_labels,
                );

                $filtered_layouts[] = $sidebar_layout_record;
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
        $sidebar_layout_id = self::getSessionSidebarLayoutId($datatype_id, $page_type);
        if ( !is_null($sidebar_layout_id) ) {
            // If the $sidebar_layout_id equals zero...
            if ( $sidebar_layout_id === 0 ) {
                // ...then return null to use the "master" sidebar layout
                return null;
            }

            // Otherwise, going to need a list of all "real" layouts...
            $top_level_layouts = self::getSidebarLayoutIds();
            if ( in_array($sidebar_layout_id, $top_level_layouts) ) {
                // If the layout isn't deleted, return its id
                return $sidebar_layout_id;
            }
            else {
                // Otherwise, user shouldn't be using it
                self::resetSessionSidebarLayoutId($datatype_id, $page_type);

                // Continue looking for the next-best layout to use
            }
        }

        // If nothing was found in the user's session, then see if they have a preference already
        //  stored in the database for this page_type...
        if ($user !== 'anon.') {
            $sidebar_layout_preference = self::getUserSidebarLayoutPreference($user, $datatype_id, $page_type);
            if ( !is_null($sidebar_layout_preference) ) {
                // ...set it as their current session layout to avoid database lookups
                $sidebar_layout = $sidebar_layout_preference->getSidebarLayout();
                self::setSessionSidebarLayoutId($datatype_id, $page_type, $sidebar_layout->getId());

                // ...return the id of the layout
                return $sidebar_layout->getId();
            }
        }

        // If the user doesn't have a preference in the database, then see if the datatype has a
        //  default layout for this page_type...
        $sidebar_layout = self::getDefaultSidebarLayout($datatype_id, $page_type);
        if ( !is_null($sidebar_layout) ) {
            // ...set it as their current session layout to avoid database lookups
            self::setSessionSidebarLayoutId($datatype_id, $page_type, $sidebar_layout->getId());

            // ...return the id of the layout
            return $sidebar_layout->getId();
        }

        // If the user doesn't have a preference and the datatype doesn't have a custom layout,
        //  then return null to use the "master" sidebar layout as a default
        return null;
    }


    /**
     * Return a datatype's default sidebar layout for this page_type, as set by a datatype admin.
     *
     * @param integer $datatype_id
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     *
     * @return SidebarLayout|null null when the datatype isn't using a custom sidebar layout as its default
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
     * @param integer $sidebar_layout_id If zero, then use the datatype's "master" sidebar layout
     */
    public function setSessionSidebarLayoutId($datatype_id, $page_type, $sidebar_layout_id)
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
        $session_layouts[$datatype_id][$page_type] = $sidebar_layout_id;
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
     * @return SidebarLayoutPreferences|null null when the user doesn't have a sidebar layout preference set
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
            JOIN odr_sidebar_layout AS sl ON slp.sidebar_layout_id = sl.id
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
            $slp = $this->em->getRepository('ODRAdminBundle:SidebarLayoutPreferences')->find($slp_id);
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
     * @param SidebarLayout $sidebar_layout
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     *
     * @return SidebarLayoutPreferences
     */
    public function setUserSidebarLayoutPreference($user, $sidebar_layout, $page_type)
    {
        // Ensure this is called with an actual sidebar layout...users aren't allowed to set the
        //  "master" sidebar layout as their default
        if ( is_null($sidebar_layout) )
            throw new ODRBadRequestException('Preferring a null SidebarLayout is not allowed', 0x3848883c);
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
            'datatype_id' => $sidebar_layout->getDataType()->getId(),
            'user_id' => $user->getId(),
            'page_type_id' => $page_type_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        foreach ($results as $result) {
            // ...if any exist, then mark them as "not default" since there's going to be a new default
            $slp_id = $result['id'];

            /** @var SidebarLayoutPreferences $slp */
            $slp = $this->em->getRepository('ODRAdminBundle:SidebarLayoutPreferences')->find($slp_id);

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
                'sidebarLayout' => $sidebar_layout->getId(),
                'createdBy' => $user->getId(),
            )
        );

        // If one doesn't exist, create it
        if ($slp == null) {
            $slp = new SidebarLayoutPreferences();
            $slp->setCreatedBy($user);
            $slp->setSidebarLayout($sidebar_layout);
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


    /**
     * Deletes any current default sidebar layout for the provided user.
     *
     * @param int $datatype_id
     * @param ODRUser $user
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     */
    public function resetUserSidebarLayoutPreference($datatype_id, $user, $page_type)
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
            'datatype_id' => $datatype_id,
            'user_id' => $user->getId(),
            'page_type_id' => $page_type_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        foreach ($results as $result) {
            // ...if any exist, then mark them as "not default"
            $slp_id = $result['id'];

            /** @var SidebarLayoutPreferences $slp */
            $slp = $this->em->getRepository('ODRAdminBundle:SidebarLayoutPreferences')->find($slp_id);

            // Unset the bit for this page_type and save it back into the field
            $bitfield_value = $slp->getDefaultFor();
            $bitfield_value -= $page_type_id;
            $slp->setDefaultFor($bitfield_value);

            $this->em->persist($slp);
        }
    }
}
