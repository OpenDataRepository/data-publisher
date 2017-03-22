<?php

namespace ODR\AdminBundle\Component\Service;

// Controllers/Classes
use ODR\OpenRepository\SearchBundle\Controller\DefaultController as SearchController;
use ODR\AdminBundle\Controller\ODRCustomController as ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\Boolean AS ODRBoolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileChecksum;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupDatafieldPermissions;
use ODR\AdminBundle\Entity\GroupDatatypePermissions;
use ODR\AdminBundle\Entity\GroupMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageChecksum;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginFields;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\TrackedError;
use ODR\OpenRepository\UserBundle\Entity\User;
use ODR\AdminBundle\Entity\UserGroup;
// Forms
// Symfony

use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Doctrine\ORM\EntityManager;


/**
 * Created by PhpStorm.
 * User: nate
 * Date: 10/14/16
 * Time: 11:59 AM
 */
class ThemeService
{


    /**
     * @var mixed
     */
    private $logger;

    /**
     * @var
     */
    private $user;
    /**
     * @var
     */
    private $created_datatypes;

    /**
     * @var mixed
     */
    private $container;

    /**
     * CreateDatatypeService constructor.
     * @param Container $container
     * @param EntityManager $entity_manager
     * @param $logger
     */
    public function __construct(Container $container, EntityManager $entity_manager, $logger) {
        $this->container = $container;
        $this->em = $entity_manager;
        $this->logger = $logger;
    }


    public function cloneThemesForDatatype($datatype_id, $from_theme_type, $to_theme_type, $user_id)
    {
        try {

            $redis = $this->container->get('snc_redis.default');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            $user_manager = $this->container->get('fos_user.user_manager');
            $this->user = $user_manager->findUserBy(array('id' => $user_id));

            $datatype_info_service = $this->container->get('odr.datatype_info_service');

            // Get the DataType to work with
            $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
            $datatype = $repo_datatype->find($datatype_id);

            if($datatype == null) {
                throw new \Exception("Datatype is null.");
            }

            // Get the Master Datatype to Clone
            $associated_datatypes = $datatype_info_service->getAssociatedDatatypes(array($datatype->getId()));

            // Clone datatype theme
            $theme = self::cloneDatatypeTheme($datatype, $from_theme_type, $to_theme_type);

            // Set new theme to have parent_theme_id = itself
            $theme->setParentTheme($theme);

            // Clone Associated Datatypes
            // The parent theme id is the "Custom" theme id to tie all the
            // related child themes to the same custom theme instance
            foreach($associated_datatypes as $dt_id) {
                $assoc_datatype = $repo_datatype->find($dt_id);
                if($dt_id != $datatype->getId() && $assoc_datatype != null) {
                    self::cloneDatatypeTheme($assoc_datatype, $from_theme_type, $to_theme_type, $theme);
                }
            }

            return "Clone datatype complete.";
        }
        catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param $obj
     * @param bool $update_user_info
     */
    protected function persistObject($obj, $update_user_info = false) {
        if($update_user_info) {
            if(method_exists($obj, "setCreatedBy")) {
                $obj->setCreatedBy($this->user);
            }
            if(method_exists($obj, "setUpdatedBy")) {
                $obj->setUpdatedBy($this->user);
            }
            if(method_exists($obj, "setCreated")) {
                // $obj->setCreated(new DateTime(time()));
            }
            if(method_exists($obj, "setUpdated")) {
                // $obj->setUpdated(new DateTime(time()));
            }
        }
        $this->em->persist($obj);
        $this->em->flush();
        $this->em->refresh($obj);
    }

    /**
     * @param RenderPlugin $parent_render_plugin
     * @param DataType|null $datatype
     * @param DataFields|null $datafield
     */
    protected function cloneRenderPluginSettings(RenderPlugin $parent_render_plugin, Datatype $datatype = null, DataFields $datafield = null) {

        //  LEFT JOIN dt_rp.renderPluginInstance AS dt_rpi WITH (dt_rpi.dataType = dt)
        $repo_rpi = $this->em->getRepository('ODRAdminBundle:RenderPluginInstance');
        if($datatype != null) {
            $parent_rpi = $repo_rpi->findOneBy(
                array(
                    'dataType' => $datatype->getId(),
                    'renderPlugin' => $parent_render_plugin->getId()
                )
            );
        }
        else {
            $parent_rpi = $repo_rpi->findOneBy(
                array(
                    'dataField' => $datafield->getId(),
                    'renderPlugin' => $parent_render_plugin->getId()
                )
            );
        }

        if($parent_rpi != null) {
            $new_rpi = clone $parent_rpi;
            // TODO - This used to be $new_datatype - why is this?
            $new_rpi->setDataType($datatype);
            self::persistObject($new_rpi);

            //  LEFT JOIN dt_rpi.renderPluginOptions AS dt_rpo
            $parent_rpo_array = $parent_rpi->getRenderPluginOptions();
            foreach ($parent_rpo_array as $parent_rpo) {
                $new_rpo = clone $parent_rpo;
                $new_rpo->setRenderPluginInstance($new_rpi);
                self::persistObject($new_rpo);
            }

            //  LEFT JOIN dt_rpi.renderPluginMap AS dt_rpm
            $parent_rpm_array = $parent_rpi->getRenderPluginMap();
            foreach ($parent_rpm_array as $parent_rpm) {
                $new_rpm = clone $parent_rpm;
                $new_rpm->setRenderPluginInstance($new_rpi);
                if($datatype != null) {
                    $new_rpm->setDataType($datatype);
                }
                else {
                    $datatype = $datafield->getDataType();
                }
                $repo_df = $this->em->getRepository('ODRAdminBundle:DataFields');
                $matching_df = $repo_df->findOneBy(
                    array(
                        'dataType' => $datatype->getId(),
                        'masterDatafield' => $parent_rpm->getDataField()
                    )
                );
                $new_rpm->setDataField($matching_df);

                self::persistObject($new_rpm);
            }
        }
    }

    /**
     * cloneDatatypeThemeFromMaster - Default behavior is to clone the theme
     * associated with a master datatype and assign it to the cloned
     * datatype. If parent_datatype is not null, the function is used to
     * create a copy of parent_datatype's theme and assign it to datatype.
     * @param DataType $datatype
     */
    protected function cloneDatatypeThemeFromMaster(
        DataType $datatype
    ) {
        // Get Themes
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
        $parent_datatype = $datatype->getMasterDataType();
        $parent_theme = $repo_theme->findOneBy(
            array(
                'dataType' => $parent_datatype->getId(),
                'themeType' => 'master'
            )
        );

        // This is the theme we will be copying to.
        $datatype_theme = $repo_theme->findOneBy(
            array(
                'dataType' => $datatype->getId(),
                'themeType' => 'master'
            )
        );

        // Need to delete any existing theme
        if($datatype_theme != null) {
            $this->em->remove($datatype_theme);
            $this->em->flush();
        }

        $new_theme = clone $parent_theme;
        $new_theme->setDataType($datatype);
        self::persistObject($new_theme);

        // Theme Meta
        $new_theme_meta = clone $parent_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);
        self::persistObject($new_theme_meta);

        // Get the DataFields
        $datafields = $datatype->getDataFields();

        // Get Theme Elements
        $parent_te_array = $parent_theme->getThemeElements();
        foreach($parent_te_array as  $parent_te) {
            $new_te = clone $parent_te;
            $new_te->setTheme($new_theme);
            self::persistObject($new_te);

            // Process Meta Records
            $parent_te_meta = $parent_te->getThemeElementMeta();
            $new_te_meta = clone $parent_te_meta;
            $new_te_meta->setThemeElement($new_te);
            self::persistObject($new_te_meta);

            // Theme Data Field
            $parent_theme_df_array = $parent_te->getThemeDataFields();
            foreach($parent_theme_df_array as $parent_tdf) {
                $new_tdf = clone $parent_tdf;
                $new_tdf->setThemeElement($new_te);
                foreach($datafields as $datafield) {
                    if($datafield->getMasterDataField()->getId() == $parent_tdf->getDataField()->getId()) {
                        $new_tdf->setDataField($datafield);
                        self::persistObject($new_tdf);
                        break;
                    }
                }
            }

            // Theme Data Type
            $parent_theme_dt_array = $parent_te->getThemeDataType();
            foreach($parent_theme_dt_array as $parent_tdt) {
                $new_tdt = clone $parent_tdt;
                $new_tdt->setThemeElement($new_te);
                foreach($this->created_datatypes as $created_datatype) {
                    if($created_datatype->getMasterDataType()->getId() == $parent_tdt->getDataType()->getId()) {
                        $new_tdt->setDataType($created_datatype);
                        self::persistObject($new_tdt);
                    }
                }
            }
        }
    }

