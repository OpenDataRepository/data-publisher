<?php

/**
 * Open Data Repository Data Publisher
 * Clone Theme Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains the functions required to clone or sync a theme.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class CloneMasterTemplateThemeService
{

    /**
     * @var EntityManager $em
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var ThemeInfoService
     */
    private $theme_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * CloneThemeService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param PermissionsManagementService $pm_service
     * @param ThemeInfoService $theme_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        PermissionsManagementService $pm_service,
        ThemeInfoService $theme_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->pm_service = $pm_service;
        $this->theme_service = $theme_service;
        $this->logger = $logger;
    }


    /**
     * Saves and reloads the provided object from the database.
     *
     * @param mixed $obj
     * @param ODRUser $user
     * @param bool $delay_flush
     */
    private function persistObject($obj, $user, $delay_flush = false)
    {
        //
        if (method_exists($obj, "setCreated"))
            $obj->setCreated(new \DateTime());
        if (method_exists($obj, "setUpdated"))
            $obj->setUpdated(new \DateTime());

        //
        if ($user != null) {
            if (method_exists($obj, "setCreatedBy"))
                $obj->setCreatedBy($user);

            if (method_exists($obj, "setUpdatedBy"))
                $obj->setUpdatedBy($user);
        }

        $this->em->persist($obj);

        // TODO Double-negative - better as positive
        if (!$delay_flush) {
            $this->em->flush();
            $this->em->refresh($obj);
        }
    }


    /**
     * Returns true if the provided Theme is missing Datafields and/or child/linked Datatypes that
     * its source Theme has.  Also, the user needs to be capable of actually making changes to the
     * layout for this to return true.
     *
     * @param Theme $theme
     * @param ODRUser $user
     *
     * @return bool
     */
    public function canSyncTheme($theme, $user)
    {
        // ----------------------------------------
        // If the user isn't allowed to make changes to this theme, then it makes no sense to
        //  notify them that the theme is out of date...
        if ($user === 'anon.')
            return false;
        if ( $theme->getThemeType() === 'master' && !$this->pm_service->isDatatypeAdmin($user, $theme->getDataType()) )
            return false;
        if ( $theme->getThemeType() !== 'master' && $theme->getCreatedBy()->getId() !== $user->getId() )
            return false;


        // ----------------------------------------
        // Otherwise...for each theme that has the provided theme as its parent, check whether its
        //  source theme has had any Datafields or child/linked Datatypes added to it
        $query = $this->em->createQuery(
           'SELECT ct.id AS current_theme_id, ctm.sourceSyncVersion AS current_version,
                   st.id AS source_theme_id, stm.sourceSyncVersion AS source_version
            FROM ODRAdminBundle:Theme AS ct
            JOIN ODRAdminBundle:ThemeMeta AS ctm WITH ctm.theme = ct
            JOIN ODRAdminBundle:Theme AS st WITH ct.sourceTheme = st
            JOIN ODRAdminBundle:ThemeMeta AS stm WITH stm.theme = st
            WHERE ct.parentTheme = :theme_id
            AND ct.deletedAt IS NULL AND ctm.deletedAt IS NULL
            AND st.deletedAt IS NULL AND stm.deletedAt IS NULL'
        )->setParameters( array('theme_id' => $theme->getId()) );
        $results = $query->getArrayResult();

        foreach ($results as $result) {
            if ( intval($result['current_version']) !== intval($result['source_version']) )
                return true;
        }

        // Otherwise, no appreciable changes have been made...no need to synchronize
        return false;
    }


    /**
     * Returns the array of differences between the given Theme and its source Theme.
     *
     * The returned array is structured as follows...
     * $theme_diff_array = array(
     *     [$theme_id] => array(
     *         ['source_theme_id'] => $source_theme_id,
     *         ['new_datafields'] => array(
     *             [$datafield_id] => $source_theme_datafield_id,
     *             ...
     *         ),
     *         ['new_datatypes'] => array(
     *             [$datatype_id] => $source_theme_datatype_id,
     *             ...
     *         )
     *     ),
     *     ...
     * )
     *
     * The 'new_datafields' and 'new_datatypes' keys only exist if a ThemeDatafield entry or a
     * ThemeDatatype entry need to be created, respectively.
     *
     * @param Theme $theme
     *
     * @throws ODRBadRequestException
     *
     * @return array
     */
    public function getThemeSourceDiff($theme)
    {
        // ----------------------------------------
        // This isn't strictly true, but enforce it for consistency with self::syncThemeWithSource()
        if ($theme->getParentTheme()->getId() !== $theme->getId())
            throw new ODRBadRequestException('Themes for child Datatypes should not be checked for differences...check their parent Theme instead');

        // Logically, there should be no difference if a theme is its own source theme...but if the
        //  datatype is linked to other datatypes, then this datatype has copies of their themes
        //  that need to be checked for updates
        $source_theme = $theme->getSourceTheme();
//        if ($source_theme->getId() == $theme->getId())
//            return array('themes' => array());


        // ----------------------------------------
        $theme_diff_array = array();
        self::themeSourceDiffWorker($theme->getId(), $source_theme->getId(), $theme_diff_array);

        // The result is a list of the themeDatafield/themeDatatype entries that need to be created
        //  for this top-level theme to be considered synchronized
        return $theme_diff_array;
    }


    /**
     * Given the ids for a theme and its source theme, this function recursively builds an array
     * detailing which themeDatafield and themeDatatype entries need to cloned so the theme and its
     * child themes are synchronized with their source themes.
     *
     * @param integer $theme_id
     * @param integer $source_theme_id
     * @param array $theme_diff_array
     */
    private function themeSourceDiffWorker($theme_id, $source_theme_id, &$theme_diff_array)
    {
        // Need the input parameters as an array for the query...
        $theme_ids = array($theme_id, $source_theme_id);


        // ----------------------------------------
        // Get all ThemeDatafield and ThemeDatatype entries for both themes
        $query = $this->em->createQuery(
           'SELECT
                partial t.{id}, partial te.{id},
                partial tdf.{id}, partial df.{id},
                partial tdt.{id}, partial c_dt.{id}, partial c_t.{id}, partial c_s_t.{id}

            FROM ODRAdminBundle:Theme AS t
            LEFT JOIN t.themeElements AS te

            LEFT JOIN te.themeDataFields AS tdf
            LEFT JOIN tdf.dataField AS df

            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.dataType AS c_dt
            LEFT JOIN tdt.childTheme AS c_t
            LEFT JOIN c_t.sourceTheme AS c_s_t

            WHERE t IN (:theme_ids)'
        )->setParameters( array('theme_ids' => $theme_ids) );
        $results = $query->getArrayResult();

        // Compress the DQL result into a more manageable format
        $theme_array = array();
        foreach ($results as $num => $t) {
            $t_id = $t['id'];

            $theme_array[$t_id] = array(
                'theme_datafields' => array(),
                'theme_datatypes' => array(),
            );

            foreach ($t['themeElements'] as $te_num => $te) {
                if (isset($te['themeDataFields'])) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        $tdf_id = $tdf['id'];
                        $df_id = $tdf['dataField']['id'];

                        $theme_array[$t_id]['theme_datafields'][$df_id] = $tdf_id;
                    }
                }

                if (isset($te['themeDataType'])) {
                    foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                        $tdt_id = $tdt['id'];
                        $c_dt_id = $tdt['dataType']['id'];
                        $c_t_id = $tdt['childTheme']['id'];
                        $c_s_t_id = $tdt['childTheme']['sourceTheme']['id'];

                        $theme_array[$t_id]['theme_datatypes'][$c_dt_id] = array('tdt_id' => $tdt_id, 'c_t_id' => $c_t_id, 'c_s_t_id' => $c_s_t_id);
                    }
                }
            }
        }


        // ----------------------------------------
        // Determine which datafields and child/linked datatypes the theme needs to clone from its source
        $diff_array = array(
//            'source_theme_id' => $source_theme_id,
            'new_datafields' => array(),
            'new_datatypes' => array(),
        );

        foreach ($theme_array[$source_theme_id]['theme_datafields'] as $df_id => $tdf_id) {
            if ( !isset($theme_array[$theme_id]['theme_datafields'][$df_id]) )
                $diff_array['new_datafields'][$df_id] = $tdf_id;
        }
        foreach ($theme_array[$source_theme_id]['theme_datatypes'] as $dt_id => $tdt) {
            if ( !isset($theme_array[$theme_id]['theme_datatypes'][$dt_id]) )
                $diff_array['new_datatypes'][$dt_id] = $tdt['tdt_id'];
        }
        if ( count($diff_array['new_datafields']) == 0 )
            unset( $diff_array['new_datafields'] );
        if ( count($diff_array['new_datatypes']) == 0 )
            unset( $diff_array['new_datatypes'] );

        // If there actually are differences, store them in the array
        if ( count($diff_array) > 0 )
            $theme_diff_array[$theme_id] = $diff_array;


        // ----------------------------------------
        // Check that all themes for any child/linked datatypes are also up to date
        foreach ($theme_array[$theme_id]['theme_datatypes'] as $dt_id => $tdt) {
            $child_theme_id = $tdt['c_t_id'];
            $child_source_theme_id = $tdt['c_s_t_id'];

            self::themeSourceDiffWorker($child_theme_id, $child_source_theme_id, $theme_diff_array);
        }
    }


    /**
     * Synchronizes the provided top-level Theme and all its child Themes with their respective
     * source Themes.  self::getThemeSourceDiff() is run to get a list of which Datafields and
     * child/linked Datatypes are contained in the source Theme, but don't have corresponding
     * ThemeDatafield and ThemeDatatype entries in the provided Theme.
     *
     * If any Datafields in the source Theme are lacking ThemeDatafield entries in the provided Theme,
     * this function will attempt to locate an existing hidden/empty ThemeElement to clone them
     * into...if one of those doesn't exist, a new ThemeElement will be created for that purpose,
     * and automatically hidden afterwards.
     *
     * If the provided top-level Theme is missing any child/linked Datatype entries that are present
     * in the source Theme, this function will create a visually identical copy of the missing
     * entries and attach them to the provided Theme.
     *
     * This function doesn't need to worry about deleted Datafields/Datatypes, as the associated
     * ThemeDatafield/ThemeDatatype entries will have been deleted by the relevant controller
     * actions in DisplaytemplateController.
     *
     * ODR doesn't bother keeping track of the info needed to sync the display_order property.
     *
     * IMPORTANT: whatever calls this MUST update the sourceSyncVersion property of ALL Themes
     * where $theme is their parent
     *
     * @param ODRUser $user
     * @param Theme $theme  The theme to synchronize
     *
     * @throws ODRBadRequestException
     *
     * @return bool True if changes were made, false otherwise
     */
    public function syncThemeWithSource($user, $theme)
    {
        // ----------------------------------------
        // Prevent sync attempts when the user shouldn't be allowed...
        if ( $theme->getThemeType() === 'master' && !$this->pm_service->isDatatypeAdmin($user, $theme->getDataType()) )
            throw new ODRForbiddenException();
        if ( $theme->getThemeType() !== 'master' && $theme->getCreatedBy()->getId() !== $user->getId() )
            throw new ODRForbiddenException();


        // ----------------------------------------
        // Logically, there should be no difference if a theme is its own source theme...but if the
        //  datatype is linked to other datatypes, then this datatype has copies of their themes
        //  that need to be checked for updates
//        if ($parent_theme->getSourceTheme()->getId() === $parent_theme->getId())
//            throw new ODRBadRequestException('Synching a Theme with itself does not make sense');

        // Also no sense running this on a theme for a child datatype
        if ($theme->getParentTheme()->getId() !== $theme->getId())
            throw new ODRBadRequestException('Themes for child Datatypes should not be synchronized...run this on the parent Theme instead');


        // Get the diff between this Theme and its source
        $theme_diff_array = self::getThemeSourceDiff($theme);

        // No sense running this if there's nothing to sync
        if ( count($theme_diff_array) == 0 )
            return false;


        // ----------------------------------------
        // Going to need these repositories...
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
        $repo_theme_datafield = $this->em->getRepository('ODRAdminBundle:ThemeDataField');
        $repo_theme_datatype = $this->em->getRepository('ODRAdminBundle:ThemeDataType');


        $this->logger->info('----------------------------------------');
        $this->logger->info('CloneThemeService: attempting to synchronize theme '.$theme->getId().' with its source theme '.$theme->getSourceTheme()->getId());


        foreach ($theme_diff_array as $theme_id => $diff_array) {
            $this->logger->debug('----------------------------------------');

            /** @var Theme $current_theme */
            $current_theme = $repo_theme->find($theme_id);

            // If entries for datafields need to be created...
            if ( isset($diff_array['new_datafields']) ) {
                // ...attempt to locate an empty, hidden theme element
                $query = $this->em->createQuery(
                   'SELECT te
                    FROM ODRAdminBundle:ThemeElement AS te
                    JOIN ODRAdminBundle:ThemeElementMeta AS tem WITH tem.themeElement = te
                    JOIN ODRAdminBundle:Theme AS t WITH te.theme = t
                    WHERE t.id = :theme_id AND tem.hidden = :hidden
                    AND t.deletedAt IS NULL AND te.deletedAt IS NULL AND tem.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'theme_id' => $current_theme->getId(),
                        'hidden' => 1
                    )
                );
                $results = $query->getResult();

                $this->logger->debug('CloneThemeService: attempting to locate a theme element to copy themeDatafield entries into for theme '.$current_theme->getId().'...');

                $target_theme_element = null;
                if ( count($results) > 0 ) {
                    /** @var ThemeElement[] $results */
                    foreach ($results as $te) {
                        if ( count($te->getThemeDataFields()) == 0 && count($te->getThemeDataType()) == 0 ) {   // TODO - should a hidden theme element be selected if it's empty or has at least one tdf entry?
                            $target_theme_element = $te;
                            break;
                        }
                    }
                }

                // If an empty/hidden theme element doesn't exist in this theme...
                if ($target_theme_element == null) {
                    // ...create a new theme element
                    $target_theme_element = new ThemeElement();
                    $target_theme_element->setTheme($current_theme);

                    $current_theme->addThemeElement($target_theme_element);
                    self::persistObject($target_theme_element, $user);

                    // ...create a new meta entry for the new theme element
                    $new_tem = new ThemeElementMeta();
                    $new_tem->setThemeElement($target_theme_element);

                    $new_tem->setDisplayOrder(-1);
                    $new_tem->setHidden(1);
                    $new_tem->setCssWidthMed('1-1');
                    $new_tem->setCssWidthXL('1-1');

                    // Ensure the in-memory version of the new theme element knows about its meta entry
                    $target_theme_element->addThemeElementMetum($new_tem);
                    self::persistObject($new_tem, $user);

                    $this->logger->debug('CloneThemeService: -- created a new theme element '.$target_theme_element->getId());
                }
                else {
                    $this->logger->debug('CloneThemeService: -- found an existing theme element '.$target_theme_element->getId());
                }


                foreach ($diff_array['new_datafields'] as $df_id => $tdf_id) {
                    // Load the themeDatafield entry that needs to be cloned
                    /** @var ThemeDataField $theme_datafield */
                    $theme_datafield = $repo_theme_datafield->find($tdf_id);

                    // Clone the theme datafield entry
                    $new_theme_datafield = clone $theme_datafield;
                    $new_theme_datafield->setThemeElement($target_theme_element);
                    $new_theme_datafield->setDisplayOrder(999);

                    $target_theme_element->addThemeDataField($new_theme_datafield);
                    self::persistObject($new_theme_datafield, $user, true);    // These don't need to be flushed/refreshed immediately...

                    $this->logger->debug('CloneThemeService: -- -- cloned theme datafield '.$tdf_id.' for datafield '.$df_id.' "'.$theme_datafield->getDataField()->getFieldName().'"');
                }

                $this->em->flush();
            }

            // If entries for datatypes need to be created...
            if ( isset($diff_array['new_datatypes']) ) {
                foreach ($diff_array['new_datatypes'] as $dt_id => $tdt_id) {
                    // Load the themeDatatype entry that needs to be cloned
                    $this->logger->debug('CloneThemeService: cloning themeDatatype '.$tdt_id.' for child/linked datatype '.$dt_id.' into theme '.$current_theme->getId().'...');

                    /** @var ThemeDataType $source_theme_datatype */
                    $source_theme_datatype = $repo_theme_datatype->find($tdt_id);
                    $source_theme_element = $source_theme_datatype->getThemeElement();

                    // Going to need these so self::cloneIntoThemeElement() can load/set properties correctly
                    $child_datatype = $source_theme_datatype->getDataType();
                    $child_source_theme = $source_theme_datatype->getChildTheme()->getSourceTheme();

                    $theme_type = $theme->getThemeType();    // TODO - this doesn't feel right...


                    // ----------------------------------------
                    // Clone the theme element...
                    $new_theme_element = clone $source_theme_element;
                    $new_theme_element->setTheme($current_theme);

                    $theme->addThemeElement($new_theme_element);
                    self::persistObject($new_theme_element, $user);

                    // ...then the theme element's meta entry
                    $new_theme_element_meta = clone $source_theme_element->getThemeElementMeta();
                    $new_theme_element_meta->setHidden(1);
                    $new_theme_element_meta->setThemeElement($new_theme_element);

                    $new_theme_element->addThemeElementMetum($new_theme_element_meta);
                    self::persistObject($new_theme_element_meta, $user);

                    $this->logger->debug('CloneThemeService: -- created new theme element '.$new_theme_element->getId());


                    // ----------------------------------------
                    // Make a copy of $child_datatype's $child_source_theme into $new_theme_element
                    self::cloneIntoThemeElement($user, $new_theme_element, $child_source_theme, $child_datatype, $theme_type, $source_theme_datatype);  // should the themeDatatype be passed here?
                }
            }
        }


        // Mark the theme as updated
        $this->theme_service->updateThemeCacheEntry($theme, $user);

        // Also need to wipe any cached datatype data, otherwise themes for new child/linked
        //  datatypes won't show up
        $this->cache_service->delete('cached_datatype_'.$theme->getDataType()->getId());
        $this->cache_service->delete('associated_datatypes_for_'.$theme->getDataType()->getId());   // this is already a top-level theme for a grandparent datatype

        $this->logger->info('----------------------------------------');

        // Return that changes were made
        return true;
    }


    /**
     * This function creates a copy of an existing theme.  The only appreciable change is to the
     * cloned theme's theme_type (e.g. "master" theme -> "search_results" theme).  This only makes
     * sense when called on a top-level theme for a top-level datatype.
     *
     * Unlike self::syncThemeWithSource(), this function will ALWAYS create a new theme.
     *
     * @param ODRUser $user
     * @param Theme $source_theme
     * @param string $dest_theme_type
     * @param DataType[] $dest_datatype
     * @param DataFields[] $dest_datafields
     * @param bool $delay_flush
     * @param mixed $extra
     *
     * @throws ODRBadRequestException
     *
     * @return Theme
     */
    public function cloneSourceTheme(
        $user,
        $source_theme,
        $dest_theme_type,
        $dest_datatype = null,
        $dest_datafields = null,
        $delay_flush = false,
        $extra = array()
    ) {

        // Create a new theme for the top-level datatype
        /*
        if($dest_datatype != null) {
            $datatype = $dest_datatype;
        }
        else {
            $datatype = $source_theme->getDataType();
        }
        */

        $new_theme = clone $source_theme;
        $new_theme->setDataType($dest_datatype[$source_theme->getDataType()->getId()]);
        $new_theme->setSourceTheme( $source_theme->getSourceTheme() );
        $new_theme->setThemeType($dest_theme_type);
        // Need to flush/refresh before setting parent theme

        // TODO - this is not persisted ... Perhaps elsewhere?
        // $datatype->addTheme($new_theme);
        /** @var DataType[] $dest_datatype */
        $dest_datatype[$source_theme->getDataType()->getId()]->addTheme($new_theme);
        // self::persistObject($new_theme, $user, $delay_flush);

        $new_theme->setParentTheme($new_theme);
        // $this->em->persist($new_theme);

        // Also need to create a new ThemeMeta entry...
        $new_theme_meta = clone $source_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);
        $new_theme_meta->setTemplateName( 'Copy of '.$new_theme_meta->getTemplateName() );

        // Extra Options
        if(isset($extra['shared']) && !$extra['shared'])
            $new_theme_meta->setShared(false);

        if(isset($extra['is_default']) && !$extra['is_default'])
            $new_theme_meta->setIsDefault(false);

        $new_theme->addThemeMetum($new_theme_meta);


        // ----------------------------------------
        // Now that a theme exists, synchronize it with its source theme
        self::cloneThemeContents(
            $user,
            $source_theme,
            $new_theme,
            $dest_theme_type,
            $dest_datatype,
            $dest_datafields,
            $delay_flush
        );


        // self::persistObject($new_theme, $user, $delay_flush);
        // $this->logger->info('CloneThemeService: persisted theme ' . $new_theme->getId());

        // Return the newly created theme
        if(!$delay_flush) {
            // Ensure the cache entry is up to date
            $this->cache_service->delete('cached_theme_'.$new_theme->getId());
            $this->em->refresh($new_theme);
        }

        // We will only persist here - persisting all objects at once
        return $new_theme;
    }


    /**
     * Iterates through all of $source_theme, cloning the ThemeElements, ThemeDatafield, and
     * ThemeDatatype entries, and attaching them to $new_theme.
     *
     * @param ODRUser $user
     * @param Theme $source_theme
     * @param Theme $new_theme
     * @param string $dest_theme_type
     * @param bool $delay_flush
     */
    private function cloneThemeContents(
        $user,
        $source_theme,
        $new_theme,
        $dest_theme_type,
        $dest_datatype_array,
        $dest_fields_array,
        $delay_flush = false
    ) {
        // ----------------------------------------
        // For each theme element the source theme has...
        $theme_elements = $source_theme->getThemeElements();
        /** @var ThemeElement $source_te */
        foreach ($theme_elements as $source_te) {
            // ...create a new theme element
            /** @var ThemeElement $new_te */
            $new_te = clone $source_te;
            $new_te->setTheme($new_theme);

            // Ensure the "in-memory" representation of $new_theme knows about the new theme entry
            $new_theme->addThemeElement($new_te);
            // self::persistObject($new_te, $user, $delay_flush);

            // ...copy its meta entry
            $new_te_meta = clone $source_te->getThemeElementMeta();
            $new_te_meta->setThemeElement($new_te);

            // Ensure the "in-memory" representation of $new_te knows about its meta entry
            $new_te->addThemeElementMetum($new_te_meta);

            // Also clone each ThemeDatafield entry in each of these theme elements
            /** @var ThemeDataField[] $source_theme_df_array */
            $source_theme_df_array = $source_te->getThemeDataFields();
            foreach ($source_theme_df_array as $source_tdf) {
                $new_tdf = clone $source_tdf;
                $new_tdf->setThemeElement($new_te);

                $new_tdf->setDataField($dest_fields_array[$source_tdf->getDataField()->getId()]);

                // Ensure the "in-memory" version knows about the new theme_datafield entry
                $new_te->addThemeDataField($new_tdf);
            }

            // Also clone each ThemeDatatype entry in each of these theme elements
            /** @var ThemeDataType[] $source_theme_dt_array */
            $source_theme_dt_array = $source_te->getThemeDataType();
            foreach ($source_theme_dt_array as $source_tdt) {

                /** @var ThemeDataType $new_tdt */
                $new_tdt = clone $source_tdt;
                $new_tdt->setThemeElement($new_te);

                $new_tdt->setDataType($dest_datatype_array[$source_tdt->getDataType()->getId()]);

                /** @var Theme $tdt_theme */
                $tdt_theme = self::cloneSourceTheme(
                    $user,
                    $source_tdt->getChildTheme(),
                    $dest_theme_type,
                    // $source_tdt->getChildTheme()->getDataType(),
                    $dest_datatype_array,  // $source_tdt->getDataType(),
                    $dest_fields_array,
                    $delay_flush
                );

                $new_tdt->setChildTheme($tdt_theme);
                $new_te->addThemeDataType($new_tdt);

                /*
                // Going to need these so self::cloneIntoThemeElement() can load/set properties correctly
                $child_datatype = $source_tdt->getDataType();
                $child_source_theme = $source_tdt->getChildTheme();

                // Make a copy of $child_datatype's $child_source_theme into $new_te
                // TODO Why doesn't this use the same call as cloneThemeFromSource?
                self::cloneIntoThemeElement(
                    $user,
                    $new_te,
                    $child_source_theme,
                    $child_datatype,
                    $dest_theme_type,
                    $source_tdt,
                    $delay_flush
                );
                */

            }
        }
    }


    /**
     *
     * @deprecated
     *
     * Clones the provided Theme and its ThemeElements, ThemeDatafields, ThemeDatatypes, and Meta
     * entries, and attaches the clone to the specified datatype under the specified theme_type.
     *
     * This will ALWAYS create a new Theme.
     *
     * @param ODRUser $user
     * @param ThemeElement $theme_element Which ThemeElement the new themeDatatype goes into
     * @param Theme $source_theme Which Theme this should copy from
     * @param Datatype $dest_datatype Which child/linked Datatype the themeDatatype should point to
     * @param string $dest_theme_type @see ThemeInfoService::VALID_THEMETYPES
     * @param ThemeDataType|null If null, this function will create a new ThemeDatatype...if not,
     *                           then this function will clone the given ThemeDatatype
     * @param bool $delay_flush
     *
     * @throws ODRBadRequestException
     *
     * @return Theme
     */
    public function cloneIntoThemeElement($user, $theme_element, $source_theme, $dest_datatype, $dest_theme_type, $source_theme_datatype = null, $delay_flush = false)
    {
        // ----------------------------------------
        // $this->logger->debug('----------------------------------------');
        // $this->logger->info('CloneThemeService: cloning source theme '.$source_theme->getId().' (datatype '.$dest_datatype->getId().' "'.$dest_datatype->getShortName().'") into theme_element '.$theme_element->getId().' of theme '.$theme_element->getTheme()->getId().' (datatype '.$theme_element->getTheme()->getDataType()->getId().')...');

        // Need to create a new Theme, ThemeMeta, and ThemeDatatype entry
        $new_theme = new Theme();
        $new_theme->setDataType($dest_datatype);
        $new_theme->setThemeType($dest_theme_type);

        $new_theme->setSourceTheme( $source_theme->getSourceTheme() );
        $new_theme->setParentTheme( $theme_element->getTheme()->getParentTheme() );


        // Ensure the "in-memory" version of $dest_datatype knows about its new theme
        // TODO - This is never persisted ... perhaps elsewhere?
        $dest_datatype->addTheme($new_theme);
        // TODO This persists only the new theme
        // self::persistObject($new_theme, $user, $delay_flush);

        // Clone the source theme's meta entry
        $new_theme_meta = clone $source_theme->getThemeMeta();
        $new_theme_meta->setTemplateName( 'Copy of '.$new_theme_meta->getTemplateName() );
        if ($dest_theme_type == 'master') {
            $new_theme_meta->setShared(true);
            $new_theme_meta->setIsDefault(true);
        }
        else {
            $new_theme_meta->setShared(false);
            $new_theme_meta->setIsDefault(false);
        }
        $new_theme_meta->setTheme($new_theme);

        // Ensure the "in-memory" representation of $new_theme knows about the new theme meta entry
        $new_theme->addThemeMetum($new_theme_meta);
        self::persistObject($new_theme_meta, $user, $delay_flush);

        // $this->logger->info('CloneThemeService: created new theme '.$new_theme->getId().' "'.$dest_theme_type.'"...datatype set to '.$new_theme->getDataType()->getId().', source theme set to '.$new_theme->getSourceTheme()->getId().', parent theme set to '.$new_theme->getParentTheme()->getId());

        if(!$delay_flush)
            $this->em->refresh($new_theme);

        // ----------------------------------------
        // Create a new themeDatatype entry to point to the child/linked datatype + new Theme
        $new_theme_datatype = null;

        if ( is_null($source_theme_datatype) ) {
            // There's no ThemeDatatype entry to clone (theoretically only when creating a link to
            //  some remote datatype), so just create a new ThemeDatatype entry
            $new_theme_datatype = new ThemeDataType();

            $new_theme_datatype->setDisplayType(0);    // Default to accordion layout
            $new_theme_datatype->setHidden(0);         // Default to "not-hidden"

            // $logger_msg = 'created new';
        }
        else {
            // There's an existing ThemeDatatype entry to clone
            /** @var ThemeDataType $source_theme_datatype */
            $new_theme_datatype = clone $source_theme_datatype;

            // $logger_msg = 'cloned existing theme_datatype '.$source_theme_datatype->getId().' to create';
        }

        $new_theme_datatype->setDataType($dest_datatype);
        $new_theme_datatype->setThemeElement($theme_element);
        $new_theme_datatype->setChildTheme($new_theme);
        // Store the new TDT Entry
        self::persistObject($new_theme_datatype, $user, $delay_flush);

        // Add the entry to the ThemeElement and persist
        $theme_element->addThemeDataType($new_theme_datatype);
        self::persistObject($theme_element, $user, $delay_flush);

        if(!$delay_flush)
            $this->em->refresh($new_theme_datatype);


        // ----------------------------------------
        // For each theme element the source theme has...
        self::cloneThemeContents($user, $source_theme, $new_theme, $dest_theme_type, $delay_flush);


        // ----------------------------------------
        // Ensure the relevant cache entry is deleted
        if(!$delay_flush)
            $this->cache_service->delete('cached_theme_'.$new_theme->getParentTheme()->getId());

        // $this->logger->debug('CloneThemeService: finished cloning source theme '.$source_theme->getId().' (datatype '.$dest_datatype->getId().' "'.$dest_datatype->getShortName().'") into theme_element '.$theme_element->getId().' of theme '.$theme_element->getTheme()->getId().' (datatype '.$theme_element->getTheme()->getDataType()->getId().')...');
        // $this->logger->debug('----------------------------------------');

        // Return the new theme
        return $new_theme;
    }
}
