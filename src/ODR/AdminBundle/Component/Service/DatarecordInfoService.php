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
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
// Utility
use ODR\AdminBundle\Component\Utility\UniqueUtility;
use ODR\AdminBundle\Component\Utility\UserUtility;
// Other
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
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
    private $datatree_info_service;

    /**
     * @var TagHelperService
     */
    private $tag_helper_service;

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
        $this->datatree_info_service = $datatree_info_service;
        $this->tag_helper_service = $tag_helper_service;
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
     * Use {@link self::stackDatarecordArray()} to get an array structure where child/linked datarecords
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
            $associated_datarecords = $this->datatree_info_service->getAssociatedDatarecords($grandparent_datarecord_id);
        }
        else {
            // Don't want any datarecords that are linked to from the given grandparent datarecord
            $associated_datarecords[] = $grandparent_datarecord_id;
        }

        // Grab the cached versions of all of the associated datarecords, and store them all at the
        //  same level in a single array
        $datarecord_array = array();
        foreach ($associated_datarecords as $num => $dr_id) {
            $datarecord_data = $this->cache_service->get('cached_datarecord_'.$dr_id);
            if ($datarecord_data == false)
                $datarecord_data = self::buildDatarecordData($dr_id);

            foreach ($datarecord_data as $dr_id => $data)
                $datarecord_array[$dr_id] = $data;
        }

        return $datarecord_array;
    }


    /**
     * Runs database queries to get all non-layout data for a given grandparent datarecord.
     *
     * @param integer $grandparent_datarecord_id
     *
     * @return array
     */
    private function buildDatarecordData($grandparent_datarecord_id)
    {
        // This function is only called when the cache entry doesn't exist

        // These are kept separate from the primary query because it's slightly easier on
        //  doctrine's array hydrator
        $xyz_values = self::getXYZData($grandparent_datarecord_id);
        $radio_selections = self::getRadioSelections($grandparent_datarecord_id);
        $tag_selections = self::getTagSelections($grandparent_datarecord_id);

        // Unlike datatype hydration, it seems that separating out the sort/name fields has no benefit
        //  ...but separating out the query to find the child/linked datarecords has a massive benefit
        $descendants = self::getDescendants($grandparent_datarecord_id);

        // Otherwise...get all non-layout data for the requested grandparent datarecord
        $query = $this->em->createQuery(
           'SELECT
               dr, partial drm.{id, publicDate}, partial p_dr.{id}, partial gp_dr.{id}, partial gp_drm.{id, prevent_user_edits},
               partial dr_cb.{id, username, email, firstName, lastName},
               partial dr_ub.{id, username, email, firstName, lastName},

               dt, partial gp_dt.{id}, partial mdt.{id, unique_id}, partial mf.{id, unique_id},
               dtm, partial dt_eif.{id}, partial dtsf.{id, dataField, field_purpose, displayOrder}, partial s_df.{id}, partial s_df_dt.{id},

               drf, partial df.{id, fieldUuid, templateFieldUuid}, partial dfm.{id, fieldName, publicDate, xml_fieldName, quality_str}, partial ft.{id, typeClass, typeName},
               e_f, e_fm, partial e_f_cb.{id, username, email, firstName, lastName},
               e_i, e_im, e_ip, e_ipm, e_is, partial e_ip_cb.{id, username, email, firstName, lastName},

               e_b, e_iv, e_dv, e_lt, e_lvc, e_mvc, e_svc, e_dtv, e_xyz,

               partial e_b_ub.{id, username, email, firstName, lastName},
               partial e_iv_ub.{id, username, email, firstName, lastName},
               partial e_dv_ub.{id, username, email, firstName, lastName},
               partial e_lt_ub.{id, username, email, firstName, lastName},
               partial e_lvc_ub.{id, username, email, firstName, lastName},
               partial e_mvc_ub.{id, username, email, firstName, lastName},
               partial e_svc_ub.{id, username, email, firstName, lastName},
               partial e_dtv_ub.{id, username, email, firstName, lastName},
               partial e_xyz_ub.{id, username, email, firstName, lastName}

            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.dataRecordMeta AS drm
            LEFT JOIN dr.createdBy AS dr_cb
            LEFT JOIN dr.updatedBy AS dr_ub
            LEFT JOIN dr.parent AS p_dr
            LEFT JOIN dr.grandparent AS gp_dr
            LEFT JOIN gp_dr.dataRecordMeta AS gp_drm

            LEFT JOIN dr.dataType AS dt
            LEFT JOIN dt.grandparent AS gp_dt
            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dt.masterDataType AS mdt
            LEFT JOIN dt.metadata_for AS mf

            LEFT JOIN dtm.externalIdField AS dt_eif
            LEFT JOIN dt.dataTypeSpecialFields AS dtsf
            LEFT JOIN dtsf.dataField AS s_df
            LEFT JOIN s_df.dataType AS s_df_dt

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
            LEFT JOIN drf.XYZData AS e_xyz
            LEFT JOIN e_xyz.updatedBy AS e_xyz_ub

            LEFT JOIN drf.dataField AS df
            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN dfm.fieldType AS ft

            WHERE
                dr.grandparent = :grandparent_id
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND df.deletedAt IS NULL
                AND (e_i.id IS NULL OR e_i.original = 0)'
        )->setParameters( array('grandparent_id' => $grandparent_datarecord_id) );

        $datarecord_data = $query->getArrayResult();

        // TODO - if $datarecord_data is empty, then $grandparent_datarecord_id was deleted...should this return something special in that case?

        // The datarecordField entry returned by the preceeding query will have quite a few blank
        //  subarrays...all but the following keys should be unset in order to reduce the amount of
        //  memory that php needs to allocate to store a cached datarecord entry...it adds up.
        $drf_keys_to_keep = array(
            'dataField',
            'id', 'created',
            'file', 'image',       // keeping these for now because multiple pieces of code assume they exist
        );

        // If this datarecord has tag datafields, then a list of "child tag selections" needs to be
        //  stored with the cached data...Display mode can't follow "display_unselected_radio_options"
        //  otherwise
        $tag_id_hierarchy = null;
        // ...and since the hierarchy doesn't change from datarecord to datarecord, the inverted
        //  version doesn't change either
        $inversed_tag_id_tree = null;
        $inversed_tag_uuid_tree = null;


        // The entity -> entity_metadata relationships have to be one -> many from a database
        //  perspective, even though there's only supposed to be a single non-deleted entity_metadata
        //  object for each entity.  Therefore, the preceding query generates an array that needs
        //  to be slightly flattened in a few places.
        foreach ($datarecord_data as $dr_num => $dr) {
            $dr_id = $dr['id'];

            // If the datarecord's datatype is null, then it belongs to a deleted datatype and
            //  should be completely ignored
            // TODO - should this filtering happen inside the mysql query?
            if ( is_null($dr['dataType']) ) {
                unset( $datarecord_data[$dr_num] );
                continue;
            }

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

            // Need to also flatten the grandparent's meta entry
            $datarecord_data[$dr_num]['grandparent'] = array(
                'id' => $dr['grandparent']['id'],
                'prevent_user_edits' => $dr['grandparent']['dataRecordMeta'][0]['prevent_user_edits'],
            );

            // Since only one datafield is allowed for a datatype's external_id_datafield, it doesn't
            //  need to be handled like a name/sort field
            $external_id_field = null;
            if ( isset($dr['dataType']['dataTypeMeta'][0]['externalIdField']['id']) )
                $external_id_field = $dr['dataType']['dataTypeMeta'][0]['externalIdField']['id'];
            // Also going to store the value for this datafields, once it's found
            $datarecord_data[$dr_num]['externalIdField_value'] = '';

            // Only want to load the tag hierarchy for this grandparent datatype once
            if ( is_null($tag_id_hierarchy) ) {
                $gp_dt_id = $datarecord_data[$dr_num]['dataType']['grandparent']['id'];

                // Need to invert the provided hierarchies so that the code can look up the parent
                //  tag when given a child tag
                // Despite technically having four levels of foreach loops, only the deepest two
                //  loops really do anything
                $tag_id_hierarchy = $this->tag_helper_service->getTagHierarchy($gp_dt_id);
                foreach ($tag_id_hierarchy as $dt_id => $df_list) {
                    foreach ($df_list as $df_id => $tag_tree) {
                        foreach ($tag_tree as $parent_tag_id => $children) {
                            foreach ($children as $child_tag_id => $tmp)
                                $inversed_tag_id_tree[$child_tag_id] = $parent_tag_id;
                        }
                    }
                }

                // Need to do the same thing with tag uuids too
                // Despite technically having four levels of foreach loops, only the deepest two
                //  loops really do anything
                $tag_uuid_hierarchy = $this->tag_helper_service->getTagHierarchy($gp_dt_id, true);
                foreach ($tag_uuid_hierarchy as $dt_id => $df_list) {
                    foreach ($df_list as $df_id => $tag_tree) {
                        foreach ($tag_tree as $parent_tag_uuid => $children) {
                            foreach ($children as $child_tag_uuid => $tmp)
                                $inversed_tag_uuid_tree[$child_tag_uuid] = $parent_tag_uuid;
                        }
                    }
                }
            }

            // Don't want to store the datatype's meta entry
            unset( $datarecord_data[$dr_num]['dataType']['dataTypeMeta'] );
            // Don't care about the datatype's grandparent either
            unset( $datarecord_data[$dr_num]['dataType']['grandparent'] );


            // Need to store a list of child/linked datarecords by their respective datatype ids
            $dr['children'] = array();
            $dr['linkedDatarecords'] = array();
            if ( isset($descendants[$dr_id]) ) {
                $dr['children'] = $descendants[$dr_id]['children'];
                $dr['linkedDatarecords'] = $descendants[$dr_id]['linkedDatarecords'];
            }

            $child_datarecords = array();
            foreach ($dr['children'] as $child_num => $cdr) {
                $cdr_id = $cdr['id'];

                // A top-level datarecord is listed as its own parent in the database
                // Don't store it as its own child
                if ( $cdr_id == $dr['id'] )
                    continue;

                // Need to verify that the child datatype isn't deleted before attempting to store
                //  the child datarecord
                if ( !is_null($cdr['dataType']) ) {
                    $cdr_dt_id = $cdr['dataType']['id'];

                    // Store that this datarecord is a child of its parent
                    if ( !isset($child_datarecords[$cdr_dt_id]) )
                        $child_datarecords[$cdr_dt_id] = array();
                    $child_datarecords[$cdr_dt_id][] = $cdr_id;
                }
            }
            foreach ($dr['linkedDatarecords'] as $child_num => $ldt) {
                // The deletion process does extra work to ensure nothing can link to a deleted
                //  datatype...but make doubly sure here
                if ( !is_null($ldt['descendant']) && !is_null($ldt['descendant']['dataType']) ) {
                    $ldr_id = $ldt['descendant']['id'];
                    $ldr_dt_id = $ldt['descendant']['dataType']['id'];

                    // Store this linked datarecord as a "child" of the datarecord that links to it
                    if ( !isset($child_datarecords[$ldr_dt_id]) )
                        $child_datarecords[$ldr_dt_id] = array();
                    $child_datarecords[$ldr_dt_id][] = $ldr_id;
                }
            }
            $datarecord_data[$dr_num]['children'] = $child_datarecords;
            unset( $datarecord_data[$dr_num]['linkedDatarecords'] );


            // Flatten datafield_meta of each datarecordfield, and organize by datafield id instead
            //  of some random number
            $new_drf_array = array();
            foreach ($dr['dataRecordFields'] as $drf_num => $drf) {
                // Not going to end up saving datafield/datafieldmeta...but need to verify it exists
                if ( !is_array($drf['dataField']) || count($drf['dataField']) == 0 ) {
                    // If the dataField array isn't an array or is empty, then this is most likely
                    //  a datarecordfield entry that references a deleted datafield

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
                else if ($expected_fieldtype == 'xYZData')
                    $expected_fieldtype = 'xyzData';

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

                        // Store the value from this storage entity if it's the one being used for
                        //  the external_id field
                        if ($external_id_field !== null && $external_id_field == $df_id)
                            $datarecord_data[$dr_num]['externalIdField_value'] = $drf[$typeclass][0]['value'];
                    }
                }

                // Organize any radio selections by radio option id
                if ( isset($radio_selections[$dr_id][$df_id]) ) {
                    $drf['radioSelection'] = $radio_selections[$dr_id][$df_id];

                    $new_rs_array = array();
                    foreach ($drf['radioSelection'] as $rs_num => $rs) {
                        $rs['updatedBy'] = UserUtility::cleanUserData( $rs['updatedBy'] );

                        $ro_id = $rs['radioOption']['id'];
                        $new_rs_array[$ro_id] = $rs;
                    }
                    $drf['radioSelection'] = $new_rs_array;
                }

                // Organize any tag selections by tag id
                if ( isset($tag_selections[$dr_id][$df_id]) ) {
                    $drf['tagSelection'] = $tag_selections[$dr_id][$df_id];

                    $new_ts_array = array();
                    foreach ($drf['tagSelection'] as $ts_num => $ts) {
                        $ts['updatedBy'] = UserUtility::cleanUserData( $ts['updatedBy'] );

                        // Might as well store the parent tag uuid here, if it exists...
                        $tag_uuid = $ts['tag']['tagUuid'];
                        if ( isset($inversed_tag_uuid_tree[$tag_uuid]) )
                            $ts['tag']['parent_tagUuid'] = $inversed_tag_uuid_tree[$tag_uuid];
                        // Note that you're "supposed" to get this from the cached datatype array,
                        //  but it's easier for the API to do it this way

                        $t_id = $ts['tag']['id'];
                        $new_ts_array[$t_id] = $ts;
                    }
                    $drf['tagSelection'] = $new_ts_array;
                }

                // Deal with xyz data
                if ( isset($xyz_values[$dr_id][$df_id]) ) {
                    $drf['xyzData'] = $xyz_values[$dr_id][$df_id];
                    foreach ($drf['xyzData'] as $num => $xyz)
                        $drf['xyzData'][$num]['updatedBy'] = UserUtility::cleanUserData( $xyz['updatedBy'] );
                }

                // Delete everything that isn't strictly needed in this $drf array
                foreach ($drf as $k => $v) {
                    if ( in_array($k, $drf_keys_to_keep) || $k == $expected_fieldtype )
                        continue;

                    // otherwise, delete it
                    unset( $drf[$k] );
                }

                // Store the resulting $drf array by its datafield id if the storage entity exists
                if ( !empty($drf[$expected_fieldtype]) )
                    $new_drf_array[$df_id] = $drf;
            }

            unset( $datarecord_data[$dr_num]['dataRecordFields'] );
            $datarecord_data[$dr_num]['dataRecordFields'] = $new_drf_array;
        }

        // Organize by datarecord id...permissions filtering doesn't work if the array isn't flat
        $formatted_datarecord_data = array();
        foreach ($datarecord_data as $num => $dr_data) {
            $dr_id = $dr_data['id'];
            $formatted_datarecord_data[$dr_id] = $dr_data;
        }

        // Find, combine, and save the values for the name/sort fields
        self::findSpecialFieldValues($formatted_datarecord_data);

        // Save the formatted datarecord data back in the cache, and return it
        $this->cache_service->set('cached_datarecord_'.$grandparent_datarecord_id, $formatted_datarecord_data);
        return $formatted_datarecord_data;
    }


    /**
     * Because of the complexity/depth of the main query in {@link self::buildDatarecordData()},
     * it's a lot harder for mysql if the database also has hundreds of radio options...so it's
     * better to get radio options in a separate query.
     *
     * @param int $grandparent_datarecord_id
     *
     * @return array
     */
    private function getRadioSelections($grandparent_datarecord_id)
    {
        $query = $this->em->createQuery(
           'SELECT rs, ro, partial rs_ub.{id, username, email, firstName, lastName},
                    partial drf.{id}, partial df.{id}, partial dr.{id}
            FROM ODRAdminBundle:RadioSelection AS rs
            JOIN rs.radioOption AS ro
            JOIN rs.updatedBy AS rs_ub
            JOIN rs.dataRecordFields AS drf
            JOIN drf.dataField AS df
            JOIN drf.dataRecord AS dr
            WHERE dr.grandparent = :grandparent_datarecord_id
            AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND df.deletedAt IS NULL AND dr.deletedAt IS NULL
            ORDER BY dr.id, df.id'
        )->setParameters( array('grandparent_datarecord_id' => $grandparent_datarecord_id) );
        $results = $query->getArrayResult();

        $radio_selections = array();
        foreach ($results as $result) {
            // Need these ids out of the array...
            $ro_id = $result['radioOption']['id'];
            $df_id = $result['dataRecordFields']['dataField']['id'];
            $dr_id = $result['dataRecordFields']['dataRecord']['id'];
            // Don't want to keep this entry
            unset( $result['dataRecordFields'] );

            // Only want selected options in the array
            if ( $result['selected'] == 1 ) {
                if ( !isset($radio_selections[$dr_id]) )
                    $radio_selections[$dr_id] = array();
                if ( !isset($radio_selections[$dr_id][$df_id]) )
                    $radio_selections[$dr_id][$df_id] = array();
                $radio_selections[$dr_id][$df_id][$ro_id] = $result;
            }
        }

        return $radio_selections;
    }


    /**
     * Because of the complexity/depth of the main query in {@link self::buildDatarecordData()},
     * it's a lot harder for mysql if the database also has hundreds of tags...so it's better to
     * get the tags in a separate query.
     *
     * @param int $grandparent_datarecord_id
     *
     * @return array
     */
    private function getTagSelections($grandparent_datarecord_id)
    {
        $query = $this->em->createQuery(
           'SELECT ts, t, partial ts_ub.{id, username, email, firstName, lastName},
                    partial drf.{id}, partial df.{id}, partial dr.{id}
            FROM ODRAdminBundle:TagSelection AS ts
            JOIN ts.tag AS t
            JOIN ts.updatedBy AS ts_ub
            JOIN ts.dataRecordFields AS drf
            JOIN drf.dataField AS df
            JOIN drf.dataRecord AS dr
            WHERE dr.grandparent = :grandparent_datarecord_id
            AND ts.deletedAt IS NULL AND t.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND df.deletedAt IS NULL AND dr.deletedAt IS NULL
            ORDER BY dr.id, df.id'
        )->setParameters( array('grandparent_datarecord_id' => $grandparent_datarecord_id) );
        $results = $query->getArrayResult();

        $tag_selections = array();
        foreach ($results as $result) {
            // Need these ids out of the array...
            $t_id = $result['tag']['id'];
            $df_id = $result['dataRecordFields']['dataField']['id'];
            $dr_id = $result['dataRecordFields']['dataRecord']['id'];
            // Don't want to keep this entry
            unset( $result['dataRecordFields'] );

            // Only want selected tags in the cached array
            if ( $result['selected'] == 1 ) {
                if ( !isset($tag_selections[$dr_id]) )
                    $tag_selections[$dr_id] = array();
                if ( !isset($tag_selections[$dr_id][$df_id]) )
                    $tag_selections[$dr_id][$df_id] = array();
                $tag_selections[$dr_id][$df_id][$t_id] = $result;
            }
        }

        return $tag_selections;
    }


    /**
     * Because of the complexity/depth of the main query in {@link self::buildDatarecordData()},
     * it's a lot harder for mysql if the database also has any xyz data...it's better to get
     * them in a separate query.
     *
     * @param int $grandparent_datarecord_id
     *
     * @return array
     */
    private function getXYZData($grandparent_datarecord_id)
    {
        $query = $this->em->createQuery(
           'SELECT xyz, partial xyz_ub.{id, username, email, firstName, lastName},
                partial drf.{id}, partial df.{id}, partial dr.{id}
            FROM ODRAdminBundle:XYZData AS xyz
            JOIN xyz.updatedBy AS xyz_ub
            JOIN xyz.dataRecordFields AS drf
            JOIN drf.dataField AS df
            JOIN drf.dataRecord AS dr
            WHERE dr.grandparent = :grandparent_datarecord_id
            AND xyz.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND df.deletedAt IS NULL AND dr.deletedAt IS NULL
            ORDER BY dr.id, df.id, xyz.x_value'
        )->setParameters( array('grandparent_datarecord_id' => $grandparent_datarecord_id) );
        $results = $query->getArrayResult();

        $xyz_values = array();
        foreach ($results as $result) {
            // Need these ids out of the array...
            $df_id = $result['dataRecordFields']['dataField']['id'];
            $dr_id = $result['dataRecordFields']['dataRecord']['id'];
            // Don't want to keep this entry
            unset( $result['dataRecordFields'] );

            if ( !isset($xyz_values[$dr_id]) )
                $xyz_values[$dr_id] = array();
            if ( !isset($xyz_values[$dr_id][$df_id]) )
                $xyz_values[$dr_id][$df_id] = array();
            $xyz_values[$dr_id][$df_id][] = $result;
        }

        return $xyz_values;
    }


    /**
     * Apparently, mysql REALLY doesn't like digging through the odr_data_tree table to figure out
     * the ids of the child/linked descendant records at the same time as the primary query in
     * {@link self::buildDatarecordData()}.
     *
     * @param int $grandparent_datarecord_id
     * @return array
     */
    private function getDescendants($grandparent_datarecord_id)
    {
        $query = $this->em->createQuery(
           'SELECT partial dr.{id}, partial cdr.{id}, partial cdr_dt.{id},
                    partial ldt.{id}, partial ldr.{id}, partial ldr_dt.{id}
            FROM ODRAdminBundle:DataRecord dr
            LEFT JOIN dr.children AS cdr
            LEFT JOIN cdr.dataType AS cdr_dt
            LEFT JOIN dr.linkedDatarecords AS ldt
            LEFT JOIN ldt.descendant AS ldr
            LEFT JOIN ldr.dataType AS ldr_dt
            WHERE dr.grandparent = :grandparent_datarecord_id
            AND dr.deletedAt IS NULL'
        )->setParameters( array('grandparent_datarecord_id' => $grandparent_datarecord_id) );
        $results = $query->getArrayResult();

        $descendants = array();
        foreach ($results as $result) {
            $dr_id = $result['id'];

            $descendants[$dr_id]['children'] = $result['children'];
            $descendants[$dr_id]['linkedDatarecords'] = $result['linkedDatarecords'];
        }

        return $descendants;
    }


    /**
     * Multiple places in ODR need to have quick/easy access to the name/sort values for a datarecord,
     * but this isn't exactly trivial because a datatype could require them to be combined from the
     * values of more than one field...
     *
     * This function does the work of locating the values, combining them together if there's more
     * than one of them, and then saving the values back in the cached datarecord array.
     *
     * @param array $dr_array
     */
    private function findSpecialFieldValues(&$dr_array)
    {
        foreach ($dr_array as $dr_id => $dr) {
            // Extract the list of special fields for this datatype
            $special_fields = $dr['dataType']['dataTypeSpecialFields'];
            // Don't want this data in the final array
            unset( $dr_array[$dr_id]['dataType']['dataTypeSpecialFields'] );

            // Going to determine what the "name" and the "sort value" of the datarecord are, so
            //  they can be cached
            $name_fields = array();
            $sort_fields = array();
            // Also need to track which datatype the special field came from
            $dt_lookup = array();

            foreach ($special_fields as $num => $dtsf) {
                $df_id = $dtsf['dataField']['id'];
                if ( $dtsf['field_purpose'] === DataTypeSpecialFields::NAME_FIELD )
                    $name_fields[ $dtsf['displayOrder'] ] = $df_id;
                else if ( $dtsf['field_purpose'] === DataTypeSpecialFields::SORT_FIELD )
                    $sort_fields[ $dtsf['displayOrder'] ] = $df_id;

                $dt_lookup[$df_id] = $dtsf['dataField']['dataType']['id'];
            }


            // ----------------------------------------
            // Attempt to find any "name" values for this datarecord...
            $name_fields_are_numeric = true;
            $name_field_values = self::findSpecialFieldValues_worker($dt_lookup, $dr, $name_fields, $name_fields_are_numeric);

            // Attempt to find any "sort" values for this datarecord...
            $sort_fields_are_numeric = true;
            $sort_field_values = self::findSpecialFieldValues_worker($dt_lookup, $dr, $sort_fields, $sort_fields_are_numeric);


            // ----------------------------------------
            // Now that the values for these special fields have been found...
            if ( !empty($name_field_values) ) {
                // Ensure the values are in the correct order before imploding
                ksort($name_field_values);
                $dr_array[$dr_id]['nameField_value'] = implode(' ', $name_field_values);

                // Also define a location to store the value after it's been run through render plugins
                // This value can't be constructed here, because part of it could come from a linked
                //  descendant...which isn't accessible at this point in time
                $dr_array[$dr_id]['nameField_formatted'] = '';
            }
            else {
                // Otherwise, no value defined...default to the datarecord id
                $dr_array[$dr_id]['nameField_value'] = $dr_id;
                $dr_array[$dr_id]['nameField_formatted'] = $dr_id;
            }

            // Do the same thing for the sort values
            if ( !empty($sort_field_values) ) {
                ksort($sort_field_values);
                $dr_array[$dr_id]['sortField_value'] = implode(' ', $sort_field_values);
            }
            else {
                $dr_array[$dr_id]['sortField_value'] = $dr_id;
            }

            // Also should store whether sorts should use SORT_NATURAL or SORT_NUMERIC
            if ( $sort_fields_are_numeric && count($sort_fields) < 2 ) {
                // If the sort fields are numeric, and there's not more than one sort field, then
                //  always use numeric
                $dr_array[$dr_id]['sortField_types'] = 'numeric';
            }
            else {
                // If the sort field isn't numeric, or there's more than one sort field, then
                //  always use natural sort
                $dr_array[$dr_id]['sortField_types'] = 'natural';
            }
        }
    }


    /**
     * Finding the values for the relevant fields is achieved the same way regardless of whether it's
     * a namefield or a sortfield.
     *
     * @param array $dt_lookup
     * @param array $dr
     * @param array $fields
     * @param bool $fields_are_numeric
     * @return array
     */
    private function findSpecialFieldValues_worker($dt_lookup, $dr, $fields, &$fields_are_numeric)
    {
        $field_values = array();

        foreach ($fields as $display_order => $df_id) {
            // Determine whether the values should be in this datarecord or not
            $is_remote = false;
            if ( $dt_lookup[$df_id] !== $dr['dataType']['id'] )
                $is_remote = true;

            if ( !$is_remote && isset($dr['dataRecordFields'][$df_id]) ) {
                // This field belongs to the current datatype, so attempt to find the value for it
                $drf = $dr['dataRecordFields'][$df_id];
                $field_values[$display_order] = self::getValue($drf);

                // Keep track of whether all values are numeric or not
                $typeclass = $drf['dataField']['dataFieldMeta']['fieldType']['typeClass'];
                if ( $typeclass !== 'IntegerValue' && $typeclass !== 'DecimalValue' )
                    $fields_are_numeric = false;
            }
            else if ( $is_remote ) {
                // Name/Sort fields are allowed to come from another datatype if it's a single-allowed
                //  child/linked descendant.
                $descendant_dt_id = $dt_lookup[$df_id];

                // At this point, the datarecord array will contain the ids of all of its
                //  child/linked descedant records, organized by datatype id...
                $tmp_dr_list = array();
                if ( isset($dr['children'][$descendant_dt_id]) )
                    $tmp_dr_list = $dr['children'][$descendant_dt_id];

                // ...so if it actually has a descendant record of that descendant datatype,
                //  then its id will be accessible
                $tmp_dr_id = null;
                if ( isset($tmp_dr_list[0]) )
                    $tmp_dr_id = $tmp_dr_list[0];

                if ( !is_null($tmp_dr_id) ) {
                    // There is a descendant record...

                    if ( isset($dr_array[$tmp_dr_id]) ) {
                        // ...and since the datarecord array contains an entry for this id,
                        //  that means it's coming from a child record
                        $child_dr = $dr_array[$tmp_dr_id];

                        // Attempt to find the value for this datafield
                        if ( isset($child_dr['dataRecordFields'][$df_id]) ) {
                            $child_drf = $child_dr['dataRecordFields'][$df_id];
                            $field_values[$display_order] = self::getValue($child_drf);
                        }
                    }
                    else {
                        // ...since the datarecord array doesn't contain an entry for this id,
                        //  that means it's supposed to come from a linked record...however, at
                        //  this point in time the linked records aren't in the array.  Therefore,
                        //  have no choice but to locate the value directly from the database

                        // Need one query to get the typeclass of the field...
                        $query = $this->em->createQuery(
                           'SELECT ft.typeClass
                            FROM ODRAdminBundle:DataFieldsMeta dfm
                            LEFT JOIN ODRAdminBundle:FieldType ft WITH dfm.fieldType = ft
                            WHERE dfm.dataField = :datafield_id
                            AND dfm.deletedAt IS NULL AND ft.deletedAt IS NULL'
                        )->setParameters( array('datafield_id' => $df_id) );
                        $results = $query->getArrayResult();

                        // Should only be one row
                        $typeclass = $results[0]['typeClass'];

                        // Knowing the typeclass enables a second query that targets the relevant
                        //  dataRecordField entry in the linked record...
                        $query = null;
                        if ( $typeclass === 'Radio' ) {
                            $query = $this->em->createQuery(
                               'SELECT ro.optionName AS field_value
                                FROM ODRAdminBundle:DataRecordFields drf
                                LEFT JOIN ODRAdminBundle:RadioSelection rs WITH rs.dataRecordFields = drf
                                LEFT JOIN ODRAdminBundle:RadioOptions ro WITH rs.radioOption = ro
                                WHERE drf.dataRecord = :datarecord_id AND drf.dataField = :datafield_id
                                AND rs.selected = 1
                                AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL'
                            )->setParameters( array('datarecord_id' => $tmp_dr_id, 'datafield_id' => $df_id) );
                        }
                        else {
                            $query = $this->em->createQuery(
                               'SELECT e.value AS field_value
                                FROM ODRAdminBundle:DataRecordFields drf
                                LEFT JOIN ODRAdminBundle:'.$typeclass.' e WITH e.dataRecordFields = drf
                                WHERE drf.dataRecord = :datarecord_id AND drf.dataField = :datafield_id
                                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL'
                            )->setParameters( array('datarecord_id' => $tmp_dr_id, 'datafield_id' => $df_id) );
                        }
                        $results = $query->getArrayResult();

                        // Should only be one value...
                        if ( isset($results[0]['field_value']) )
                            $field_values[$display_order] = $results[0]['field_value'];
                        else
                            $field_values[$display_order] = '';

                        // Keep track of whether all values are numeric or not
                        if ( $typeclass !== 'IntegerValue' && $typeclass !== 'DecimalValue' )
                            $fields_are_numeric = false;
                    }
                }

                // Otherwise, there's no descendant child/linked record to get a value from
            }
        }

        return $field_values;
    }


    /**
     * Extracts the value from a dataRecordField entry in the array.  This doesn't include Tags or
     * XYZ values, because those aren't valid for a DatatypeSpecialField.
     *
     * @param $drf
     * @return string
     */
    private function getValue($drf)
    {
        $df = $drf['dataField'];
        $typeclass = lcfirst($df['dataFieldMeta']['fieldType']['typeClass']);

        if ( $typeclass === 'radio' ) {
            // Radio fields need to dig through the radioSelections...
            if ( isset($drf['radioSelection']) ) {
                foreach ($drf['radioSelection'] as $rs_num => $rs) {
                    if ( $rs['selected'] === 1 )
                        return trim($rs['radioOption']['optionName']);
                }
            }
        }
        else if ( $typeclass === 'datetimeValue' ) {
            if ( isset($drf[$typeclass][0]['value']) ) {
                // Datetime fields need to be converted into a string...
                return ($drf[$typeclass][0]['value'])->format('Y-m-d');
            }
        }
        else {
            if ( isset($drf[$typeclass][0]['value']) ) {
                // All other fields can just be used directly
                return trim($drf[$typeclass][0]['value']);
            }
        }

        // Otherwise, no value exists...return the empty string
        return '';
    }


    /**
     * Recursively "inflates" a $datarecord_array from {@link self::getDatarecordArray()} so that
     * child/linked datarecords are stored "underneath" their parents/grandparents.
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
     * Deletes the cached table entries for the specified datatype...currently used by several
     * render plugins after they get removed or their settings get changed...
     *
     * TODO - is there a better place for this function?  CacheService doesn't load the database...
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
     * Generates a CSRF token for every datarecord/datafield pair in the provided arrays.
     *
     * @param array $datatype_array {@link DatabaseInfoService::buildDatatypeData()}
     * @param array $datarecord_array {@link DatarecordInfoService::buildDatarecordData()}
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
     * This function assumes it's creating a fake top-level datarecord.  If the caller instead needs
     * a fake child datarecord, then it's their responsibility to splice the array this function
     * returns with the array of its parent.  The caller will also need to deal with setting the
     * parent/grandparent attributes in that case.
     *
     * @param array $datatype_array {@link DatabaseInfoService::getDatatypeArray()}
     * @param int $target_datatype_id
     * @param string $fake_dr_id An optional string to use for identifying the datarecord
     * @param array $datafield_values An optional array of df_id => value pairs, if the "fake" record isn't supposed to be completely blank
     *
     * @return array
     */
    public function createFakeDatarecordEntry($datatype_array, $target_datatype_id, $fake_dr_id = '', $datafield_values = array())
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
        if ( $fake_dr_id === '' ) {
            $fake_dr_id = UniqueUtility::uniqueIdReal();
            while ( is_numeric($fake_dr_id) )
                $fake_dr_id = UniqueUtility::uniqueIdReal();
        }

        $entry = array(
            'id' => $fake_dr_id,
            'is_fake' => true,

            // These values shouldn't cause a problem...
            'provisioned' => true,
            'unique_id' => '',

            'dataRecordMeta' => array(
                'id' => null,    // TODO - is this a problem?  it shouldn't be, since drf_id should never be used...
                'publicDate' => new \DateTime('2200-01-01 00:00:00'),
            ),

            'parent' => array(
                'id' => $fake_dr_id,
            ),
            'grandparent' => array(
                'id' => $fake_dr_id,
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
            'nameField_value' => $fake_dr_id,    // Get Edit mode to render the datatype name instead of a blank
            'sortField_value' => '',
            'sortField_typeclass' => '',

            // Don't actually need to create entries here, twig effectively assumes all fields
            //  have no value in this case
            'dataRecordFields' => array()
        );

        // If this was a request to create a "fake" record with some non-blank values...
        if ( !empty($datafield_values) ) {
            foreach ($datafield_values as $df_id => $value) {
                // ...then create a "fake" drf entry...
                $fake_drf_entry = self::createFakeDatarecordFieldEntry($datatype_array, $df_id, $value);
                // ...and save it into the "fake" record entry if it was valid
                if ( !empty($fake_drf_entry) )
                    $entry['dataRecordFields'][$df_id] = $fake_drf_entry;
            }
        }

        return $entry;
    }


    /**
     * If the user wants to create a new record via InlineLink, and the data they've entered failed
     * to save properly, then a FakeEdit record needs to be created with the data they've already
     * entered.
     *
     * @param array $datatype_array
     * @param integer $df_id
     * @param mixed $value
     *
     * @return array
     */
    private function createFakeDatarecordFieldEntry($datatype_array, $df_id, $value)
    {
        $fake_drf = array();

        // Need to determine the fieldtype of the requested datafield, but there could be multiple
        //  datatypes in the cached datatype array...
        foreach ($datatype_array as $dt_id => $dt) {
            if ( isset($dt['dataFields'][$df_id]) ) {
                $df = $dt['dataFields'][$df_id];
                $typeclass = strtolower( $df['dataFieldMeta']['fieldType']['typeClass'] );

                switch ($typeclass) {
                    // FakeEdit doesn't allow uploads of files/images...
                    case 'file':
                    case 'image':
                    // ...and InlineLink currently ignores radio/tag fields...
                    case 'radio':
                    case 'tags':
                    // ...so any value in these fieldtypes should get ignored
                    case 'markdown':
                    case 'xyzdata':
                        // TODO - modify to fill out non-markdown fields?
                        return array();
                }

                // Need to check whether the datafield has the "no_user_edits" or "is_derived"
                //  properties from a render plugin
                if ( !empty($df['renderPluginInstances']) ) {
                    foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                        // Datafield plugins are guaranteed to have a single renderPluginMap entry,
                        //  but don't know what the renderPluginField name is
                        foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                            if ( isset($rpf['properties']['no_user_edits'])
                                || isset($rpf['properties']['is_derived'])
                            ) {
                                // ...the user isn't supposed to be able to change this datafield's
                                //  value
                                return array();
                            }
                        }
                    }
                }

                // ...and also check from a datatype plugin
                if ( !empty($dt['renderPluginInstances']) ) {
                    foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                        foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                            if ( $rpf['id'] === $df_id ) {
                                // This datafield is being used by a datatype plugin...
                                if ( isset($rpf['properties']['no_user_edits'])
                                    || isset($rpf['properties']['is_derived'])
                                ) {
                                    // ...and the user isn't supposed to be able to change its value
                                    return array();
                                }
                            }
                        }
                    }
                }

                // Otherwise, there's nothing preventing FakeEdit from displaying this value
                $fake_drf[$typeclass] = array(
                    0 => array(
                        'value' => $value
                    )
                );
                break;
            }
        }

        return $fake_drf;
    }


    /**
     * Originally, changes made to a datarecord would also change that datarecord's updated property,
     * as well as the the updated property of that datarecord's parent...repeated until it hit the
     * datarecord's grandparent.
     *
     * After external applications started checking the updated property to determine when stuff
     * changed, it became apparent that the scope needed to expand to include every single possible
     * ancestor of the datarecord...otherwise, the external applications had to repeatedly recheck
     * basically everything they could be interested in.
     *
     * This "every possible ancestor" logic needs to be applied to multiple places, and is easier
     * anyways when it's off in its own function.
     *
     * NOTE: this can return datarecords belonging to deleted datatypes, even when $include_deleted
     * is false.
     *
     * @param int[] $datarecords_to_process
     * @return int[]
     */
    public function findAllAncestors($datarecords_to_process, $include_deleted = false)
    {
        $conn = $this->em->getConnection();

        $all_datarecord_ids = array();
        foreach ($datarecords_to_process as $num => $dr_id)
            $all_datarecord_ids[$dr_id] = 0;

        while ( !empty($datarecords_to_process) ) {
            $query =
               'SELECT ddr.id AS ddr_id, ddr.parent_id AS parent_id, adr.id AS linked_ancestor_id
                FROM odr_data_record ddr
                LEFT JOIN odr_linked_data_tree ldt ON ldt.descendant_id = ddr.id AND ldt.deletedAt IS NULL
                LEFT JOIN odr_data_record adr ON ldt.ancestor_id = adr.id
                WHERE ddr.id IN (?)';
            if ( !$include_deleted )
                $query .= ' AND ddr.deletedAt IS NULL';
            $parameters = array(1 => $datarecords_to_process);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->executeQuery($query, $parameters, $types);  // change to fetchAll() for debugging

            $datarecords_to_process = array();
            foreach ($results as $result) {
                $ddr_id = intval($result['ddr_id']);
                $all_datarecord_ids[$ddr_id] = 0;

                // Store this datarecord's ancestor regardless of whether it was a child or a link
                if ( !is_null($result['parent_id']) ) {
                    $parent_id = intval($result['parent_id']);
                    if (  $ddr_id !== $parent_id ) {
                        $datarecords_to_process[$parent_id] = 0;
                        $all_datarecord_ids[$parent_id] = 0;
                    }
                }
                if ( !is_null($result['linked_ancestor_id']) ) {
                    $linked_ancestor_id = intval($result['linked_ancestor_id']);
                    $datarecords_to_process[$linked_ancestor_id] = 0;
                    $all_datarecord_ids[$linked_ancestor_id] = 0;
                }
            }

            // The loop requires the datarecord ids to be values, not keys
            $datarecords_to_process = array_keys($datarecords_to_process);
        }

        // The resulting list of datarecords should also be returned as values, not keys
        $all_datarecord_ids = array_keys($all_datarecord_ids);
        return $all_datarecord_ids;
    }


    /**
     * The ideal way to invert {@link self::findAllAncestors()} isn't entirely obvious, so it makes
     * sense to have a mirror function as well.
     *
     * NOTE: this can return datarecords belonging to deleted datatypes, even when $include_deleted
     * is false.
     *
     * @param int[] $datarecords_to_process
     * @return int[]
     */
    public function findAllDescendants($datarecords_to_process, $include_deleted = false)
    {
        $conn = $this->em->getConnection();

        $all_datarecord_ids = array();
        while ( !empty($datarecords_to_process) ) {
            // Need two queries to do this...first is to get all child descendants of these records
            $query =
               'SELECT dr.id AS dr_id
                FROM odr_data_record dr
                WHERE dr.grandparent_id IN (?)';
            if ( !$include_deleted )
                $query .= ' AND dr.deletedAt IS NULL';

            $parameters = array(1 => $datarecords_to_process);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->executeQuery($query, $parameters, $types);  // change to fetchAll() for debugging

            $datarecords_to_process = array();
            foreach ($results as $result) {
                $dr_id = intval($result['dr_id']);

                $all_datarecord_ids[$dr_id] = 0;
                $datarecords_to_process[$dr_id] = 0;
            }
            // The next query needs the datarecord ids to be values, not keys
            $datarecords_to_process = array_keys($datarecords_to_process);

            // Second query is to get all records the previous set linked to
            $query =
               'SELECT ldt.descendant_id AS ddr_id
                FROM odr_data_record adr
                JOIN odr_linked_data_tree ldt ON ldt.ancestor_id = adr.id
                WHERE adr.id IN (?)';
            if ( !$include_deleted )
                $query .= ' AND ldt.deletedAt IS NULL';

            $parameters = array(1 => $datarecords_to_process);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->executeQuery($query, $parameters, $types);

            $datarecords_to_process = array();
            foreach ($results as $result) {
                $ddr_id = intval($result['ddr_id']);

                $datarecords_to_process[$ddr_id] = 0;
            }

            // The next stage of the loop requires the datarecord ids to be values, not keys
            $datarecords_to_process = array_keys($datarecords_to_process);
        }

        // The resulting list of datarecords should also be returned as values, not keys
        $all_datarecord_ids = array_keys($all_datarecord_ids);
        return $all_datarecord_ids;
    }
}