    /**
     * cloneDatatypeTheme - Default behavior is to clone the theme
     * associated with a master datatype and assign it to the cloned
     * datatype. If parent_datatype is not null, the function is used to
     * create a copy of parent_datatype's theme and assign it to datatype.
     * @param DataType $datatype
     * @param String $theme_type
     * @param Int $cloned_theme_id
     */
    protected function cloneDatatypeTheme(
        DataType $datatype,
        $from_theme_type = "master",
        $to_theme_type = "search_results",
        $cloned_theme = null
    ) {
        // This is the theme we will be copying to.
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
        $datatype_theme = $repo_theme->findOneBy(
            array(
                'dataType' => $datatype->getId(),
                'themeType' => $from_theme_type
            )
        );

        $new_theme = clone $datatype_theme;
        if($cloned_theme != null) {
            $new_theme->setParentTheme($cloned_theme);
        }
        $new_theme->setThemeType($to_theme_type);
        $new_theme->setSourceTheme($datatype_theme);
        self::persistObject($new_theme);

        // Theme Meta
        $new_theme_meta = clone $datatype_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);
        self::persistObject($new_theme_meta);

        // Get the DataFields
        $datafields = $datatype->getDataFields();

        // Get Theme Elements
        $parent_te_array = $datatype_theme->getThemeElements();
        foreach($parent_te_array as  $parent_te) {
            $new_te = clone $parent_te;
            $new_te->setTheme($new_theme);
            self::persistObject($new_te);

            // Process Meta Records
            $parent_te_meta = $parent_te->getThemeElementMeta();
            $new_te_meta = clone $parent_te_meta;
            $new_te_meta->setThemeElement($new_te);
            self::persistObject($new_te_meta);

            // Theme Data Field
            $parent_theme_df_array = $parent_te->getThemeDataFields();
            foreach($parent_theme_df_array as $parent_tdf) {
                $new_tdf = clone $parent_tdf;
                $new_tdf->setThemeElement($new_te);
                foreach($datafields as $datafield) {
                    if($datafield->getMasterDataField()->getId() == $parent_tdf->getDataField()->getId()) {
                        $new_tdf->setDataField($datafield);
                        self::persistObject($new_tdf);
                        break;
                    }
                }
            }

            // Theme Data Type
            $parent_theme_dt_array = $parent_te->getThemeDataType();
            foreach($parent_theme_dt_array as $parent_tdt) {
                $new_tdt = clone $parent_tdt;
                $new_tdt->setThemeElement($new_te);
                // $new_tdt->setDataType($created_datatype);
                self::persistObject($new_tdt);
            }
        }

        return $new_theme;

    }
}