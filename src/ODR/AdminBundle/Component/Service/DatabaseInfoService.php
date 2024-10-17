<?php

/**
 * Open Data Repository Data Publisher
 * Database Info Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get and rebuild the cached version of the datatype array, as
 * well as several other utility functions related to lists of datatypes.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
// Utility
use ODR\AdminBundle\Component\Utility\UserUtility;


class DatabaseInfoService
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
     * @var Logger
     */
    private $logger;


    /**
     * DatabaseInfoService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatatreeInfoService $datatree_info_service
     * @param TagHelperService $tag_helper_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatreeInfoService $datatree_info_service,
        TagHelperService $tag_helper_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dti_service = $datatree_info_service;
        $this->th_service = $tag_helper_service;
        $this->logger = $logger;
    }


    /**
     * Utility function to convert a unique id into a datatype entry...most useful for other
     * services...
     *
     * @throws ODRException
     *
     * @param string $unique_id
     *
     * @return DataType
     */
    public function getDatatypeFromUniqueId($unique_id)
    {
        // Ensure it's a valid unique identifier first...
        $pattern = '/^[a-z0-9]+$/';
        if ( preg_match($pattern, $unique_id) !== 1 )
            throw new ODRBadRequestException('Invalid unique_id: "'.$unique_id.'"', 0xaf067bda);

        /** @var DataType $dt */
        $dt = $this->em->getRepository('ODRAdminBundle:DataType')->findOneBy(
            array('unique_id' => $unique_id)
        );
        if ( is_null($dt) )
            throw new ODRNotFoundException('Datatype', false, 0xaf067bda);

        return $dt;
    }


    /**
     * Loads and returns the cached data array for the requested datatype.  The returned array
     * contains data of all datatypes with the requested datatype as their grandparent, as
     * well as any datatypes that are linked to by the requested datatype or its children.
     *
     * Use {@link self::stackDatatypeArray()} to get an array structure where child/linked datatypes
     * are stored "underneath" their parent datatypes.
     * 
     * @param integer $grandparent_datatype_id
     * @param bool $include_links  If true, then the returned array will also contain linked datatypes
     *
     * @return array
     */
    public function getDatatypeArray($grandparent_datatype_id, $include_links = true)
    {
        $associated_datatypes = array();
        if ($include_links) {
            // Need to locate all linked datatypes for the provided datatype
            $associated_datatypes = $this->dti_service->getAssociatedDatatypes($grandparent_datatype_id);
        }
        else {
            // Don't want any datatypes that are linked to from the given grandparent datatype
            $associated_datatypes[] = $grandparent_datatype_id;
        }

        // Load the cached versions of each associated datatype, and store them all at the same
        //  level in a single array
        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            $datatype_data = $this->cache_service->get('cached_datatype_'.$dt_id);
            if ($datatype_data == false)
                $datatype_data = self::buildDatatypeData($dt_id);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }

        return $datatype_array;
    }


    /**
     * Similar to {@link self::getDatatypeArray()}, but instead includes the datatypes that link to
     * the requested datatype.  The $include_links parameter doesn't exist, as there's no reason to
     * call this where that value would be false.
     *
     * The returned array is generally going to be unsuitable for {@link self::stackDatatypeArray()}
     *
     * @param integer $datatype_id
     *
     * @return array
     */
    public function getInverseDatatypeArray($datatype_id)
    {
        // Need to locate all linked datatypes for the provided datatype
        $associated_datatypes = $this->dti_service->getInverseAssociatedDatatypes($datatype_id);

        // Load the cached versions of each associated datatype, and store them all at the same
        //  level in a single array
        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            $datatype_data = $this->cache_service->get('cached_datatype_'.$dt_id);
            if ($datatype_data == false)
                $datatype_data = self::buildDatatypeData($dt_id);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }

        return $datatype_array;
    }


    /**
     * Gets all datatype, datafield, and their associated render plugin information...the array
     * is slightly modified, stored in the cache, and then returned.
     *
     * @param integer $grandparent_datatype_id
     *
     * @return array
     */
    private function buildDatatypeData($grandparent_datatype_id)
    {
        // This function is only called when the cache entry doesn't exist

        // Going to need the datatree array to rebuild this
        $datatree_array = $this->dti_service->getDatatreeArray();

        // Going to need any tag hierarchy data for this datatype
        $tag_hierarchy = $this->th_service->getTagHierarchy($grandparent_datatype_id);


        // ----------------------------------------
        // Need to perform an adjustment so that array hydration of "master" datafields/datatypes
        //  matches full hydration of the same entities...
        $derived_dt_data = array();
        $derived_df_data = array();
        self::getDerivedData($grandparent_datatype_id, $derived_dt_data, $derived_df_data);

        // These two are kept separate from the primary query because mysql does not like having
        //  to potentially load hundreds of either of these entities in the main query
        $radio_options = self::getRadioOptionData($grandparent_datatype_id);
        $tags = self::getTagData($grandparent_datatype_id);

        // Name/Sort fields are also separate from the primary query because the datatype could have
        //  more than one of either, and both of them have their own displayOrder
        $special_fields = self::getSpecialFields($grandparent_datatype_id);

        // The doctrine hydrator apparently sometimes has issues loading renderPluginEvents, so the
        //  renderPlugin data has to be loaded in its own function too...
        $render_plugin_data = self::getRenderPluginData($grandparent_datatype_id);

        // Get all the rest of the non-layout data for the requested datatype
        $query = $this->em->createQuery(
           'SELECT
                dt, dtm,
                partial dt_eif.{id}, partial dt_bif.{id},
                partial md.{id, unique_id},
                partial mf.{id, unique_id},
                partial dt_cb.{id, username, email, firstName, lastName},
                partial dt_ub.{id, username, email, firstName, lastName},

                partial dt_rpi.{id},

                df, dfm, partial ft.{id, typeClass, typeName},
                partial df_cb.{id, username, email, firstName, lastName},

                partial df_rpi.{id}

            FROM ODRAdminBundle:DataType AS dt
            LEFT JOIN dt.createdBy AS dt_cb
            LEFT JOIN dt.updatedBy AS dt_ub
            LEFT JOIN dt.metadata_datatype AS md
            LEFT JOIN dt.metadata_for AS mf

            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dtm.externalIdField AS dt_eif
            LEFT JOIN dtm.backgroundImageField AS dt_bif

            LEFT JOIN dt.renderPluginInstances AS dt_rpi

            LEFT JOIN dt.dataFields AS df
            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN df.createdBy AS df_cb
            LEFT JOIN dfm.fieldType AS ft

            LEFT JOIN df.renderPluginInstances AS df_rpi

            WHERE
                dt.grandparent = :grandparent_datatype_id
                AND dt.deletedAt IS NULL
            ORDER BY dt.id, df.id'
        )->setParameters(
            array(
                'grandparent_datatype_id' => $grandparent_datatype_id
            )
        );
        // TODO - rename above RenderPluginOptionsDef to RenderPluginOptions
        $datatype_data = $query->getArrayResult();

        // TODO - if $datatype_data is empty, then $grandparent_datatype_id was deleted...should this return something special in that case?

        // The entity -> entity_metadata relationships have to be one -> many from a database
        // perspective, even though there's only supposed to be a single non-deleted entity_metadata
        // object for each entity.  Therefore, the preceding query generates an array that needs
        // to be somewhat flattened in a few places.
        foreach ($datatype_data as $dt_num => $dt) {
            $dt_id = $dt['id'];

            // Flatten datatype meta
            if ( count($dt['dataTypeMeta']) == 0 ) {
                // TODO - this comparison (and the 3 others in this function) really needs to be strict (!== 1)
                // TODO - ...but that would lock up multiple dev servers until their databases get fixed
                // ...throwing an exception here because this shouldn't ever happen, and also requires
                //  manual intervention to fix...
                throw new ODRException('Unable to rebuild the cached_datatype_'.$dt_id.' array because of a database error for datatype '.$dt_id);
            }

            $dtm = $dt['dataTypeMeta'][0];
            $datatype_data[$dt_num]['dataTypeMeta'] = $dtm;
            $datatype_data[$dt_num]['masterDataType'] = $derived_dt_data[$dt_id];

            // Scrub irrelevant data from the datatype's createdBy and updatedBy properties
            $datatype_data[$dt_num]['createdBy'] = UserUtility::cleanUserData( $dt['createdBy'] );
            $datatype_data[$dt_num]['updatedBy'] = UserUtility::cleanUserData( $dt['updatedBy'] );

            // Attach the renderPlugin data for this datatype, if the datatype is using any
            if ( !empty($datatype_data[$dt_num]['renderPluginInstances']) ) {
                // Going to completely replace the array entry here
                $tmp_rpi = array();

                foreach ($datatype_data[$dt_num]['renderPluginInstances'] as $rpi_num => $rpi) {
                    // The render plugin data has already been loaded and cleaned up...
                    $rpi_id = $rpi['id'];
                    if ( !isset($render_plugin_data[$rpi_id]) )
                        throw new ODRException('Unable to rebuild the cached_datatype_'.$dt_id.' array because the data for renderPluginInstance '.$rpi_id.' is missing');

                    // ...just need to locate it by the id of the renderPluginInstance
                    $tmp_rpi[$rpi_id] = $render_plugin_data[$rpi_id];
                }

                $datatype_data[$dt_num]['renderPluginInstances'] = $tmp_rpi;
            }

            // Attach any name/sort fields for this datatype
            $datatype_data[$dt_num]['nameFields'] = array();
            $datatype_data[$dt_num]['sortFields'] = array();
            if ( isset($special_fields[$dt_id]) ) {
                if ( !empty($special_fields[$dt_id]['name']) )
                    $datatype_data[$dt_num]['nameFields'] = $special_fields[$dt_id]['name'];
                if ( !empty($special_fields[$dt_id]['sort']) )
                    $datatype_data[$dt_num]['sortFields'] = $special_fields[$dt_id]['sort'];
            }


            // ----------------------------------------
            // Organize the datafields by their datafield_id instead of a random number
            $new_datafield_array = array();
            foreach ($dt['dataFields'] as $df_num => $df) {
                $df_id = $df['id'];
                $typeclass = $df['dataFieldMeta'][0]['fieldType']['typeClass'];

                // Flatten datafield_meta and masterDatafield of each datafield
                if ( count($df['dataFieldMeta']) == 0 ) {
                    // ...throwing an exception here because this shouldn't ever happen, and also
                    //  requires manual intervention to fix...
                    throw new ODRException('Unable to rebuild the cached_datatype_'.$dt_id.' array because of a database error for datafield '.$df_id);
                }

                $dfm = $df['dataFieldMeta'][0];
                $df['dataFieldMeta'] = $dfm;

                // Scrub irrelevant data from the datafield's createdBy property
                $df['createdBy'] = UserUtility::cleanUserData( $df['createdBy'] );

                // Attach the renderPlugin data for this datafield, if the datafield is using any
                if ( !empty($df['renderPluginInstances']) ) {
                    // Going to completely replace the array entry here
                    $tmp_rpi = array();

                    foreach ($df['renderPluginInstances'] as $rpi_num => $rpi) {
                        // The render plugin data has already been loaded and cleaned up...
                        $rpi_id = $rpi['id'];
                        if ( !isset($render_plugin_data[$rpi_id]) )
                            throw new ODRException('Unable to rebuild the cached_datatype_'.$dt_id.' array because the data for renderPluginInstance '.$rpi_id.' is missing');

                        // ...just need to locate it by the id of the renderPluginInstance
                        $tmp_rpi[$rpi_id] = $render_plugin_data[$rpi_id];
                    }

                    $df['renderPluginInstances'] = $tmp_rpi;
                }

                // Attach the id of this datafield's masterDatafield if it exists
                $df['masterDataField'] = $derived_df_data[$df_id];

                // Attach radio options if they exist
                if ( isset($radio_options[$df_id]) )
                    $df['radioOptions'] = $radio_options[$df_id];

                // Attach tags if they exist
                if ( $typeclass === 'Tag' ) {
                    // This is supposed to be a tag field...
                    if ( !isset($tags[$df_id]) ) {
                        // ...but it has no tags...ensure blank arrays exist
                        $df['tags'] = array();
                        $df['tagTree'] = array();
                    }
                    else {
                        // Tags exist, attempt to locate any tag hierarchy data
                        $tag_tree = array();
                        if ( isset($tag_hierarchy[$dt_id][$df_id]) )
                            $tag_tree = $tag_hierarchy[$dt_id][$df_id];

                        // Stack/order the tags before saving them in the array
                        $tag_list = $this->th_service->stackTagArray($tags[$df_id], $tag_tree);
                        $this->th_service->orderStackedTagArray($tag_list);

                        // Also save the tag hierarchy in here for convenience
                        $df['tags'] = $tag_list;
                        $df['tagTree'] = $tag_tree;    // TODO - this kind of duplicates the data in 'tags'...but it's used by template cloning, so don't want to touch it right now...
                    }
                }

                $new_datafield_array[$df_id] = $df;
            }

            unset( $datatype_data[$dt_num]['dataFields'] );
            $datatype_data[$dt_num]['dataFields'] = $new_datafield_array;


            // ----------------------------------------
            // Build up a list of child/linked datatypes and their basic information
            // I think the 'is_link' property is used during rendering, but I'm not sure about the
            //  'multiple_allowed' property
            $descendants = array();
            foreach ($datatree_array['descendant_of'] as $child_dt_id => $parent_dt_id) {
                if ($parent_dt_id == $dt_id)
                    $descendants[$child_dt_id] = array('is_link' => 0, 'multiple_allowed' => 0);
            }
            foreach ($datatree_array['linked_from'] as $child_dt_id => $parents) {
                if ( in_array($dt_id, $parents) )
                    $descendants[$child_dt_id] = array('is_link' => 1, 'multiple_allowed' => 0);
            }
            foreach ($datatree_array['multiple_allowed'] as $child_dt_id => $parents) {
                if ( isset($descendants[$child_dt_id]) && in_array($dt_id, $parents) )
                    $descendants[$child_dt_id]['multiple_allowed'] = 1;
            }

            if ( count($descendants) > 0 )
                $datatype_data[$dt_num]['descendants'] = $descendants;
        }


        // Organize by datatype id...permissions filtering doesn't work if the array isn't flat
        $formatted_datatype_data = array();
        foreach ($datatype_data as $num => $dt_data) {
            $dt_id = $dt_data['id'];

            $formatted_datatype_data[$dt_id] = $dt_data;
        }


        // ----------------------------------------
        // Save the formatted datarecord data back in the cache, and return it
        $this->cache_service->set('cached_datatype_'.$grandparent_datatype_id, $formatted_datatype_data);
        return $formatted_datatype_data;
    }


    /**
     * There is an edge case when a datafield is "derived" from a "master" datafield, but then the
     * "master" datafield is deleted...because of how doctrine operates, using full hydration will
     * result in  is_null( $df->getMasterDatafield() ) === false...but running array hydration on
     * the same entity will result in  $df['masterDataField'] === null.  The exact same thing happens
     * with datatypes.
     *
     * Since those two results are clearly contradictory, this function forces array hydration
     * (which is mostly performed here) to match the results of full hydration (which is typically
     * used elsewhere).
     *
     * This is primarily needed so template synchronization can be guaranteed to match a derived
     * datafield with its master datafield...same deal with master datatypes.
     *
     * @param int $grandparent_datatype_id
     * @param array $derived_dt_data
     * @param array $derived_df_data
     */
    private function getDerivedData($grandparent_datatype_id, &$derived_dt_data, &$derived_df_data)
    {
        $query = $this->em->createQuery(
           'SELECT
                partial dt.{id}, partial mdt.{id, unique_id}, partial mdt_dtm.{id, shortName},
                partial df.{id}, partial mdf.{id}
            FROM ODRAdminBundle:DataType AS dt
            LEFT JOIN dt.masterDataType AS mdt
            LEFT JOIN mdt.dataTypeMeta AS mdt_dtm
            LEFT JOIN dt.dataFields AS df
            LEFT JOIN df.masterDataField AS mdf
            WHERE dt.grandparent = :grandparent_datatype_id'
        )->setParameters( array('grandparent_datatype_id' => $grandparent_datatype_id) );

        // Need to disable the softdeleteable filter so doctrine pulls the id for deleted master
        //  datafield entries
        $this->em->getFilters()->disable('softdeleteable');
        $master_data = $query->getArrayResult();
        $this->em->getFilters()->enable('softdeleteable');

        foreach ($master_data as $dt_num => $dt) {
            // Store the potentially deleted master datatype
            $dt_id = $dt['id'];
            $mdt_data = null;
            if ( isset($dt['masterDataType']) && !is_null($dt['masterDataType']) ) {
                // Due to loading deleted entries, need to find the most recent dataTypeMeta entry
                $short_name = '';
                foreach ($dt['masterDataType']['dataTypeMeta'] as $mdt_dtm_num => $mdt_dtm)
                    $short_name = $mdt_dtm['shortName'];

                $mdt_data = array(
                    'id' => $dt['masterDataType']['id'],
                    'unique_id' => $dt['masterDataType']['unique_id'],
                    'shortName' => $short_name,
                );
            }
            $derived_dt_data[$dt_id] = $mdt_data;

            // Store the potentially deleted master datafield
            foreach ($dt['dataFields'] as $df_num => $df) {
                $df_id = $df['id'];
                $mdf_data = null;
                if ( isset($df['masterDataField']) && !is_null($df['masterDataField']) ) {
                    $mdf_data = array(
                        'id' => $df['masterDataField']['id']
                    );
                }

                $derived_df_data[$df_id] = $mdf_data;
            }
        }
    }


    /**
     * Because of the complexity/depth of the main query in buildDatatypeData(), it's a lot harder
     * for mysql if the database also has hundreds of radio options...so it's better to get radio
     * options in a separate query.
     *
     * @param int $grandparent_datatype_id
     *
     * @return array
     */
    private function getRadioOptionData($grandparent_datatype_id)
    {
        $query = $this->em->createQuery(
           'SELECT partial dt.{id}, partial df.{id}, ro, rom
            FROM ODRAdminBundle:DataType AS dt
            LEFT JOIN dt.dataFields AS df
            LEFT JOIN df.radioOptions AS ro
            LEFT JOIN ro.radioOptionMeta AS rom
            WHERE dt.grandparent = :grandparent_datatype_id
            AND dt.deletedAt IS NULL
            ORDER BY df.id, rom.displayOrder, ro.id'
        )->setParameters( array('grandparent_datatype_id' => $grandparent_datatype_id) );
        $datatype_data = $query->getArrayResult();

        $radio_options = array();
        foreach ($datatype_data as $dt_num => $dt) {
            $dt_id = $dt['id'];

            foreach ($dt['dataFields'] as $df_num => $df) {
                if ( !empty($df['radioOptions']) ) {
                    $df_id = $df['id'];
                    $radio_options[$df_id] = array();

                    foreach ($df['radioOptions'] as $ro_num => $ro) {
                        if ( count($ro['radioOptionMeta']) == 0 ) {
                            // ...throwing an exception here because this shouldn't ever happen, and
                            //  also requires manual intervention to fix...
                            throw new ODRException('Unable to rebuild the cached_datatype_'.$dt_id.' array because of a database error for radio option '.$ro['id']);
                        }

                        $ro['radioOptionMeta'] = $ro['radioOptionMeta'][0];
                        $radio_options[$df_id][$ro_num] = $ro;
                    }
                }
            }
        }

        return $radio_options;
    }


    /**
     * Because of the complexity/depth of the main query in buildDatatypeData(), it's a lot harder
     * for mysql if the database also has hundreds of tags...so it's better to get all the tags in
     * in a separate query.
     *
     * @param int $grandparent_datatype_id
     *
     * @return array
     */
    private function getTagData($grandparent_datatype_id)
    {
        $query = $this->em->createQuery(
           'SELECT partial dt.{id}, partial df.{id}, t, tm
            FROM ODRAdminBundle:DataType AS dt
            LEFT JOIN dt.dataFields AS df
            LEFT JOIN df.tags AS t
            LEFT JOIN t.tagMeta AS tm
            WHERE dt.grandparent = :grandparent_datatype_id
            AND dt.deletedAt IS NULL'    // tags have a display order, but it only makes sense when they're being stacked
        )->setParameters( array('grandparent_datatype_id' => $grandparent_datatype_id) );
        $datatype_data = $query->getArrayResult();

        $tags = array();
        foreach ($datatype_data as $dt_num => $dt) {
            $dt_id = $dt['id'];

            foreach ($dt['dataFields'] as $df_num => $df) {
                if ( !empty($df['tags']) ) {
                    $df_id = $df['id'];
                    $tags[$df_id] = array();

                    foreach ($df['tags'] as $t_num => $t) {
                        if ( count($t['tagMeta']) == 0 ) {
                            // ...throwing an exception here because this shouldn't ever happen, and
                            //  also requires manual intervention to fix...
                            throw new ODRException('Unable to rebuild the cached_datatype_'.$dt_id.' array because of a database error for tag '.$t['id']);
                        }

                        $tag_id = $t['id'];
                        $t['tagMeta'] = $t['tagMeta'][0];
                        $tags[$df_id][$tag_id] = $t;
                    }
                }
            }
        }

        return $tags;
    }


    /**
     * Due to datatypes potentially having multiple name/sort fields, both with their own displayOrder
     * values, it's easier to get these in a separate query.
     *
     * @param int $grandparent_datatype_id
     *
     * @return array
     */
    private function getSpecialFields($grandparent_datatype_id)
    {
        $query = $this->em->createQuery(
           'SELECT dt.id AS dt_id, dtsf.field_purpose, dtsf.displayOrder, df.id AS df_id
            FROM ODRAdminBundle:DataType AS dt
            LEFT JOIN ODRAdminBundle:DataTypeSpecialFields AS dtsf WITH dtsf.dataType = dt
            LEFT JOIN ODRAdminBundle:DataFields AS df WITH dtsf.dataField = df
            WHERE dt.grandparent = :grandparent_datatype_id
            AND dt.deletedAt IS NULL AND dtsf.deletedAt IS NULL AND df.deletedAt IS NULL
            ORDER BY dt.id, dtsf.field_purpose, dtsf.displayOrder, df.id'
        )->setParameters( array('grandparent_datatype_id' => $grandparent_datatype_id) );
        $results = $query->getArrayResult();

        $special_fields = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $field_purpose = $result['field_purpose'];
            $display_order = $result['displayOrder'];
            $df_id = $result['df_id'];

            if ( !isset($special_fields[$dt_id]) )
                $special_fields[$dt_id] = array('name' => array(), 'sort' => array());

            if ( !is_null($df_id) ) {
                if ( $field_purpose === DataTypeSpecialFields::NAME_FIELD )
                    $special_fields[$dt_id]['name'][$display_order] = $df_id;
                else if ( $field_purpose === DataTypeSpecialFields::SORT_FIELD )
                    $special_fields[$dt_id]['sort'][$display_order] = $df_id;
            }
        }

        return $special_fields;
    }


    /**
     * Doctrine apparently has random issues hydrating renderPluginEvent entries when called from
     * the query in self::buildDatatypeData()...so it the stuff specific to renderPlugins has to be
     * split into its own function.
     *
     * Additionally, this function also reorganizes the data from the database so it's more readily
     * useful to other parts of ODR.
     *
     * @param int $grandparent_datatype_id
     *
     * @return array
     */
    private function getRenderPluginData($grandparent_datatype_id)
    {
        $render_plugin_instances = array();

        // Locate each render plugin attached to each datatype descended from the grandparent...
        $query = $this->em->createQuery(
           'SELECT partial dt.{id},
                partial rpi.{id}, rp, partial rpe.{id, eventName},
                partial rpom.{id, value}, partial rpo.{id, name},
                partial rpm.{id},
                partial rpf.{id, fieldName, allowedFieldtypes, must_be_unique, single_uploads_only, no_user_edits, autogenerate_values, is_derived, is_optional},
                rpm_df

            FROM ODRAdminBundle:DataType AS dt

            LEFT JOIN dt.renderPluginInstances AS rpi
            LEFT JOIN rpi.renderPlugin AS rp
            LEFT JOIN rp.renderPluginEvents AS rpe
            LEFT JOIN rpi.renderPluginOptionsMap AS rpom
            LEFT JOIN rpom.renderPluginOptionsDef AS rpo
            LEFT JOIN rpi.renderPluginMap AS rpm
            LEFT JOIN rpm.renderPluginFields AS rpf
            LEFT JOIN rpm.dataField AS rpm_df

            WHERE dt.grandparent = :grandparent_datatype_id
            AND dt.deletedAt IS NULL'
        )->setParameters(
            array(
                'grandparent_datatype_id' => $grandparent_datatype_id
            )
        );
        $results = $query->getArrayResult();

        // Only interested in datatypes with attached renderPlugins
        foreach ($results as $result) {
            if ( !empty($result['renderPluginInstances']) ) {
                foreach ($result['renderPluginInstances'] as $rpi_num => $rpi) {
                    $rpi_id = $rpi['id'];
                    $render_plugin_instances[$rpi_id] = $rpi;
                }
            }
        }

        // Locate each render plugin attached to each datafield in a datatype that's descended from
        //  the grandparent...
        $query = $this->em->createQuery(
            'SELECT partial df.{id},
                partial rpi.{id}, rp, partial rpe.{id, eventName},
                partial rpom.{id, value}, partial rpo.{id, name},
                partial rpm.{id},
                partial rpf.{id, fieldName, allowedFieldtypes, must_be_unique, single_uploads_only, no_user_edits, autogenerate_values, is_derived, is_optional},
                rpm_df

            FROM ODRAdminBundle:DataFields AS df
            LEFT JOIN df.dataType AS dt

            LEFT JOIN df.renderPluginInstances AS rpi
            LEFT JOIN rpi.renderPlugin AS rp
            LEFT JOIN rp.renderPluginEvents AS rpe
            LEFT JOIN rpi.renderPluginOptionsMap AS rpom
            LEFT JOIN rpom.renderPluginOptionsDef AS rpo
            LEFT JOIN rpi.renderPluginMap AS rpm
            LEFT JOIN rpm.renderPluginFields AS rpf
            LEFT JOIN rpm.dataField AS rpm_df

            WHERE dt.grandparent = :grandparent_datatype_id
            AND dt.deletedAt IS NULL'
        )->setParameters(
            array(
                'grandparent_datatype_id' => $grandparent_datatype_id
            )
        );
        $results = $query->getArrayResult();

        // Only interested in datafields with attached renderPlugins
        foreach ($results as $result) {
            if ( !empty($result['renderPluginInstances']) ) {
                foreach ($result['renderPluginInstances'] as $rpi_num => $rpi) {
                    $rpi_id = $rpi['id'];
                    $render_plugin_instances[$rpi_id] = $rpi;
                }
            }
        }


        // ----------------------------------------
        // Need to modify the resulting array somewhat to make it easier for other parts of ODR
        //  to use
        foreach ($render_plugin_instances as $rpi_id => $rpi) {
            // The renderPluginInstance should always have a renderPlugin entry...
            if ( is_null($rpi['renderPlugin']) )
                throw new ODRException('Unable to rebuild the cached_datatype_'.$grandparent_datatype_id.' array because of a database error for rpi '.$rpi_id);

            // For renderPluginEvents, only care about the event name
            $tmp_rpe = array();
            $rp = $rpi['renderPlugin'];
            foreach ($rp['renderPluginEvents'] as $rpe_num => $rpe)
                $tmp_rpe[ $rpe['eventName'] ] = 1;

            if ( !empty($tmp_rpe) )
                $render_plugin_instances[$rpi_id]['renderPlugin']['renderPluginEvents'] = $tmp_rpe;

            // All plugins will have an entry for mapped fields, although it might be empty
            foreach ($rpi['renderPluginMap'] as $rpm_num => $rpm) {
                // ...each renderPluginMap will have a single renderPluginField entry...
                $rpf = $rpm['renderPluginFields'];
                $rpf_fieldName = $rpf['fieldName'];
                $rpf_allowedFieldtypes = $rpf['allowedFieldtypes'];

                // ...and will have a single dataField entry if it's a datatype plugin (but won't
                //  if it's a datafield plugin)
                $rpf_df = array();
                if ( !isset($rpm['dataField']) ) {
                    // ...but it might not be set due to the existence of "optional" renderPluginFields

                    // Unfortunately, ODR was originally written following the idea that "an rpf entry
                    //  MUST have a df entry"...but putting a null value in here for the id neatly
                    //  handles most of those places
                    $rpf_df = array('id' => null);
                }
                else {
                    // ...if it is set though, don't want to make any changes here
                    $rpf_df = $rpm['dataField'];
                }

                // The datafield entry in here should also have the rpf's allowedFieldtype values
                $rpf_df['allowedFieldtypes'] = $rpf_allowedFieldtypes;

                // It also needs any of the rpf's properties
                $rpf_df['properties'] = array();
                if ( $rpf['must_be_unique'] )
                    $rpf_df['properties']['must_be_unique'] = 1;
                if ( $rpf['single_uploads_only'] )
                    $rpf_df['properties']['single_uploads_only'] = 1;
                if ( $rpf['no_user_edits'] )
                    $rpf_df['properties']['no_user_edits'] = 1;
                if ( $rpf['autogenerate_values'] )
                    $rpf_df['properties']['autogenerate_values'] = 1;
                if ( $rpf['is_derived'] )
                    $rpf_df['properties']['is_derived'] = 1;
                if ( $rpf['is_optional'] )
                    $rpf_df['properties']['is_optional'] = 1;

                // ...so the label of the renderPluginField can just point to the datafield that's
                //  fulfilling the role defined by the rendrPluginField
                $render_plugin_instances[$rpi_id]['renderPluginMap'][$rpf_fieldName] = $rpf_df;

                // Don't want the old array structure
                unset( $render_plugin_instances[$rpi_id]['renderPluginMap'][$rpm_num] );
            }

            // All plugins will have an entry for required options, although it might be empty
            $tmp_rpom = array();
            foreach ($rpi['renderPluginOptionsMap'] as $rpom_num => $rpom) {
                // ...then each RenderPluginOptionsMap will have a single renderPluginOptionsDef entry
                $rpom_value = $rpom['value'];
                $rpo_name = $rpom['renderPluginOptionsDef']['name'];

                // ...so the renderPluginOption name can just point to the renderPluginOption value
                $tmp_rpom[$rpo_name] = $rpom_value;
            }
            // Replace the previous array with the new structure
            $render_plugin_instances[$rpi_id]['renderPluginOptionsMap'] = $tmp_rpom;
        }

        return $render_plugin_instances;
    }


    /**
     * Recursively "inflates" a $datarecord_array from {@link self::getDatatypeArray()} so that
     * child/linked datarecords are stored "underneath" their parents/grandparents.
     *
     * @param array $datatype_array {@link self::getDatatypeArray()}
     * @param integer $initial_datatype_id
     *
     * @return array
     */
    public function stackDatatypeArray($datatype_array, $initial_datatype_id)
    {
        $current_datatype = array();
        if ( isset($datatype_array[$initial_datatype_id]) ) {
            $current_datatype = $datatype_array[$initial_datatype_id];

            // For each descendant this datatype has...
            if ( isset($current_datatype['descendants']) ) {
                foreach ($current_datatype['descendants'] as $child_datatype_id => $child_datatype) {

                    $tmp = array();
                    if ( isset($datatype_array[$child_datatype_id])) {
                        // Stack each child datatype individually
                        $tmp[$child_datatype_id] = self::stackDatatypeArray($datatype_array, $child_datatype_id);
                    }

                    // ...store child datatypes under their parent
                    $current_datatype['descendants'][$child_datatype_id]['datatype'] = $tmp;
                }
            }
        }

        return $current_datatype;
    }


    /**
     * Returns an array with how many datarecords the user is allowed to see for each datatype in
     * $datatype_ids
     *
     * @param int[] $datatype_ids
     * @param array $datatype_permissions {@link PermissionsManagementService::getDatatypePermissions()}
     *
     * @return array
     */
    public function getDatarecordCounts($datatype_ids, $datatype_permissions)
    {
        $can_view_public_datarecords = array();
        $can_view_nonpublic_datarecords = array();

        foreach ($datatype_ids as $num => $dt_id) {
            if ( isset($datatype_permissions[$dt_id])
                && isset($datatype_permissions[$dt_id]['dr_view'])
            ) {
                $can_view_nonpublic_datarecords[] = $dt_id;
            } else {
                $can_view_public_datarecords[] = $dt_id;
            }
        }

        // Figure out how many datarecords the user can view for each of the datatypes
        $metadata = array();
        if ( count($can_view_nonpublic_datarecords) > 0 ) {
            $query = $this->em->createQuery(
               'SELECT dt.id AS dt_id, COUNT(dr.id) AS datarecord_count
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                WHERE dt IN (:datatype_ids) AND dr.provisioned = FALSE
                AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL
                GROUP BY dt.id'
            )->setParameters(
                array(
                    'datatype_ids' => $can_view_nonpublic_datarecords
                )
            );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $count = $result['datarecord_count'];
                $metadata[$dt_id] = $count;
            }
        }

        if ( count($can_view_public_datarecords) > 0 ) {
            $query = $this->em->createQuery(
               'SELECT dt.id AS dt_id, COUNT(dr.id) AS datarecord_count
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                WHERE dt IN (:datatype_ids) AND drm.publicDate != :public_date AND dr.provisioned = FALSE
                AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL
                GROUP BY dt.id'
            )->setParameters(
                array(
                    'datatype_ids' => $can_view_public_datarecords,
                    'public_date' => '2200-01-01 00:00:00'
                )
            );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $count = $result['datarecord_count'];
                $metadata[$dt_id] = $count;
            }
        }

        return $metadata;
    }
}
