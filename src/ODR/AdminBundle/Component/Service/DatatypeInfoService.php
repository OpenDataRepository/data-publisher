<?php

namespace ODR\AdminBundle\Component\Service;

// Controllers/Classes
use ODR\OpenRepository\SearchBundle\Controller\DefaultController as SearchController;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;

use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Doctrine\ORM\EntityManager;


/**
 * Created by PhpStorm.
 * User: nate
 * Date: 10/14/16
 * Time: 11:59 AM
 */
class DatatypeInfoService
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
     * Builds and returns a list of all datarecords linked to from the provided datarecord ids.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer[] $ancestor_ids
     *
     * @return integer[]
     */
    private function getLinkedDatarecords($ancestor_ids)
    {
        // Locate all datarecords that are linked to from any datarecord listed in $datarecord_ids
        $query = $this->em->createQuery(
            'SELECT descendant.id AS descendant_id
            FROM ODRAdminBundle:LinkedDataTree AS ldt
            JOIN ldt.ancestor AS ancestor
            JOIN ldt.descendant AS descendant
            WHERE ancestor.id IN (:ancestor_ids)
            AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( array('ancestor_ids' => $ancestor_ids) );
        $results = $query->getArrayResult();

        // Flatten the results array
        $linked_datarecord_ids = array();
        foreach ($results as $result)
            $linked_datarecord_ids[] = $result['descendant_id'];

        // If there were datarecords found, get all of their associated child/linked datarecords
        $associated_datarecord_ids = array();
        if ( count($linked_datarecord_ids) > 0 )
            $associated_datarecord_ids = self::getAssociatedDatarecords($linked_datarecord_ids);

        // Don't want any duplicate datarecord ids...
        $linked_datarecord_ids = array_unique( array_merge($linked_datarecord_ids, $associated_datarecord_ids) );

        return $linked_datarecord_ids;
    }

    /**
     * Builds and returns a list of all child and linked datatype ids related to the given datatype id.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param int[] $datatype_ids
     * @param boolean $include_links
     *
     * @return int[]
     */
    public function getAssociatedDatatypes($datatype_ids, $include_links = true)
    {
        // Locate all datatypes that are either children of or linked to the datatypes in $datatype_ids
        $results = array();
        if ($include_links) {
            $query = $this->em->createQuery(
                'SELECT descendant.id AS descendant_id
                FROM ODRAdminBundle:DataTree AS dt
                LEFT JOIN dt.dataTreeMeta AS dtm
                LEFT JOIN dt.ancestor AS ancestor
                LEFT JOIN dt.descendant AS descendant
                WHERE ancestor.id IN (:ancestor_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('ancestor_ids' => $datatype_ids) );
            $results = $query->getArrayResult();
        }
        else {
            $query = $this->em->createQuery(
                'SELECT descendant.id AS descendant_id
                FROM ODRAdminBundle:DataTree AS dt
                LEFT JOIN dt.dataTreeMeta AS dtm
                LEFT JOIN dt.ancestor AS ancestor
                LEFT JOIN dt.descendant AS descendant
                WHERE dtm.is_link = :is_link AND ancestor.id IN (:ancestor_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('is_link' => 0, 'ancestor_ids' => $datatype_ids) );
            $results = $query->getArrayResult();
        }

        // Flatten the resulting array...
        $child_datatype_ids = array();
        foreach ($results as $num => $result)
            $child_datatype_ids[] = $result['descendant_id'];

        // If child datatypes were found, also see if those child datatypes have children of their own
        if ( count($child_datatype_ids) > 0 )
            $child_datatype_ids = array_merge( $child_datatype_ids, self::getAssociatedDatatypes($child_datatype_ids, $include_links) );

        // Return an array of the requested datatype ids and their children
        $associated_datatypes = array_unique( array_merge($child_datatype_ids, $datatype_ids) );
        return $associated_datatypes;
    }


    /**
     * Automatically decompresses and unserializes redis data.
     *
     * @throws \Exception
     *
     * @param string $redis_value - the value returned by the redis call.
     *
     * @return boolean|string
     */
    public static function getRedisData($redis_value) {
        if(strlen($redis_value) > 0) {
            return unserialize(gzuncompress($redis_value));
        }
        return false;
    }

    /**
     * Gets all layout information required for the given datatype in array format
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datatree_array
     * @param integer $datatype_id
     * @param boolean $force_rebuild
     *
     * @return array
     */
    public function getDatatypeData($datatree_array, $datatype_id, $force_rebuild = false)
    {

        // If datatype data exists in memcached and user isn't demanding a fresh version, return that
        $redis = $this->container->get('snc_redis.default');;
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        if (!$force_rebuild) {
            $cached_datatype_data = self::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$datatype_id)));
            if ( $cached_datatype_data != false && count($cached_datatype_data) > 0 )
                return $cached_datatype_data;
        }


        // Otherwise...get all non-layout data for a given grandparent datarecord
        $query = $this->em->createQuery(
            'SELECT
                t, tm,
                dt, dtm, dt_rp, dt_rpi, dt_rpo, dt_rpm, dt_rpf, dt_rpm_df,
                te, tem,
                tdf, df, ro, rom,
                dfm, ft, df_rp, df_rpi, df_rpo, df_rpm,
                tdt, c_dt

            FROM ODRAdminBundle:dataType AS dt
            LEFT JOIN dt.dataTypeMeta AS dtm

            LEFT JOIN dt.themes AS t
            LEFT JOIN t.themeMeta AS tm

            LEFT JOIN dtm.renderPlugin AS dt_rp
            LEFT JOIN dt_rp.renderPluginInstance AS dt_rpi WITH (dt_rpi.dataType = dt)
            LEFT JOIN dt_rpi.renderPluginOptions AS dt_rpo
            LEFT JOIN dt_rpi.renderPluginMap AS dt_rpm
            LEFT JOIN dt_rpm.renderPluginFields AS dt_rpf
            LEFT JOIN dt_rpm.dataField AS dt_rpm_df

            LEFT JOIN t.themeElements AS te
            LEFT JOIN te.themeElementMeta AS tem

            LEFT JOIN te.themeDataFields AS tdf
            LEFT JOIN tdf.dataField AS df
            LEFT JOIN df.radioOptions AS ro
            LEFT JOIN ro.radioOptionMeta AS rom

            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN dfm.fieldType AS ft

            LEFT JOIN dfm.renderPlugin AS df_rp
            LEFT JOIN df_rp.renderPluginInstance AS df_rpi WITH (df_rpi.dataField = df)
            LEFT JOIN df_rpi.renderPluginOptions AS df_rpo
            LEFT JOIN df_rpi.renderPluginMap AS df_rpm

            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.dataType AS c_dt

            WHERE
                dt.id = :datatype_id
                AND t.deletedAt IS NULL AND dt.deletedAt IS NULL AND te.deletedAt IS NULL
            ORDER BY dt.id, t.id, tem.displayOrder, te.id, tdf.displayOrder, df.id, rom.displayOrder, ro.id'
        )->setParameters( array('datatype_id' => $datatype_id) );

        $datatype_data = $query->getArrayResult();

        // The entity -> entity_metadata relationships have to be one -> many from a database perspective, even though there's only supposed to be a single non-deleted entity_metadata object for each entity
        // Therefore, the preceeding query generates an array that needs to be slightly flattened in a few places
        foreach ($datatype_data as $dt_num => $dt) {
            // Flatten datatype meta
            $dtm = $dt['dataTypeMeta'][0];
            $datatype_data[$dt_num]['dataTypeMeta'] = $dtm;

            // Flatten theme_meta of each theme, and organize by theme id instead of a random number
            $new_theme_array = array();
            foreach ($dt['themes'] as $t_num => $theme) {
                $theme_id = $theme['id'];

                $tm = $theme['themeMeta'][0];
                $theme['themeMeta'] = $tm;

                // Flatten theme_element_meta of each theme_element
                foreach ($theme['themeElements'] as $te_num => $te) {
                    $tem = $te['themeElementMeta'][0];
                    $theme['themeElements'][$te_num]['themeElementMeta'] = $tem;

                    // Flatten datafield_meta of each datafield
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        $dfm = $tdf['dataField']['dataFieldMeta'][0];
                        $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['dataFieldMeta'] = $dfm;

                        // Flatten radio options if it exists
                        foreach ($tdf['dataField']['radioOptions'] as $ro_num => $ro) {
                            $rom = $ro['radioOptionMeta'][0];
                            $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['radioOptions'][$ro_num]['radioOptionMeta'] = $rom;
                        }
                        if ( count($tdf['dataField']['radioOptions']) == 0 )
                            unset( $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['radioOptions'] );
                    }

                    // Attach the is_link property to each of the theme_datatype entries
                    foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                        $child_datatype_id = $tdt['dataType']['id'];

                        if ( isset($datatree_array['linked_from'][$child_datatype_id]) && in_array($datatype_id, $datatree_array['linked_from'][$child_datatype_id]) )
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['is_link'] = 1;
                        else
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['is_link'] = 0;

                        if ( isset($datatree_array['multiple_allowed'][$child_datatype_id]) && in_array($datatype_id, $datatree_array['multiple_allowed'][$child_datatype_id]) )
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['multiple_allowed'] = 1;
                        else
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['multiple_allowed'] = 0;
                    }

                    // Easier on twig if these arrays simply don't exist if nothing is in them...
                    if ( count($te['themeDataFields']) == 0 )
                        unset( $theme['themeElements'][$te_num]['themeDataFields'] );
                    if ( count($te['themeDataType']) == 0 )
                        unset( $theme['themeElements'][$te_num]['themeDataType'] );
                }

                $new_theme_array[$theme_id] = $theme;
            }

            unset( $datatype_data[$dt_num]['themes'] );
            $datatype_data[$dt_num]['themes'] = $new_theme_array;
        }

        // Organize by datatype id
        $formatted_datatype_data = array();
        foreach ($datatype_data as $num => $dt_data) {
            $dt_id = $dt_data['id'];

            $formatted_datatype_data[$dt_id] = $dt_data;
        }

        $redis->set($redis_prefix.'.cached_datatype_'.$datatype_id, gzcompress(serialize($formatted_datatype_data)));
        return $formatted_datatype_data;
    }


    /**
     * Utility function to returns the DataTree table in array format
     * TODO: This function is a really bad idea - will be absolutely GIGANTIC at some point.
     * Why is this needed? Plus, how do you know when it needs to be flushed?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param boolean $force_rebuild
     *
     * @return array
     */
    public function getDatatreeArray($force_rebuild = false)
    {
        // Attempt to load from cache first
        $redis = $this->container->get('snc_redis.default');
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        $datatree_array = self::getRedisData(($redis->get($redis_prefix.'.cached_datatree_array')));
        if ( !($force_rebuild || $datatree_array == false) ) {
            return $datatree_array;
        }

        $query = $this->em->createQuery(
            'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id, dtm.is_link AS is_link, dtm.multiple_allowed AS multiple_allowed
            FROM ODRAdminBundle:DataType AS ancestor
            JOIN ODRAdminBundle:DataTree AS dt WITH ancestor = dt.ancestor
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE ancestor.deletedAt IS NULL AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND descendant.deletedAt IS NULL');
        $results = $query->getArrayResult();

        $datatree_array = array(
            'descendant_of' => array(),
            'linked_from' => array(),
            'multiple_allowed' => array(),
        );
        foreach ($results as $num => $result) {
            $ancestor_id = $result['ancestor_id'];
            $descendant_id = $result['descendant_id'];
            $is_link = $result['is_link'];
            $multiple_allowed = $result['multiple_allowed'];

            if ( !isset($datatree_array['descendant_of'][$ancestor_id]) )
                $datatree_array['descendant_of'][$ancestor_id] = '';

            if ($is_link == 0) {
                $datatree_array['descendant_of'][$descendant_id] = $ancestor_id;
            }
            else {
                if ( !isset($datatree_array['linked_from'][$descendant_id]) )
                    $datatree_array['linked_from'][$descendant_id] = array();

                $datatree_array['linked_from'][$descendant_id][] = $ancestor_id;
            }

            if ($multiple_allowed == 1) {
                if ( !isset($datatree_array['multiple_allowed'][$descendant_id]) )
                    $datatree_array['multiple_allowed'][$descendant_id] = array();

                $datatree_array['multiple_allowed'][$descendant_id][] = $ancestor_id;
            }
        }

        // Store in cache and return
        $redis->set($redis_prefix.'.cached_datatree_array', gzcompress(serialize($datatree_array)));
        return $datatree_array;
    }

}