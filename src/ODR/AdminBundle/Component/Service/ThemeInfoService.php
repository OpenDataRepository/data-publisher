<?php

/**
 * Open Data Repository Data Publisher
 * Theme Info Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains functions to get/build info about themes and which ones the users should use.
 */

namespace ODR\AdminBundle\Component\Service;


// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemePreferences;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRBadRequestException;
// Services
use ODR\AdminBundle\Component\Utility\UserUtility;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\HttpFoundation\Session\Session;


class ThemeInfoService
{

    /**
     * Earlier versions of ODR used a combination of theme_type and context to control when and where
     * themes could be used...in late 2023 this was changed so that any theme could be used anywhere,
     * and as a result theme_type only because useful to indicate a datatype's "master" theme.
     *
     * NOTE: due to this change, the database could have 'master', 'custom', 'custom_view', 'table',
     * 'search_results', or 'linking' for this value...as such, it's only safe to compare with/against
     * the string 'master'.
     *
     * @var string[]
     */
    const THEME_TYPES = array(
        'master',
        'custom',
    );

    /**
     * This value is stored as a bitfield in the database, because the user could potentially want
     * a theme to be the "default" for multiple contexts.
     *
     * @var string[]
     */
    const PAGE_TYPES = array(
        1 => 'search_results',
        2 => 'display',
//        4 => 'edit',
        8 => 'linking',    // TODO - differentiate between the "currently linked" table and the "linking search results"?
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
     * @var DatatreeInfoService
     */
    private $datatree_info_service;

    /**
     * @var PermissionsManagementService
     */
    private $permissions_service;

    /**
     * @var Session
     */
    private $session;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * ThemeInfoService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatatreeInfoService $datatree_info_service
     * @param PermissionsManagementService $permissions_service
     * @param Session $session
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatreeInfoService $datatree_info_service,
        PermissionsManagementService $permissions_service,
        Session $session,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->datatree_info_service = $datatree_info_service;
        $this->permissions_service = $permissions_service;
        $this->session = $session;
        $this->logger = $logger;
    }


    /**
     * Returns basic information on all themes for this datatype that the user is allowed to see.
     *
     * @param ODRUser $user
     * @param Datatype $datatype
     *
     * @return array
     */
    public function getAvailableThemes($user, $datatype)
    {
        // Not guaranteed to have a logged-in user...
        $user_id = 0;
        if ($user !== 'anon.')
            $user_id = $user->getId();

        // Get all themes for this datatype
        $query = $this->em->createQuery(
           'SELECT t, tm, tp,
                partial t_cb.{id, username, email, firstName, lastName}
            FROM ODRAdminBundle:Theme AS t
            JOIN t.createdBy AS t_cb
            JOIN t.themeMeta AS tm
            LEFT JOIN t.themePreferences AS tp WITH (tp.createdBy = :user_id)
            WHERE t.dataType = :datatype_id
            AND t = t.parentTheme AND t.deletedAt IS NULL AND tp.deletedAt IS NULL
            ORDER BY tm.displayOrder, tm.templateName'
        )->setParameters(
            array(
                'datatype_id' => $datatype->getId(),
                'user_id' => $user_id,    // Only get theme preference entries belonging to the user calling the function
            )
        );
        $results = $query->getArrayResult();

        // Filter the list of themes based on what the user is allowed to see
        $is_datatype_admin = $this->permissions_service->isDatatypeAdmin($user, $datatype);
        $filtered_themes = array();
        foreach ($results as $theme) {
            // Easier to extract some of these properties from the array...
            $theme_meta = $theme['themeMeta'][0];
            $theme_preferences = null;
            if ( isset($theme['themePreferences'][0]) )
                $theme_preferences = $theme['themePreferences'][0];

            $created_by = $theme['createdBy'];
            $user_string = $created_by['email'];
            if ( isset($created_by['firstName']) && isset($created_by['lastName']) && $created_by['firstName'] !== '' )
                $user_string = $created_by['firstName'].' '.$created_by['lastName'];


            // Only allow user to view if the theme is shared, or they created it, or they're an
            //  admin of this datatype
            if ( $theme_meta['shared']
                || $user_id == $created_by['id']
                || $is_datatype_admin
            ) {
                // Themes can be defaults for multiple page_types...
                $default_for_labels = array();
                $default_for = $theme_meta['defaultFor'];
                foreach (self::PAGE_TYPES as $bit => $label) {
                    if ( $default_for & $bit )
                        $default_for_labels[] = ucfirst( str_replace('_', ' ', $label) );
                }

                // Users' preferred themes are independent of whether the theme is default for a
                //  given page_type...
                $user_preference_labels = array();
                if ( !is_null($theme_preferences) ) {
                    $user_default_for = $theme_preferences['defaultFor'];
                    foreach (self::PAGE_TYPES as $bit => $label) {
                        if ( $user_default_for & $bit )
                            $user_preference_labels[] = ucfirst( str_replace('_', ' ', $label) );
                    }
                }

                // Earlier versions of ODR used a combination of theme_type and page_type to control
                //  when and where they were used...in late 2023 this was changed so that any theme
                //  could be used anywhere, and as a result theme_type only because useful to indicate
                //  a datatype's "master" theme
                $theme_type = 'custom';
                if ($theme['themeType'] === 'master')
                    $theme_type = 'master';

                $theme_record = array(
                    'id' => $theme['id'],
                    'name' => $theme_meta['templateName'],
                    'description' => $theme_meta['templateDescription'],
                    'is_shared' => $theme_meta['shared'],
                    'display_order' => $theme_meta['displayOrder'],
                    'theme_type' => $theme_type,
                    'theme_visibility' => $theme_meta['themeVisibility'],
                    'is_table_theme' => $theme_meta['isTableTheme'],
                    'created_by' => $created_by['id'],
                    'created_by_name' => $user_string,

                    'default_for' => $default_for_labels,
                    'user_preference_for' => $user_preference_labels,
                );

                $filtered_themes[] = $theme_record;
            }
        }

        return $filtered_themes;
    }


