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
     * @var mixed
     */
    private $container;

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

            $bypass_cache = false;
            $em = $this->em;
            $redis = $this->container->get('snc_redis.default');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            $user_manager = $this->container->get('fos_user.user_manager');
            $user = $user_manager->findUserBy(array('id' => $user_id));

            print "test 1 - " . $datatype_id . "\n";
            $datatype_info_service = $this->container->get('odr.datatype_info_service');

            // Get the DataType to work with
            $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
            $datatype = $repo_datatype->find($datatype_id);
            print "test 2\n";

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

            // Get the Master Datatype to Clone
            $master_datatype = $datatype->getMasterDataType();
            $associated_datatypes = $datatype_info_service->getAssociatedDatatypes(array($master_datatype->getId()));
            print "test 3\n";

            // Clone Associated Datatypes
            foreach($associated_datatypes as $dt_id) {
                $new_datatype = null;
                $dt_master = null;
                if($dt_id == $datatype->getId()) {
                    $new_datatype = $datatype;
                    $dt_master = $master_datatype;
                    print "test 4a - " . $dt_id . " - " . $dt_master->getId() . "\n";
                }
                else {
                    $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
                    $dt_master = $repo_datatype->find($dt_id);
                    print "test 4b - " . $dt_id . " - " . $dt_master->getId() . "\n";
                }

                self::cloneDatatype($dt_master, $new_datatype);
            }

            // Clone associated datatype themes
            /*
            foreach($associated_datatypes as $dt_id) {
                $new_datatype = null;
                if($dt_id == $datatype->getId()) {
                    $new_datatype = $datatype;
                }
                self::cloneDatatype($master_datatype, $new_datatype);
            }
            */

            return "Clone datatype complete.";
        }
        catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    protected function persistObject($obj) {
        print "jjj\n";
        $this->em->persist($obj);
        print "kkkjjj\n";
        // $this->em->detach($obj);
        print "nnnjjj\n";
        $this->em->flush();
        print "llljjj\n";
        $this->em->refresh($obj);
    }

    protected function cloneDatatype(DataType $parent_datatype, DataType $new_datatype = null)
    {

        print "test 5\n";
        if ($new_datatype == null) {
            // Clone the parent to create a new datatype
            $new_datatype = clone $parent_datatype;
        }
        $new_datatype->setIsMasterType(false);
        $new_datatype->setMasterDataType($parent_datatype);
        print "id = " . $new_datatype->getId() . "\n";
        print "test 5a\n";
        self::persistObject($new_datatype);
        print "test 5b\n";

        print "test 6\n";
        $parent_meta = $parent_datatype->getDataTypeMeta();
        $new_meta = clone $parent_meta;
        $new_meta->setDataType($new_datatype);
        $new_meta->setMasterRevision(0);
        $new_meta->setMasterPublishedRevision(0);
        // Track the published version
        if($parent_datatype->getMasterPublishedRevision() == null) {
            $new_meta->setTrackingMasterRevision(-100);
        }
        else {
            $new_meta->setTrackingMasterRevision($parent_datatype->getMasterPublishedRevision());
        }


        // Get Render Plugins
        //  LEFT JOIN dtm.renderPlugin AS dt_rp
        $parent_render_plugin = $parent_meta->getRenderPlugin();
        $new_render_plugin = clone $parent_render_plugin;
        self::persistObject($new_render_plugin);
        print "test 7\n";

        $new_meta->setRenderPlugin($new_render_plugin);
        self::persistObject($new_meta);
        print "test 8\n";

        // Process data fields so themes and render plugin map can be created
        $parent_df_array = $parent_datatype->getDataFields();
        foreach ($parent_df_array as $parent_df) {
            $new_df = clone $parent_df;
            $new_df->setDataType($new_datatype);
            $new_df->setIsMasterField(false);
            $new_df->setMasterDatafield($parent_df);
            self::persistObject($new_df);
            print "test 9\n";

            // Process Meta Records
            $parent_df_meta = $parent_df->getDataFieldMeta();
            $new_df_meta = clone $parent_df_meta;
            $new_df_meta->setDataField($new_df);
            $new_df_meta->setMasterRevision(0);
            $new_df_meta->setMasterPublishedRevision(0);
            $new_df_meta->setTrackingMasterRevision($parent_df_meta->getMasterPublishedRevision());
            self::persistObject($new_df_meta);
            print "test 10\n";
        }

        // Get Field Render Plugins

        //  LEFT JOIN dt_rp.renderPluginInstance AS dt_rpi WITH (dt_rpi.dataType = dt)
        $repo_rpi = $this->em->getRepository('ODRAdminBundle:RenderPluginInstance');
        $parent_rpi = $repo_rpi->findOneBy(
            array(
                'dataType' => $parent_datatype->getId(),
                'renderPlugin' => $parent_render_plugin->getId()
            )
        );
        if($parent_rpi != null) {
            $new_rpi = clone $parent_rpi;
            $new_rpi->setDataType($new_datatype);
            self::persistObject($new_rpi);
            print "test 11\n";

            //  LEFT JOIN dt_rpi.renderPluginOptions AS dt_rpo
            $parent_rpo_array = $parent_rpi->getRenderPluginOptions();
            foreach ($parent_rpo_array as $parent_rpo) {
                $new_rpo = clone $parent_rpo;
                $new_rpo->setRenderPluginInstance($new_rpi);
                self::persistObject($new_rpo);
                print "test 12\n";
            }
        }
    }

    protected function cloneDatatypeTheme(DataType $parent_datatype, DataType $new_datatype = null) {
        // Get Themes
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
        $parent_theme = $repo_theme->findOneBy(
            array(
                'data_type' => $parent_datatype->getId(),
                'theme_type' => 'master'
            )
        );
        $new_theme = clone $parent_theme;
        $new_theme->setDataType($new_datatype);
        self::persistObject($new_theme);

        // Get Theme Elements
        $parent_te_array = $parent_theme->getThemeElements();
        foreach($parent_te_array as $parent_te) {
            $new_te = clone $parent_te;
            $new_te->setTheme($new_theme);
            self::persistObject($new_te);

            // Process Meta Records
            $parent_te_meta = $parent_te->getThemeElementMeta();
            $new_te_meta = clone $parent_te_meta;
            $new_te_meta->setThemeElement($new_te);
            self::persistObject($new_te_meta);
        }

        //  Need to do these after creating fields?
        //  LEFT JOIN dt_rpi.renderPluginMap AS dt_rpm
        //  LEFT JOIN dt_rpm.renderPluginFields AS dt_rpf
        //  LEFT JOIN dt_rpm.dataField AS dt_rpm_df
    }
}