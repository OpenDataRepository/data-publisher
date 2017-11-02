<?php

/**
 * Open Data Repository Data Publisher
 * Theme Info Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO
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
     * @param DatatypeInfoService $dti_service
     * @param Session $session
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatypeInfoService $dti_service,
        Session $session,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dti_service = $dti_service;
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
     * @return array
     */
    public function getAvailableThemes($user, $datatype, $theme_type = "master")
    {
        // TODO -
        $theme_types = array();
        if ($theme_type == 'master') {
            $theme_types[] = 'master';
            $theme_types[] = 'custom_view';
        }
        else {
            $theme_types[] = $theme_type;
            $theme_types[] = 'custom_'.$theme_type;
        }

        // Get all themes for this datatype that fulfill the previous criteria
        $query = $this->em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Theme AS t
            JOIN ODRAdminBundle:ThemeMeta AS tm WITH tm.theme = t
            WHERE t.dataType = :datatype_id AND t.themeType IN (:theme_types)
            AND t.deletedAt IS NULL
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
                );

                array_push($filtered_themes, $theme_record);
            }
        }

        return $filtered_themes;
    }


    /**
     * Given a top-level datatype id, this function figures out the ids of the themes the user
     * would prefer to use for all of the associated datatypes, and then returns their cached
     * entries back to the caller.
     *
     * Note that the keys of the returned array are datatype_ids, not theme_ids.
     *
     * @param integer $grandparent_datatype_id
     * @param ODRUser $user
     * @param string $theme_type
     * @param bool $include_links
     *
     * @return array
     */
    public function getThemesForDatatype($grandparent_datatype_id, $user, $theme_type = 'master', $include_links = true)
    {
        $associated_datatypes = array();
        if ($include_links) {
            // Need to locate all linked datatypes for the provided datatype
            $associated_datatypes = $this->cache_service->get('associated_datatypes_for_'.$grandparent_datatype_id);
            if ($associated_datatypes == false) {
                $associated_datatypes = $this->dti_service->getAssociatedDatatypes( array($grandparent_datatype_id) );

                // Save the list of associated datatypes back into the cache
                $this->cache_service->set('associated_datatypes_for_'.$grandparent_datatype_id, $associated_datatypes);
            }
        }
        else {
            // Don't want any datatypes that are linked to from the given grandparent datatype
            $associated_datatypes[] = $grandparent_datatype_id;
        }

        // Now that there's a list of the datatypes that we need themes for...
        $top_level_themes = self::getTopLevelThemes();
        $parent_theme_ids = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            // ...figure out which theme the user is using for this datatype
            $parent_theme_ids[] = self::getPreferredTheme($user, $dt_id, $theme_type, $top_level_themes);
        }

        // Now that the themes are known, return the cached theme array entries for those themes
        return self::getThemeArray($parent_theme_ids);
    }


    /**
     * Attempts to return the id of the user's preferred theme for the given datatype/theme_type.
     *
     * @param ODRUser $user
     * @param integer $datatype_id
     * @param string $theme_type
     * @param array $top_level_themes
     *
     * @return int|null
     */
    public function getPreferredTheme($user, $datatype_id, $theme_type, $top_level_themes = null)
    {
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
        // TODO - throw an error instead?
        $theme = self::getDatatypeDefaultTheme($datatype_id, 'master');
        if ($theme != null)
            return $theme->getId();

        // ...if there's not even a master theme for this datatype, something is horribly wrong
        throw new ODRException('Unable to locate master theme for datatype '.$datatype_id);
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
        // If the user has specified a theme for their current session...
        if ( $this->session->has('session_themes') ) {
            // ...see if a theme is stored for this session for this datatype
            $session_themes = $this->session->get('session_themes');

            if (isset($session_themes[$datatype_id])
                && isset($session_themes[$datatype_id][$theme_type])
            ) {
                return $session_themes[$datatype_id][$theme_type];
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
        // TODO
        $theme_type = $theme->getThemeType();
        if ($theme->getThemeType() == "custom_view")
            $theme_type = "master";
        else
            $theme_type = preg_replace('/^custom_/','', $theme_type);

        // Load any existing session themes
        $session_themes = array();
        if ( $this->session->has('session_themes') )
            $session_themes = $this->session->get('session_themes');

        // Save the theme choice in the session
        $session_themes[$datatype_id][$theme_type] = $theme->getId();
        $this->session->set('session_themes', $session_themes);
    }


    /**
     * Clears the user's preferred theme for this datatype for this session.
     *
     * @param integer $datatype_id
     * @param string $theme_type
     */
    public function resetSessionTheme($datatype_id, $theme_type)
    {
        // TODO
        if ($theme_type == "custom_view")
            $theme_type = "master";
        else
            $theme_type = preg_replace('/^custom_/','', $theme_type);

        // Load any existing session themes
        $session_themes = array();
        if ( $this->session->has('session_themes') )
            $session_themes = $this->session->get('session_themes');

        // Unset the theme for this session if it exists
        if (isset($session_themes[$datatype_id])
            && isset($session_themes[$datatype_id][$theme_type])
        ) {
            unset( $session_themes[$datatype_id][$theme_type] );
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

        // ----------------------------------------
        // Get which theme_types define a "category" of themes
        $theme_types = array();
        if ($theme_type == 'master' || $theme_type == 'custom_view') {
            $theme_types[] = 'master';
            $theme_types[] = 'custom_view';
        }
        else {
            $theme_types[] = $theme_type;
            $theme_types[] = 'custom_'.$theme_type;
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
            throw new ODRBadRequestException('This should only be called on Themes of top-level Datatypes');


        // ----------------------------------------
        // Get which theme_types define a "category" of themes
        $theme_types = array();
        if ($theme->getThemeType() == 'master' || $theme->getThemeType() == 'custom_view') {
            $theme_types[] = 'master';
            $theme_types[] = 'custom_view';
        }
        else {
            $theme_types[] = $theme->getThemeType();
            $theme_types[] = 'custom_'.$theme->getThemeType();
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
     * Return the default Theme for this theme_type set by a datatype admin TODO
     *
     * @param integer $datatype_id
     * @param string $theme_type
     *
     * @return Theme
     */
    public function getDatatypeDefaultTheme($datatype_id, $theme_type = 'master')
    {
        // TODO
        $theme_types = array();
        if ($theme_type == 'master') {
            $theme_types[] = 'master';
            $theme_types[] = 'custom_view';
        }
        else {
            $theme_types[] = $theme_type;
        }

        //
        $query = $this->em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Theme AS t
            JOIN ODRAdminBundle:ThemeMeta AS tm WITH tm.theme = t
            WHERE t.dataType = :datatype_id AND tm.isDefault = :is_default
            AND t.themeType IN (:theme_types)
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
     * Loads and returns a cached data array for the specified theme ids.  Note that the keys for
     * the returned array are theme_ids, unlike in self::getThemesForDatatype() where the keys are
     * datatype_ids.
     * 
     * @param int[] $parent_theme_ids
     * 
     * @return array
     */
    public function getThemeArray($parent_theme_ids)
    {
        // Themes are stored by
        $theme_array = array();
        foreach ($parent_theme_ids as $num => $parent_theme_id) {
            // Attempt to the cached version of this theme
            $theme_data = $this->cache_service->get('cached_theme_'.$parent_theme_id);

            // If the requested entry doesn't exist, rebuild it
            if ($theme_data == false)
                $theme_data = self::buildthemeData($parent_theme_id);

            // Organize by theme id
            foreach ($theme_data as $parent_theme_id => $data)
                $theme_array[$parent_theme_id] = $data;
        }

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
/*
        $timing = true;
        $timing = false;

        $t0 = $t1 = $t2 = null;
        if ($timing)
            $t0 = microtime(true);
*/
        // This function is only called when the cache entry doesn't exist

        // Going to need the datatree array to rebuild this cache entry
        $datatree_array = $this->dti_service->getDatatreeArray();

        // Get all the data for the requested theme
        $query = $this->em->createQuery(
           'SELECT
                t, tm, t_cb, t_ub,
                dt,
                te, tem,
                tdf, df,
                tdt, c_dt
                
            FROM ODRAdminBundle:Theme AS t
            LEFT JOIN t.themeMeta AS tm
            LEFT JOIN t.createdBy AS t_cb
            LEFT JOIN t.updatedBy AS t_ub
            
            LEFT JOIN t.dataType AS dt
            
            LEFT JOIN t.themeElements AS te
            LEFT JOIN te.themeElementMeta AS tem

            LEFT JOIN te.themeDataFields AS tdf
            LEFT JOIN tdf.dataField AS df

            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.dataType AS c_dt
            
            WHERE t.parentTheme = :parent_theme_id
            AND t.deletedAt IS NULL AND te.deletedAt IS NULL
            
            ORDER BY tem.displayOrder, te.id, tdf.displayOrder, df.id'
        )->setParameters( array('parent_theme_id' => $parent_theme_id) );

        $theme_data = $query->getArrayResult();

/*
        if ($timing) {
            $t1 = microtime(true);
            $diff = $t1 - $t0;
            print 'buildThemeData('.$theme_id.')'."\n".'query execution in: '.$diff."\n";
        }
*/

        // The entity -> entity_metadata relationships have to be one -> many from a database
        // perspective, even though there's only supposed to be a single non-deleted entity_metadata
        // object for each entity.  Therefore, the preceding query generates an array that needs
        // to be somewhat flattened in a few places.
        foreach ($theme_data as $theme_num => $theme) {
            // Flatten theme meta
            $theme_meta = $theme['themeMeta'][0];
            $theme_data[$theme_num]['themeMeta'] = $theme_meta;

            // Scrub irrelevant data from the theme's createdBy and updatedBy properties
            $theme_data[$theme_num]['createdBy'] = UserUtility::cleanUserData( $theme['createdBy'] );
            $theme_data[$theme_num]['updatedBy'] = UserUtility::cleanUserData( $theme['updatedBy'] );

            // Only want to keep the id of this theme's datatype?
            $dt_id = $theme_data[$theme_num]['dataType']['id'];
            $theme_data[$theme_num]['dataType'] = array('id' => $dt_id);


            // ----------------------------------------
            // Theme elements are ordered, so preserve $te_num
            $new_te_array = array();
            foreach ($theme['themeElements'] as $te_num => $te) {
                // Flatten theme_element_meta of each theme_element
                $tem = $te['themeElementMeta'][0];
                $te['themeElementMeta'] = $tem;

                // theme_datafield entries are ordered, so preserve $tdf_num
                foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                    // Only want to preserve the id of the datafield
                    $df_id = $tdf['dataField']['id'];

                    $te['themeDataFields'][$tdf_num]['dataField'] = array('id' => $df_id);
                }

                //
                foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                    // Only want to preserve the id of the child/linked datatype
                    $child_dt_id = $tdt['dataType']['id'];
                    $te['themeDataType'][$tdt_num]['dataType'] = array('id' => $child_dt_id);

                    $te['themeDataType'][$tdt_num]['is_link'] = 0;
                    $te['themeDataType'][$tdt_num]['multiple_allowed'] = 0;

                    // Don't need to check the 'descendant_of' segment of the datatree array...

                    if ( isset($datatree_array['linked_from']) && isset($datatree_array['linked_from'][$child_dt_id]) ) {
                        // This child is a linked datatype somewhere...figure out whether the
                        //  parent datatype in question actually links to it
                        $parents = $datatree_array['linked_from'][$child_dt_id];
                        if ( in_array($dt_id, $parents) )
                            $te['themeDataType'][$tdt_num]['is_link'] = 1;
                    }

                    if ( isset($datatree_array['multiple_allowed']) && isset($datatree_array['multiple_allowed'][$child_dt_id]) ) {
                        // TODO
                        $parents = $datatree_array['multiple_allowed'][$child_dt_id];
                        if ( in_array($dt_id, $parents) )
                            $te['themeDataType'][$tdt_num]['multiple_allowed'] = 1;
                    }
                }

                // Easier on twig if these arrays simply don't exist if nothing is in them...
                if ( count($te['themeDataFields']) == 0 )
                    unset( $te['themeDataFields'] );
                if ( count($te['themeDataType']) == 0 )
                    unset( $te['themeDataType'] );

                $new_te_array[$te_num] = $te;
            }

            unset( $theme_data[$theme_num]['themeElements'] );
            $theme_data[$theme_num]['themeElements'] = $new_te_array;
        }

        // Organize by datatype id
        $formatted_theme_data = array();
        foreach ($theme_data as $num => $t_data) {
//            $t_id = $t_data['id'];
//            $formatted_theme_data[$t_id] = $t_data;

            $dt_id = $t_data['dataType']['id'];
            $formatted_theme_data[$dt_id] = $t_data;
        }

/*
        if ($timing) {
            $t1 = microtime(true);
            $diff = $t2 - $t1;
            print 'buildThemeData('.$theme_id.')'."\n".'array formatted in: '.$diff."\n";
        }
*/
//exit( '<pre>'.print_r($formatted_theme_data, true).'</pre>' );

        // Save the formatted datarecord data back in the cache, and return it
        $this->cache_service->set('cached_theme_'.$parent_theme_id, $formatted_theme_data);
        return $formatted_theme_data;
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
            WHERE t.dataType IN (:datatype_ids)
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
