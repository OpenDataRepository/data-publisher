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
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
// Utility
use ODR\AdminBundle\Component\Utility\UserUtility;
use Symfony\Component\HttpFoundation\Session\Session;


class ThemeInfoService
{

    // To make understanding this stuff somewhat easier...
    const VALID_THEMETYPES = array(
        'master',
        'custom_view',
        'search_results',
        'table'
    );
    // These theme_types should only be used for displaying which datarecords matched a search
    const SHORT_FORM_THEMETYPES = array(
        'search_results',
        'table'
    );
    // These theme_types should be used for everything else...Design/Display/Edit/etc
    const LONG_FORM_THEMETYPES = array(
        'master',
        'custom_view'
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
    private $dti_service;

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
     * @param Session $session
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatreeInfoService $datatree_info_service,
        Session $session,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dti_service = $datatree_info_service;
        $this->session = $session;
        $this->logger = $logger;
    }


    /**
     * Returns basic information on all themes for this datatype that the user is allowed to see.
     *
     * @param ODRUser $user
     * @param Datatype $datatype
     * @param string $theme_type
     *
     * @throws ODRBadRequestException
     *
     * @return array
     */
    public function getAvailableThemes($user, $datatype, $theme_type = "master")
    {
        // Determine which "class" of themes the user wants to see
        $theme_types = array();
        if ($theme_type == 'master' || $theme_type == 'custom_view') {
            // User wants themes that work on Display/Edit pages
            $theme_types = self::LONG_FORM_THEMETYPES;
        }
        else if ($theme_type == 'search_results' || $theme_type == 'table') {
            // User wants themes that work on Search Result pages
            $theme_types = self::SHORT_FORM_THEMETYPES;
        }
        else {
            throw new ODRBadRequestException('"'.$theme_type.'" is not a supported theme type', 0x722ce43e);
        }

        // ----------------------------------------
        // Get all themes for this datatype that fulfill the previous criteria
        $query = $this->em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Theme AS t
            JOIN ODRAdminBundle:ThemeMeta AS tm WITH tm.theme = t
            WHERE t.dataType = :datatype_id AND t.themeType IN (:theme_types)
            AND t = t.parentTheme AND t.deletedAt IS NULL
            ORDER BY tm.displayOrder, tm.templateName'
        )->setParameters( array('datatype_id' => $datatype->getId(), 'theme_types' => $theme_types));
        $results = $query->getResult();

        // Filter the list of themes based on what the user is allowed to see
        $filtered_themes = array();
        foreach ($results as $theme) {
            /** @var Theme $theme */

            // Only allow user to view if the theme is shared, or they created it
            if ( $theme->isShared()
                || $user !== 'anon.' && $user->getId() == $theme->getCreatedBy()->getId()
            ) {
                $theme_record = array(
                    'id' => $theme->getId(),
                    'name' => $theme->getTemplateName(),
                    'description' => $theme->getTemplateDescription(),
                    'public' => $theme->isShared(),     // TODO - change to shared
                    'is_default' => $theme->isDefault(),
                    'display_order' => $theme->getDisplayOrder(),
                    'theme_type' => $theme->getThemeType(),
                    'created_by' => $theme->getCreatedBy()->getId(),
                    'created_by_name' => $theme->getCreatedBy()->getUserString(),
                );

                array_push($filtered_themes, $theme_record);
            }
        }