    /**
     * Attempts to return the id of the user's preferred theme for the given datatype/page_type.
     *
     * @param ODRUser|string|null $user  Either 'anon.' or an ODRUser object
     * @param integer $datatype_id
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     *
     * @return int
     */
    public function getPreferredThemeId($user, $datatype_id, $page_type)
    {
        // Ensure the provided page_type is valid
        if ( !in_array($page_type, self::PAGE_TYPES) )
            throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0x66034d60);

        // Look in the user's session first...
        $theme_id = self::getSessionThemeId($datatype_id, $page_type);
        if ( !is_null($theme_id) ) {
            // Going to need a list of top-level themes...
            $top_level_themes = self::getTopLevelThemes();
            if ( in_array($theme_id, $top_level_themes) ) {
                // If the theme isn't deleted, return its id
                return $theme_id;
            }
            else {
                // Otherwise, user shouldn't be using it as their session theme
                self::resetSessionThemeId($datatype_id, $page_type);

                // Continue looking for the next-best theme to use
            }
        }

        // If nothing was found in the user's session, then see if the user has a preference already
        //  stored in the database for this page_type...
        if ( !is_null($user) && $user !== 'anon.' ) {
            $theme_preference = self::getUserThemePreference($user, $datatype_id, $page_type);
            if ( !is_null($theme_preference) ) {
                // ...set it as their current session theme to avoid database lookups
                $theme = $theme_preference->getTheme();
                self::setSessionThemeId($datatype_id, $page_type, $theme->getId());

                // ...return the id of the theme
                return $theme->getId();
            }
        }

        // If the user doesn't have a preference in the database, then see if the datatype has a
        //  default theme for this page_type...
        $theme = self::getDatatypeDefaultTheme($datatype_id, $page_type);
        if ( !is_null($theme) ) {
            // ...set it as their current session theme to avoid database lookups
            self::setSessionThemeId($datatype_id, $page_type, $theme->getId());

            // ...return the id of the theme
            return $theme->getId();
        }

        // If there's no default theme for this page_type, then return the datatype's master theme
        $theme = self::getDatatypeMasterTheme($datatype_id);
        if ( !is_null($theme) )
            return $theme->getId();

