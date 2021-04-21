<?php

/**
 * Open Data Repository Data Publisher
 * Datarecord Info Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get and rebuild the cached version of the datarecord array.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\TagTree;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
// Utility
use ODR\AdminBundle\Component\Utility\UniqueUtility;
use ODR\AdminBundle\Component\Utility\UserUtility;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class DatarecordInfoService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var DatatreeInfoService
     */
    private $dti_service;

    /**
     * @var TagHelperService
     */
    private $th_service;

    /**
     * @var CsrfTokenManager
     */
    private $token_manager;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * DatarecordInfoService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatatreeInfoService $datatree_info_service
     * @param TagHelperService $tag_helper_service
     * @param CsrfTokenManager $token_manager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatreeInfoService $datatree_info_service,
        TagHelperService $tag_helper_service,
        CsrfTokenManager $token_manager,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dti_service = $datatree_info_service;
        $this->th_service = $tag_helper_service;
        $this->token_manager = $token_manager;
        $this->logger = $logger;
    }


    /**
     * Attempts to locate and return a datarecord with the given external id.
     *
     * @param DataFields $external_id_field
     * @param string $external_id_value
     *
     * @return DataRecord|null
     */
    public function getDatarecordByExternalId($external_id_field, $external_id_value)
    {
        // Verify that the field can actually be an external id field before searching...
        if ( !$external_id_field->getIsUnique() )
            throw new ODRBadRequestException('getDatarecordByExternalId() called with non-unique datafield', 0x3cff5d01);

        // Attempt to locate the datarecord using the given external id
        $query = $this->em->createQuery(
           'SELECT dr
            FROM ODRAdminBundle:'.$external_id_field->getFieldType()->getTypeClass().' AS e
            JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
            WHERE e.dataField = :datafield AND e.value = :datafield_value
            AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
        )->setParameters(
            array(
                'datafield' => $external_id_field->getId(),
                'datafield_value' => $external_id_value
            )
        );
        $results = $query->getResult();

        // Return the datarecord if it exists
        $datarecord = null;
        if ( isset($results[0]) )
            $datarecord = $results[0];

        return $datarecord;
    }


    /**
     * Attempts to locate and return a child datarecord from both its external id and its parent
     * datarecord's external id.
     *
     * @param DataFields $child_external_id_field
     * @param string $child_external_id_value
     * @param DataFields $parent_external_id_field
     * @param string $parent_external_id_value
     *
     * @return DataRecord|null
     */
    public function getChildDatarecordByExternalId($child_external_id_field, $child_external_id_value, $parent_external_id_field, $parent_external_id_value)
    {
        // Verify that both fields can actually be external id fields before searching...
        if ( !$child_external_id_field->getIsUnique() )
            throw new ODRBadRequestException('getChildDatarecordByExternalId() called with non-unique child datafield', 0xe0ae9098);
        if ( !$parent_external_id_field->getIsUnique() )
            throw new ODRBadRequestException('getChildDatarecordByExternalId() called with non-unique parent datafield', 0xe0ae9098);

        // Attempt to locate the datarecord using the given external id
        $query = $this->em->createQuery(
           'SELECT dr
            FROM ODRAdminBundle:'.$child_external_id_field->getFieldType()->getTypeClass().' AS e_1
            JOIN ODRAdminBundle:DataRecordFields AS drf_1 WITH e_1.dataRecordFields = drf_1
            JOIN ODRAdminBundle:DataRecord AS dr WITH drf_1.dataRecord = dr
            JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
            JOIN ODRAdminBundle:DataRecordFields AS drf_2 WITH drf_2.dataRecord = parent
            JOIN ODRAdminBundle:'.$parent_external_id_field->getFieldType()->getTypeClass().' AS e_2 WITH e_2.dataRecordFields = drf_2
            WHERE dr.id != parent.id
            AND e_1.dataField = :child_datafield AND e_2.dataField = :parent_datafield
            AND e_1.value = :child_datafield_value AND e_2.value = :parent_datafield_value
            AND e_1.deletedAt IS NULL AND drf_1.deletedAt IS NULL AND dr.deletedAt IS NULL
            AND parent.deletedAt IS NULL AND drf_2.deletedAt IS NULL AND e_2.deletedAt IS NULL'
        )->setParameters(
            array(
                'child_datafield' => $child_external_id_field->getId(),
                'child_datafield_value' => $child_external_id_value,
                'parent_datafield' => $parent_external_id_field->getId(),
                'parent_datafield_value' => $parent_external_id_value
            )
        );
        $results = $query->getResult();

        // Return the datarecord if it exists
        $datarecord = null;
        if ( isset($results[0]) )
            $datarecord = $results[0];

        return $datarecord;
    }


    /**
     * Attempts to locate and return a single child datarecord based on its parent's external id.
     *
     * If more than one child datarecord exists, the function returns null.
     *
     * @param DataType $child_datatype
     * @param DataFields $parent_external_id_field
     * @param string $parent_external_id_value
     *
     * @return DataRecord|null
     */
    public function getSingleChildDatarecordByParent($child_datatype, $parent_external_id_field, $parent_external_id_value)
    {
        // Verify that the field can actually be an external id field before searching...
        if ( !$parent_external_id_field->getIsUnique() )
            throw new ODRBadRequestException('getChildDatarecordByParent() called with non-unique datafield', 0x5c705932);

        // Attempt to locate the datarecord using the given external id
        $query = $this->em->createQuery(
           'SELECT dr
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
            JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = parent
            JOIN ODRAdminBundle:'.$parent_external_id_field->getFieldType()->getTypeClass().' AS e WITH e.dataRecordFields = drf
            WHERE dr.dataType = :child_datatype_id AND e.dataField = :parent_datafield
            AND e.value = :parent_datafield_value
            AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND e.deletedAt IS NULL'
        )->setParameters(
            array(
                'child_datatype_id' => $child_datatype->getId(),
                'parent_datafield_value' => $parent_external_id_value,
                'parent_datafield' => $parent_external_id_field->getId()
            )
        );
        $results = $query->getResult();

        // Return the datarecord if it exists, and also return null if there's more than one...the
        //  function is called to determine whether the parent datarecord has a single child datarecord
        //  that it can overwrite during importing
        $datarecord = null;
        if ( isset($results[0]) && count($results) == 1 )
            $datarecord = $results[0];

        return $datarecord;
    }


    /**
     * Loads and returns the cached data array for the requested datarecord.  The returned array
     * contains data of all datarecords with the requested datarecord as their grandparent, as
     * well as any datarecords that are linked to by the requested datarecord or its children.
     *
     * Use self::stackDatarecordArray() to get an array structure where child/linked datarecords
     * are stored "underneath" their parent datarecords.
     *
     * @param integer $grandparent_datarecord_id
     * @param bool $include_links  If true, then the returned array will also contain linked datarecords
     *
     * @return array
     */
    public function getDatarecordArray($grandparent_datarecord_id, $include_links = true)
    {
        $associated_datarecords = array();
        if ($include_links) {
            // Need to locate all linked datarecords for the provided datarecord
            $associated_datarecords = $this->cache_service->get('associated_datarecords_for_'.$grandparent_datarecord_id);
            if ($associated_datarecords == false) {
                $associated_datarecords = $this->dti_service->getAssociatedDatarecords($grandparent_datarecord_id);

                // Save the list of associated datarecords back into the cache
                $this->cache_service->set('associated_datarecords_for_'.$grandparent_datarecord_id, $associated_datarecords);
            }
        }
        else {
            // Don't want any datarecords that are linked to from the given grandparent datarecord
            $associated_datarecords[] = $grandparent_datarecord_id;
        }

        // Grab the cached versions of all of the associated datarecords, and store them all at the same level in a single array
        $datarecord_array = array();
        foreach ($associated_datarecords as $num => $dr_id) {
            $datarecord_data = $this->cache_service->get('cached_datarecord_'.$dr_id);
            if ($datarecord_data == false)
                $datarecord_data = self::buildDatarecordData($dr_id);

            // TODO - if sortfield belongs to a linked datatype, then sortfieldValue doesn't contain the value used for sorting

            foreach ($datarecord_data as $dr_id => $data)
                $datarecord_array[$dr_id] = $data;
        }

        return $datarecord_array;
    }


    /**
     * Runs a single database query to get all non-layout data for a given grandparent datarecord.
     *
     * @param integer $grandparent_datarecord_id
     *
     * @return array
     */
    private function buildDatarecordData($grandparent_datarecord_id)
    {
        // This function is only called when the cache entry doesn't exist

        // Otherwise...get all non-layout data for the requested grandparent datarecord
        $query = $this->em->createQuery(
           'SELECT
               dr, partial drm.{id, publicDate}, partial p_dr.{id}, partial gp_dr.{id},
               partial dr_cb.{id, username, email, firstName, lastName},
               partial dr_ub.{id, username, email, firstName, lastName},

               dt, partial gp_dt.{id}, partial mdt.{id, unique_id}, partial mf.{id, unique_id},
               dtm, partial dt_eif.{id}, partial dt_nf.{id}, partial dt_sf.{id},

               drf, partial df.{id, fieldUuid, templateFieldUuid}, partial dfm.{id, fieldName, xml_fieldName}, partial ft.{id, typeClass, typeName},
               e_f, e_fm, partial e_f_cb.{id, username, email, firstName, lastName},
               e_i, e_im, e_ip, e_ipm, e_is, partial e_ip_cb.{id, username, email, firstName, lastName},

               e_b, e_iv, e_dv, e_lt, e_lvc, e_mvc, e_svc, e_dtv, rs, ro, ts, t,

               partial e_b_ub.{id, username, email, firstName, lastName},
               partial e_iv_ub.{id, username, email, firstName, lastName},
               partial e_dv_ub.{id, username, email, firstName, lastName},
               partial e_lt_ub.{id, username, email, firstName, lastName},
               partial e_lvc_ub.{id, username, email, firstName, lastName},
               partial e_mvc_ub.{id, username, email, firstName, lastName},
               partial e_svc_ub.{id, username, email, firstName, lastName},
               partial e_dtv_ub.{id, username, email, firstName, lastName},
               partial rs_ub.{id, username, email, firstName, lastName},
               partial ts_ub.{id, username, email, firstName, lastName},

               partial cdr.{id}, partial cdr_dt.{id},
               ldt, partial ldr.{id}, partial ldr_dt.{id}

            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.dataRecordMeta AS drm
            LEFT JOIN dr.createdBy AS dr_cb
            LEFT JOIN dr.updatedBy AS dr_ub
            LEFT JOIN dr.parent AS p_dr
            LEFT JOIN dr.grandparent AS gp_dr

            LEFT JOIN dr.dataType AS dt
            LEFT JOIN dt.grandparent AS gp_dt
            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dt.masterDataType AS mdt
            LEFT JOIN dt.metadata_for AS mf

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
            LEFT JOIN e_b.updatedBy AS e_b_ub
            LEFT JOIN drf.integerValue AS e_iv
            LEFT JOIN e_iv.updatedBy AS e_iv_ub
            LEFT JOIN drf.decimalValue AS e_dv
            LEFT JOIN e_dv.updatedBy AS e_dv_ub
            LEFT JOIN drf.longText AS e_lt
            LEFT JOIN e_lt.updatedBy AS e_lt_ub
            LEFT JOIN drf.longVarchar AS e_lvc
            LEFT JOIN e_lvc.updatedBy AS e_lvc_ub
            LEFT JOIN drf.mediumVarchar AS e_mvc
            LEFT JOIN e_mvc.updatedBy AS e_mvc_ub
            LEFT JOIN drf.shortVarchar AS e_svc
            LEFT JOIN e_svc.updatedBy AS e_svc_ub
            LEFT JOIN drf.datetimeValue AS e_dtv
            LEFT JOIN e_dtv.updatedBy AS e_dtv_ub
            LEFT JOIN drf.radioSelection AS rs
            LEFT JOIN rs.updatedBy AS rs_ub
            LEFT JOIN rs.radioOption AS ro
            LEFT JOIN drf.tagSelection AS ts
            LEFT JOIN ts.updatedBy AS ts_ub
            LEFT JOIN ts.tag AS t

            LEFT JOIN drf.dataField AS df
            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN dfm.fieldType AS ft

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

        $datarecord_data = $query->getArrayResult();

        // TODO - if $datarecord_data is empty, then $grandparent_datarecord_id was deleted...should this return something special in that case?

        // The datarecordField entry returned by the preceeding query will have quite a few blank
        //  subarrays...all but the following keys should be unset in order to reduce the amount of
        //  memory that php needs to allocate to store a cached datarecord entry...it adds up.
        $drf_keys_to_keep = array(
            'id', 'created',
            'file', 'image',       // keeping these for now because multiple pieces of code assume they exist
            'child_tagSelections', // this property will only be created if 'tagSelection' exists
        );

        // If this datarecord has tag datafields, then a list of "child tag selections" needs to be
        //  stored with the cached data...Display mode can't follow "display_unselected_radio_options"
        //  otherwise
        $tag_hierarchy = null;


        // The entity -> entity_metadata relationships have to be one -> many from a database
        //  perspective, even though there's only supposed to be a single non-deleted entity_metadata
        //  object for each entity.  Therefore, the preceding query generates an array that needs
        //  to be slightly flattened in a few places.
        foreach ($datarecord_data as $dr_num => $dr) {
            $dr_id = $dr['id'];

            // Flatten datarecord_meta
            if ( count($dr['dataRecordMeta']) == 0 ) {
                // TODO - this comparison (and the 3 others in this function) really needs to be strict (!== 1)
                // TODO - ...but that would lock up multiple dev servers until their databases get fixed
                // ...throwing an exception here because this shouldn't ever happen, and also requires
                //  manual intervention to fix...
                throw new ODRException('Unable to rebuild the cached_datarecord_'.$dr_id.' array because of a database error for datarecord '.$dr_id);
            }

            $drm = $dr['dataRecordMeta'][0];
            $datarecord_data[$dr_num]['dataRecordMeta'] = $drm;
            $datarecord_data[$dr_num]['createdBy'] = UserUtility::cleanUserData( $dr['createdBy'] );
            $datarecord_data[$dr_num]['updatedBy'] = UserUtility::cleanUserData( $dr['updatedBy'] );

            // Store which datafields are used for the datatype's external_id_datafield, name_datafield, and sort_datafield
            $external_id_field = null;
            $name_datafield = null;
            $sort_datafield = null;

            $dt_id = $dr['dataType']['id'];
            if ( isset($dr['dataType']['dataTypeMeta'][0]['externalIdField']['id']) )
                $external_id_field = $dr['dataType']['dataTypeMeta'][0]['externalIdField']['id'];
            if ( isset($dr['dataType']['dataTypeMeta'][0]['nameField']['id']) )
                $name_datafield = $dr['dataType']['dataTypeMeta'][0]['nameField']['id'];
            if ( isset($dr['dataType']['dataTypeMeta'][0]['sortField']['id']) )
                $sort_datafield = $dr['dataType']['dataTypeMeta'][0]['sortField']['id'];

            // Also going to store the values for these datafields, once they're found
            $datarecord_data[$dr_num]['externalIdField_value'] = '';
            $datarecord_data[$dr_num]['nameField_value'] = '';
            $datarecord_data[$dr_num]['sortField_value'] = '';

            // Only want to load the tag hierarchy for this grandparent datatype once
            if ( is_null($tag_hierarchy) ) {
                $gp_dt_id = $datarecord_data[$dr_num]['dataType']['grandparent']['id'];
                $tag_hierarchy = $this->th_service->getTagHierarchy($gp_dt_id);
            }

            // Don't want to store the datatype's meta entry
            unset( $datarecord_data[$dr_num]['dataType']['dataTypeMeta'] );
            // Don't care about the datatype's grandparent either
            unset( $datarecord_data[$dr_num]['dataType']['grandparent'] );


            // Need to store a list of child/linked datarecords by their respective datatype ids
            $child_datarecords = array();
            foreach ($dr['children'] as $child_num => $cdr) {
                $cdr_id = $cdr['id'];
                $cdr_dt_id = $cdr['dataType']['id'];

                // A top-level datarecord is listed as its own parent in the database
                // Don't store it as its own child
                if ( $cdr_id == $dr['id'] )
                    continue;

                if ( $cdr_dt_id !== null && !isset($child_datarecords[$cdr_dt_id]) )
                    $child_datarecords[$cdr_dt_id] = array();

                if ( $cdr_id !== null )
                    $child_datarecords[$cdr_dt_id][] = $cdr_id;
            }
            foreach ($dr['linkedDatarecords'] as $child_num => $ldt) {
                $ldr_id = $ldt['descendant']['id'];
                $ldr_dt_id = $ldt['descendant']['dataType']['id'];

                if ( $ldr_dt_id !== null && !isset($child_datarecords[$ldr_dt_id]) )
                    $child_datarecords[$ldr_dt_id] = array();

                if ( $ldr_id !== null )
                    $child_datarecords[$ldr_dt_id][] = $ldr_id;
            }
            $datarecord_data[$dr_num]['children'] = $child_datarecords;
            unset( $datarecord_data[$dr_num]['linkedDatarecords'] );


            // Flatten datafield_meta of each datarecordfield, and organize by datafield id instead
            //  of some random number
            $new_drf_array = array();
            foreach ($dr['dataRecordFields'] as $drf_num => $drf) {
                // Not going to end up saving datafield/datafieldmeta...but need to verify it exists
                if ( !is_array($drf['dataField']) || count($drf['dataField']) == 0 ) {
                    // If the dataField array is empty, then this is most likely a datarecordfield
                    //  entry that references a deleted datafield

                    // Not really an error, since deleting datafields doesn't also delete drf entries
                    continue;
                }
                $df_id = $drf['dataField']['id'];

                if ( count($drf['dataField']['dataFieldMeta']) == 0 ) {
                    // ...throwing an exception here because this shouldn't ever happen, and also
                    //  requires manual intervention to fix...
                    throw new ODRException('Unable to rebuild the cached_datarecord_'.$dr_id.' array because of a database error for datafield '.$df_id);
                }

                $drf['dataField']['dataFieldMeta'] = $drf['dataField']['dataFieldMeta'][0];

                // Going to delete most of the sub arrays inside $drf that are empty...
                $expected_fieldtype = $drf['dataField']['dataFieldMeta']['fieldType']['typeClass'];
                $expected_fieldtype = lcfirst($expected_fieldtype);
                if ($expected_fieldtype == 'radio')
                    $expected_fieldtype = 'radioSelection';
                else if ($expected_fieldtype == 'tag')
                    $expected_fieldtype = 'tagSelection';

                // Flatten file metadata and get rid of encrypt_key
                foreach ($drf['file'] as $file_num => $file) {
                    unset( $drf['file'][$file_num]['encrypt_key'] );

                    if ( count($file['fileMeta']) == 0 ) {
                        // ...throwing an exception here because this shouldn't ever happen, and also
                        //  requires manual intervention to fix...
                        throw new ODRException('Unable to rebuild the cached_datarecord_'.$dr_id.' array because of a database error for file '.$file['id']);
                    }

                    $fm = $file['fileMeta'][0];
                    $drf['file'][$file_num]['fileMeta'] = $fm;

                    // Get rid of all private/non-essential information in the createdBy association
                    $drf['file'][$file_num]['createdBy'] = UserUtility::cleanUserData( $drf['file'][$file_num]['createdBy'] );
                }

                // Flatten image metadata
                $ordered_images = array();
                foreach ($drf['image'] as $image_num => $image) {
                    // Get rid of both the thumbnail's and the parent's encrypt keys
                    unset( $image['encrypt_key'] );
                    unset( $image['parent']['encrypt_key'] );

                    unset( $image['imageMeta'] );   // This is a phantom meta entry created for this image's thumbnail

                    if ( count($image['parent']['imageMeta']) == 0 ) {
                        // ...throwing an exception here because this shouldn't ever happen, and also
                        //  requires manual intervention to fix...
                        throw new ODRException('Unable to rebuild the cached_datarecord_'.$dr_id.' array because of a database error for image '.$image['parent']['id']);
                    }

                    $im = $image['parent']['imageMeta'][0];
                    $image['parent']['imageMeta'] = $im;

                    // Get rid of all private/non-essential information in the createdBy association
                    $image['parent']['createdBy'] = UserUtility::cleanUserData( $image['parent']['createdBy'] );

                    $image_id = $image['parent']['id'];
                    $display_order = $image['parent']['imageMeta']['displayorder'];

                    $ordered_images[ $display_order.'_'.$image_id ] = $image;
                }

                // Sort the images and discard the sort key afterwards
                if ( count($ordered_images) > 0 ) {
                    ksort($ordered_images);
                    $ordered_images = array_values($ordered_images);

                    $drf['image'] = $ordered_images;
                }

                // Scrub all user information from the rest of the array
                $keys = array('boolean', 'integerValue', 'decimalValue', 'longText', 'longVarchar', 'mediumVarchar', 'shortVarchar', 'datetimeValue');
                foreach ($keys as $typeclass) {
                    if ( count($drf[$typeclass]) > 0 ) {
                        $drf[$typeclass][0]['updatedBy'] = UserUtility::cleanUserData( $drf[$typeclass][0]['updatedBy'] );

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

                            // Also store the typeclass...Integer/Decimal need to use SORT_NUMERIC instead of SORT_NATURAL...
                            $datarecord_data[$dr_num]['sortField_typeclass'] = ucfirst($typeclass);
                        }
                    }
                }

                // Organize radio selections by radio option id
                $new_rs_array = array();
                foreach ($drf['radioSelection'] as $rs_num => $rs) {
                    $rs['updatedBy'] = UserUtility::cleanUserData( $rs['updatedBy'] );

                    $ro_id = $rs['radioOption']['id'];
                    $new_rs_array[$ro_id] = $rs;

                    if ($name_datafield !== null && $name_datafield == $df_id && $rs['selected'] === 1) {
                        // Should only be one selection since this is a name field...
                        $datarecord_data[$dr_num]['nameField_value'] = $rs['radioOption']['optionName'];
                    }
                    if ($sort_datafield !== null && $sort_datafield == $df_id && $rs['selected'] === 1) {
                        // Should only be one selection since this is a sort field...
                        $datarecord_data[$dr_num]['sortField_value'] = $rs['radioOption']['optionName'];

                        // Also store the typeclass...Integer/Decimal need to use SORT_NUMERIC instead of SORT_NATURAL...
                        $datarecord_data[$dr_num]['sortField_typeclass'] = ucfirst($typeclass);
                    }
                }
                $drf['radioSelection'] = $new_rs_array;

                // Organize tag selections by tag id
                $new_ts_array = array();
                foreach ($drf['tagSelection'] as $ts_num => $ts) {
                    $ts['updatedBy'] = UserUtility::cleanUserData( $ts['updatedBy'] );

                    $t_id = $ts['tag']['id'];
                    if($ts['tag']['userCreated'] > 0) {
                        /** @var TagTree $tag_tree */
                        $tag_tree = $this->em->getRepository('ODRAdminBundle:TagTree')
                            ->findOneBy(array(
                                'child' => $ts['tag']['id']
                            ));
                        $ts['tag_parent_uuid'] = $tag_tree->getParent()->getTagUuid();
                    }
                    $new_ts_array[$t_id] = $ts;
                }
                $drf['tagSelection'] = $new_ts_array;

                // Delete everything that isn't strictly needed in this $drf array
                foreach ($drf as $k => $v) {
                    if ( in_array($k, $drf_keys_to_keep) || $k == $expected_fieldtype )
                        continue;

                    // otherwise, delete it
                    unset( $drf[$k] );
                }

                // If tag selections exist for this drf entry...
                if ( isset($drf['tagSelection']) ) {
                    // ...then a list of which non-leaf tags have selected child/grandchild/etc tags
                    //  needs to be created and stored
                    $tag_tree = array();
                    $inversed_tag_tree = array();
                    if ( isset($tag_hierarchy[$dt_id]) && isset($tag_hierarchy[$dt_id][$df_id]) ) {
                        $tag_tree = $tag_hierarchy[$dt_id][$df_id];

                        // Building the list of which tags have selected child tags is easier if
                        //  the tag tree is inverted first
                        foreach ($tag_tree as $parent_tag_id => $children) {
                            foreach ($children as $child_tag_id => $tmp)
                                $inversed_tag_tree[$child_tag_id] = $parent_tag_id;
                        }
                    }

                    // For each tag that is selected...
                    $selections = array();
                    foreach ($drf['tagSelection'] as $t_id => $ts) {
                        if ( isset($tag_tree[$t_id]) ) {
                            // ...if it's a tag with children, it shouldn't have a tagSelection entry
                            unset( $drf['tagSelection'][$t_id] );
                        }
                        else if ( $ts['selected'] === 1 ) {
                            // ...otherwise, it's a tag without children and is selected...
                            $current_tag_id = $t_id;
                            // ...then for every ancestor of this tag...
                            while ( isset($inversed_tag_tree[$current_tag_id]) ) {
                                // ...store that they have a descendant tag that is selected
                                $parent_tag_id = $inversed_tag_tree[$current_tag_id];
                                $selections[$parent_tag_id] = '';

                                // ...continue looking for parent tags
                                $current_tag_id = $parent_tag_id;
                            }
                        }
                    }

                    // Store the array of which tags
                    $drf['child_tagSelections'] = $selections;
                }

                // Store the resulting $drf array by its datafield id
                $new_drf_array[$df_id] = $drf;
            }

            unset( $datarecord_data[$dr_num]['dataRecordFields'] );
            $datarecord_data[$dr_num]['dataRecordFields'] = $new_drf_array;
        }

        // Organize by datarecord id...permissions filtering doesn't work if the array isn't flat
        $formatted_datarecord_data = array();
        foreach ($datarecord_data as $num => $dr_data) {
            $dr_id = $dr_data['id'];

            // These two values should default to the datarecord id if empty
            if ( $dr_data['nameField_value'] == '' )
                $dr_data['nameField_value'] = $dr_id;
            if ( $dr_data['sortField_value'] == '' ) {
                $dr_data['sortField_value'] = $dr_id;
                $dr_data['sortField_typeclass'] = '';
            }

            $formatted_datarecord_data[$dr_id] = $dr_data;
        }


        // Save the formatted datarecord data back in the cache, and return it
        $this->cache_service->set('cached_datarecord_'.$grandparent_datarecord_id, $formatted_datarecord_data);
        return $formatted_datarecord_data;
    }


    /**
     * Recursively "inflates" a flattened $datarecord_array so that child/linked datarecords are
     * stored "underneath" their parents/grandparents.
     *
     * @see self::getDatarecordArray()
     *
     * @param array $datarecord_array
     * @param integer $initial_datarecord_id
     *
     * @return array
     */
    public function stackDatarecordArray($datarecord_array, $initial_datarecord_id)
    {
        $current_datarecord = array();
        if ( isset($datarecord_array[$initial_datarecord_id]) ) {
            $current_datarecord = $datarecord_array[$initial_datarecord_id];

            // If this datarecord has children...
            if ( isset($current_datarecord['children']) ) {
                foreach ($current_datarecord['children'] as $dt_id => $dr_list) {

                    // ...stack each child individually
                    $tmp = array();
                    foreach ($dr_list as $num => $dr_id) {
                        if ( isset($datarecord_array[$dr_id]) )
                            $tmp[$dr_id] = self::stackDatarecordArray($datarecord_array, $dr_id);
                    }

                    // ...sort array of child datarecords by their respective sortvalue
                    uasort($tmp, function ($a, $b) {
                        return strnatcmp($a['sortField_value'], $b['sortField_value']);
                    });

                    // ...store child datarecords under their parent
                    $current_datarecord['children'][$dt_id] = $tmp;
                }
            }
        }

        return $current_datarecord;
    }


    /**
     * Marks the specified datarecord (and all its parents) as updated by the given user.
     *
     * @param DataRecord $datarecord
     * @param ODRUser $user
     */
    public function updateDatarecordCacheEntry($datarecord, $user)
    {
        // Whenever an edit is made to a datarecord, each of its parents (if it has any) also need
        //  to be marked as updated
        $dr = $datarecord;
        while ($dr->getId() !== $dr->getParent()->getId()) {
            // Mark this (non-top-level) datarecord as updated by this user
            $dr->setUpdatedBy($user);
            $dr->setUpdated(new \DateTime());
            $this->em->persist($dr);

            // Continue locating parent datarecords...
            $dr = $dr->getParent();
        }

        // $dr is now the grandparent of $datarecord
        $dr->setUpdatedBy($user);
        $dr->setUpdated(new \DateTime());
        $this->em->persist($dr);

        // Save all changes made
        $this->em->flush();

        // Child datarecords don't have their own cached entries, it's all contained within the
        //  cache entry for their top-level datarecord
        $this->cache_service->delete('cached_datarecord_'.$dr->getId());

        // Delete the filtered list of data meant specifically for table themes
        $this->cache_service->delete('cached_table_data_'.$dr->getId());

        // Clear json caches used in API
        $this->cache_service->delete('json_record_' . $dr->getUniqueId());
    }


    /**
     * Deletes the cached table entries for the specified datatype...currently used by several
     * render plugins after they get removed or their settings get changed...
     *
     * TODO - better way of handling this requirement?
     *
     * @param int $grandparent_datatype_id
     */
    public function deleteCachedTableData($grandparent_datatype_id)
    {
        $query = $this->em->createQuery(
           'SELECT dr.id AS dr_id
            FROM ODRAdminBundle:DataRecord AS dr
            WHERE dr.dataType = :datatype_id
            AND dr.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $grandparent_datatype_id) );
        $results = $query->getArrayResult();

        foreach ($results as $result)
            $this->cache_service->delete('cached_table_data_'.$result['dr_id']);
    }


    /**
     * Because ODR permits an arbitrarily deep hierarchy when it comes to linking datarecords...
     * e.g.  A links to B links to C links to D links to...etc
     * ...the cache entry 'associated_datarecords_for_<A>' will then mention (B, C, D, etc.), because
     *  they all need to be loaded via getDatarecordArray() in order to properly render A.
     *
     * However, this means that linking/unlinking of datarecords between B/C, C/D, D/etc also affects
     * which datarecords A needs to load...so any linking/unlinking needs to be propagated upwards...
     *
     * TODO - potentially modify this to use SearchService::getCachedSearchDatarecordList()?
     * TODO - ...or create a new CacheClearService and move every single cache clearing function into there instead?
     *
     * @param array $datarecord_ids the datarecord_ids are values in the array, NOT keys
     */
    public function deleteCachedDatarecordLinkData($datarecord_ids)
    {
        $records_to_check = $datarecord_ids;
        $records_to_clear = $records_to_check;

        while ( !empty($records_to_check) ) {
            // Determine whether anything links to the given datarecords...
            $query = $this->em->createQuery(
               'SELECT grandparent.id AS ancestor_id
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                JOIN ODRAdminBundle:DataRecord AS grandparent WITH ancestor.grandparent = grandparent
                JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                WHERE descendant.id IN (:datarecords)
                AND ldt.deletedAt IS NULL
                AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL
                AND grandparent.deletedAt IS NULL'
            )->setParameters( array('datarecords' => $records_to_check) );
            $results = $query->getArrayResult();

            $records_to_check = array();
            foreach ($results as $result) {
                $ancestor_id = $result['ancestor_id'];
                $records_to_clear[] = $ancestor_id;
                $records_to_check[] = $ancestor_id;
            }
        }

        // Clearing this cache entry for each of the ancestor records found ensures that the
        //  newly linked/unlinked datarecords show up (or not) when they should
        foreach ($records_to_clear as $num => $dr_id)
            $this->cache_service->delete('associated_datarecords_for_'.$dr_id);
    }


    /**
     * Generates a CSRF token for every datarecord/datafield pair in the provided arrays.
     *
     * @param array $datatype_array    @see DatabaseInfoService::buildDatatypeData()
     * @param array $datarecord_array  @see DatarecordInfoService::buildDatarecordData()
     *
     * @return array
     */
    public function generateCSRFTokens($datatype_array, $datarecord_array)
    {
        $token_list = array();

        foreach ($datarecord_array as $dr_id => $dr) {
            if (!isset($token_list[$dr_id]))
                $token_list[$dr_id] = array();

            $dt_id = $dr['dataType']['id'];

            if (!isset($datatype_array[$dt_id]))
                continue;

            foreach ($datatype_array[$dt_id]['dataFields'] as $df_id => $df) {

                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];

                $token_id = $typeclass . 'Form_' . $dr_id . '_' . $df_id;
                $token_list[$dr_id][$df_id] = $this->token_manager->getToken($token_id)->getValue();

            }
        }

        return $token_list;
    }


    /**
     * Uses the given cached datatype array to generate a cache entry for a barebones "fake" record
     * of the given datatype id.
     *
     * This function assumes it's creating a fake top-level datarecord.  The caller will need to
     * splice this fake datarecord's ids into the cached array of a parent datarecord, if needed.
     * The caller will also need to deal with setting the parent/grandparent attributes in this case.
     *
     * @param array $datatype_array @see DatabaseInfoService::getDatatypeArray()
     * @param int $target_datatype_id
     *
     * @return array
     */
    public function createFakeDatarecordEntry($datatype_array, $target_datatype_id)
    {
        if ( !isset($datatype_array[$target_datatype_id]) )
            throw new ODRBadRequestException('Unable to generate a fake record of datatype '.$target_datatype_id, 0x00ba0e84);

        // Going to need to copy most of the cached datatype array into the fake datarecord entry
        $dt_entry = $datatype_array[$target_datatype_id];
        // It doesn't really matter whether these entries exist or not, but unset them to be tidy
        unset( $dt_entry[$target_datatype_id]['dataFields'] );
        unset( $dt_entry[$target_datatype_id]['createdBy'] );
        unset( $dt_entry[$target_datatype_id]['updatedBy'] );


        // The new "fake" datarecord needs an id...ensure it's not numeric to avoid collisions
        // self::generateCSRFTokens() doesn't require numeric ids, and the length doesn't matter
        // Don't need to use UUIDService::generateDatarecordUniqueId(), $fake_id will be discarded
        $fake_id = UniqueUtility::uniqueIdReal();
        while ( is_numeric($fake_id) )
            $fake_id = UniqueUtility::uniqueIdReal();

        $entry = array(
            'id' => $fake_id,
            'is_fake' => true,

            // These values shouldn't cause a problem...
            'provisioned' => true,
            'unique_id' => '',

            'dataRecordMeta' => array(
                'id' => null,    // TODO - is this a problem?  it shouldn't be, since drf_id should never be used...
                'publicDate' => new \DateTime('2200-01-01 00:00:00'),
            ),

            'parent' => array(
                'id' => $fake_id,
            ),
            'grandparent' => array(
                'id' => $fake_id,
            ),
            'dataType' => $dt_entry,

            // These null values shouldn't cause a problem...
            'created' => null,
            'updated' => null,
            'createdBy' => null,
            'updatedBy' => null,
            'deletedAt' => null,

            'children' => array(),
            'externalIdField_value' => '',
            'nameField_value' => $fake_id,    // Get Edit mode to render the datatype name instead of a blank
            'sortField_value' => '',
            'sortField_typeclass' => '',

            // Don't actually need to create entries here, twig effectively assumes all fields
            //  have no value in this case
            'dataRecordFields' => array()
        );

        return $entry;
    }
}