        return $filtered_themes;
    }


    /**
     * Attempts to return the id of the user's preferred theme for the given datatype/theme_type.
     *
     * @param string|ODRUser $user  Either 'anon.' or an ODRUser object
     * @param integer $datatype_id
     * @param string $theme_type
     * @param array $top_level_themes should only be used by ThemeService
     *
     * @return int
     */
    public function getPreferredTheme($user, $datatype_id, $theme_type, $top_level_themes = null)
    {
        // Ensure the provided theme type is valid
        if ( !in_array($theme_type, self::VALID_THEMETYPES) )
            throw new ODRBadRequestException('"'.$theme_type.'" is not a supported theme type', 0x66034d60);

        // Look in the user's session first...
        $theme_id = self::getSessionTheme($datatype_id, $theme_type);
        if ($theme_id != null) {

            if ($top_level_themes == null)
                $top_level_themes = self::getTopLevelThemes();

            if ( in_array($theme_id, $top_level_themes) ) {
                // If the theme isn't deleted, return its id
                return $theme_id;
            }
            else {
                // Otherwise, user shouldn't be using it as their session theme
                self::resetSessionTheme($datatype_id, $theme_type);

                // Continue looking for the next-best theme to use
            }
        }


        // If nothing in the user's session, then check the database for their default preferences...
        if ($user !== 'anon.') {
            $theme_preference = self::getUserDefaultTheme($user, $datatype_id, $theme_type);
            if ($theme_preference != null) {
                // ...set it as their current session theme to avoid database lookups
                $theme = $theme_preference->getTheme();
                self::setSessionTheme($datatype_id, $theme);

                // ...return the id of the theme
                return $theme->getId();
            }
        }


        // Otherwise, default to the datatype's default theme
        $theme = self::getDatatypeDefaultTheme($datatype_id, $theme_type);
        if ($theme != null) {
            // ...set it as their current session theme to avoid database lookups
            self::setSessionTheme($datatype_id, $theme);

            // ...return the id of the theme
            return $theme->getId();
        }

        // ...If for some reason there's no default theme for this theme_type, return the datatype's master theme
        $theme = self::getDatatypeDefaultTheme($datatype_id, 'master');
        if ($theme != null)
            return $theme->getId();

        // ...if there's not even a master theme for this datatype, something is horribly wrong
        throw new ODRException('Unable to locate master theme for datatype '.$datatype_id, 500, 0xba003ad0);
    }


    /**
     * Returns the user's preferred theme for this datatype for their current session.
     *
     * @param integer $datatype_id
     * @param string $theme_type
     *
     * @return int|null
     */
    public function getSessionTheme($datatype_id, $theme_type)
    {
        // Ensure the provided theme type is valid
        if ( !in_array($theme_type, self::VALID_THEMETYPES) )
            throw new ODRBadRequestException('"'.$theme_type.'" is not a supported theme type', 0xd3fe0f6d);

        // Themes are stored in the session by which "class" they belong to
        $theme_class = 'long_form';
        if ( in_array($theme_type, self::SHORT_FORM_THEMETYPES) )
            $theme_class = 'short_form';


        // If the user has specified a theme for their current session...
        if ( $this->session->has('session_themes') ) {
            // ...see if a theme is stored for this session for this datatype
            $session_themes = $this->session->get('session_themes');

            if (isset($session_themes[$datatype_id])
                && isset($session_themes[$datatype_id][$theme_class])
            ) {
                return $session_themes[$datatype_id][$theme_class];
            }
        }

        // Otherwise, no session theme, return null
        return null;
    }


    /**
     * Stores a specific theme id as the user's preferred theme for this datatype for this session.
     *
     * @param integer $datatype_id
     * @param Theme $theme
     */
    public function setSessionTheme($datatype_id, $theme)
    {
        // Ensure the provided theme type is valid
        $theme_type = $theme->getThemeType();
        if ( !in_array($theme_type, self::VALID_THEMETYPES) )
            throw new ODRBadRequestException('"'.$theme_type.'" is not a supported theme type', 0xf07deceb);

        // Themes are stored in the session by which "class" they belong to
        $theme_class = 'long_form';
        if ( in_array($theme_type, self::SHORT_FORM_THEMETYPES) )
            $theme_class = 'short_form';


        // Load any existing session themes
        $session_themes = array();
        if ( $this->session->has('session_themes') )
            $session_themes = $this->session->get('session_themes');

        if ( !isset($session_themes[$datatype_id]) )
            $session_themes[$datatype_id] = array();

        // Save the theme choice in the session
        $session_themes[$datatype_id][$theme_class] = $theme->getId();
        $this->session->set('session_themes', $session_themes);
    }


    /**
     * Clears the user's preferred theme for this datatype for this session.  If a theme_type is
     * specified, then only that theme_type for the datatype is cleared.
     *
     * @param integer $datatype_id
     * @param string $theme_type
     */
    public function resetSessionTheme($datatype_id, $theme_type = '')
    {
        $theme_class = '';
        if ($theme_type !== '') {
            // Ensure the provided theme type is valid
            if (!in_array($theme_type, self::VALID_THEMETYPES))
                throw new ODRBadRequestException('"'.$theme_type.'" is not a supported theme type', 0x68a0df80);

            // Themes are stored in the session by which "class" they belong to
            $theme_class = 'long_form';
            if (in_array($theme_type, self::SHORT_FORM_THEMETYPES))
                $theme_class = 'short_form';
        }

        // Load any existing session themes
        $session_themes = array();
        if ( $this->session->has('session_themes') )
            $session_themes = $this->session->get('session_themes');

        // Unset the theme for this session if it exists
        if (isset($session_themes[$datatype_id]) ) {
            if ($theme_class === '')
                unset( $session_themes[$datatype_id] );
            else if ( isset($session_themes[$datatype_id][$theme_class]) )
                unset( $session_themes[$datatype_id][$theme_class] );
        }

        // Save back to session
        $this->session->set("session_themes", $session_themes);
    }


    /**
     * Returns a ThemePreferences object containing the user's preferred theme for the given
     * datatype, if there is one.
     *
     * @param ODRUser $user
     * @param integer $datatype_id
     * @param string $theme_type
     *
     * @return ThemePreferences|null
     */
    public function getUserDefaultTheme($user, $datatype_id, $theme_type)
    {
        // If no user, then no user theme by extension
        if ($user === 'anon.')
            return null;


        // Determine which "class" of themes the user wants to see
        $theme_types = array();
        if ($theme_type == 'master' || $theme_type == 'custom_view') {
            // User wants themes that work on Display/Edit pages
            $theme_types = self::LONG_FORM_THEMETYPES;
        }
        else if ($theme_type == 'search_results' || $theme_type == 'table') {
            // User wants themes that work on Search Result pages
            $theme_types = self::SHORT_FORM_THEMETYPES;
        }
        else {
            throw new ODRBadRequestException('"'.$theme_type.'" is not a supported theme type', 0xfaedeca2);
        }


        // ----------------------------------------
        // Determine whether the user already has a preferred Theme for this "category"
        $query = $this->em->createQuery(
           'SELECT tp
            FROM ODRAdminBundle:ThemePreferences AS tp
            JOIN ODRAdminBundle:Theme AS t WITH tp.theme = t
            JOIN ODRAdminBundle:DataType AS dt WITH t.dataType = dt
            WHERE t.dataType = :datatype_id AND tp.createdBy = :user_id AND tp.isDefault = 1
            AND t.themeType IN (:theme_types)
            AND tp.deletedAt IS NULL AND t.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters(
            array(
                'datatype_id' => $datatype_id,
                'user_id' => $user->getId(),
                'theme_types' => $theme_types
            )
        );
        $result = $query->getResult();

        if ( count($result) == 0 )
            return null;

        // Should only be one...
        return $result[0];
    }


    /**
     * Sets the given Theme to be a default for the provided user.  Any other Themes in the same
     * "category" are marked as "not default".
     *
     * @param ODRUser $user
     * @param Theme $theme
     *
     * @throws ODRBadRequestException
     *
     * @return ThemePreferences
     */
    public function setUserDefaultTheme($user, $theme)
    {
        // Complain if this isn't a top-level theme
        if ($theme->getId() !== $theme->getParentTheme()->getId())
            throw new ODRBadRequestException('This should only be called on Themes of top-level Datatypes', 0x4f2519d6);


        // ----------------------------------------
        // Get which theme_types define a "category" of themes
        $theme_type = $theme->getThemeType();

        $theme_types = self::LONG_FORM_THEMETYPES;
        if ($theme_type == 'search_results' || $theme_type == 'table') {
            // User wants themes that work on Search Result pages
            $theme_types = self::SHORT_FORM_THEMETYPES;
        }

        // Determine whether the user already has a preferred Theme for this "category"
        $query = $this->em->createQuery(
           'SELECT tp
            FROM ODRAdminBundle:ThemePreferences AS tp
            JOIN ODRAdminBundle:Theme AS t WITH tp.theme = t
            JOIN ODRAdminBundle:DataType AS dt WITH t.dataType = dt
            WHERE t.dataType = :datatype_id AND tp.createdBy = :user_id AND tp.isDefault = 1
            AND t.themeType IN (:theme_types)
            AND tp.deletedAt IS NULL AND t.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters(
            array(
                'datatype_id' => $theme->getDataType()->getId(),
                'user_id' => $user->getId(),
                'theme_types' => $theme_types
            )
        );
        $results = $query->getResult();

        // If they do, then mark it as "not default", since there's going to be a new default...
        /** @var ThemePreferences $tp */
        foreach ($results as $tp) {
            $tp->setIsDefault(false);
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
        }

        // Mark this ThemePreference entry as the one the user wants to use
        $tp->setIsDefault(true);
        $tp->setUpdatedBy($user);

        // Done with the modifications
        $this->em->persist($tp);
        $this->em->flush();
        $this->em->refresh($tp);

        return $tp;
    }


    /**
     * Return a datatype's default Theme for this theme_type, as set by a datatype admin.  If the
     * datatype's "master" theme is desired, self::getDatatypeMasterTheme() should be used instead.
     *
     * @param integer $datatype_id
     * @param string $theme_type
     *
     * @return Theme
     */
    public function getDatatypeDefaultTheme($datatype_id, $theme_type = 'master')
    {
        // Ensure the provided theme_type is valid
        $theme_types = array();
        if ($theme_type == 'master' || $theme_type == 'custom_view') {
            // User wants themes that work on Display/Edit pages
            $theme_types = self::LONG_FORM_THEMETYPES;
        }
        else if ($theme_type == 'search_results' || $theme_type == 'table') {
            // User wants themes that work on Search Result pages
            $theme_types = self::SHORT_FORM_THEMETYPES;
        }
        else {
            throw new ODRBadRequestException('"'.$theme_type.'" is not a supported theme type', 0xb940fc66);
        }


        // Query the database for the default top-level theme for this datatype
        $query = $this->em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Theme AS t
            JOIN ODRAdminBundle:ThemeMeta AS tm WITH tm.theme = t
            WHERE t.dataType = :datatype_id AND tm.isDefault = :is_default
            AND t = t.parentTheme AND t.themeType IN (:theme_types)
            AND t.deletedAt IS NULL AND tm.deletedAt IS NULL'
        )->setParameters(
            array(
                'datatype_id' => $datatype_id,
                'is_default' => true,
                'theme_types' => $theme_types,
            )
        );
        $result = $query->getResult();

        //
        if ( !isset($result[0]) )
            // If no default theme for this theme_type exists, just return null
            return null;
        else
            // Otherwise, return the default theme
            return $result[0];
    }


    /**
     * Returns the given datatype's "master" theme.
     *
     * @param integer $datatype_id
     * @param integer $theme_element_id
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


        // Sanity check...
        if ( count($result) !== 1 ) {
            throw new ODRException('This datatype does not have a "master" theme', 500, 0x2e0a8d28);
        }
        else {
            // Return this datatype's master theme
            return $result[0];
        }
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
        $datatree_array = $this->dti_service->getDatatreeArray();

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
                trpi, partial rpi.{id}

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
        // ----------------------------------------
        // If list of top level themes exists in cache, return that
        $top_level_themes = $this->cache_service->get('top_level_themes');
        if ( $top_level_themes !== false && count($top_level_themes) > 0 )
            return $top_level_themes;


        // ----------------------------------------
        // Otherwise, rebuild the list of top-level themes
        $top_level_datatypes = $this->dti_service->getTopLevelDatatypes();

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
