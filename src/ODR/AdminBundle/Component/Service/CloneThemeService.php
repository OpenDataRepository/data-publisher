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
use ODR\AdminBundle\Entity\ThemeRenderPluginInstance;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class CloneThemeService
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
     * @var LockService
     */
    private $lock_service;

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
     * @param LockService $lock_service
     * @param PermissionsManagementService $permissions_service
     * @param ThemeInfoService $theme_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        LockService $lock_service,
        PermissionsManagementService $permissions_service,
        ThemeInfoService $theme_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->lock_service = $lock_service;
        $this->pm_service = $permissions_service;
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

/*
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
*/
        // TODO - dig through all of ODR and ensure the version numbers are getting updated correctly
        $tmp = self::getThemeSourceDiff($theme);
        if ( count($tmp) > 0 )
            return true;

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
        // Get all ThemeDatafield, ThemeDatatype, and ThemeRenderPluginInstance entries for both themes
        $query = $this->em->createQuery(
           'SELECT
                partial t.{id}, partial te.{id},
                partial tdf.{id}, partial df.{id},
                partial tdt.{id}, partial c_dt.{id}, partial c_t.{id}, partial c_s_t.{id},
                partial trpi.{id}, partial rpi.{id}

            FROM ODRAdminBundle:Theme AS t
            LEFT JOIN t.themeElements AS te

            LEFT JOIN te.themeDataFields AS tdf
            LEFT JOIN tdf.dataField AS df

            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.dataType AS c_dt
            LEFT JOIN tdt.childTheme AS c_t
            LEFT JOIN c_t.sourceTheme AS c_s_t

            LEFT JOIN te.themeRenderPluginInstance AS trpi
            LEFT JOIN trpi.renderPluginInstance as rpi

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
                'theme_renderPluginInstances' => array(),
            );

            foreach ($t['themeElements'] as $te_num => $te) {
                if ( isset($te['themeDataFields']) ) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        // Ignore themeDatafield entries for deleted datafields
                        if ( !is_null($tdf['dataField']) ) {
                            $tdf_id = $tdf['id'];
                            $df_id = $tdf['dataField']['id'];

                            $theme_array[$t_id]['theme_datafields'][$df_id] = $tdf_id;
                        }
                    }
                }

                if ( isset($te['themeDataType']) ) {
                    foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                        // Ignore themeDatatype entries for deleted datatypes
                        if ( !is_null($tdt['dataType']) ) {
                            $tdt_id = $tdt['id'];
                            $c_dt_id = $tdt['dataType']['id'];
                            $c_t_id = $tdt['childTheme']['id'];
                            $c_s_t_id = $tdt['childTheme']['sourceTheme']['id'];

                            $theme_array[$t_id]['theme_datatypes'][$c_dt_id] = array('tdt_id' => $tdt_id, 'c_t_id' => $c_t_id, 'c_s_t_id' => $c_s_t_id);
                        }
                    }
                }

                if ( isset($te['themeRenderPluginInstance']) ) {
                    foreach ($te['themeRenderPluginInstance'] as $rpi_num => $trpi) {
                        // Ignore themeRenderPluginInstance entries for deleted renderPluginInstances
                        if ( !is_null($trpi['renderPluginInstance']) ) {
                            $trpi_id = $trpi['id'];
                            $rpi_id = $trpi['renderPluginInstance']['id'];

                            $theme_array[$t_id]['theme_renderPluginInstances'][$rpi_id] = array('trpi_id' => $trpi_id);
                        }
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
            'new_renderplugininstances' => array(),

            'copy_theme_structure' => true,
        );

        // For each themeDatafield in the source theme...
        foreach ($theme_array[$source_theme_id]['theme_datafields'] as $df_id => $tdf_id) {
            if ( !isset($theme_array[$theme_id]['theme_datafields'][$df_id]) ) {
                // ...if the local theme doesn't have an entry for this datafield, one will need to
                //  get created
                $diff_array['new_datafields'][$df_id] = $tdf_id;
            }
            else {
                // If the local theme does have an entry for this datafield, then a user might have
                //  modified the local theme...any datafields that need to get created have to go
                //  into a new themeElement
                $diff_array['copy_theme_structure'] = false;
            }
        }

        // For each themeDatatype in the source theme...
        foreach ($theme_array[$source_theme_id]['theme_datatypes'] as $dt_id => $tdt) {
            if ( !isset($theme_array[$theme_id]['theme_datatypes'][$dt_id]) ) {
                // ...if the local theme doesn't have an entry for this child/linked datatype, one
                //  will need to get created
                $diff_array['new_datatypes'][$dt_id] = $tdt['tdt_id'];
            }
        }

        // For each themeRenderPluginInstance in the source theme...
        foreach ($theme_array[$source_theme_id]['theme_renderPluginInstances'] as $rpi_id => $trpi) {
            if ( !isset($theme_array[$theme_id]['theme_renderPluginInstances'][$rpi_id]) ) {
                // ...if the local theme doesn't have an entry for this renderPluginInstance, one
                //  will need to get created
                $diff_array['new_renderplugininstances'][$rpi_id] = $trpi['trpi_id'];
            }
        }

        // If any of these three types of entries are empty, then drop them out of the array so the
        //  later parts of this process don't need to worry about them
        if ( empty($diff_array['new_datafields']) ) {
            unset( $diff_array['new_datafields'] );
            unset( $diff_array['copy_theme_structure'] );
        }
        if ( empty($diff_array['new_datatypes']) ) {
            unset($diff_array['new_datatypes']);
        }
        if ( empty($diff_array['new_renderplugininstances']) ) {
            unset($diff_array['new_renderplugininstances']);
        }

        // If there actually are differences, store them in the array
        if ( !empty($diff_array) )
            $theme_diff_array[$theme_id] = $diff_array;


        // ----------------------------------------
        // Also need to verify that the themes for child/linked datatypes are up-to-date
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


        // Bad Things (tm) happen if multiple processes attempt to synchronize the same theme at
        //  the same time, so use Symfony's LockHandler component to prevent that...
        $lockHandler = $this->lock_service->createLock('theme_'.$theme->getId().'_sync.lock', 900.0);    // acquire lock for 15 minutes?
        if ( !$lockHandler->acquire() ) {
            // Another process is already synchronizing this theme...block until it's done...
            $lockHandler->acquire(true);
            // ...then abort the synchronization without duplicating any changes
            return false;
        }


        // ----------------------------------------
        // Going to need these repositories...
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
        $repo_theme_datatype = $this->em->getRepository('ODRAdminBundle:ThemeDataType');
        $repo_theme_render_plugin_instance = $this->em->getRepository('ODRAdminBundle:ThemeRenderPluginInstance');

        $this->logger->info('----------------------------------------');
        $this->logger->info('CloneThemeService: attempting to synchronize theme '.$theme->getId().' with its source theme '.$theme->getSourceTheme()->getId());


        // For each theme that has a difference...
        foreach ($theme_diff_array as $theme_id => $diff_array) {
            /** @var Theme $current_theme */
            $current_theme = $repo_theme->find($theme_id);

            // If the theme being synchronized doesn't have any user modifications, then the sizes
            //  and positions of the various theme entities should be cloned...otherwise, any new
            //  datafields/datatypes need to be inserted into a hidden theme_element
            $copy_theme_structure = false;
            if ( isset($diff_array['copy_theme_structure']) )
                $copy_theme_structure = $diff_array['copy_theme_structure'];


            // If entries for datafields need to be created...
            if ( isset($diff_array['new_datafields']) ) {
                // This function isn't called recursively, so indent is always 1 here
                $indent = 1;
                if ($copy_theme_structure)
                    self::copyDatafieldStructure($current_theme, $user, $indent);
                else
                    self::attachAdditionalDatafields($diff_array, $current_theme, $user, $indent);
            }

            // If entries for renderPluginInstances need to be created...
            if ( isset($diff_array['new_renderplugininstances']) ) {
                foreach ($diff_array['new_renderplugininstances'] as $rpi_id => $trpi_id) {
                    // Load the themeRenderPluginInstance entry that needs to be cloned
                    /** @var ThemeRenderPluginInstance $source_trpi */
                    $source_trpi = $repo_theme_render_plugin_instance->find($trpi_id);
                    $source_theme_element = $source_trpi->getThemeElement();
                    $source_rpi = $source_trpi->getRenderPluginInstance();

                    $this->logger->debug('CloneThemeService: -- cloning themeRenderPluginInstance '.$trpi_id.' for renderPluginInstance '.$rpi_id.' "'.$source_rpi->getRenderPlugin()->getPluginName().'" into theme '.$current_theme->getId().'...');


                    // ----------------------------------------
                    // Create a new theme element...DO NOT CLONE, because that also forces a doctrine
                    //  load of the source themeElement's themeDatafield list
                    $new_theme_element = new ThemeElement();
                    $new_theme_element->setTheme($current_theme);

                    $theme->addThemeElement($new_theme_element);
                    self::persistObject($new_theme_element, $user, true);    // don't flush immediately

                    // ...then the theme element's meta entry
                    $new_theme_element_meta = clone $source_theme_element->getThemeElementMeta();
                    $new_theme_element_meta->setHidden(1);
                    $new_theme_element_meta->setThemeElement($new_theme_element);

                    $new_theme_element->addThemeElementMetum($new_theme_element_meta);
                    self::persistObject($new_theme_element_meta, $user, true);    // don't flush immediately

                    $this->logger->debug('CloneThemeService: -- -- created new theme element');


                    // ----------------------------------------
                    // Clone the themeRenderPluginInstance entry
                    $new_trpi = new ThemeRenderPluginInstance();
                    $new_trpi->setThemeElement($new_theme_element);
                    $new_trpi->setRenderPluginInstance($source_rpi);

                    $new_theme_element->addThemeRenderPluginInstance($new_trpi);
                    self::persistObject($new_trpi, $user, true);    // don't flush immediately

                    $this->logger->debug('CloneThemeService: -- -- created new theme renderPluginInstance');
                }
            }

            // If entries for datatypes need to be created...
            if ( isset($diff_array['new_datatypes']) ) {
                foreach ($diff_array['new_datatypes'] as $dt_id => $tdt_id) {
                    // Load the themeDatatype entry that needs to be cloned
                    /** @var ThemeDataType $source_theme_datatype */
                    $source_theme_datatype = $repo_theme_datatype->find($tdt_id);
                    $source_theme_element = $source_theme_datatype->getThemeElement();

                    // Going to need these so self::cloneIntoThemeElement() can load/set properties correctly
                    $child_datatype = $source_theme_datatype->getDataType();
                    $child_source_theme = $source_theme_datatype->getChildTheme()->getSourceTheme();

                    $this->logger->debug('CloneThemeService: -- cloning themeDatatype '.$tdt_id.' for child/linked datatype '.$dt_id.' "'.$child_datatype->getShortName().'" into theme '.$current_theme->getId().'...');
                    $theme_type = $theme->getThemeType();


                    // ----------------------------------------
                    // Create a new theme element...DO NOT CLONE, because that also forces a doctrine
                    //  load of the source themeElement's themeDatafield list
                    $new_theme_element = new ThemeElement();
                    $new_theme_element->setTheme($current_theme);

                    $theme->addThemeElement($new_theme_element);
                    self::persistObject($new_theme_element, $user, true);    // don't flush immediately

                    // ...then the theme element's meta entry
                    $new_theme_element_meta = clone $source_theme_element->getThemeElementMeta();
                    $new_theme_element_meta->setHidden(1);
                    $new_theme_element_meta->setThemeElement($new_theme_element);

                    $new_theme_element->addThemeElementMetum($new_theme_element_meta);
                    self::persistObject($new_theme_element_meta, $user, true);    // don't flush immediately
                    $this->logger->debug('CloneThemeService: -- -- created new theme element');


                    // Make a copy of $child_datatype's $child_source_theme into $new_theme_element
                    // This function isn't called recursively, so indent should always be 2 here
                    $indent = 2;
                    self::cloneIntoThemeElement($user, $new_theme_element, $child_source_theme, $child_datatype, $theme_type, $source_theme_datatype, $indent);
                }
            }
        }

        // Should be okay to flush by now
        $this->em->flush();


        // Mark the theme as updated
        $this->theme_service->updateThemeCacheEntry($theme, $user);

        // Also need to wipe any cached datatype data, otherwise themes for new child/linked
        //  datatypes won't show up
        $this->cache_service->delete('cached_datatype_'.$theme->getDataType()->getId());
        $this->cache_service->delete('associated_datatypes_for_'.$theme->getDataType()->getId());   // this is already a top-level theme for a grandparent datatype

        $this->logger->info('CloneThemeService: finished synchronizing theme '.$theme->getId().' with its source theme '.$theme->getSourceTheme()->getId());
        $this->logger->info('----------------------------------------');

        // Can release the lock on the theme cloning now
        $lockHandler->release();

        // Return that changes were made
        return true;
    }


    /**
     * In cases where the theme doesn't have any datafields, this process can copy the layout
     * straight from the source theme.
     *
     * @param Theme $current_theme
     * @param ODRUser $user
     * @param int $indent
     */
    private function copyDatafieldStructure($current_theme, $user, $indent)
    {
        // Debugging assistance on recursive functions...
        $indent_text = '';
        for ($i = 0; $i < $indent; $i++)
            $indent_text .= ' --';

        // For each themeDatafield entry in this theme's source...
        $source_theme = $current_theme->getSourceTheme();
        foreach ($source_theme->getThemeElements() as $te) {
            /** @var ThemeElement $te */
            $tdf_list = $te->getThemeDataFields();
            if ( count($tdf_list) > 0 ) {
                // Create a copy of this themeElement from the source theme

                // Create a new theme element...DO NOT CLONE, because that also forces a doctrine load
                //  of the source themeElement's themeDatafield list
                $new_te = new ThemeElement();
                $new_te->setTheme($current_theme);

                $current_theme->addThemeElement($new_te);
                self::persistObject($new_te, $user, true);    // don't flush immediately

                $new_te_meta = clone $te->getThemeElementMeta();
                $new_te_meta->setThemeElement($new_te);

                $new_te->addThemeElementMetum($new_te_meta);
                self::persistObject($new_te_meta, $user, true);    // don't flush immediately

                $this->logger->debug('CloneThemeService:'.$indent_text.' cloned theme_element '.$te->getId().' into derived theme '.$current_theme->getId());


                // ----------------------------------------
                // Now clone each datafield in the theme element...
                foreach ($tdf_list as $num => $tdf) {
                    /** @var ThemeDataField $tdf */

                    // Clone the existing theme datafield entry
                    $new_tdf = clone $tdf;
                    $new_tdf->setThemeElement($new_te);

                    $new_te->addThemeDataField($new_tdf);
                    self::persistObject($new_tdf, $user, true);    // don't flush immediately

                    $this->logger->debug('CloneThemeService:'.$indent_text.' -- cloned theme_datafield entry for datafield '.$tdf->getDataField()->getId().' "'.$tdf->getDataField()->getFieldName().'"');
                }
            }
            else {
                // This themeElement doesn't contain any datafields...ignore it, since this function
                //  only deals with datafields
            }
        }

        // Don't want to flush here
//        $this->em->flush();
    }


    /**
     * If a theme being synchronized already has at least datafield, then it's impossible to know
     * whether a user has already customized the layout somehow...since clobbering their changes is
     * unacceptable, any new themeDatafield entries should instead be attached to a newly-created
     * hidden ThemeElement.
     *
     * @param array $diff_array {@link self::getThemeSourceDiff()}
     * @param Theme $current_theme
     * @param ODRUser $user
     * @param int $indent
     */
    private function attachAdditionalDatafields($diff_array, $current_theme, $user, $indent)
    {
        // Debugging assistance on recursive functions...
        $indent_text = '';
        for ($i = 0; $i < $indent; $i++)
            $indent_text .= ' --';

        // Going to need this for later...
        $repo_theme_datafield = $this->em->getRepository('ODRAdminBundle:ThemeDataField');


        // ----------------------------------------
        // Don't bother searching for an empty themeElement...even if there's one hanging around,
        //  using it could screw up the user's preferred layout somehow

        // ...instead, always create a new theme element
        $target_theme_element = new ThemeElement();
        $target_theme_element->setTheme($current_theme);

        $current_theme->addThemeElement($target_theme_element);
        self::persistObject($target_theme_element, $user, true);    // don't flush immediately

        // ...create a new meta entry for the new theme element
        $new_tem = new ThemeElementMeta();
        $new_tem->setThemeElement($target_theme_element);

        $new_tem->setDisplayOrder(-1);
        $new_tem->setHidden(1);
        $new_tem->setHideBorder(false);
        $new_tem->setCssWidthMed('1-1');
        $new_tem->setCssWidthXL('1-1');

        // Ensure the in-memory version of the new theme element knows about its meta entry
        $target_theme_element->addThemeElementMetum($new_tem);
        self::persistObject($new_tem, $user, true);    // don't flush immediately

        $this->logger->debug('CloneThemeService:'.$indent_text.' created a new theme element');


        // ----------------------------------------
        // Now, for each datafield that the local theme is missing...
        foreach ($diff_array['new_datafields'] as $df_id => $tdf_id) {
            // ...load the missing themeDatafield entry...
            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $repo_theme_datafield->find($tdf_id);

            // ...so it can get cloned
            $new_theme_datafield = clone $theme_datafield;
            $new_theme_datafield->setThemeElement($target_theme_element);
            $new_theme_datafield->setDisplayOrder(999);

            $target_theme_element->addThemeDataField($new_theme_datafield);
            self::persistObject($new_theme_datafield, $user, true);    // These don't need to be flushed/refreshed immediately...

            $this->logger->debug('CloneThemeService:'.$indent_text.' -- cloned theme datafield '.$tdf_id.' for datafield '.$df_id.' "'.$theme_datafield->getDataField()->getFieldName().'"');
        }

        // Don't want to flush here
//        $this->em->flush();
    }


    /**
     * This function creates a copy of an existing theme, with the only real change being to its
     * theme_type (e.g. "master" theme -> "custom" theme).  This only makes sense when called on a
     * top-level theme for a top-level datatype.
     *
     * Unlike self::syncThemeWithSource(), this function will ALWAYS create a new theme.
     *
     * @param ODRUser $user
     * @param Theme $source_theme
     * @param string $dest_theme_type {@link ThemeInfoService::THEME_TYPES}
     *
     * @return Theme
     */
    public function cloneSourceTheme($user, $source_theme, $dest_theme_type)
    {
        // ----------------------------------------
        // If the source theme does not belong to a top-level datatype, then refuse to clone
        if ($source_theme->getId() !== $source_theme->getParentTheme()->getId())
            throw new ODRBadRequestException("Don't clone a child Datatype's Theme...any cloning or synchronizing must start from a top-level Theme");

        // ...also verify that the given theme_type is valid
        if ( !in_array($dest_theme_type, ThemeInfoService::THEME_TYPES) )
            throw new ODRBadRequestException('Invalid theme_type given to CloneThemeService::cloneSourceTheme()');

        // ...also make some attempt to prevent duplicate "master" themes
//        if ($dest_theme_type == 'master')
//            throw new ODRBadRequestException('Datatypes should only have one "master" theme');


        // Going to create a new theme for this top-level datatype
        $datatype = $source_theme->getDataType();


        $this->logger->info('----------------------------------------');
        $this->logger->info('CloneThemeService: attempting to make a clone of theme '.$source_theme->getId().', belonging to datatype '.$source_theme->getDataType()->getId().' "'.$source_theme->getDataType()->getShortName().'"...');


        // ----------------------------------------
        // Copy the top-level theme...
        $new_theme = clone $source_theme;
        $new_theme->setDataType($datatype);
        $new_theme->setSourceTheme( $source_theme->getSourceTheme() );
        $new_theme->setThemeType($dest_theme_type);

        $datatype->addTheme($new_theme);
        self::persistObject($new_theme, $user);    // Need to flush/refresh before setting parent theme

        $new_theme->setParentTheme($new_theme);
        $this->em->persist($new_theme);

        // ...and its ThemeMeta entry
        $new_theme_meta = clone $source_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);
        $new_theme_meta->setTemplateName( 'Copy of '.$new_theme_meta->getTemplateName() );
        $new_theme_meta->setShared(false);
        $new_theme_meta->setDefaultFor(0);

        $new_theme->addThemeMetum($new_theme_meta);
        self::persistObject($new_theme_meta, $user);    // think it also needs flushed here

        $this->logger->debug('CloneThemeService: -- created a new theme '.$new_theme->getId().' ('.$dest_theme_type.')');


        // ----------------------------------------
        // Now that a theme exists, synchronize it with its source theme
        // Since this function isn't called recursively, indent should always be 1 here
        $indent = 1;
        self::cloneThemeContents($user, $source_theme, $new_theme, $dest_theme_type, $indent);

        // Ensure everything is flushed
        $this->em->flush();


        // ----------------------------------------
        // Don't need to mark the new theme as updated, but need to ensure it doesn't exist in a
        //  partially cached state
        $this->cache_service->delete('cached_theme_'.$new_theme->getId());
        $this->logger->info('----------------------------------------');

        // Return the newly created theme
        $this->em->refresh($new_theme);
        return $new_theme;
    }


    /**
     * Iterates through all of $source_theme, cloning the ThemeElements, ThemeDatafield, and
     * ThemeDatatype entries, and attaching them to $new_theme.
     *
     * @param ODRUser $user
     * @param Theme $source_theme
     * @param Theme $new_theme
     * @param string $dest_theme_type {@link ThemeInfoService::THEME_TYPES}
     * @param int $indent
     */
    private function cloneThemeContents($user, $source_theme, $new_theme, $dest_theme_type, $indent = 0)
    {
        // Debugging assistance on recursive functions...
        $indent_text = '';
        for ($i = 0; $i < $indent; $i++)
            $indent_text .= ' --';

        // ----------------------------------------
        // For each theme element the source theme has...
        $theme_elements = $source_theme->getThemeElements();
        /** @var ThemeElement[] $theme_elements */
        $theme_element_ids = array();
        foreach($theme_elements as $te)
            $theme_element_ids[] = $te->getId();

        $this->logger->debug('CloneThemeService:'.$indent_text.' Need to copy theme elements from theme ' .$source_theme->getId(). ': ['.join(',', $theme_element_ids). ']'  );

        foreach ($theme_elements as $source_te) {
            /** @var ThemeElement $source_te */
            // Create a new theme element...DO NOT CLONE, because that also forces a doctrine load
            //  of the source themeElement's themeDatafield list
            $new_te = new ThemeElement();
            $new_te->setTheme($new_theme);

            // Ensure the "in-memory" representation of $new_theme knows about the new theme entry
            $new_theme->addThemeElement($new_te);
            self::persistObject($new_te, $user, true);    // don't flush immediately

            // ...copy its meta entry
            $new_te_meta = clone $source_te->getThemeElementMeta();
            $new_te_meta->setThemeElement($new_te);

            // Ensure the "in-memory" representation of $new_te knows about its meta entry
            $new_te->addThemeElementMetum($new_te_meta);
            self::persistObject($new_te_meta, $user, true);    // don't flush immediately

            $this->logger->debug('CloneThemeService:'.$indent_text.' -- copied theme_element '.$source_te->getId().' from source theme '.$source_theme->getId().' into a new theme_element for the theme '.$new_theme->getId() );


            // If the source themeElement has themeDatafield entries...
            /** @var ThemeDataField[] $source_theme_df_array */
            $source_theme_df_array = $source_te->getThemeDataFields();
            foreach ($source_theme_df_array as $source_tdf) {
                // ...then need to clone each of them
                $new_tdf = clone $source_tdf;
                $new_tdf->setThemeElement($new_te);

                // Ensure the "in-memory" version knows about the new theme_datafield entry
                $new_te->addThemeDataField($new_tdf);
                self::persistObject($new_tdf, $user, true);    // These don't need to be flushed/refreshed immediately...

                $df = $new_tdf->getDataField();
                $this->logger->debug('CloneThemeService:'.$indent_text.' -- -- copied theme_datafield '.$source_tdf->getId().' for datafield '.$df->getId().' "'.$df->getFieldName().'"');
            }


            // If the source themeElement has themeRenderPluginInstance entries...
            /** @var ThemeRenderPluginInstance[] $source_theme_rpi_array */
            $source_theme_rpi_array = $source_te->getThemeRenderPluginInstance();
            foreach ($source_theme_rpi_array as $source_trpi) {
                // ...then need to clone each of those
                $new_trpi = clone $source_trpi;
                $new_trpi->setThemeElement($new_te);

                // Ensure the "in-memory" version knows about the new theme_renderPluginInstance entry
                $new_te->addThemeRenderPluginInstance($new_trpi);
                self::persistObject($new_trpi, $user, true);    // These don't need to be flushed/refreshed immediately...

                $rpi = $new_trpi->getRenderPluginInstance();
                $this->logger->debug('CloneThemeService:'.$indent_text.' -- -- copied theme_renderPluginInstance '.$source_trpi->getId().' for renderPluginInstance '.$rpi->getId().', for the RenderPlugin "'.$rpi->getRenderPlugin()->getPluginName().'"');
            }

//            $this->em->flush();


            // If the source themeElement has themeDatatype entries...
            /** @var ThemeDataType[] $source_theme_dt_array */
            $source_theme_dt_array = $source_te->getThemeDataType();
            foreach ($source_theme_dt_array as $source_tdt) {
                // ...then going to need these so self::cloneIntoThemeElement() can work correctly
                $child_datatype = $source_tdt->getDataType();
                $child_source_theme = $source_tdt->getChildTheme();

                // Make a copy of $child_datatype's $child_source_theme into $new_te
                self::cloneIntoThemeElement($user, $new_te, $child_source_theme, $child_datatype, $dest_theme_type, $source_tdt, ($indent+2));

                $this->logger->debug('CloneThemeService:'.$indent_text.' -- -- attached child theme '.$source_tdt->getChildTheme()->getId().' (child_datatype '.$child_datatype->getId().') to theme_datatype '.$source_tdt->getId());
            }
        }
    }


    /**
     * This function clones the child/linked $dest_dataype's master theme and attaches it into the
     * given $theme_element as the specified theme_type.
     *
     * This will ALWAYS create a new Theme.
     *
     * @param ODRUser $user
     * @param ThemeElement $theme_element Which ThemeElement the new themeDatatype goes into
     * @param Theme $source_theme Which Theme this should copy from
     * @param Datatype $dest_datatype Which child/linked Datatype the themeDatatype should point to
     * @param string $dest_theme_type {@link ThemeInfoService::THEME_TYPES}
     * @param ThemeDataType|null $source_theme_datatype If null, this function will create a new
     *                                                  ThemeDatatype...if not, then this function
     *                                                  will clone the given ThemeDatatype
     * @param int $indent
     *
     * @return Theme
     */
    public function cloneIntoThemeElement($user, $theme_element, $source_theme, $dest_datatype, $dest_theme_type, $source_theme_datatype = null, $indent = 0)
    {
        // Debugging assistance on recursive functions...
        $indent_text = '';
        for ($i = 0; $i < $indent; $i++)
            $indent_text .= ' --';

        // ----------------------------------------
        $source_datatype = $theme_element->getTheme()->getDataType();
        $this->logger->debug('CloneThemeService:'.$indent_text.' ----------------------------------------');
        $this->logger->info('CloneThemeService:'.$indent_text.' cloning source theme '.$source_theme->getId().' (datatype '.$dest_datatype->getId().' "'.$dest_datatype->getShortName().'") into theme_element '.$theme_element->getId().' of theme '.$theme_element->getTheme()->getId().' (datatype '.$source_datatype->getId().' "'.$source_datatype->getShortName().'")...');

        // Need to create a new Theme, ThemeMeta, and ThemeDatatype entry
        $new_theme = new Theme();
        $new_theme->setDataType($dest_datatype);
        $new_theme->setThemeType($dest_theme_type);

        $new_theme->setSourceTheme( $source_theme->getSourceTheme() );
        $new_theme->setParentTheme( $theme_element->getTheme()->getParentTheme() );


        // Ensure the "in-memory" version of $dest_datatype knows about its new theme
        $dest_datatype->addTheme($new_theme);
        self::persistObject($new_theme, $user);

        // Clone the source theme's meta entry
        $new_theme_meta = clone $source_theme->getThemeMeta();
        $new_theme_meta->setTemplateName( 'Copy of '.$new_theme_meta->getTemplateName() );
        if ($dest_theme_type == 'master') {
            $new_theme_meta->setShared(true);
            $new_theme_meta->setDefaultFor(0);    // Don't want to clone this value
        }
        else {
            $new_theme_meta->setShared(false);
            $new_theme_meta->setDefaultFor(0);
        }
        $new_theme_meta->setTheme($new_theme);

        // Ensure the "in-memory" representation of $new_theme knows about the new theme meta entry
        $new_theme->addThemeMetum($new_theme_meta);
        self::persistObject($new_theme_meta, $user);

        $this->logger->info('CloneThemeService:'.$indent_text.' -- created new theme '.$new_theme->getId().' "'.$dest_theme_type.'"...datatype set to '.$new_theme->getDataType()->getId().', source theme set to '.$new_theme->getSourceTheme()->getId().', parent theme set to '.$new_theme->getParentTheme()->getId());
        $this->em->refresh($new_theme);


        // ----------------------------------------
        // Create a new themeDatatype entry to point to the child/linked datatype + new Theme
        $new_theme_datatype = null;
        $logger_msg = '';

        if ( is_null($source_theme_datatype) ) {
            // There's no ThemeDatatype entry to clone (theoretically only when creating a link to
            //  some remote datatype), so just create a new ThemeDatatype entry
            $new_theme_datatype = new ThemeDataType();
            $new_theme_datatype->setHidden(0);
            $new_theme_datatype->setDisplayType(ThemeDataType::ACCORDION_HEADER);

            $logger_msg = 'created new';
        }
        else {
            // There's an existing ThemeDatatype entry to clone
            /** @var ThemeDataType $source_theme_datatype */
            $new_theme_datatype = clone $source_theme_datatype;

            $logger_msg = 'cloned existing theme_datatype '.$source_theme_datatype->getId().' to create';
        }

        $new_theme_datatype->setDataType($dest_datatype);
        $new_theme_datatype->setThemeElement($theme_element);
        $new_theme_datatype->setChildTheme($new_theme);

        $theme_element->addThemeDataType($new_theme_datatype);
        self::persistObject($new_theme_datatype, $user);    // probably should flush here...

        $this->logger->info('CloneThemeService:'.$indent_text.' -- '.$logger_msg.' theme_datatype '.$new_theme_datatype->getId().'...datatype set to '.$dest_datatype->getId().', child theme set to '.$new_theme->getId());
        $this->em->refresh($new_theme_datatype);


        // ----------------------------------------
        // Now that the themeElement exists, clone the child/linked datatype into it
        self::cloneThemeContents($user, $source_theme, $new_theme, $dest_theme_type, ($indent+2));

        // Ensure everything is flushed
        $this->em->flush();


        // ----------------------------------------
        // Ensure the relevant cache entry is deleted
        $this->cache_service->delete('cached_theme_'.$new_theme->getParentTheme()->getId());

        $this->logger->debug('CloneThemeService:'.$indent_text.' finished cloning source theme '.$source_theme->getId().' (datatype '.$dest_datatype->getId().' "'.$dest_datatype->getShortName().'") into theme_element '.$theme_element->getId().' of theme '.$theme_element->getTheme()->getId().' (datatype '.$theme_element->getTheme()->getDataType()->getId().')...');
        $this->logger->debug('CloneThemeService:'.$indent_text.' ----------------------------------------');

        // Return the new theme
        return $new_theme;
    }
}
