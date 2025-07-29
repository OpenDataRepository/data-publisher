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

        // Need to locate each theme for these datatypes, so they can all get cloned...
        /** @var Theme[] $results */
        $query = $this->em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Theme AS t
            WHERE t.dataType IN (:datatype_ids) AND t.parentTheme = t
            AND t.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $associated_datatypes) );
        $results = $query->getResult();
        /** @var Theme[] $results */

        $new_parent_themes = array();
        $this->source_themes = array();
        foreach ($results as $t) {
            $new_theme = self::cloneSourceTheme(
                $user,
                $t,    // copy this theme
                null,  // let the function set the parent theme
                $t->getThemeType(),
                $dt_mapping,
                $df_mapping,
                $rpi_mapping
            );
            $new_parent_themes[] = $new_theme;
        }
        // self::cloneSourceTheme never flushes, so have to do it manually
        $this->em->flush();

        // ----------------------------------------
        // At this point, the newly cloned themes do not have a sourceTheme set

        // Easier for self::correctThemeData() if $dt_mapping is inverted
        $reverse_dt_mapping = array();
        foreach ($dt_mapping as $old_dt_id => $new_dt)
            $reverse_dt_mapping[ $new_dt->getId() ] = $old_dt_id;

        /** @var Theme[] $new_parent_themes */
        foreach ($new_parent_themes as $t) {
            /** @var Theme $t */
            // Each of these new themes then needs to be updated with its correct source theme
            self::correctThemeData($user, $t, $reverse_dt_mapping);
        }

        $this->logger->info('CloneMasterTemplateThemeService: finished cloning top-level themes for datatypes ['.$dts.']');
        $this->logger->info('----------------------------------------');
    }


    /**
     * This function creates a (mostly complete) copy of an existing theme.  DOES NOT FLUSH.
     *
     * IMPORTANT: the source theme(s) for the newly cloned (set of) theme(s) WILL NOT be set when
     * when this function returns.  The caller MUST deal with this, otherwise ODR will throw errors
     * later on.  Conveniently, {@link self::$source_themes} is filled out with the correct info as
     * this function does its thing...
     *
     * @param ODRUser $user
     * @param Theme $source_theme The theme to copy
     * @param Theme|null $new_parent_theme Should be null when called on a top-level theme
     * @param string $dest_theme_type
     * @param DataType[] $dest_datatype {@link CloneMasterDatatypeService::createDatatypeFromMaster()}
     * @param DataFields[] $dest_datafields {@link CloneMasterDatatypeService::createDatatypeFromMaster()}
     * @param RenderPluginInstance[] $dest_rpis {@link CloneMasterDatatypeService::cloneRenderPlugins()}
     * @param int $indent Internally used to make the debug output easier to read
     * @param bool $is_new_master_theme Internally used to ensure {@link self::$source_themes} is correct
     *
     * @return Theme
     */
    private function cloneSourceTheme($user, $source_theme, $new_parent_theme, $dest_theme_type, $dest_datatype = array(), $dest_datafields = array(), $dest_rpis = array(), $indent = 0, $is_new_master_theme = false)
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
        // Need to set the parent theme in here, because it's impossible to set correctly later
        if ( is_null($new_parent_theme) )
            $new_parent_theme = $new_theme;
        $new_theme->setParentTheme($new_parent_theme);

        // Used to also set the source theme in here, but that only worked when cloning a template
        //  ...the previous logic effectively...latched...onto the first theme that got cloned as the
        //  new source theme, which definitely does not work when copying a "normal datatype" because
        //  the order is not guaranteed
        $new_theme->setSourceTheme(null);

        // Also need to create a new ThemeMeta entry...
        $new_theme_meta = clone $source_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);
        $new_theme_meta->setTemplateName( 'Copy of '.$new_theme_meta->getTemplateName() );

        $new_theme->addThemeMetum($new_theme_meta);

        $this->em->persist($new_theme);
        $this->em->persist($new_theme_meta);

        // ---------------------------------------------
        // This function can still find the correct "source" theme, though...
        $source_theme_id = $source_theme->getId();
        $source_theme_parent_id = $source_theme->getParentTheme()->getId();
        $source_theme_source_id = $source_theme->getSourceTheme()->getId();

        // ...if the theme that's getting cloned is both its parent and its own "source", then the
        //  theme that gets copied from it should be the new "source" theme for this datatype
        if ( $is_new_master_theme || ($source_theme_id === $source_theme_parent_id && $source_theme_id === $source_theme_source_id) ) {
            $source_theme_datatype_id = $source_theme->getDataType()->getId();

            $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' ** marking this new theme as a "source" theme');
            $this->source_themes[$source_theme_datatype_id] = $new_theme;

            // Pass this to self::cloneThemeContents(), so that any themes it ends up attempting to
            //  create for child descendants are also marked as "source" themes for the new children
            $is_new_master_theme = true;
        }

        // ----------------------------------------
        // Now that a theme exists, copy the contents of its source theme into it
        self::cloneThemeContents(
            $user,
            $source_theme,
            $new_parent_theme,
            $new_theme,
            $dest_theme_type,
            $dest_datatype,
            $dest_datafields,
            $dest_rpis,
            ($indent+1),
            $is_new_master_theme
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
     * @param Theme $source_theme The theme being copied
     * @param Theme $new_parent_theme The "group" of themes this new content belongs to
     * @param Theme $new_theme The theme receiving the copied entities
     * @param string $dest_theme_type
     * @param DataType[] $dest_datatype_array {@link CloneMasterDatatypeService::createDatatypeFromMaster()}
     * @param DataFields[] $dest_datafields {@link CloneMasterDatatypeService::createDatatypeFromMaster()}
     * @param RenderPluginInstance[] $dest_rpis {@link CloneMasterDatatypeService::cloneRenderPlugins()}
     * @param int $indent Internally used to make the debug output easier to read
     * @param bool $is_new_master_theme Internally used to ensure {@link self::$source_themes} is correct
     */
    private function cloneThemeContents($user, $source_theme, $new_parent_theme, $new_theme, $dest_theme_type, $dest_datatype_array, $dest_datafields, $dest_rpis, $indent, $is_new_master_theme)
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

                // Need to get this entry to point to the newly cloned descendant datatype...
                $child_datatype = $dest_datatype_array[ $source_tdt->getDataType()->getId() ];
                $new_tdt->setDataType($child_datatype);
                self::persistObject($new_tdt, $user, true);    // These don't need to be flushed/refreshed immediately...

                // If this descendant datatype is not a child datatype, then the theme that'll be
                //  cloned should not be the new datatype's "master" theme
                $child_is_new_master_theme = $is_new_master_theme;
                if ( $child_is_new_master_theme && $child_datatype->getId() === $child_datatype->getGrandparent()->getId() ) {
                    $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' ** ** child datatype '.$child_datatype->getId().' ('. $child_datatype->getShortName().') is a linked descendant, discontinuing setting master theme...');
                    $child_is_new_master_theme = false;
                }

                // Clone the theme for this descendant
                /** @var Theme $tdt_theme */
                $tdt_theme = self::cloneSourceTheme(
                    $user,
                    $source_tdt->getChildTheme(),
                    $new_parent_theme,
                    $dest_theme_type,
                    $dest_datatype_array,
                    $dest_datafields,
                    $dest_rpis,
                    ($indent+2),
                    $child_is_new_master_theme
                );

                $new_tdt->setChildTheme($tdt_theme);
                $new_te->addThemeDataType($new_tdt);

                $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' -- -- attached child theme '.$source_tdt->getChildTheme()->getId().' (child_datatype '.$child_datatype->getId().') to theme_datatype '.$source_tdt->getId());
            }
        }
    }


    /**
     * Recursively sets all children of the given theme to have a new parent_theme.
     *
     * @param ODRUser $user
     * @param Theme $theme The theme being corrected
     * @param int[] $reverse_dt_mapping
     * @param int $indent Internally used to make the debug output easier to read
     */
    private function correctThemeData($user, $theme, $reverse_dt_mapping, $indent = 0)
    {
        // Debugging assistance on recursive functions...
        $indent_text = '';
        for ($i = 0; $i < $indent; $i++)
            $indent_text .= ' --';

        // Reload the theme from the database so the logging info is correct
        $this->em->refresh($theme);

        // Need to convert the new dt_id back into the dt_id it was copied from, because that's
        //  what $this->source_themes uses
        $new_parent_theme_dt_id = $theme->getDataType()->getId();
        $old_dt_id = $reverse_dt_mapping[$new_parent_theme_dt_id];
        $new_source_theme = $this->source_themes[$old_dt_id];

        // Set the theme to use the correct parent
        $theme->setSourceTheme($new_source_theme);
        self::persistObject($theme, $user, true);    // don't flush immediately

        $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' changed newly cloned theme '.$theme->getId().' to have the source_theme '.$new_source_theme->getId());

        /** @var ThemeElement[] $te_array */
        $te_array = $theme->getThemeElements();
        foreach($te_array as $te) {
            $tdt_array = $te->getThemeDataType();
            /** @var ThemeDataType[] $tdt */
            foreach ($tdt_array as $tdt) {
                $this->logger->debug('CloneMasterTemplateThemeService:'.$indent_text.' -- checking themeDatatype entries in newly cloned themeElement '.$te->getId().' for child themes...');
                self::correctThemeData($user, $tdt->getChildTheme(), $reverse_dt_mapping, ($indent+2));
            }
        }
    }
}
