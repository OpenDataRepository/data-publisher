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
class DatarecordInfoService
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

    public function getRelatedDatarecords($datarecord_id) {

        // ----------------------------------------
        // Always bypass cache if in dev mode?
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;

        // Grab all datarecords "associated" with the desired datarecord...
        $redis = $this->container->get('snc_redis.default');;
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        $associated_datarecords = self::getRedisData(($redis->get($redis_prefix.'.associated_datarecords_for_'.$datarecord_id)));
        if ($bypass_cache || $associated_datarecords == false) {
            $associated_datarecords = self::getAssociatedDatarecords(array($datarecord_id));

            $redis->set($redis_prefix.'.associated_datarecords_for_'.$datarecord_id, gzcompress(serialize($associated_datarecords)));
        }

        // Grab the cached versions of all of the associated datarecords, and store them all at the same level in a single array
        $datarecord_array = array();
        foreach ($associated_datarecords as $num => $dr_id) {
            $datarecord_data = self::getRedisData(($redis->get($redis_prefix.'.cached_datarecord_'.$dr_id)));
            if ($bypass_cache || $datarecord_data == false)
                $datarecord_data = self::getDatarecordData($dr_id, true);

            foreach ($datarecord_data as $dr_id => $data)
                $datarecord_array[$dr_id] = $data;
        }


        return $datarecord_array;

    }

    /**
     * Builds and returns a list of all child and linked datarecords of the given datarecord id.
     * Due to recursive interaction with self::getLinkedDatarecords(), this function doesn't attempt to store results in the cache.
     *
     * @param int[] $grandparent_ids
     *
     * @return int[]
     */
    protected function getAssociatedDatarecords($grandparent_ids)
    {
        // Locate all datarecords that are children of the datarecords listed in $grandparent_ids
        $query = $this->em->createQuery(
            'SELECT dr.id AS id
            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.grandparent AS grandparent
            WHERE grandparent.id IN (:grandparent_ids)
            AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( array('grandparent_ids' => $grandparent_ids) );
        $results = $query->getArrayResult();

        // Flatten the results array
        $datarecord_ids = array();
        foreach ($results as $result)
            $datarecord_ids[] = $result['id'];

        // Get all children and datarecords linked to all the datarecords in $datarecord_ids
        $linked_datarecord_ids = self::getLinkedDatarecords( $datarecord_ids);

        // Don't want any duplicate datarecord ids...
        $associated_datarecord_ids = array_unique( array_merge($grandparent_ids, $linked_datarecord_ids) );

        return $associated_datarecord_ids;
    }


    /**
     * Given a group's permission arrays, filter the provided datarecord/datatype arrays so twig doesn't render anything they're not supposed to see.
     *
     * @param array &$datatype_array    @see self::getDatatypeArray()
     * @param array &$datarecord_array  @see self::getDatarecordArray()
     * @param array $permissions_array  @see self::getUserPermissionsArray()
     */
    protected function filterByGroupPermissions(&$datatype_array, &$datarecord_array, $permissions_array)
    {
        $debug = true;
        $debug = false;

        if ($debug)
            print '----- permissions filter -----'."\n";

        // Also need to go through the datarecord array and remove both datarecords and datafields that the user isn't allowed to see
        foreach ($datarecord_array as $dr_id => $dr) {
            // Save datatype id of this datarecord
            $dt_id = $dr['dataType']['id'];

            // If there was no datatype permission entry for this datatype, have it default to false
            if ( !isset($can_view_datarecord[$dt_id]) )
                $can_view_datarecord[$dt_id] = false;

            // If the datarecord is non-public and user doesn't have the 'can_view_datarecord' permission, then remove the datarecord from the array
            if ( $dr['dataRecordMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datarecord[$dt_id] ) {
                unset( $datarecord_array[$dr_id] );
                if ($debug)
                    print 'removed non-public datarecord '.$dr_id."\n";

                // No sense checking anything else for this datarecord, skip to the next one
                continue;
            }

            // The user is allowed to view this datarecord...
            foreach ($dr['dataRecordFields'] as $df_id => $drf) {

                // Remove the datafield if needed
                if ( isset($datafields_to_remove[$df_id]) ) {
                    unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id] );
                    if ($debug)
                        print 'removed datafield '.$df_id.' from datarecord '.$dr_id."\n";

                    // No sense checking file/image public status, skip to the next datafield
                    continue;
                }

                // ...remove the files the user isn't allowed to see
                foreach ($drf['file'] as $file_num => $file) {
                    if ( $file['fileMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datarecord[$dt_id] ) {
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id]['file'][$file_num] );
                        if ($debug)
                            print 'removed non-public file '.$file['id'].' from datarecord '.$dr_id.' datatype '.$dt_id."\n";
                    }
                }

                // ...remove the images the user isn't allowed to see
                foreach ($drf['image'] as $image_num => $image) {
                    if ( $image['parent']['imageMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datarecord[$dt_id] ) {
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id]['image'][$image_num] );
                        if ($debug)
                            print 'removed non-public image '.$image['parent']['id'].' from datarecord '.$dr_id.' datatype '.$dt_id."\n";
                    }
                }
            }
        }
    }


    /**
     * Runs a single database query to get all non-layout data for a given grandparent datarecord.
     *
     * @param integer $grandparent_datarecord_id
     * @param boolean $force_rebuild
     *
     * @return array
     */
    protected function getDatarecordData($grandparent_datarecord_id, $force_rebuild = false)
    {
        /*
        $debug = true;
        $debug = false;
        $timing = true;
        $timing = false;

        $t0 = $t1 = null;
        if ($timing)
            $t0 = microtime(true);
        */
        // If datarecord data exists in memcached and user isn't demanding a fresh version, return that
        $redis = $this->container->get('snc_redis.default');;
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        if (!$force_rebuild) {
            $cached_datarecord_data = self::getRedisData(($redis->get($redis_prefix.'.cached_datarecord_'.$grandparent_datarecord_id)));
            if ( $cached_datarecord_data != false && count($cached_datarecord_data) > 0 )
                return $cached_datarecord_data;
        }


        // Otherwise...get all non-layout data for a given grandparent datarecord
        $query = $this->em->createQuery(
            'SELECT
               dr, drm, dr_cb, dr_ub, p_dr, gp_dr,
               dt, dtm, dt_eif, dt_nf, dt_sf,
               drf, e_f, e_fm, e_f_cb,
               e_i, e_im, e_ip, e_ipm, e_is, e_ip_cb,
               e_b, e_iv, e_dv, e_lt, e_lvc, e_mvc, e_svc, e_dtv, rs, ro,
               e_b_cb, e_iv_cb, e_dv_cb, e_lt_cb, e_lvc_cb, e_mvc_cb, e_svc_cb, e_dtv_cb, rs_cb,
               df,
               cdr, cdr_dt, ldt, ldr, ldr_dt

            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.dataRecordMeta AS drm
            LEFT JOIN dr.createdBy AS dr_cb
            LEFT JOIN dr.updatedBy AS dr_ub
            LEFT JOIN dr.parent AS p_dr
            LEFT JOIN dr.grandparent AS gp_dr

            LEFT JOIN dr.dataType AS dt
            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dtm.externalIdField AS dt_eif
            LEFT JOIN dtm.nameField AS dt_nf
            LEFT JOIN dtm.sortField AS dt_sf

            LEFT JOIN dr.dataRecordFields AS drf
            LEFT JOIN drf.file AS e_f
            LEFT JOIN e_f.fileMeta AS e_fm
            LEFT JOIN e_f.createdBy AS e_f_cb

            LEFT JOIN drf.image AS e_i
            LEFT JOIN e_i.imageMeta AS e_im
            LEFT JOIN e_i.parent AS e_ip
            LEFT JOIN e_ip.imageMeta AS e_ipm
            LEFT JOIN e_i.imageSize AS e_is
            LEFT JOIN e_ip.createdBy AS e_ip_cb

            LEFT JOIN drf.boolean AS e_b
            LEFT JOIN e_b.createdBy AS e_b_cb
            LEFT JOIN drf.integerValue AS e_iv
            LEFT JOIN e_iv.createdBy AS e_iv_cb
            LEFT JOIN drf.decimalValue AS e_dv
            LEFT JOIN e_dv.createdBy AS e_dv_cb
            LEFT JOIN drf.longText AS e_lt
            LEFT JOIN e_lt.createdBy AS e_lt_cb
            LEFT JOIN drf.longVarchar AS e_lvc
            LEFT JOIN e_lvc.createdBy AS e_lvc_cb
            LEFT JOIN drf.mediumVarchar AS e_mvc
            LEFT JOIN e_mvc.createdBy AS e_mvc_cb
            LEFT JOIN drf.shortVarchar AS e_svc
            LEFT JOIN e_svc.createdBy AS e_svc_cb
            LEFT JOIN drf.datetimeValue AS e_dtv
            LEFT JOIN e_dtv.createdBy AS e_dtv_cb
            LEFT JOIN drf.radioSelection AS rs
            LEFT JOIN rs.createdBy AS rs_cb
            LEFT JOIN rs.radioOption AS ro

            LEFT JOIN drf.dataField AS df

            LEFT JOIN dr.children AS cdr
            LEFT JOIN cdr.dataType AS cdr_dt

            LEFT JOIN dr.linkedDatarecords AS ldt
            LEFT JOIN ldt.descendant AS ldr
            LEFT JOIN ldr.dataType AS ldr_dt

            WHERE
                dr.grandparent = :grandparent_id
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND df.deletedAt IS NULL
                AND (e_i.id IS NULL OR e_i.original = 0)'
        )->setParameters(array('grandparent_id' => $grandparent_datarecord_id));

//print $query->getSQL();  exit();
        $datarecord_data = $query->getArrayResult();
//print '<pre>'.print_r($datarecord_data, true).'</pre>';  exit();

        /*
        if ($timing) {
            $t1 = microtime(true);
            $diff = $t1 - $t0;
            print 'getDatarecordData('.$grandparent_datarecord_id.')'."\n".'query execution in: '.$diff."\n";
        }
        */
        // The entity -> entity_metadata relationships have to be one -> many from a database perspective, even though there's only supposed to be a single non-deleted entity_metadata object for each entity
        // Therefore, the previous query generates an array that needs to be slightly flattened in a few places
        foreach ($datarecord_data as $dr_num => $dr) {
            // Flatten datarecord_meta
            $drm = $dr['dataRecordMeta'][0];
            $datarecord_data[$dr_num]['dataRecordMeta'] = $drm;
            $datarecord_data[$dr_num]['createdBy'] = self::cleanUserData( $dr['createdBy'] );
            $datarecord_data[$dr_num]['updatedBy'] = self::cleanUserData( $dr['updatedBy'] );

            // Store which datafields are used for the datatype's external_id_datafield, name_datafield, and sort_datafield
            $external_id_field = null;
            $name_datafield = null;
            $sort_datafield = null;

            if ( isset($dr['dataType']['dataTypeMeta'][0]['externalIdField']['id']) )
                $external_id_field = $dr['dataType']['dataTypeMeta'][0]['externalIdField']['id'];
            if ( isset($dr['dataType']['dataTypeMeta'][0]['nameField']['id']) )
                $name_datafield = $dr['dataType']['dataTypeMeta'][0]['nameField']['id'];
            if ( isset($dr['dataType']['dataTypeMeta'][0]['sortField']['id']) )
                $sort_datafield = $dr['dataType']['dataTypeMeta'][0]['sortField']['id'];

            $datarecord_data[$dr_num]['externalIdField_value'] = '';
            $datarecord_data[$dr_num]['nameField_value'] = '';
            $datarecord_data[$dr_num]['sortField_value'] = '';

            // Don't want to store the datatype's meta entry
            unset( $datarecord_data[$dr_num]['dataType']['dataTypeMeta'] );

            // Need to store a list of child/linked datarecords by their respective datatype ids
            $child_datarecords = array();
            foreach ($dr['children'] as $child_num => $cdr) {
                $cdr_id = $cdr['id'];
                $cdr_dt_id = $cdr['dataType']['id'];

                // A top-level datarecord is listed as its own parent in the database...don't want to store it in the list of children
                if ( $cdr_id == $dr['id'] )
                    continue;

                if ( !isset($child_datarecords[$cdr_dt_id]) )
                    $child_datarecords[$cdr_dt_id] = array();
                $child_datarecords[$cdr_dt_id][] = $cdr_id;
            }
            foreach ($dr['linkedDatarecords'] as $child_num => $ldt) {
                $ldr_id = $ldt['descendant']['id'];
                $ldr_dt_id = $ldt['descendant']['dataType']['id'];

                if ( !isset($child_datarecords[$ldr_dt_id]) )
                    $child_datarecords[$ldr_dt_id] = array();
                $child_datarecords[$ldr_dt_id][] = $ldr_id;
            }
            $datarecord_data[$dr_num]['children'] = $child_datarecords;
            unset( $datarecord_data[$dr_num]['linkedDatarecords'] );


            // Flatten datafield_meta of each datarecordfield, and organize by datafield id instead of some random number
            $new_drf_array = array();
            foreach ($dr['dataRecordFields'] as $drf_num => $drf) {

                $df_id = $drf['dataField']['id'];
                unset( $drf['dataField'] );

                // Flatten file metadata and get rid of encrypt_key
                foreach ($drf['file'] as $file_num => $file) {
                    unset( $drf['file'][$file_num]['encrypt_key'] ); // TODO - should encrypt_key actually remain in the array?

                    $fm = $file['fileMeta'][0];
                    $drf['file'][$file_num]['fileMeta'] = $fm;

                    // Get rid of all private/non-essential information in the createdBy association
                    $drf['file'][$file_num]['createdBy'] = self::cleanUserData( $drf['file'][$file_num]['createdBy'] );
                }

                // Flatten image metadata, get rid of both the thumbnail's and the parent's encrypt keys, and sort appropriately
                $sort_by_image_id = true;
                foreach ($drf['image'] as $image_num => $image) {
                    if ($image['parent']['imageMeta'][0]['displayorder'] != 0) {
                        $sort_by_image_id = false;
                        break;
                    }
                }

                $ordered_images = array();
                foreach ($drf['image'] as $image_num => $image) {
                    unset( $image['encrypt_key'] );
                    unset( $image['parent']['encrypt_key'] ); // TODO - should encrypt_key actually remain in the array?

                    unset( $image['imageMeta'] );   // This is a phantom meta entry created for this image's thumbnail
                    $im = $image['parent']['imageMeta'][0];
                    $image['parent']['imageMeta'] = $im;

                    // Get rid of all private/non-essential information in the createdBy association
                    $image['parent']['createdBy'] = self::cleanUserData( $image['parent']['createdBy'] );

                    if ($sort_by_image_id) {
                        // Store by parent id
                        $ordered_images[ $image['parent']['id'] ] = $image;
                    }
                    else {
                        // Store by display_order
                        $ordered_images[ $image['parent']['imageMeta']['displayorder'] ] = $image;
                    }
                }

                ksort($ordered_images);
                $drf['image'] = $ordered_images;

                // Scrub all user information from the rest of the array
                $keys = array('boolean', 'integerValue', 'decimalValue', 'longText', 'longVarchar', 'mediumVarchar', 'shortVarchar', 'datetimeValue');
                foreach ($keys as $typeclass) {
                    if ( count($drf[$typeclass]) > 0 ) {
                        $drf[$typeclass][0]['createdBy'] = self::cleanUserData( $drf[$typeclass][0]['createdBy'] );

                        // Store the value from this storage entity if it's the one being used for external_id/name/sort datafields
                        if ($external_id_field !== null && $external_id_field == $df_id) {
                            $datarecord_data[$dr_num]['externalIdField_value'] = $drf[$typeclass][0]['value'];
                        }
                        if ($name_datafield !== null && $name_datafield == $df_id) {
                            // Need to ensure this value is a string so php sorting functions don't complain
                            if ($typeclass == 'datetimeValue')
                                $datarecord_data[$dr_num]['nameField_value'] = $drf[$typeclass][0]['value']->format('Y-m-d');
                            else
                                $datarecord_data[$dr_num]['nameField_value'] = $drf[$typeclass][0]['value'];
                        }
                        if ($sort_datafield !== null && $sort_datafield == $df_id) {
                            // Need to ensure this value is a string so php sorting functions don't complain
                            if ($typeclass == 'datetimeValue')
                                $datarecord_data[$dr_num]['sortField_value'] = $drf[$typeclass][0]['value']->format('Y-m-d');
                            else
                                $datarecord_data[$dr_num]['sortField_value'] = $drf[$typeclass][0]['value'];
                        }
                    }
                }

                // Organize radio selections by radio option id
                $new_rs_array = array();
                foreach ($drf['radioSelection'] as $rs_num => $rs) {
                    $rs['createdBy'] = self::cleanUserData( $rs['createdBy'] );

                    $ro_id = $rs['radioOption']['id'];
                    $new_rs_array[$ro_id] = $rs;
                }

                $drf['radioSelection'] = $new_rs_array;
                $new_drf_array[$df_id] = $drf;
            }

            unset( $datarecord_data[$dr_num]['dataRecordFields'] );
            $datarecord_data[$dr_num]['dataRecordFields'] = $new_drf_array;
        }

        // Organize by datarecord id...DO NOT even attenpt to make this array recursive...for now...
        $formatted_datarecord_data = array();
        foreach ($datarecord_data as $num => $dr_data) {
            $dr_id = $dr_data['id'];

            // These two values should default to the datarecord id if empty
            if ( $dr_data['nameField_value'] == '' )
                $dr_data['nameField_value'] = $dr_id;
            if ( $dr_data['sortField_value'] == '' )
                $dr_data['sortField_value'] = $dr_id;

            $formatted_datarecord_data[$dr_id] = $dr_data;
        }

        $redis->set($redis_prefix.'.cached_datarecord_'.$grandparent_datarecord_id, gzcompress(serialize($formatted_datarecord_data)));
        return $formatted_datarecord_data;
    }

    /**
     * Removes all private/non-essential user info from an array generated by self::getDatarecordData() or self::getDatatypeData()
     *
     * @param array $user_data
     *
     * @return array
     */
    protected function cleanUserData($user_data)
    {
        foreach ($user_data as $key => $value) {
            if ($key !== 'username' && $key !== 'email' && $key !== 'firstName' && $key !== 'lastName'/* && $key !== 'institution' && $key !== 'position'*/)
                unset( $user_data[$key] );
        }

        return $user_data;
    }








































    /**
     * Gets all layout information required for the given datatype in array format
     *
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
     * Returns the id of the grandparent of the given datatype
     *
     * @param array $datatree_array         @see self::getDatatreeArray()
     * @param integer $initial_datatype_id
     *
     * @return integer
     */
    public function getGrandparentDatatypeId($datatree_array, $initial_datatype_id)
    {
        $grandparent_datatype_id = $initial_datatype_id;
        while( isset($datatree_array['descendant_of'][$grandparent_datatype_id]) && $datatree_array['descendant_of'][$grandparent_datatype_id] !== '' )
            $grandparent_datatype_id = $datatree_array['descendant_of'][$grandparent_datatype_id];

        return $grandparent_datatype_id;
    }

    /**
     * Utility function to returns the DataTree table in array format
     * TODO: This function is a really bad idea - will be absolutely GIGANTIC at some point.
     * Why is this needed? Plus, how do you know when it needs to be flushed?
     *
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


    /**
     * Determines and returns an array of top-level datatype ids
     *
     * @return int[]
     */
    public function getTopLevelDatatypes()
    {
        $query = $this->em->createQuery(
            'SELECT dt.id AS datatype_id
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $all_datatypes = array();
        foreach ($results as $num => $result)
            $all_datatypes[] = $result['datatype_id'];

        $query = $this->em->createQuery(
            'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
            FROM ODRAdminBundle:DataTree AS dt
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE dtm.is_link = 0
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $parent_of = array();
        foreach ($results as $num => $result)
            $parent_of[ $result['descendant_id'] ] = $result['ancestor_id'];

        $top_level_datatypes = array();
        foreach ($all_datatypes as $datatype_id) {
            if ( !isset($parent_of[$datatype_id]) )
                $top_level_datatypes[] = $datatype_id;
        }

        return $top_level_datatypes;
    }

}