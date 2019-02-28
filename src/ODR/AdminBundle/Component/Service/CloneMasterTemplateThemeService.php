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
     * @var Theme[]
     */
    private $source_themes = array();

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
     *
     * @throws ODRBadRequestException
     *
     * @return Theme
     */
    public function cloneSourceTheme(
        $user,
        $source_theme,
        $dest_theme_type,
        $dest_datatype = array(),
        $dest_datafields = array(),
        $delay_flush = false
    ) {

        // Create a new theme for the top-level datatype
        $this->logger->info('CloneThemeService: cloning theme ' . $source_theme->getId());

        $new_theme = clone $source_theme;
        $new_theme->setDataType($dest_datatype[$source_theme->getDataType()->getId()]);
        $new_theme->setThemeType($dest_theme_type);
        $new_theme->setParentTheme($new_theme);


        // Also need to create a new ThemeMeta entry...
        $new_theme_meta = clone $source_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);
        $new_theme_meta->setTemplateName( 'Copy of '.$new_theme_meta->getTemplateName() );

        $new_theme->addThemeMetum($new_theme_meta);

        // TODO Fix Source Theme - this is messed up
        $found_theme = false;
        foreach($this->source_themes as $dt_id => $t) {
            if($source_theme->getDataType()->getId() == $dt_id) {
                $found_theme = true;
                $new_theme->setSourceTheme( $t );
            }
        }
        if(!$found_theme) {
            $new_theme->setSourceTheme( $new_theme );

            // persist so we can work with it.
            $this->em->persist($new_theme);
            $this->source_themes[$source_theme->getDataType()->getId()] = $new_theme;
        }

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

        // TODO Figure out where caching should be fixed...

        // Return the newly created theme
        return $new_theme;
    }


    /**
     *
     * After the Datatype, Datafield, Radio Option, and any RenderPlugin settings are cloned, the
     * Theme stuff from the master template needs to be cloned too...
     *
     * @param $user
     * @param $dt_mapping
     * @param $df_mapping
     * @param $associated_datatypes
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function cloneTheme(
        $user,
        $dt_mapping,
        $df_mapping,
        $associated_datatypes
    ) {
        // Need to store each theme that got created...
        /** @var Theme[] $results */
        $query = $this->em->createQuery(
            'SELECT t
            FROM ODRAdminBundle:Theme AS t
            WHERE t.dataType IN (:datatype_ids) AND t = t.parentTheme
            AND t.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $associated_datatypes) );
        $results = $query->getResult();

        $new_parent_themes = array();
        $this->source_themes = array();
        foreach ($results as $t) {
            // TODO If we don't flush the theme, we can reassign datatypes before committing
            $new_theme = self::cloneSourceTheme(
                $user,
                $t,
                $t->getThemeType(),
                $dt_mapping,
                $df_mapping,
                true
            );

            $new_parent_themes[] = $new_theme;
        }

        /** @var Theme[] $new_parent_themes */
        foreach($new_parent_themes as $t) {
            /** @var Theme $t */
            self::correctThemeData($t, $t);
            self::persistObject($t, $user, true);
        }

        $this->em->flush();

    }


    /**
     * Fixes issues with parent/child chain.
     *
     * @param Theme $theme
     * @param Theme $parent_theme
     */
    private function correctThemeData(Theme $theme, Theme $parent_theme) {
        // Correct the Datatype ID if needed
        $theme->setParentTheme($parent_theme);

        /** @var ThemeElement[] $te_array */
        $te_array = $theme->getThemeElements();
        foreach($te_array as $te) {
            /** @var ThemeElement $te */
            self::correctThemeElement($parent_theme, $te);
        }
    }


    /**
     * @param Theme $parent_theme
     * @param ThemeElement $te
     */
    private function correctThemeElement(Theme $parent_theme, ThemeElement $te) {
        /** @var ThemeDataType[] $tdt_array */
        $tdt_array = $te->getThemeDataType();
        foreach($tdt_array as $tdt) {
            // NOTE Parent theme is actually grandparent theme.
            /** @var ThemeDataType $tdt */
            self::correctThemeDataType($parent_theme, $tdt);
        }

    }

    /**
     * @param Theme $parent_theme
     * @param ThemeDataType $tdt
     */
    private function correctThemeDataType(Theme $parent_theme, ThemeDataType $tdt) {
        self::correctThemeData($tdt->getChildTheme(), $parent_theme, $tdt->getChildTheme()->getSourceTheme());
    }

    /**
     * Iterates through all of $source_theme, cloning the ThemeElements, ThemeDatafield, and
     * ThemeDatatype entries, and attaching them to $new_theme.
     *
     * @param $user
     * @param $source_theme
     * @param $new_theme
     * @param $dest_theme_type
     * @param $dest_datatype_array
     * @param $dest_datafields
     * @param bool $delay_flush
     */
    private function cloneThemeContents(
        $user,
        $source_theme,
        $new_theme,
        $dest_theme_type,
        $dest_datatype_array,
        $dest_datafields,
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

                $new_tdf->setDataField($dest_datafields[$source_tdf->getDataField()->getId()]);

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
                    $dest_datatype_array,
                    $dest_datafields,
                    $delay_flush
                );

                $new_tdt->setChildTheme($tdt_theme);
                $new_te->addThemeDataType($new_tdt);

            }
        }
    }

}
