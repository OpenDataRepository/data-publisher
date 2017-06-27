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
class CreateDatatypeService
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

    /**
     * Utility function that does the work of encrypting a given File/Image entity.
     *
     * @throws \Exception
     *
     * @param integer $object_id The id of the File/Image to encrypt
     * @param string $object_type "File" or "Image"
     *
     */
    public function createDatatypeFromMaster($datatype_id, $user_id)
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

            // Check if datatype is not in "create" mode
            if($datatype->getSetupStep() != "create") {
                throw new \Exception("Datatype is not in the correct setup mode.  Setup step was: " . $datatype->getSetupStep());
            }

            if($datatype->getMasterDataType() == null || $datatype->getMasterDataType()->getId() < 1) {
                throw new \Exception("Invalid master template id.");
            }

            $datatype_prefix = $datatype->getShortName();

            // Get the Master Datatype to Clone
            $master_datatype = $datatype->getMasterDataType();
            $associated_datatypes = $datatype_info_service->getAssociatedDatatypes(array($master_datatype->getId()));

            // Clone Associated Datatypes
            $this->created_datatypes = array();
            foreach($associated_datatypes as $dt_id) {
                $new_datatype = null;
                $dt_master = null;
                if($dt_id == $master_datatype->getId()) {
                    $new_datatype = $datatype;
                    $dt_master = $master_datatype;
                }
                else {
                    $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
                    $dt_master = $repo_datatype->find($dt_id);
                }

                self::cloneDatatype($dt_master, $new_datatype, $datatype_prefix);

                if($dt_id == $datatype->getId()) {
                    $datatype = $new_datatype;
                }

            }

            // Clone Data Tree and Data Tree Meta
            self::cloneDataTree($this->created_datatypes, $master_datatype);

            // Clone associated datatype themes
            foreach($this->created_datatypes as $datatype) {
                self::cloneDatatypeThemeFromMaster($datatype);
            }

            // Save state change of data type
            $datatype->setSetupStep('design');
            self::persistObject($datatype);

            return "Clone datatype complete.";
        }
        catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param $created_datatypes
     * @param DataType $parent_datatype
     */
    protected function cloneDataTree($created_datatypes, Datatype $parent_datatype) {
        $repo_dt = $this->em->getRepository('ODRAdminBundle:DataTree');
        $dt_array = $repo_dt->findBy(
            array(
                'ancestor' => $parent_datatype->getId(),
            )
        );

        $current_ancestor = null;
        foreach($this->created_datatypes as $num => $datatype) {
            if($datatype->getMasterDataType()->getId() == $parent_datatype->getId()) {
                $current_ancestor = $datatype;
            }
        }

        // We get the descendants
        foreach($dt_array as $dt) {
            foreach($created_datatypes as $datatype) {
                if($dt->getDescendant()->getId() == $datatype->getMasterDataType()->getId()) {
                    $new_dt = new DataTree();
                    $new_dt->setAncestor($current_ancestor);
                    $new_dt->setDescendant($datatype);
                    self::persistObject($new_dt);

                    // Clone meta
                    $new_meta = clone $dt->getDataTreeMeta();
                    $new_meta->setDataTree($new_dt);
                    self::persistObject($new_meta);

                    // Check children
                    self::cloneDataTree($created_datatypes, $datatype->getMasterDataType());
                }
            }
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
     * @param DataType $parent_datatype
     * @param DataType|null $new_datatype
     * @param string $datatype_prefix
     */
    protected function cloneDatatype(DataType $parent_datatype, DataType $new_datatype = null, $datatype_prefix = "")
    {

        if ($new_datatype == null) {
            // Clone the parent to create a new datatype
            $new_datatype = clone $parent_datatype;
        }
        $new_datatype->setIsMasterType(false);
        $new_datatype->setMasterDataType($parent_datatype);
        self::persistObject($new_datatype);
        array_push($this->created_datatypes, $new_datatype);

        $parent_meta = $parent_datatype->getDataTypeMeta();
        // Meta might already exist - need to copy relevant fields and delete
        $existing_meta = $new_datatype->getDataTypeMeta();

        $new_meta = clone $parent_meta;
        if($existing_meta != null) {
            $new_meta->setShortName($existing_meta->getShortName());
            // $new_meta->setSearchSlug($existing_meta->getSearchSlug());
            $new_meta->setSearchSlug('data_' . $new_datatype->getId());
            $new_meta->setLongName($existing_meta->getLongName());
            $new_meta->setDescription($existing_meta->getDescription());
            // Delete Entity
            $this->em->remove($existing_meta);
            $this->em->flush();
        }

        // Use a prefix if short name not equal prefix
        if($new_meta->getShortName() != $datatype_prefix) {
            $new_meta->setLongName($datatype_prefix . " - " . $new_meta->getShortName());
        }
        $new_meta->setDataType($new_datatype);
        $new_meta->setMasterRevision(0);
        $new_meta->setMasterPublishedRevision(0);
        // Track the published version
        if($parent_datatype->getDataTypeMeta()->getMasterPublishedRevision() == null) {
            $new_meta->setTrackingMasterRevision(-100);
        }
        else {
            $new_meta->setTrackingMasterRevision($parent_datatype->getDataTypeMeta()->getMasterPublishedRevision());
        }

        // Get Render Plugins
        //  LEFT JOIN dtm.renderPlugin AS dt_rp
        $parent_render_plugin = $parent_meta->getRenderPlugin();

        $new_meta->setRenderPlugin($parent_render_plugin);
        self::persistObject($new_meta);

        // Create DataType Permissions for child/linked types
        $pms = $this->container->get('odr.permissions_management_service');
        $pms->createGroupsForDatatype($this->user, $new_datatype);


        // Process data fields so themes and render plugin map can be created
        $parent_df_array = $parent_datatype->getDataFields();
        foreach ($parent_df_array as $parent_df) {
            $new_df = clone $parent_df;
            $new_df->setDataType($new_datatype);
            $new_df->setIsMasterField(false);
            $new_df->setMasterDatafield($parent_df);
            self::persistObject($new_df, true);

            // Process Meta Records
            $parent_df_meta = $parent_df->getDataFieldMeta();
            if($parent_df_meta) {
                // TODO This should always exist.  Likely issue was caused by
                // the fact that a previous test failed. It's bad news that these
                // things can fail and now warn the user...
                $new_df_meta = clone $parent_df_meta;
                $new_df_meta->setDataField($new_df);
                $new_df_meta->setMasterRevision(0);
                $new_df_meta->setMasterPublishedRevision(0);
                $new_df_meta->setTrackingMasterRevision($parent_df_meta->getMasterPublishedRevision());
                self::persistObject($new_df_meta, true);

                // Need to process Radio Options....
                $parent_ro_array = $parent_df->getRadioOptions();
                if(count($parent_ro_array) > 0) {
                    foreach($parent_ro_array as $parent_ro) {
                        $new_ro = clone $parent_ro;
                        $new_ro->setDataField($new_df);
                        self::persistObject($new_ro, true);

                        $parent_ro_meta = $parent_ro->getRadioOptionMeta();
                        $new_ro_meta = clone $parent_ro_meta;
                        $new_ro_meta->setRadioOption($new_ro);
                        self::persistObject($new_ro_meta, true);
                    }
                }
            }
            // Process field plugins
            if($parent_df->getRenderPlugin()) {
                self::cloneRenderPluginSettings($parent_df->getRenderPlugin(), null, $new_df);
            }

            $pms->createGroupsForDatafield($this->user, $new_df);
        }

        // Now that fields are created, get Process Datatype Render Plugin.
        self::cloneRenderPluginSettings($parent_datatype->getRenderPlugin(), $new_datatype);


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
        $cloned_theme_id = "0"
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
        if($cloned_theme_id > 0) {
            $new_theme->setParentTheme($cloned_theme_id);
        }
        $new_theme->setThemeType($to_theme_type);
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