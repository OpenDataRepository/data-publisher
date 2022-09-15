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
use ODR\AdminBundle\Entity\DataRecord;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
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
     * @var string
     */
    private $odr_web_dir;

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
     * @param string $odr_web_dir
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatreeInfoService $datatree_info_service,
        TagHelperService $tag_helper_service,
        $odr_web_dir,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->dti_service = $datatree_info_service;
        $this->th_service = $tag_helper_service;
        $this->odr_web_dir = $odr_web_dir;
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
     * Use self::stackDatatypeArray() to get an array structure where child/linked datatypes
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
            $associated_datatypes = $this->cache_service->get('associated_datatypes_for_'.$grandparent_datatype_id);
            if ($associated_datatypes == false) {
                $associated_datatypes = $this->dti_service->getAssociatedDatatypes($grandparent_datatype_id);

                // Save the list of associated datatypes back into the cache
                $this->cache_service->set('associated_datatypes_for_'.$grandparent_datatype_id, $associated_datatypes);
            }
        }
        else {
            // Don't want any datatypes that are linked to from the given grandparent datatype
            $associated_datatypes[] = $grandparent_datatype_id;
        }

        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
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
        // Assume there's two datafields, a "master" df and another df "derived" from the master,
        //  then delete the "master" datafield.  After that, reload the derived datafield $df...

        // Full hydration will result in  is_null($df->getMasterDatafield()) === false, because
        //  doctrine returns some sort of proxy object for the deleted master datafield
        // However, array hydration in the same situation will say  $df['masterDataField'] === null,
        //  which has a different meaning...so this subquery is required to make array hydration
        //  have the same behavior as full hydration

        // This is primarily needed so template synchronization can be guaranteed to match a derived
        //  datafield with its master datafield...same deal with master datatypes
        $query = $this->em->createQuery(
           'SELECT
                partial dt.{id}, 
                partial mdt.{id, unique_id}, 
                partial mdt_dtm.{id, shortName},
                partial df.{id}, 
                partial mdf.{id}

                FROM ODRAdminBundle:DataType AS dt
                LEFT JOIN dt.masterDataType AS mdt
                LEFT JOIN mdt.dataTypeMeta AS mdt_dtm
                LEFT JOIN dt.dataFields AS df
                LEFT JOIN df.masterDataField AS mdf

                WHERE dt.grandparent = :grandparent_datatype_id'
        )->setParameters( array('grandparent_datatype_id' => $grandparent_datatype_id) );
        // AND dt.deletedAt IS NULL AND df.deletedAt IS NULL'

        // Need to disable the softdeleteable filter so doctrine pulls the id for deleted master
        //  datafield entries
        $this->em->getFilters()->disable('softdeleteable');
        $master_data = $query->getArrayResult();
        $this->em->getFilters()->enable('softdeleteable');

        $derived_dt_data = array();
        $derived_df_data = array();
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


        // ----------------------------------------
        // Get all non-layout data for the requested datatype
        $query = $this->em->createQuery(
           'SELECT
                dt, dtm,
                partial dt_eif.{id}, partial dt_nf.{id}, partial dt_sf.{id}, partial dt_bif.{id},
                partial md.{id, unique_id},
                partial mf.{id, unique_id},
                partial dt_cb.{id, username, email, firstName, lastName},
                partial dt_ub.{id, username, email, firstName, lastName},

                partial dt_rpi.{id}, dt_rpi_rp,
                partial dt_rpom.{id, value}, partial dt_rpo.{id, name},
                partial dt_rpm.{id},
                partial dt_rpf.{id, fieldName, allowedFieldtypes, must_be_unique, single_uploads_only, no_user_edits, autogenerate_values, is_derived},
                dt_rpm_df,

                df, dfm, partial ft.{id, typeClass, typeName},
                partial df_cb.{id, username, email, firstName, lastName},

                ro, rom, t, tm,
                partial df_rpi.{id}, df_rpi_rp,
                partial df_rpom.{id, value}, partial df_rpo.{id, name},
                partial df_rpm.{id},
                partial df_rpf.{id, fieldName, allowedFieldtypes, must_be_unique, single_uploads_only, no_user_edits, autogenerate_values, is_derived}

            FROM ODRAdminBundle:DataType AS dt
            LEFT JOIN dt.createdBy AS dt_cb
            LEFT JOIN dt.updatedBy AS dt_ub
            LEFT JOIN dt.metadata_datatype AS md
            LEFT JOIN dt.metadata_for AS mf

            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dtm.externalIdField AS dt_eif
            LEFT JOIN dtm.nameField AS dt_nf
            LEFT JOIN dtm.sortField AS dt_sf
            LEFT JOIN dtm.backgroundImageField AS dt_bif

            LEFT JOIN dt.renderPluginInstances AS dt_rpi
            LEFT JOIN dt_rpi.renderPlugin AS dt_rpi_rp
            LEFT JOIN dt_rpi.renderPluginOptionsMap AS dt_rpom
            LEFT JOIN dt_rpom.renderPluginOptionsDef AS dt_rpo
            LEFT JOIN dt_rpi.renderPluginMap AS dt_rpm
            LEFT JOIN dt_rpm.renderPluginFields AS dt_rpf
            LEFT JOIN dt_rpm.dataField AS dt_rpm_df

            LEFT JOIN dt.dataFields AS df
            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN df.createdBy AS df_cb
            LEFT JOIN dfm.fieldType AS ft

            LEFT JOIN df.radioOptions AS ro
            LEFT JOIN ro.radioOptionMeta AS rom

            LEFT JOIN df.tags AS t
            LEFT JOIN t.tagMeta AS tm

            LEFT JOIN df.renderPluginInstances AS df_rpi
            LEFT JOIN df_rpi.renderPlugin AS df_rpi_rp
            LEFT JOIN df_rpi.renderPluginOptionsMap AS df_rpom
            LEFT JOIN df_rpom.renderPluginOptionsDef AS df_rpo
            LEFT JOIN df_rpi.renderPluginMap AS df_rpm
            LEFT JOIN df_rpm.renderPluginFields AS df_rpf

            WHERE
                dt.grandparent = :grandparent_datatype_id
                AND dt.deletedAt IS NULL
            ORDER BY dt.id, df.id, rom.displayOrder, ro.id'
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

            // Flatten the renderPluginFields and renderPluginOptions sections of the render plugin
            //  data, if it exists
            if ( !empty($datatype_data[$dt_num]['renderPluginInstances']) )
                $datatype_data[$dt_num]['renderPluginInstances'] = self::flattenRenderPlugin($datatype_data[$dt_num]['renderPluginInstances']);


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

                // Flatten the renderPluginFields and renderPluginOptions sections of the render
                //  plugin data, if it exists
                if ( !empty($df['renderPluginInstances']) )
                    $df['renderPluginInstances'] = self::flattenRenderPlugin($df['renderPluginInstances']);

                // Attach the id of this datafield's masterDatafield if it exists
                $df['masterDataField'] = $derived_df_data[$df_id];

                // Flatten radio options if they exist
                // They're ordered by displayOrder, so preserve $ro_num
                foreach ($df['radioOptions'] as $ro_num => $ro) {
                    if ( count($ro['radioOptionMeta']) == 0 ) {
                        // ...throwing an exception here because this shouldn't ever happen, and
                        //  also requires manual intervention to fix...
                        throw new ODRException('Unable to rebuild the cached_datatype_'.$dt_id.' array because of a database error for radio option '.$ro['id']);
                    }

                    $rom = $ro['radioOptionMeta'][0];
                    $df['radioOptions'][$ro_num]['radioOptionMeta'] = $rom;
                }
                if ( count($df['radioOptions']) == 0 )
                    unset( $df['radioOptions'] );

                // Flatten tags if they exist
                $tag_list = array();
                foreach ($df['tags'] as $t_num => $t) {
                    if ( count($t['tagMeta']) == 0 ) {
                        // ...throwing an exception here because this shouldn't ever happen, and
                        //  also requires manual intervention to fix...
                        throw new ODRException('Unable to rebuild the cached_datatype_'.$dt_id.' array because of a database error for tag '.$t['id']);
                    }

                    $tag_id = $t['id'];
                    $tag_list[$tag_id] = $t;
                    $tag_list[$tag_id]['tagMeta'] = $t['tagMeta'][0];
                }
                if ($typeclass !== 'Tag') {
                    unset( $df['tags'] );
                }
                else if ( count($tag_list) == 0 ) {
                    // No tags, ensure blank arrays exist
                    $df['tags'] = array();
                    $df['tagTree'] = array();
                }
                else {
                    // Tags exist, attempt to locate any tag hierarchy data
                    $tag_tree = array();
                    if ( isset($tag_hierarchy[$dt_id]) && isset($tag_hierarchy[$dt_id][$df_id]) )
                        $tag_tree = $tag_hierarchy[$dt_id][$df_id];

                    // Stack/order the tags before saving them in the array
                    $tag_list = $this->th_service->stackTagArray($tag_list, $tag_tree);
                    $this->th_service->orderStackedTagArray($tag_list);

                    // Also save the tag hierarchy in here for convenience
                    $df['tags'] = $tag_list;
                    $df['tagTree'] = $tag_tree;
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
     * The renderPluginFields and renderPluginOptions sections of the datatype array have their
     * labels at a deeper level of the array because they're loaded via the renderPluginInstance...
     * this is kind of "backwards", and these sections of the array are easier to understand and
     * use after some modifications.
     *
     * @param array $data
     *
     * @return array
     */
    private function flattenRenderPlugin($data)
    {
        // Easier to modify a copy of the original array
        $render_plugin_instances = $data;

        // The default render plugin won't have an instance
        foreach ($render_plugin_instances as $rpi_num => $rpi) {
            // Don't need to do anything with the render plugin entry
            // All plugins will have an entry for required fields, although it might be empty

            foreach ($rpi['renderPluginMap'] as $rpm_num => $rpm) {
                // ...then each renderPluginMap will have a single renderPluginField entry...
                $rpf = $rpm['renderPluginFields'];
                $rpf_fieldName = $rpf['fieldName'];
                $rpf_allowedFieldtypes = $rpf['allowedFieldtypes'];

                // ...and will have a single dataField entry if it's a datatype plugin (but won't
                //  if it's a datafield plugin)
                $rpf_df = array();
                if ( isset($rpm['dataField']) )
                    $rpf_df = $rpm['dataField'];

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

                // ...so the label of the renderPluginField can just point to the datafield that's
                //  fulfilling the role defined by the rendrPluginField
                $render_plugin_instances[$rpi_num]['renderPluginMap'][$rpf_fieldName] = $rpf_df;

                // Don't want the old array structure
                unset( $render_plugin_instances[$rpi_num]['renderPluginMap'][$rpm_num] );
            }

            // All plugins will have an entry for required options, although it might be empty
            $tmp_rpo = array();
            foreach ($rpi['renderPluginOptionsMap'] as $rpom_num => $rpom) {
                // ...then each RenderPluginOptionsMap will have a single renderPluginOptionsDef entry
                $rpom_value = $rpom['value'];
                $rpo_name = $rpom['renderPluginOptionsDef']['name'];

                // ...so the renderPluginOption name can just point to the renderPluginOption value
                $render_plugin_instances[$rpi_num]['renderPluginOptionsMap'][$rpo_name] = $rpom_value;

                // Don't want the old array structure
                unset( $render_plugin_instances[$rpi_num]['renderPluginOptionsMap'][$rpom_num] );
            }
        }

        // Done cleaning the render plugin data
        return $render_plugin_instances;
    }


    /**
     * "Inflates" the normally flattened $datatype_array...
     *
     * @param array $datatype_array
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
     * Marks the specified datatype (and all its parents) as updated by the given user.
     *
     * @param DataType $datatype
     * @param ODRUser $user
     */
    public function updateDatatypeCacheEntry($datatype, $user)
    {
        // Whenever an edit is made to a datatype, each of its parents (if it has any) also need
        //  to be marked as updated
        while ( $datatype->getId() !== $datatype->getParent()->getId() ) {
            // Mark this (non-top-level) datatype as updated by this user
            $datatype->setUpdatedBy($user);
            $datatype->setUpdated(new \DateTime());
            $this->em->persist($datatype);

            // Continue locating parent datatypes...
            $datatype = $datatype->getParent();
        }

        // $datatype is now guaranteed to be top-level
        $datatype->setUpdatedBy($user);
        $datatype->setUpdated(new \DateTime());
        $this->em->persist($datatype);

        // Save all changes made
        $this->em->flush();


        // Child datatypes don't have their own cached entries, it's all contained within the
        //  cache entry for their top-level datatype
        $this->cache_service->delete('cached_datatype_'.$datatype->getId());

        // Need to clear cached records related to this type for the API...
        $records = $this->em->getRepository('ODRAdminBundle:DataRecord')->findBy(array('dataType' => $datatype->getId()));
        /** @var DataRecord $record */
        foreach($records as $record) {
            $this->cache_service->delete('json_record_'.$record->getUniqueId());
        }
    }


    /**
     * Because ODR permits an arbitrarily deep hierarchy when it comes to linking datatypes...
     * e.g.  A links to B links to C links to D links to...etc
     * ...the cache entry 'associated_datatypes_for_<A>' will then mention (B, C, D, etc.), because
     *  they all need to be loaded via getDatatypeData() in order to properly render A.
     *
     * However, this means that linking/unlinking of datatypes between B/C, C/D, D/etc also affects
     * which datatypes A needs to load...so any linking/unlinking needs to be propagated upwards...
     *
     * TODO - ...create a new CacheClearService and move every single cache clearing function into there instead?
     * TODO - ...or should this be off in the DatatreeInfoService?
     *
     * @param array $datatype_ids dt_ids are values in the array, NOT keys
     */
    public function deleteCachedDatatypeLinkData($datatype_ids)
    {
        // Locate all datatypes that end up needing to load cache entries for the datatypes in
        //  $datatype_ids...
        $datatree_array = $this->dti_service->getDatatreeArray();
        $all_linked_ancestors = $this->dti_service->getLinkedAncestors($datatype_ids, $datatree_array, true);

        // Ensure the datatype that were originally passed in get the cache entry cleared
        foreach ($datatype_ids as $num => $dt_id)
            $all_linked_ancestors[] = $dt_id;

        // Clearing this cache entry for each of the ancestor datatypes found ensures that the
        //  newly linked/unlinked datarecords show up (or not) when they should
        foreach ($all_linked_ancestors as $num => $dt_id)
            $this->cache_service->delete('associated_datatypes_for_'.$dt_id);
    }


    /**
     * TODO - shouldn't this technically be in SortService?
     * Should be called whenever the default sort order of datarecords within a datatype changes.
     *
     * @param int $datatype_id
     */
    public function resetDatatypeSortOrder($datatype_id)
    {
        // Delete the cached default ordering of records in this datatype
        $this->cache_service->delete('datatype_'.$datatype_id.'_record_order');

        // DisplaytemplateController::datatypepropertiesAction() currently handles deleting of cached
        //  datarecord entries when the sort datafield is changed...


        // TODO - this doesn't feel like it belongs here...but putting it in the GraphPluginInterface also doesn't quite make sense...
        // Also, delete any pre-rendered graph images for this datatype so they'll be rebuilt with
        //  the legend order matching the new datarecord order
        $graph_filepath = $this->odr_web_dir.'/uploads/files/graphs/datatype_'.$datatype_id.'/';
        if ( file_exists($graph_filepath) ) {
            $files = scandir($graph_filepath);
            foreach ($files as $filename) {
                // TODO - assumes linux?
                if ($filename === '.' || $filename === '..')
                    continue;

                unlink($graph_filepath.'/'.$filename);
            }
        }
    }


    /**
     * Returns an array with how many datarecords the user is allowed to see for each datatype in
     * $datatype_ids
     *
     * @param int[] $datatype_ids
     * @param array $datatype_permissions
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
