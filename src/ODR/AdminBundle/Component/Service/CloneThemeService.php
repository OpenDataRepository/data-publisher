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
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
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
     * @param ThemeInfoService $theme_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        ThemeInfoService $theme_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->theme_service = $theme_service;
        $this->logger = $logger;
    }


    /**
     * Saves and reloads the provided object from the database.
     *
     * @param mixed $obj
     * @param ODRUser|null $user
     */
    private function persistObject($obj, $user = null)
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
        $this->em->flush();
        $this->em->refresh($obj);
    }


    /**
     * Returns true if it makes sense to sync the provided Theme with its source.
     *
     * @param Theme $theme
     *
     * @return bool
     */
    public function canSyncTheme($theme)
    {
        // Need to compute the difference between the Theme and its source to know whether it makes
        //  sense to sync the two Themes...
        $diff = self::getThemeSourceDiff($theme);

        // If there's at least once ThemeDatafield or ThemeDatatype entry to create, return true
        if ( count($diff['themes']) > 0 )
            return true;
        else
            return false;
    }


    /**
     * Returns an array of differences between this theme and its source.
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

        // By definition, there's no difference if the theme is its own source
        $source_theme = $theme->getSourceTheme();
        if ($source_theme->getId() == $theme->getId())
            return array('themes' => array());


        // ----------------------------------------
        // Get a list of all ThemeDatafield and ThemeDatatype ids that exist in the source Theme
        $query = $this->em->createQuery(
            'SELECT
                t, te,
                tdf, df,
                tdt, c_dt

            FROM ODRAdminBundle:Theme AS t
            LEFT JOIN t.themeElements AS te
            LEFT JOIN te.themeDataFields AS tdf
            LEFT JOIN tdf.dataField AS df
            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.dataType AS c_dt
            
            WHERE t.parentTheme = :theme_id'
        )->setParameters( array('theme_id' => $source_theme->getParentTheme()->getId()) );
        $results = $query->getArrayResult();

        $source_theme_datafields = array();
        $source_theme_datatypes = array();
        foreach ($results as $t_num => $t) {
            $theme_id = $t['id'];

            foreach ($t['themeElements'] as $te_num => $te) {
                if ( isset($te['themeDataFields']) ) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        $df_id = $tdf['dataField']['id'];
                        $source_theme_datafields[$df_id] = $theme_id;
                    }
                }

                if ( isset($te['themeDataType']) ) {
                    foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                        $dt_id = $tdt['dataType']['id'];
                        $source_theme_datatypes[$dt_id] = $theme_id;
                    }
                }
            }
        }

        // Get a list of all ThemeDatafield and ThemeDatatype ids that exist in the source Theme
        $query = $this->em->createQuery(
            'SELECT
                t, te,
                tdf, df,
                tdt, c_dt

            FROM ODRAdminBundle:Theme AS t
            LEFT JOIN t.themeElements AS te
            LEFT JOIN te.themeDataFields AS tdf
            LEFT JOIN tdf.dataField AS df
            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.dataType AS c_dt
            
            WHERE t.parentTheme = :theme_id'
        )->setParameters( array('theme_id' => $theme->getParentTheme()->getId()) );
        $results = $query->getArrayResult();

        foreach ($results as $t_num => $t) {
            foreach ($t['themeElements'] as $te_num => $te) {
                if ( isset($te['themeDataFields']) ) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        $df_id = $tdf['dataField']['id'];
                        if ( isset($source_theme_datafields[$df_id]) )
                            unset($source_theme_datafields[$df_id]);
                    }
                }

                if ( isset($te['themeDataType']) ) {
                    foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                        $dt_id = $tdt['dataType']['id'];
                        if ( isset($source_theme_datatypes[$dt_id]) )
                            unset($source_theme_datatypes[$dt_id]);
                    }
                }
            }
        }


        // ----------------------------------------
        // Redo the difference array so it makes more sense
        $themes = array();
        foreach ($source_theme_datafields as $df_id => $t_id) {
            if ( !isset($themes[$t_id]) )
                $themes[$t_id] = array();
            if ( !isset($themes[$t_id]['new_datafields']) )
                $themes[$t_id]['new_datafields'] = array();

            $themes[$t_id]['new_datafields'][] = $df_id;
        }
        foreach ($source_theme_datatypes as $dt_id => $t_id) {
            if ( !isset($themes[$t_id]) )
                $themes[$t_id] = array();
            if ( !isset($themes[$t_id]['new_datatypes']) )
                $themes[$t_id]['new_datatypes'] = array();

            $themes[$t_id]['new_datatypes'][] = $dt_id;
        }

        //
        return array('themes' => $themes);
    }


    /**
     * Syncs the provided top-level Theme and all its child Themes with their source Themes,
     * by adding ThemeDatafield entries for new Datafields and ThemeDatatype entries for new
     * child/linked datatypes.
     *
     * There will only be new ThemeDatatype entries when a new child/linked datatype was
     * added...a new ThemeDatatype entry for the new child/linked datatype will be attached
     * to a new ThemeElement.  If the new datatype is not linked, then its master theme will
     * get cloned as well.
     *
     * After taking care of the ThemeDatatype entries, any remaining new datafields will
     * get new ThemeDatatype entries attached to the first ThemeElement in the destination
     * Theme.
     *
     * This function doesn't need to worry about deleted Datafields/Datatypes, as the associated
     * ThemeDatafield/ThemeDatatype entries will have been deleted by the relevant controller
     * actions in DisplaytemplateController.
     *
     * ODR doesn't bother keeping track of the info needed to sync the display_order property.
     *
     * @param ODRUser $user
     * @param Theme $parent_theme
     *
     * @throws ODRBadRequestException
     *
     * @return bool True if changes were made, false otherwise
     */
    public function syncThemeWithSource($user, $parent_theme)
    {
        // ----------------------------------------
        // No sense running this if the theme is its own source
        if ($parent_theme->getSourceTheme()->getId() === $parent_theme->getId())
            throw new ODRBadRequestException('Synching a Theme with itself does not make sense');

        // Also no sense running this on a theme for a child datatype
        if ($parent_theme->getParentTheme()->getId() !== $parent_theme->getId())
            throw new ODRBadRequestException('Themes for child Datatype should not be synced...sync the parent Theme instead');


        // ----------------------------------------
        // Get the diff between this Theme and its source
        $diff = self::getThemeSourceDiff($parent_theme);


        // No sense running this if there's nothing to sync
        if ( count($diff['themes']) == 0 )
            return false;


        $this->logger->debug('----------------------------------------');
        $this->logger->debug('CloneThemeService: attempting to sync theme '.$parent_theme->getId().' with its source theme '.$parent_theme->getSourceTheme()->getId());


        // Going to need this repository...
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');

        // Ensure the themeDatatype entries are up to date first...missing themeDatafield entries
        //  may get created in the process
        foreach ($diff['themes'] as $theme_id => $data) {
            /** @var Theme $theme */
            $theme = $repo_theme->findOneBy(
                array(
                    'sourceTheme' => $theme_id,
                    'parentTheme' => $parent_theme->getId(),
                )
            );

            if ($theme == null) {
                // $parent_theme contain a theme for this child datatype...load the master theme for
                //  said child datatype
                /** @var Theme $source_theme */
                $source_theme = $repo_theme->find($theme_id);

                // Copy the theme for the child datatype and store it under $parent_theme
                self::cloneParentTheme($user, $source_theme, $source_theme->getDataType(), $parent_theme->getThemeType(), $parent_theme);
            }
            else {
                // $source_theme theme is a theme for child datatype that belongs to $parent_theme's
                //  group of themes...need to check whether new themeDatafield/themeDatatype entries
                //  need to be created
                $this->logger->debug('----------------------------------------');

                if ( isset($data['new_datafields']) ) {
                    // Need to create themeDatafield entries inside $source_theme

                    // Locate the first visible theme element containing datafields for this theme
                    $query = $this->em->createQuery(
                       'SELECT tdf
                        FROM ODRAdminBundle:ThemeDataField AS tdf
                        JOIN ODRAdminBundle:ThemeElement AS te WITH tdf.themeElement = te
                        JOIN ODRAdminBundle:ThemeElementMeta AS tem WITH tem.themeElement = te
                        WHERE te.theme = :theme_id AND tem.hidden = :hidden
                        AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL AND tem.deletedAt IS NULL
                        ORDER BY tem.displayOrder, tdf.id'
                    )->setParameters(
                        array(
                            'theme_id' => $theme->getId(),
                            'hidden' => 0
                        )
                    );
                    $results = $query->getResult();

                    $this->logger->debug('CloneThemeService: attempting to locate a theme element to copy themeDatafield entries into for theme '.$theme->getId().'...');

                    $target_theme_element = null;
                    if ( count($results) > 0 ) {
                        /** @var ThemeDataField $tdf */
                        $tdf = $results[0];
                        $target_theme_element = $tdf->getThemeElement();
                    }

                    // If the theme element doesn't exist for some reason...
                    if ($target_theme_element == null) {
                        // ...create a new theme element
                        $target_theme_element = new ThemeElement();
                        $target_theme_element->setTheme($theme);

                        $theme->addThemeElement($target_theme_element);
                        self::persistObject($target_theme_element);

                        // ...create a new meta entry for the new theme element
                        $new_tem = new ThemeElementMeta();
                        $new_tem->setThemeElement($target_theme_element);

                        $new_tem->setDisplayOrder(-1);
                        $new_tem->setHidden(0);
                        $new_tem->setCssWidthMed('1-1');
                        $new_tem->setCssWidthXL('1-1');

                        $target_theme_element->addThemeElementMetum($new_tem);
                        self::persistObject($new_tem);

                        $this->logger->debug('CloneThemeService: -- created a new theme element '.$target_theme_element->getId());
                    }
                    else {
                        $this->logger->debug('CloneThemeService: -- found an existing new theme element '.$target_theme_element->getId());
                    }


                    foreach ($data['new_datafields'] as $num => $datafield_id) {
                        // Locate the themeDatafield entry that needs to be cloned
                        $query = $this->em->createQuery(
                           'SELECT tdf
                            FROM ODRAdminBundle:ThemeDataField AS tdf
                            JOIN ODRAdminBundle:ThemeElement AS te WITH tdf.themeElement = te
                            WHERE tdf.dataField = :datafield_id AND te.theme = :theme_id
                            AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL'
                        )->setParameters(
                            array(
                                'datafield_id' => $datafield_id,
                                'theme_id' => $theme_id,
                            )
                        );
                        $result = $query->getResult();

                        $this->logger->debug('CloneThemeService: attempting to locate themeDatafield entry based on datafield '.$datafield_id.' inside theme '.$theme->getId().'...');

                        /** @var ThemeDataField $theme_datafield */
                        $theme_datafield = $result[0];

                        // Clone the theme datafield entry
                        $new_theme_datafield = clone $theme_datafield;
                        $new_theme_datafield->setThemeElement($target_theme_element);
                        $new_theme_datafield->setDisplayOrder(999);

                        $target_theme_element->addThemeDataField($new_theme_datafield);
                        self::persistObject($new_theme_datafield);

                        $this->logger->debug('CloneThemeService: -- created new theme datafield '.$new_theme_datafield->getId());
                    }
                }

                if ( isset($data['new_datatypes']) ) {
                    // Need to create new themeDatatype entries inside $source_theme

                    foreach ($data['new_datatypes'] as $num => $child_datatype_id) {
                        // Locate the themeDatatype entry that needs to be cloned
                        $query = $this->em->createQuery(
                           'SELECT tdt
                            FROM ODRAdminBundle:ThemeDatatype AS tdt
                            JOIN ODRAdminBundle:ThemeElement AS te WITH tdt.themeElement = te
                            WHERE tdt.dataType = :child_datatype_id AND te.theme = :theme_id
                            AND tdt.deletedAt IS NULL AND te.deletedAt IS NULL'
                        )->setParameters(
                            array(
                                'child_datatype_id' => $child_datatype_id,
                                'theme_id' => $theme_id,
                            )
                        );
                        $result = $query->getResult();

                        $this->logger->debug('CloneThemeService: attempting to locate themeDatatype entry based on child/linked datatype '.$child_datatype_id.' inside theme '.$theme->getId().'...');

                        /** @var ThemeDataType $theme_datatype */
                        $theme_datatype = $result[0];

                        // Clone the theme element first...
                        $new_theme_element = clone $theme_datatype->getThemeElement();
                        $new_theme_element->setTheme($theme);

                        $theme->addThemeElement($new_theme_element);
                        self::persistObject($new_theme_element, $user);


                        // Clone the theme element's meta entry next...
                        $new_theme_element_meta = clone $theme_datatype->getThemeElement()->getThemeElementMeta();
                        $new_theme_element_meta->setThemeElement($new_theme_element);

                        $new_theme_element->addThemeElementMetum($new_theme_element_meta);
                        self::persistObject($new_theme_element_meta);


                        // Clone the theme datatype entry last
                        $new_theme_datatype = clone $theme_datatype;
                        $new_theme_datatype->setThemeElement($new_theme_element);

                        $new_theme_element->addThemeDataType($new_theme_datatype);
                        self::persistObject($new_theme_datatype);

                        $this->logger->debug('CloneThemeService: -- created new theme element '.$new_theme_element->getId().', new theme datatype '.$new_theme_datatype->getId());
                    }
                }
            }
        }

        // Mark the theme as updated
        $this->theme_service->updateThemeCacheEntry($parent_theme, $user);

        // Return that changes were made
        return true;
    }


    /**
     * This function creates a copy of some existing theme in a datatype
     * (e.g. "master" theme -> "search_results" theme).  It will only work when called on a theme
     * for a top-level datatype, and will ALWAYS create a new theme for the destination datatype.
     *
     * @param ODRUser $user
     * @param Theme $source_theme
     * @param string $dest_theme_type
     *
     * @throws ODRBadRequestException
     *
     * @return Theme
     */
    public function cloneThemeFromParent($user, $source_theme, $dest_theme_type)
    {
        // ----------------------------------------
        // If the source theme does not belong to a top-level datatype, then refuse to clone
        if ($source_theme->getId() !== $source_theme->getParentTheme()->getId())
            throw new ODRBadRequestException("Don't clone a child Datatype's Theme...either sync or clone this Datatype's grandparent's Theme");
        $this->logger->debug('----------------------------------------');


        // ----------------------------------------
        // Clone the theme for the top-level datatype first
        $this->logger->debug('CloneThemeService: attempting to clone source theme '.$source_theme->getId().' "'.$source_theme->getThemeType().'" from datatype '.$source_theme->getDataType()->getId().' into a new "'.$dest_theme_type.'" theme');
        $new_parent_theme = self::cloneParentTheme($user, $source_theme, $source_theme->getDataType(), $dest_theme_type);

        // Then, for each theme with $source_theme as its parent...
        $query = $this->em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Theme AS t
            WHERE t.parentTheme = :parent_theme AND t.id != :top_level_theme
            AND t.deletedAt IS NULL'
        )->setParameters(
            array(
                'parent_theme' => $source_theme->getId(),
                'top_level_theme' => $source_theme->getId()     // don't want to re-clone the theme for the top-level datatype
            )
        );
        $results = $query->getResult();

        /** @var Theme $child_theme */
        foreach ($results as $child_theme) {
            // ...clone the relevant theme for each child datatype
            $child_datatype = $child_theme->getDataType();
            $this->logger->debug('CloneThemeService: attempting to clone source theme '.$child_theme->getId().' "'.$child_theme->getThemeType().'" from datatype '.$child_theme->getDataType()->getId().' into a new "'.$dest_theme_type.'" theme');

            self::cloneParentTheme($user, $child_theme, $child_datatype, $dest_theme_type, $new_parent_theme);
        }

        $this->logger->debug('CloneThemeService: finished cloning source theme '.$source_theme->getId());
        return $new_parent_theme;
    }


    /**
     * Clones the provided Theme and its ThemeElements, ThemeDatafields, ThemeDatatypes, and Meta
     * entries, and attaches the clone to the specified datatype under the specified theme_type.
     *
     * This will ALWAYS create a new Theme.
     *
     * This function is private because the rest of ODR doesn't need the ability to clone an
     * arbitrary Theme, and should instead go through self::cloneThemeFromParent() to create a new
     * Theme for a top-level datatype and all its children...or use self::syncThemeWithSource()
     * to update an existing Theme with new Datafields and child Datatypes.
     *
     * @param ODRUser $user
     * @param Theme $source_theme
     * @param Datatype $dest_datatype
     * @param string $dest_theme_type
     * @param Theme|null $dest_parent_theme  MUST be specified when cloning into a child datatype
     *
     * @throws ODRBadRequestException
     *
     * @return Theme
     */
    private function cloneParentTheme($user, $source_theme, $dest_datatype, $dest_theme_type, $dest_parent_theme = null)
    {
        // ----------------------------------------
        // Don't accidentally create a second 'master' theme for this datatype
        foreach ($dest_datatype->getThemes() as $theme) {
            /** @var Theme $theme */
            if ($theme->getThemeType() == 'master' && $dest_theme_type == 'master')
                throw new ODRBadRequestException('Datatypes are not allowed to have more than one "master" theme');
        }
        $this->logger->debug('----------------------------------------');


        // ----------------------------------------
        // Create a new theme for this...doesn't make sense to clone, changing everything
        $new_theme = new Theme();
        $new_theme->setDataType($dest_datatype);
        $new_theme->setThemeType($dest_theme_type);
        $new_theme->setCreatedBy($user);
        $new_theme->setUpdatedBy($user);

        // This is cloning a theme within the same datatype...should use $source_theme's source
        $new_theme->setSourceTheme( $source_theme->getSourceTheme() );
        // If the parent theme exists, then this is a theme for a child datatype
        // If it doesn't exist, it'll be set to use this new theme a couple lines down...
        $new_theme->setParentTheme($dest_parent_theme);


        // Ensure the "in-memory" version of $dest_datatype knows about its new theme
        $dest_datatype->addTheme($new_theme);

        $this->em->persist($new_theme);
        $this->em->flush();
        $this->em->refresh($new_theme);

        // If $dest_parent_theme is null, then this is a theme for a top-level datatype, therefore
        //  this theme's parent should be set to itself
        // This works the same whether just cloning a theme, or whether cloning an entire datatype
        if ($dest_parent_theme == null)
            $new_theme->setParentTheme($new_theme);

        $this->em->persist($new_theme);


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
        self::persistObject($new_theme_meta, $user);

        $this->logger->debug('CloneThemeService: created new theme '.$new_theme->getId().' "'.$dest_theme_type.'"...datatype set to '.$new_theme->getDataType()->getId().', source theme set to '.$new_theme->getSourceTheme()->getId().', parent theme set to '.$new_theme->getParentTheme()->getId());
        $this->em->refresh($new_theme);


        // ----------------------------------------
        // For each theme element the source theme has...
        foreach ($source_theme->getThemeElements() as $source_te) {
            /** @var ThemeElement $source_te */
            // ...create a new theme element
            $new_te = clone $source_te;
            $new_te->setTheme($new_theme);

            // Ensure the "in-memory" representation of $new_theme knows about the new theme entry
            $new_theme->addThemeElement($new_te);
            self::persistObject($new_te, $user);

            // ...copy its meta entry
            $new_te_meta = clone $source_te->getThemeElementMeta();
            $new_te_meta->setThemeElement($new_te);

            // Ensure the "in-memory" representation of $new_te knows about its meta entry
            $new_te->addThemeElementMetum($new_te_meta);
            self::persistObject($new_te_meta, $user);

            $this->logger->debug('CloneThemeService: -- copied theme_element '.$source_te->getId().' into new datatype theme_element '.$new_te->getId());

            // Also clone each ThemeDatafield entry in each of these theme elements
            /** @var ThemeDataField[] $source_theme_df_array */
            $source_theme_df_array = $source_te->getThemeDataFields();
            foreach ($source_theme_df_array as $source_tdf) {
                $new_tdf = clone $source_tdf;
                $new_tdf->setThemeElement($new_te);

                // Ensure the "in-memory" version knows about the new theme_datafield entry
                $new_te->addThemeDataField($new_tdf);
                self::persistObject($new_tdf, $user);

                $df = $new_tdf->getDataField();
                $this->logger->debug('CloneThemeService: -- -- copied theme_datafield '.$source_tdf->getId().' for datafield '.$df->getId().' "'.$df->getFieldName().'"');
            }

            // Also clone each ThemeDatatype entry in each of these theme elements
            /** @var ThemeDataType[] $source_theme_dt_array */
            $source_theme_dt_array = $source_te->getThemeDataType();
            foreach ($source_theme_dt_array as $source_tdt) {
                $new_tdt = clone $source_tdt;
                $new_tdt->setThemeElement($new_te);

                // Ensure the "in-memory" version of knows about the new theme_datatype entry
                $new_te->addThemeDataType($new_tdt);
                self::persistObject($new_tdt, $user);

                $dt = $new_tdt->getDataType();
                $this->logger->debug('CloneThemeService: -- -- copied theme_datatype '.$source_tdt->getId().' for datatype '.$dt->getId().' "'.$dt->getShortName().'"');
            }
        }

        // Ensure the relevant cache entry is deleted
        $this->cache_service->delete('cached_theme_'.$new_theme->getParentTheme()->getId());

        // Return the new theme
        return $new_theme;
    }


    /**
     * This function is for the CloneDatatypeService to copy the master template's themes into the
     * Datatype being cloned.
     *
     * @param ODRUser $user
     * @param Theme $source_theme
     * @param DataType $dest_datatype
     * @param Datatype[] $mapping Array keys are master template datatype ids, array values
     *                            are the Datatype entries that have been cloned from the templates
     *
     * @throws ODRBadRequestException
     *
     * @return Theme
     */
    public function cloneThemeFromTemplate($user, $source_theme, $mapping)
    {
        // ----------------------------------------
        // If the source theme does not belong to a top-level datatype, then refuse to clone
        if ($source_theme->getId() !== $source_theme->getParentTheme()->getId())
            throw new ODRBadRequestException("Don't clone a child Datatype's Theme...either sync or clone this Datatype's grandparent's Theme");
        $this->logger->debug('----------------------------------------');


        // ----------------------------------------
        // Clone the theme for the top-level datatype first
        $source_datatype = $source_theme->getDataType();
        $dest_datatype = $mapping[ $source_datatype->getId() ];

        $this->logger->debug('CloneThemeService: attempting to clone source theme '.$source_theme->getId().' "'.$source_theme->getThemeType().'" from datatype '.$source_datatype->getId().' into destination datatype '.$dest_datatype->getId().' "'.$dest_datatype->getShortName().'"');
        $new_parent_theme = self::cloneTemplateTheme($user, $source_theme, $mapping, $source_theme->getThemeType());

        // Then, for each theme with $source_theme as its parent...
        $query = $this->em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Theme AS t
            WHERE t.parentTheme = :parent_theme AND t.id != :top_level_theme
            AND t.deletedAt IS NULL'
        )->setParameters(
            array(
                'parent_theme' => $source_theme->getId(),
                'top_level_theme' => $source_theme->getId()     // don't want to re-clone the theme for the top-level datatype
            )
        );
        $results = $query->getResult();

        /** @var Theme $child_theme */
        foreach ($results as $child_theme) {
            // ...clone the relevant theme for each child datatype
            $source_datatype = $child_theme->getDataType();
            $child_datatype = $mapping[ $source_datatype->getId() ];

            $this->logger->debug('----------------------------------------');
            $this->logger->debug('CloneThemeService: attempting to clone source theme '.$child_theme->getId().' "'.$child_theme->getThemeType().'" from datatype '.$source_datatype->getId().' into destination datatype '.$child_datatype->getId().' "'.$child_datatype->getShortName().'"');
            self::cloneTemplateTheme($user, $child_theme, $mapping, $child_theme->getThemeType(), $new_parent_theme);
        }

        $this->logger->debug('CloneThemeService: finished cloning source theme '.$source_theme->getId());
        return $new_parent_theme;
    }


    /**
     * Clones the provided Theme and its ThemeElements, ThemeDatafields, ThemeDatatypes, and Meta
     * entries, and attaches the clone to the specified datatype under the specified theme_type.
     *
     * This will ALWAYS create a new Theme.
     *
     * This function is private because the rest of ODR doesn't need the ability to clone an
     * arbitrary Theme, and should instead go through self::cloneThemeFromParent() to create a new
     * Theme for a top-level datatype and all its children...or use self::syncThemeWithSource()
     * to update an existing Theme with new Datafields and child Datatypes.
     *
     * @param ODRUser $user
     * @param Theme $source_theme
     * @param Datatype[] $mapping
     * @param string $dest_theme_type
     * @param Theme|null $dest_parent_theme  MUST be specified when cloning into a child datatype
     *
     * @throws ODRBadRequestException
     *
     * @return Theme
     */
    private function cloneTemplateTheme($user, $source_theme, $mapping, $dest_theme_type, $dest_parent_theme = null)
    {
        // ----------------------------------------
        // Need to...
        $source_datatype = $source_theme->getDataType();
        $dest_datatype = $mapping[ $source_datatype->getId() ];

        // Don't accidentally create a second 'master' theme for this datatype
        foreach ($dest_datatype->getThemes() as $theme) {
            /** @var Theme $theme */
            if ($theme->getThemeType() == 'master' && $dest_theme_type == 'master')
                throw new ODRBadRequestException('Datatypes are not allowed to have more than one "master" theme');
        }

        // Going to need these...
        // TODO - modify datatype cloning so this info is contained in $mapping
        $repo_datafield = $this->em->getRepository('ODRAdminBundle:DataFields');


        // ----------------------------------------
        // Create a new theme for this...doesn't make sense to clone, changing everything
        $new_theme = new Theme();
        $new_theme->setDataType($dest_datatype);
        $new_theme->setThemeType($dest_theme_type);
        $new_theme->setSourceTheme(null);
        $new_theme->setParentTheme($dest_parent_theme);
        $new_theme->setCreatedBy($user);
        $new_theme->setUpdatedBy($user);

        // Ensure the "in-memory" version of $dest_datatype knows about its new theme
        $dest_datatype->addTheme($new_theme);

        $this->em->persist($new_theme);
        $this->em->flush();
        $this->em->refresh($new_theme);



        // This is part of cloning a datatype...unfortunately, this function has no way to
        //  determine source theme if the destination theme_type isn't 'master'
        if ($dest_theme_type == 'master')
            $new_theme->setSourceTheme($new_theme);

        // CloneDatatypeService has to set the sourceTheme property for every other theme created



        // If $dest_parent_theme is null, then this is a theme for a top-level datatype, therefore
        //  this theme's parent should be set to itself
        // This works the same whether just cloning a theme, or whether cloning an entire datatype
        if ($dest_parent_theme == null)
            $new_theme->setParentTheme($new_theme);

        $this->em->persist($new_theme);

        // Clone the source theme's meta entry
        $new_theme_meta = clone $source_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);

        // Ensure the "in-memory" representation of $new_theme knows about the new theme meta entry
        $new_theme->addThemeMetum($new_theme_meta);
        self::persistObject($new_theme_meta, $user);

        $this->logger->debug('CloneThemeService: created new theme '.$new_theme->getId().' "'.$dest_theme_type.'"...parent theme set to '.$new_theme->getParentTheme()->getId());
        $this->em->refresh($new_theme);
        if ($new_theme->getSourceTheme() != null)
            $this->logger->debug('CloneThemeService: -- source theme set to '.$new_theme->getSourceTheme()->getId());
        else
            $this->logger->debug('CloneThemeService: -- WARNING: source theme set to null');

        // ----------------------------------------
        // For each theme element the source theme has...
        foreach ($source_theme->getThemeElements() as $source_te) {
            /** @var ThemeElement $source_te */
            // ...create a new theme element
            $new_te = clone $source_te;
            $new_te->setTheme($new_theme);

            // Ensure the "in-memory" representation of $new_theme knows about the new theme entry
            $new_theme->addThemeElement($new_te);
            self::persistObject($new_te, $user);

            // ...copy its meta entry
            $new_te_meta = clone $source_te->getThemeElementMeta();
            $new_te_meta->setThemeElement($new_te);

            // Ensure the "in-memory" representation of $new_te knows about its meta entry
            $new_te->addThemeElementMetum($new_te_meta);
            self::persistObject($new_te_meta, $user);

            $this->logger->debug('CloneThemeService: -- copied theme_element '.$source_te->getId().' into new datatype theme_element '.$new_te->getId());

            // Also clone each ThemeDatafield entry in each of these theme elements
            /** @var ThemeDataField[] $source_theme_df_array */
            $source_theme_df_array = $source_te->getThemeDataFields();
            foreach ($source_theme_df_array as $source_tdf) {
                $new_tdf = clone $source_tdf;
                $new_tdf->setThemeElement($new_te);

                // This ThemeDatafield entry needs to point to the cloned datafield in the new
                //  Datatype...pointing to the old one would be useless
                /** @var DataFields $cloned_datafield */
                $this->logger->debug('CloneThemeService: -- -- attempting to locate correct datafield by datatype id '.$dest_datatype->getId().', masterDatafield '.$source_tdf->getDataField()->getId().'...' );
                $cloned_datafield = $repo_datafield->findOneBy(
                    array(
                        'dataType' => $dest_datatype->getId(),
                        'masterDataField' => $source_tdf->getDataField()->getId(),
                    )
                );
                $new_tdf->setDataField($cloned_datafield);

                // Ensure the "in-memory" version knows about the new theme_datafield entry
                $new_te->addThemeDataField($new_tdf);
                self::persistObject($new_tdf, $user);

                $df = $new_tdf->getDataField();
                $this->logger->debug('CloneThemeService: -- -- copied theme_datafield '.$source_tdf->getId().' for datafield '.$df->getId().' "'.$df->getFieldName().'"');
            }

            // Also clone each ThemeDatatype entry in each of these theme elements
            /** @var ThemeDataType[] $source_theme_dt_array */
            $source_theme_dt_array = $source_te->getThemeDataType();
            foreach ($source_theme_dt_array as $source_tdt) {
                $new_tdt = clone $source_tdt;
                $new_tdt->setThemeElement($new_te);

                // This ThemeDatatype entry needs to point to the cloned child datatype in the new
                //  Datatype...pointing to the old one would be useless
                $tdt_dest_datatype = $mapping[ $source_tdt->getDataType()->getId() ];
                $new_tdt->setDataType($tdt_dest_datatype);

                // Ensure the "in-memory" version of knows about the new theme_datatype entry
                $new_te->addThemeDataType($new_tdt);
                self::persistObject($new_tdt, $user);

                $dt = $new_tdt->getDataType();
                $this->logger->debug('CloneThemeService: -- -- copied theme_datatype '.$source_tdt->getId().' for datatype '.$dt->getId().' "'.$dt->getShortName().'"');
            }
        }

        // Ensure the relevant cache entry is deleted
        $this->cache_service->delete('cached_theme_'.$new_theme->getParentTheme()->getId());

        // Return the new theme
        return $new_theme;
    }
}