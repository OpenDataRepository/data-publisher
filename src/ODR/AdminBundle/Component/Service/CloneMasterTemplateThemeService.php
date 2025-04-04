<?php

/**
 * Open Data Repository Data Publisher
 * Clone Master Template Theme Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains the functions required to clone or sync a theme, specifically for use with
 * CloneMasterDatatypeService.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeRenderPluginInstance;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
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
     * @var Theme[]
     */
    private $source_themes = array();

    /**
     * @var Logger
     */
    private $logger;


    /**
     * CloneMasterTemplateThemeService constructor.
     *
     * @param EntityManager $entity_manager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        Logger $logger
    ) {
        $this->em = $entity_manager;
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
     * After the Datatype, Datafield, Radio Option, and any RenderPlugin settings are cloned, the
     * Theme stuff from the master template needs to be cloned too...
     *
     * @param ODRUser $user
     * @param DataType[] $dt_mapping
     * @param DataFields[] $df_mapping
     * @param int[] $associated_datatypes
     * @param RenderPluginInstance[] $rpi_mapping
     */
    public function cloneTheme($user, $dt_mapping, $df_mapping, $associated_datatypes, $rpi_mapping)
    {
        $this->logger->info('----------------------------------------');
        $dts = implode(',', $associated_datatypes);
        $this->logger->info('CloneMasterTemplateThemeService: cloning top-level themes for datatypes ['.$dts.']');

        // Need to locate each theme that got created...
        /** @var Theme[] $results */
        $query = $this->em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Theme AS t
            WHERE t.dataType IN (:datatype_ids) AND t = t.parentTheme
            AND t.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $associated_datatypes) );
        $results = $query->getResult();
        /** @var Theme[] $results */

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
                $rpi_mapping,
                true
            );

            $new_parent_themes[] = $new_theme;
        }

        // Flush here so the correct theme ids are displayed in the log
        $this->em->flush();

        /** @var Theme[] $new_parent_themes */
        foreach ($new_parent_themes as $t) {
            /** @var Theme $t */
            self::correctThemeData($user, $t, $t, 0);
        }

        $this->logger->info('CloneMasterTemplateThemeService: finished cloning top-level themes for datatypes ['.$dts.']');
        $this->logger->info('----------------------------------------');
    }


    /**
     * This function creates a copy of an existing theme.  The only appreciable change is to the
     * cloned theme's theme_type (e.g. "master" theme -> "search_results" theme).  This only makes
     * sense when called on a top-level theme for a top-level datatype.
     *
     * @param ODRUser $user
     * @param Theme $source_theme
     * @param string $dest_theme_type
     * @param DataType[] $dest_datatype
     * @param DataFields[] $dest_datafields
     * @param RenderPluginInstance[] $dest_rpis
     * @param bool $delay_flush
     * @param int $indent
     *
     * @return Theme
     */
    public function cloneSourceTheme($user, $source_theme, $dest_theme_type, $dest_datatype = array(), $dest_datafields = array(), $dest_rpis = array(), $delay_flush = false, $indent = 0)
    {
        // Debugging assistance on recursive functions...
        $indent_text = '';
        for ($i = 0; $i < $indent; $i++)
            $indent_text .= ' --';

        // Create a new theme for the top-level datatype
        $this->logger->info('CloneMasterTemplateThemeService:'.$indent_text.' ----------------------------------------');
        $this->logger->info('CloneMasterTemplateThemeService:'.$indent_text.' cloning source theme '.$source_theme->getId().' ('.$source_theme->getThemeType().') from datatype '.$source_theme->getDataType()->getId().' "'.$source_theme->getDataType()->getShortName().'"...');

        $new_theme = clone $source_theme;
        $new_theme->setDataType($dest_datatype[$source_theme->getDataType()->getId()]);
        $new_theme->setThemeType($dest_theme_type);
        $new_theme->setParentTheme($new_theme);

        // Also need to create a new ThemeMeta entry...
        $new_theme_meta = clone $source_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);
        $new_theme_meta->setTemplateName( 'Copy of '.$new_theme_meta->getTemplateName() );

        $new_theme->addThemeMetum($new_theme_meta);

        $found_theme = false;
        foreach ($this->source_themes as $dt_id => $t) {
            if ($source_theme->getDataType()->getId() == $dt_id) {
                $found_theme = true;
                $new_theme->setSourceTheme( $t );

                $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' -- set new theme source to '.$t->getId());
            }
        }
        if (!$found_theme) {
            $new_theme->setSourceTheme( $new_theme );

            $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' -- set new theme source to self');

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
            $dest_rpis,
            $delay_flush,
            ($indent+1)
        );

        $this->logger->info('CloneMasterTemplateThemeService:'.$indent_text.' finished cloning source theme '.$source_theme->getId().' from datatype '.$source_theme->getDataType()->getId().' "'.$source_theme->getDataType()->getShortName().'"...');
        $this->logger->info('CloneMasterTemplateThemeService:'.$indent_text.' ----------------------------------------');

        // Return the newly created theme
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
     * @param DataType[] $dest_datatype_array
     * @param DataFields[] $dest_datafields
     * @param RenderPluginInstance[] $dest_rpis
     * @param bool $delay_flush
     * @param int $indent
     */
    private function cloneThemeContents($user, $source_theme, $new_theme, $dest_theme_type, $dest_datatype_array, $dest_datafields, $dest_rpis, $delay_flush, $indent)
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
        foreach ($theme_elements as $te)
            $theme_element_ids[] = $te->getId();

        $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' need to copy theme elements from source theme ' .$source_theme->getId(). ': ['.implode(',', $theme_element_ids). ']'  );

        foreach ($theme_elements as $source_te) {
            // ...create a new theme element

            // Do NOT clone the relevant source themeElement, as that seems to carry over that
            //  source themeElement's themeDatafield list
            $new_te = new ThemeElement();
            $new_te->setTheme($new_theme);

            // Ensure the "in-memory" representation of $new_theme knows about the new theme entry
            $new_theme->addThemeElement($new_te);
            self::persistObject($new_te, $user, true);    // These don't need to be flushed/refreshed immediately...

            // ...copy its meta entry
            $new_te_meta = clone $source_te->getThemeElementMeta();
            $new_te_meta->setThemeElement($new_te);

            // Ensure the "in-memory" representation of $new_te knows about its meta entry
            $new_te->addThemeElementMetum($new_te_meta);
            self::persistObject($new_te_meta, $user, true);    // These don't need to be flushed/refreshed immediately...

            $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' -- copied theme_element '.$source_te->getId().' from source theme '.$source_theme->getId().' into a new theme_element');

            // Also clone each ThemeDatafield entry in each of these theme elements
            /** @var ThemeDataField[] $source_theme_df_array */
            $source_theme_df_array = $source_te->getThemeDataFields();
            foreach ($source_theme_df_array as $source_tdf) {
                $new_tdf = clone $source_tdf;
                $new_tdf->setThemeElement($new_te);

                // No compelling reason to change hidden, hideHeader, or useIconInTables

                $dest_df = $dest_datafields[ $source_tdf->getDataField()->getId() ];
                $new_tdf->setDataField($dest_df);
                self::persistObject($new_tdf, $user, true);    // These don't need to be flushed/refreshed immediately...

                // Ensure the "in-memory" version knows about the new theme_datafield entry
                $new_te->addThemeDataField($new_tdf);
                $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' -- -- copied theme_datafield '.$source_tdf->getId().' for dest_datafield '.$dest_df->getId().' "'.$dest_df->getFieldName().'"');
            }

            // Also clone each ThemeRenderPluginInstance entry in each of these theme elements
            /** @var ThemeRenderPluginInstance[] $source_theme_rpi_array */
            $source_theme_rpi_array = $source_te->getThemeRenderPluginInstance();
            foreach ($source_theme_rpi_array as $source_trpi) {
                $new_trpi = clone $source_trpi;
                $new_trpi->setThemeElement($new_te);

                $dest_rpi = $dest_rpis[ $source_trpi->getRenderPluginInstance()->getId() ];
                $new_trpi->setRenderPluginInstance($dest_rpi);
                self::persistObject($new_trpi, $user, true);    // These don't need to be flushed/refreshed immediately...

                $rpi = $new_trpi->getRenderPluginInstance();
                $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' -- -- copied theme_renderPluginInstance '.$source_trpi->getId().' for dest_renderPluginInstance '.$dest_rpi->getId().' for the RenderPlugin "'.$dest_rpi->getRenderPlugin()->getPluginName().'"');
            }

            // Also clone each ThemeDatatype entry in each of these theme elements
            /** @var ThemeDataType[] $source_theme_dt_array */
            $source_theme_dt_array = $source_te->getThemeDataType();
            foreach ($source_theme_dt_array as $source_tdt) {

                /** @var ThemeDataType $new_tdt */
                $new_tdt = clone $source_tdt;
                $new_tdt->setThemeElement($new_te);

                $child_datatype = $dest_datatype_array[ $source_tdt->getDataType()->getId() ];
                $new_tdt->setDataType($child_datatype);
                self::persistObject($new_tdt, $user, true);    // These don't need to be flushed/refreshed immediately...

                /** @var Theme $tdt_theme */
                $tdt_theme = self::cloneSourceTheme(
                    $user,
                    $source_tdt->getChildTheme(),
                    $dest_theme_type,
                    $dest_datatype_array,
                    $dest_datafields,
                    $dest_rpis,
                    $delay_flush,
                    ($indent+2)
                );

                $new_tdt->setChildTheme($tdt_theme);
                $new_te->addThemeDataType($new_tdt);

                $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' -- -- attached child theme '.$source_tdt->getChildTheme()->getId().' (child_datatype '.$child_datatype->getId().') to theme_datatype '.$source_tdt->getId());
            }
        }
    }


    /**
     * Recursively sets all children of $theme to have $parent_theme as their parent.
     *
     * @param ODRUser $user
     * @param Theme $parent_theme
     * @param Theme $theme
     * @param int $indent
     */
    private function correctThemeData($user, $parent_theme, $theme, $indent)
    {
        // Debugging assistance on recursive functions...
        $indent_text = '';
        for ($i = 0; $i < $indent; $i++)
            $indent_text .= ' --';

        // Reload the theme from the database so the logging info is correct
        $this->em->refresh($theme);

        // Set the theme to use the correct parent
        $theme->setParentTheme($parent_theme);
        self::persistObject($theme, $user, true);    // don't flush immediately

        $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' changed newly cloned theme '.$theme->getId().' to have the parent_theme '.$parent_theme->getId());

        /** @var ThemeElement[] $te_array */
        $te_array = $theme->getThemeElements();
        foreach($te_array as $te) {
            $tdt_array = $te->getThemeDataType();
            /** @var ThemeDataType[] $tdt */
            foreach ($tdt_array as $tdt) {
                $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' -- checking themeDatatype entries in newly cloned themeElement '.$te->getId().' for child themes...');
                self::correctThemeData($user, $parent_theme, $tdt->getChildTheme(), ($indent+2));
            }
        }
    }
}