        // ...if there's not even a master theme for this datatype, something is horribly wrong
        throw new ODRException('Unable to locate master theme for datatype '.$datatype_id, 500, 0xba003ad0);
    }


    /**
     * Returns the id of the user's preferred theme for this current datatype/page_type combo for
     * their current session.
     *
     * @param integer $datatype_id
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     *
     * @return int|null
     */
    public function getSessionThemeId($datatype_id, $page_type)
    {
        // Ensure the provided page_type is valid
        if ( !in_array($page_type, self::PAGE_TYPES) )
            throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0xd3fe0f6d);


        // If the user has changed any theme for their current session...
        if ( $this->session->has('session_themes') ) {
            $session_themes = $this->session->get('session_themes');

            // ...see if they have a theme for this datatype/page_type combo
            if ( isset($session_themes[$datatype_id][$page_type]) )
                return $session_themes[$datatype_id][$page_type];
        }

        // Otherwise, no session theme...return null
        return null;
    }


    /**
     * Stores a specific theme id as the user's preferred theme for this datatype/page_type combo
     * for this session.
     *
     * @param integer $datatype_id
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     * @param integer $theme_id
     */
    public function setSessionThemeId($datatype_id, $page_type, $theme_id)
    {
        // Ensure the provided page_type is valid
        if ( !in_array($page_type, self::PAGE_TYPES) )
            throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0xf07deceb);


        // Load any existing session themes
        $session_themes = array();
        if ( $this->session->has('session_themes') )
            $session_themes = $this->session->get('session_themes');

        if ( !isset($session_themes[$datatype_id]) )
            $session_themes[$datatype_id] = array();

        // Save the theme choice in the session
        $session_themes[$datatype_id][$page_type] = $theme_id;
        $this->session->set('session_themes', $session_themes);
    }


    /**
     * Clears the user's preferred themes for this datatype for this session.  If a page_type is
     * specified, then only the theme for that page_type is cleared.
     *
     * @param integer $datatype_id
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     */
    public function resetSessionThemeId($datatype_id, $page_type = '')
    {
        if ( $page_type !== '' ) {
            // Ensure the provided page_type is valid
            if ( !in_array($page_type, self::PAGE_TYPES) )
                throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0x68a0df80);
        }

        // Load any existing session themes
        $session_themes = array();
        if ( $this->session->has('session_themes') )
            $session_themes = $this->session->get('session_themes');

        // If the page type was not set, then just unset anything stored for this datatype
        if ( $page_type === '' )
            unset( $session_themes[$datatype_id] );
        else
            unset( $session_themes[$datatype_id][$page_type] );

        // Save back to session
        $this->session->set("session_themes", $session_themes);
    }


    /**
     * Returns a ThemePreferences object containing the user's preferred theme for the given
     * datatype, if there is one.
     *
     * @param ODRUser $user
     * @param integer $datatype_id
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     *
     * @return ThemePreferences|null
     */
    public function getUserThemePreference($user, $datatype_id, $page_type)
    {
        // Anonymous users don't have theme preferences
        if ($user === 'anon.')
            return null;

        // Ensure the provided page_type is valid
        $page_type_id = array_search($page_type, self::PAGE_TYPES);
        if ( $page_type_id === false )
            throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0xfaedeca2);


        // ----------------------------------------
        // Determine whether the user already has a preferred Theme for this page_type
        // NOTE: using native SQL, because Doctrine apparently hates the '&' operator
        $query =
           'SELECT tp.id
            FROM odr_theme_preferences AS tp
            JOIN odr_theme AS t ON tp.theme_id = t.id
            JOIN odr_data_type AS dt ON t.data_type_id = dt.id
            WHERE dt.id = :datatype_id
            AND tp.createdBy = :user_id AND (tp.default_for & :page_type_id)
            AND tp.deletedAt IS NULL AND t.deletedAt IS NULL AND dt.deletedAt IS NULL';
        $params = array(
            'datatype_id' => $datatype_id,
            'user_id' => $user->getId(),
            'page_type_id' => $page_type_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        // Should only be one...
        foreach ($results as $result) {
            $tp_id = $result['id'];

            /** @var ThemePreferences $tp */
            $tp = $this->em->getRepository('ODRAdminBundle:ThemePreferences')->find($tp_id);
            return $tp;
        }

        // If the query returned nothing, then return null instead
        return null;
    }


    /**
     * Sets the given Theme to be a default for the provided user.  Any other Themes in the same
     * "category" are marked as "not default".
     *
     * @param ODRUser $user
     * @param Theme $theme
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     *
     * @return ThemePreferences
     */
    public function setUserThemePreference($user, $theme, $page_type)
    {
        // Complain if this isn't a top-level theme
        if ( $theme->getId() !== $theme->getParentTheme()->getId() )
            throw new ODRBadRequestException('This should only be called on Themes of top-level Datatypes', 0x4f2519d6);


        // ----------------------------------------
        // Ensure the provided page_type is valid
        $page_type_id = array_search($page_type, self::PAGE_TYPES);
        if ( $page_type_id === false )
            throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0x4f2519d6);

        // Determine whether the user already has a preferred Theme for this page_type
        // NOTE: using native SQL, because Doctrine apparently hates the '&' operator
        $query =
           'SELECT tp.id
            FROM odr_theme_preferences AS tp
            JOIN odr_theme AS t ON tp.theme_id = t.id
            JOIN odr_data_type AS dt ON t.data_type_id = dt.id
            WHERE dt.id = :datatype_id
            AND tp.createdBy = :user_id AND (tp.default_for & :page_type_id)
            AND tp.deletedAt IS NULL AND t.deletedAt IS NULL AND dt.deletedAt IS NULL';
        $params = array(
            'datatype_id' => $theme->getDataType()->getId(),
            'user_id' => $user->getId(),
            'page_type_id' => $page_type_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        foreach ($results as $result) {
            // If they do, then mark it as "not default", since there's going to be a new default...
            $tp_id = $result['id'];

            /** @var ThemePreferences $tp */
            $tp = $this->em->getRepository('ODRAdminBundle:ThemePreferences')->find($tp_id);

            // Unset the bit for this page_type and save it back into the field
            $bitfield_value = $tp->getDefaultFor();
            $bitfield_value -= $page_type_id;
            $tp->setDefaultFor($bitfield_value);

            $this->em->persist($tp);
        }


        // ----------------------------------------
        // Attempt to locate the ThemePreferences entry for the given Theme/User pair
        $tp = $this->em->getRepository('ODRAdminBundle:ThemePreferences')->findOneBy(
            array(
                'theme' => $theme->getId(),
                'createdBy' => $user->getId(),
            )
        );

        // If one doesn't exist, create it
        if ($tp == null) {
            $tp = new ThemePreferences();
            $tp->setCreatedBy($user);
            $tp->setTheme($theme);
            $tp->setDefaultFor(0);
        }

        // Mark this ThemePreference entry as the one the user wants to use
        $bitfield_value = $tp->getDefaultFor();
        $bitfield_value += $page_type_id;
        $tp->setDefaultFor($bitfield_value);
        $tp->setUpdatedBy($user);

        // Done with the modifications
        $this->em->persist($tp);
        $this->em->flush();
        $this->em->refresh($tp);

        return $tp;
    }


    /**
     * Return a datatype's default Theme for this page_type, as set by a datatype admin.  If the
     * datatype's "master" theme is desired, self::getDatatypeMasterTheme() should be used instead.
     *
     * @param integer $datatype_id
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     *
     * @return Theme
     */
    public function getDatatypeDefaultTheme($datatype_id, $page_type)
    {
        // Ensure the provided page_type is valid
        $page_type_id = array_search($page_type, self::PAGE_TYPES);
        if ( $page_type_id === false )
            throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type', 0xb940fc66);


        // ----------------------------------------
        // Query the database for the default top-level theme for this datatype
        // NOTE: using native SQL, because Doctrine apparently hates the '&' operator
        $query =
           'SELECT t.id
            FROM odr_theme AS t
            JOIN odr_theme_meta AS tm ON tm.theme_id = t.id
            WHERE t.data_type_id = :datatype_id AND (tm.default_for & :page_type_id)
            AND t.id = t.parent_theme_id
            AND t.deletedAt IS NULL AND tm.deletedAt IS NULL';
        $params = array(
            'datatype_id' => $datatype_id,
            'page_type_id' => $page_type_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        // Should only be one...
        foreach ($results as $result) {
            $t_id = $result['id'];

            /** @var Theme $t */
            $t = $this->em->getRepository('ODRAdminBundle:Theme')->find($t_id);
            return $t;
        }

        // If the query returned nothing, then return null instead
        return null;
    }


    /**
     * Returns the given datatype's "master" theme.
     *
     * @param integer $datatype_id
     *
     * @return Theme
     */
    public function getDatatypeMasterTheme($datatype_id)
    {
        // Query the database to get this datatype's master theme
        $query = $this->em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Theme AS t
            JOIN ODRAdminBundle:ThemeMeta AS tm WITH tm.theme = t
            WHERE t.dataType = :datatype_id AND t.themeType = :theme_type
            AND t = t.sourceTheme
            AND t.deletedAt IS NULL AND tm.deletedAt IS NULL'
        )->setParameters(
            array(
                'datatype_id' => $datatype_id,
                'theme_type' => 'master',
            )
        );
        $result = $query->getResult();

        // There should only be one master theme for a datatype...
        if ( count($result) !== 1 )
            throw new ODRException('This datatype does not have a "master" theme', 500, 0x2e0a8d28);

        // Return this datatype's master theme
        return $result[0];
    }


    /**
     * Loads and returns a cached data array for the specified theme ids.
     * 
     * @param integer $parent_theme_id
     * 
     * @return array
     */
    public function getThemeArray($parent_theme_id)
    {
        // Attempt to the cached version of this theme
        $theme_data = $this->cache_service->get('cached_theme_'.$parent_theme_id);

        // If the requested entry doesn't exist, rebuild it
        if ($theme_data == false)
            $theme_data = self::buildThemeData($parent_theme_id);

        // Organize by theme id
        $theme_array = array();
        foreach ($theme_data as $t_id => $data)
            $theme_array[$t_id] = $data;

        return $theme_array;
    }
    
    
    /**
     * Gets all theme, theme element, theme datafield, and theme datatype information...the array
     * is slightly modified, stored in the cache, and then returned.
     *
     * @param integer $parent_theme_id
     *
     * @return array
     */
    private function buildThemeData($parent_theme_id)
    {
        // This function is only called when the cache entry doesn't exist

        // Going to need the datatree array to rebuild this cache entry
        $datatree_array = $this->datatree_info_service->getDatatreeArray();

        // Get all the data for the requested theme
        $query = $this->em->createQuery(
           'SELECT
                t, tm, partial dt.{id},
                partial t_cb.{id, username, email, firstName, lastName},
                partial t_ub.{id, username, email, firstName, lastName},
                partial pt.{id}, partial st.{id},

                te, tem,
                tdf, partial df.{id},
                tdt, partial c_dt.{id}, partial c_t.{id},
                trpi, partial rpi.{id},
                partial rptom.{id, value}, partial rptom_rpi.{id}, partial rptom_rpod.{id, name}

            FROM ODRAdminBundle:Theme AS t
            LEFT JOIN t.themeMeta AS tm
            LEFT JOIN t.createdBy AS t_cb
            LEFT JOIN t.updatedBy AS t_ub

            LEFT JOIN t.parentTheme AS pt
            LEFT JOIN t.sourceTheme AS st

            LEFT JOIN t.dataType AS dt

            LEFT JOIN t.themeElements AS te
            LEFT JOIN te.themeElementMeta AS tem

            LEFT JOIN te.themeDataFields AS tdf
            LEFT JOIN tdf.dataField AS df

            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.dataType AS c_dt
            LEFT JOIN tdt.childTheme AS c_t

            LEFT JOIN te.themeRenderPluginInstance AS trpi
            LEFT JOIN trpi.renderPluginInstance AS rpi

            LEFT JOIN t.renderPluginThemeOptionsMap AS rptom
            LEFT JOIN rptom.renderPluginInstance AS rptom_rpi
            LEFT JOIN rptom.renderPluginOptionsDef AS rptom_rpod

            WHERE t.parentTheme = :parent_theme_id
            AND t.deletedAt IS NULL AND te.deletedAt IS NULL

            ORDER BY tem.displayOrder, te.id, tdf.displayOrder, df.id'
        )->setParameters( array('parent_theme_id' => $parent_theme_id) );

        $theme_data = $query->getArrayResult();

        // TODO - if $theme_data is empty, then $parent_theme_id was deleted...should this return something special in that case?

        // The entity -> entity_metadata relationships have to be one -> many from a database
        // perspective, even though there's only supposed to be a single non-deleted entity_metadata
        // object for each entity.  Therefore, the preceding query generates an array that needs
        // to be somewhat flattened in a few places.
        foreach ($theme_data as $theme_num => $theme) {

            // If the theme's datatype is null, then it belongs to a deleted datatype and should
            //  be completely ignored
            // TODO - should this filtering happen inside the mysql query?
            if ( is_null($theme['dataType']) ) {
                unset( $theme_data[$theme_num] );
                continue;
            }

            // Flatten theme meta
            if ( count($theme['themeMeta']) == 0 ) {
                // TODO - this comparison (and the other one in this function) really needs to be strict (!== 1)
                // TODO - ...but that would lock up multiple dev servers until their databases get fixed
                // ...throwing an exception here because this shouldn't ever happen, and also requires
                //  manual intervention to fix...
                throw new ODRException('Unable to rebuild the cached_theme_'.$parent_theme_id.' array because of a database error for theme '.$parent_theme_id);
            }

            $theme_meta = $theme['themeMeta'][0];
            $theme_data[$theme_num]['themeMeta'] = $theme_meta;

            // Scrub irrelevant data from the theme's createdBy and updatedBy properties
            $theme_data[$theme_num]['createdBy'] = UserUtility::cleanUserData( $theme['createdBy'] );
            $theme_data[$theme_num]['updatedBy'] = UserUtility::cleanUserData( $theme['updatedBy'] );

            // Need to save the theme's datatype's id for later...
            $dt_id = $theme_data[$theme_num]['dataType']['id'];


            // ----------------------------------------
            // Theme elements are ordered, so preserve $te_num
            $new_te_array = array();
            foreach ($theme['themeElements'] as $te_num => $te) {
                // Flatten theme_element_meta of each theme_element
                if ( count($te['themeElementMeta']) == 0 ) {
                    // ...throwing an exception here because this shouldn't ever happen, and also requires
                    //  manual intervention to fix...
                    throw new ODRException('Unable to rebuild the cached_theme_'.$parent_theme_id.' array because of a database error for theme_element '.$te['id']);
                }

                $tem = $te['themeElementMeta'][0];
                $te['themeElementMeta'] = $tem;

                // themeDatafield entries are ordered, so preserve $tdf_num
                foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                    // Don't preserve entries for deleted datafields
                    if ( is_null($tdf['dataField']) )
                        unset( $te['themeDataFields'][$tdf_num] );
                }

                // Currently only one themeDatatype is allowed per themeElement, but preserve
                //  $tdt_num regardless incase this changes in the future...
                foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                    // Don't preserve entries for deleted datatypes
                    if ( is_null($tdt['dataType']) ) {
                        unset( $te['themeDataType'][$tdt_num] );
                        continue;
                    }

                    // Otherwise, don't need to verify that this is actually a child/linked datatype
                    $child_dt_id = $tdt['dataType']['id'];

                    // Want to store the 'is_link' and 'multiple_allowed' properties from the
                    //  datatree array here for convenience...
                    $te['themeDataType'][$tdt_num]['is_link'] = 0;
                    $te['themeDataType'][$tdt_num]['multiple_allowed'] = 0;

                    if ( isset($datatree_array['linked_from']) && isset($datatree_array['linked_from'][$child_dt_id]) ) {
                        // This child is a linked datatype somewhere...figure out whether the
                        //  parent datatype in question actually links to it before storing the value
                        $parents = $datatree_array['linked_from'][$child_dt_id];
                        if ( in_array($dt_id, $parents) )
                            $te['themeDataType'][$tdt_num]['is_link'] = 1;
                    }

                    if ( isset($datatree_array['multiple_allowed']) && isset($datatree_array['multiple_allowed'][$child_dt_id]) ) {
                        // Ensure this child/linked datatype is relevant before storing whether
                        //  more than one child/linked datarecord is allowed
                        $parents = $datatree_array['multiple_allowed'][$child_dt_id];
                        if ( in_array($dt_id, $parents) )
                            $te['themeDataType'][$tdt_num]['multiple_allowed'] = 1;
                    }
                }

                // Currently only one themeRenderPluginInstance is allowed per themeElement, but
                //  preserve $trpi_num regardless incase this changes in the future...
                foreach ($te['themeRenderPluginInstance'] as $trpi_num => $trpi) {
                    // Don't preserve entries for deleted renderPluginInstances
                    if ( is_null($trpi['renderPluginInstance']) )
                        unset( $te['themeDataType'][$trpi_num] );
                }

                // Easier on twig for these arrays to simply not exist, if nothing is in them...
                if ( empty($te['themeDataFields']) )
                    unset( $te['themeDataFields'] );
                if ( empty($te['themeDataType']) )
                    unset( $te['themeDataType'] );
                if ( empty($te['themeRenderPluginInstance']) )
                    unset( $te['themeRenderPluginInstance'] );

                $new_te_array[$te_num] = $te;
            }

            unset( $theme_data[$theme_num]['themeElements'] );
            $theme_data[$theme_num]['themeElements'] = $new_te_array;

            // ----------------------------------------
            // Going to store any RenderPluginThemeOptionMaps by their RenderPluginInstance
            $new_rptom_array = array();
            foreach ($theme['renderPluginThemeOptionsMap'] as $rptom_num => $rptom) {
                $rpi_id = $rptom['renderPluginInstance']['id'];
                $rpo_name = $rptom['renderPluginOptionsDef']['name'];
                $rpo_value = $rptom['value'];

                unset( $rptom['renderPluginInstance'] );
                unset( $rptom['renderPluginOptionsDef'] );

                if ( !isset($new_rptom_array[$rpi_id]) )
                    $new_rptom_array[$rpi_id] = array();
                if ( !isset($new_rptom_array[$rpi_id][$rpo_name]) )
                    $new_rptom_array[$rpi_id][$rpo_name] = $rpo_value;
            }

            $theme_data[$theme_num]['renderPluginThemeOptionsMap'] = $new_rptom_array;
        }

        // Organize by theme id
        $formatted_theme_data = array();
        foreach ($theme_data as $num => $t_data) {
            $t_id = $t_data['id'];
            $formatted_theme_data[$t_id] = $t_data;
        }

        // Save the formatted datarecord data back in the cache, and return it
        $this->cache_service->set('cached_theme_'.$parent_theme_id, $formatted_theme_data);
        return $formatted_theme_data;
    }


    /**
     * "Inflates" the normally flattened $theme_array...
     *
     * @param array $theme_array
     * @param integer $initial_theme_id
     *
     * @return array
     */
    public function stackThemeArray($theme_array, $initial_theme_id)
    {
        $current_theme = array();
        if ( isset($theme_array[$initial_theme_id]) ) {
            $current_theme = $theme_array[$initial_theme_id];

            // For each descendant this theme has...
            foreach ($current_theme['themeElements'] as $te_num => $te) {
                if ( isset($te['themeDataType']) ) {
                    foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                        $child_theme_id = $tdt['childTheme']['id'];

                        $tmp = array( $child_theme_id => self::stackThemeArray($theme_array, $child_theme_id) );
                        $current_theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['childTheme'] = array('id' => $child_theme_id, 'theme' => $tmp);
                    }
                }
            }

        }

        return $current_theme;
    }


    /**
     * Marks the specified theme as updated by the given user.
     *
     * @param Theme $theme
     * @param ODRUser $user
     */
    public function updateThemeCacheEntry($theme, $user)
    {
        // Updates to a theme entry don't need to cascade
        $theme->setUpdatedBy($user);
        $theme->setUpdated(new \DateTime());
        $this->em->persist($theme);

        // Save all changes made
        $this->em->flush();

        // Delete the cached version of this theme
        $this->cache_service->delete('cached_theme_'.$theme->getParentTheme()->getId());
    }


    /**
     * This function is currently only used to verify that a user isn't currently preferring a
     * deleted theme in their session...
     *
     * @return int[]
     */
    public function getTopLevelThemes()
    {
        // If list of top level themes exists in cache, return that
        $top_level_themes = $this->cache_service->get('top_level_themes');
        if ( $top_level_themes !== false && count($top_level_themes) > 0 )
            return $top_level_themes;


        // ----------------------------------------
        // Otherwise, rebuild the list of top-level themes
        $top_level_datatypes = $this->datatree_info_service->getTopLevelDatatypes();

        $query = $this->em->createQuery(
           'SELECT t.id AS theme_id
            FROM ODRAdminBundle:Theme AS t
            WHERE t.dataType IN (:datatype_ids) AND t = t.parentTheme
            AND t.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $top_level_datatypes) );
        $results = $query->getArrayResult();

        $top_level_themes = array();
        foreach ($results as $result)
            $top_level_themes[] = $result['theme_id'];


        // ----------------------------------------
        // Store the list in the cache and return
        $this->cache_service->set('top_level_themes', $top_level_themes);
        return $top_level_themes;
    }
}
