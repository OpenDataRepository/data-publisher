<?php

/**
 * Open Data Repository Data Publisher
 * Datatype Info Service
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


class DatatypeInfoService
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
     * DatatypeInfoService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param TagHelperService $tagHelperService
     * @param string $odr_web_dir
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        TagHelperService $tag_helper_service,
        $odr_web_dir,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
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
        $pattern = '/^[a-z0-9]{7}$/';
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
     * Returns an array of top-level datatype ids.
     *
     * @return int[]
     */
    public function getTopLevelDatatypes()
    {
        // ----------------------------------------
        // If list of top level datatypes exists in cache, return that
        $top_level_datatypes = $this->cache_service->get('top_level_datatypes');
        if ( $top_level_datatypes !== false && count($top_level_datatypes) > 0 )
            return $top_level_datatypes;


        // ----------------------------------------
        // Otherwise, rebuild the list of top-level datatypes
        // TODO - enforce dt.is_master_type = 0  here?
        // TODO - cut out metadata datatypes from this?
        $query = $this->em->createQuery(
           'SELECT dt.id AS datatype_id
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataType AS grandparent WITH dt.grandparent = grandparent
            WHERE dt.setup_step IN (:setup_steps) AND dt.id = grandparent.id
            AND dt.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( array('setup_steps' => DataType::STATE_VIEWABLE) );
        $results = $query->getArrayResult();

        // AND dt.metadataFor IS NULL
        $top_level_datatypes = array();
        foreach ($results as $result)
            $top_level_datatypes[] = $result['datatype_id'];


        // ----------------------------------------
        // Store the list in the cache and return
        $this->cache_service->set('top_level_datatypes', $top_level_datatypes);
        return $top_level_datatypes;
    }


    // TODO - create something to return top-level templates?


    /**
     * Traverses the cached version of the datatree array in order to return the grandparent id
     * of the given datatype id.
     *
     * @param int $initial_datatype_id
     * @param array|null $datatree_array
     *
     * @return int
     */
    public function getGrandparentDatatypeId($initial_datatype_id, $datatree_array = null)
    {
        if ( is_null($datatree_array) )
            $datatree_array = self::getDatatreeArray();

        $grandparent_datatype_id = $initial_datatype_id;
        while (
            isset($datatree_array['descendant_of'][$grandparent_datatype_id])
            && $datatree_array['descendant_of'][$grandparent_datatype_id] !== ''
        ) {
            // This isn't a top-level datatype, so grab it's immediate parent datatype's id
            $grandparent_datatype_id = $datatree_array['descendant_of'][$grandparent_datatype_id];
        }

        return $grandparent_datatype_id;
    }


    /**
     * Utility function to returns the DataTree table in array format
     *
     * @return array
     */
    public function getDatatreeArray()
    {
        // ----------------------------------------
        // If datatree data exists in cache and user isn't demanding a fresh version, return that
        $datatree_array = $this->cache_service->get('cached_datatree_array');
        if ( $datatree_array !== false && count($datatree_array) > 0 )
            return $datatree_array;


        // ----------------------------------------
        // Otherwise...get all the datatree data
        $query = $this->em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id, dtm.is_link AS is_link, dtm.multiple_allowed AS multiple_allowed
            FROM ODRAdminBundle:DataType AS ancestor
            JOIN ODRAdminBundle:DataTree AS dt WITH ancestor = dt.ancestor
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE ancestor.setup_step IN (:setup_step) AND descendant.setup_step IN (:setup_step)
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
            AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( array('setup_step' => DataType::STATE_VIEWABLE) );
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
        $this->cache_service->set('cached_datatree_array', $datatree_array);
        return $datatree_array;
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
                $associated_datatypes = self::getAssociatedDatatypes(array($grandparent_datatype_id));

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
     * This function locates all datatypes whose grandparent id is in $grandparent_datatype_ids,
     * then calls self::getLinkedDatatypes() to locate all datatypes linked to by these datatypes,
     * which calls this function again to locate any datatypes that are linked to by those
     * linked datatypes...
     *
     * The end result is an array of top-level datatype ids.  Due to recursive shennanigans,
     * these functions don't attempt to cache the results.
     *
     * @param int[] $grandparent_datatype_ids
     *
     * @return int[]
     */
    public function getAssociatedDatatypes($grandparent_datatype_ids)
    {
        // TODO - convert to use the datatree array?

        // Locate all datatypes that are children of the datatypes listed in $grandparent_datatype_ids
        $query = $this->em->createQuery(
           'SELECT dt.id AS id
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataType AS grandparent WITH dt.grandparent = grandparent
            WHERE grandparent.id IN (:grandparent_ids)
            AND dt.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( array('grandparent_ids' => $grandparent_datatype_ids) );
        $results = $query->getArrayResult();

        // Flatten the results array
        $datatype_ids = array();
        foreach ($results as $result)
            $datatype_ids[] = $result['id'];

        // Locate all datatypes that are linked to by the datatypes listed in $grandparent_datatype_ids
        $linked_datatype_ids = self::getLinkedDatatypes($datatype_ids);

        // Don't want any duplicate datatype ids...
        $associated_datatype_ids = array_unique( array_merge($grandparent_datatype_ids, $linked_datatype_ids) );

        return $associated_datatype_ids;
    }


    /**
     * Builds and returns a list of all datatypes linked to from the provided datatype ids.
     *
     * @param int[] $ancestor_ids
     *
     * @return int[]
     */
    public function getLinkedDatatypes($ancestor_ids)
    {
        // TODO - convert to use the datatree array?

        // Locate all datatypes that are linked to from any datatype listed in $datatype_ids
        $query = $this->em->createQuery(
           'SELECT descendant.id AS descendant_id
            FROM ODRAdminBundle:DataTree AS dt
            JOIN dt.dataTreeMeta AS dtm
            JOIN dt.ancestor AS ancestor
            JOIN dt.descendant AS descendant
            WHERE ancestor.id IN (:ancestor_ids) AND dtm.is_link = 1
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
            AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( array('ancestor_ids' => $ancestor_ids) );
        $results = $query->getArrayResult();

        // Flatten the results array
        $linked_datatype_ids = array();
        foreach ($results as $result)
            $linked_datatype_ids[] = $result['descendant_id'];

        // If there were datatypes found, get all of their associated child/linked datatypes
        $associated_datatype_ids = array();
        if ( count($linked_datatype_ids) > 0 )
            $associated_datatype_ids = self::getAssociatedDatatypes($linked_datatype_ids);

        // Don't want any duplicate datatype ids...
        $linked_datatype_ids = array_unique( array_merge($linked_datatype_ids, $associated_datatype_ids) );

        return $linked_datatype_ids;
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
        $datatree_array = self::getDatatreeArray();

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
                partial dt.{id}, partial mdt.{id, unique_id}, partial df.{id}, partial mdf.{id}

                FROM ODRAdminBundle:DataType AS dt
                LEFT JOIN dt.masterDataType AS mdt
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
                $mdt_data = array(
                    'id' => $dt['masterDataType']['id'],
                    'unique_id' => $dt['masterDataType']['unique_id']
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

                dt_rp, dt_rpi, dt_rpo, dt_rpm, dt_rpf, dt_rpm_df,

                df, dfm, ft,
                partial df_cb.{id, username, email, firstName, lastName},

                ro, rom, t, tm,
                df_rp, df_rpi, df_rpo, df_rpm

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

            LEFT JOIN dtm.renderPlugin AS dt_rp
            LEFT JOIN dt_rp.renderPluginInstance AS dt_rpi WITH (dt_rpi.dataType = dt)
            LEFT JOIN dt_rpi.renderPluginOptions AS dt_rpo
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

            LEFT JOIN dfm.renderPlugin AS df_rp
            LEFT JOIN df_rp.renderPluginInstance AS df_rpi WITH (df_rpi.dataField = df)
            LEFT JOIN df_rpi.renderPluginOptions AS df_rpo
            LEFT JOIN df_rpi.renderPluginMap AS df_rpm

            WHERE
                dt.grandparent = :grandparent_datatype_id
                AND dt.deletedAt IS NULL
            ORDER BY dt.id, df.id, rom.displayOrder, ro.id'
        )->setParameters(
            array(
                'grandparent_datatype_id' => $grandparent_datatype_id
            )
        );

        $datatype_data = $query->getArrayResult();

        // TODO - if $datatype_data is empty, then $grandparent_datatype_id was deleted...should this return something special in that case?

        // The entity -> entity_metadata relationships have to be one -> many from a database
        // perspective, even though there's only supposed to be a single non-deleted entity_metadata
        // object for each entity.  Therefore, the preceding query generates an array that needs
        // to be somewhat flattened in a few places.
        foreach ($datatype_data as $dt_num => $dt) {
            $dt_id = $dt['id'];

            // Flatten datatype meta
            // TODO Figure out why DTM is sometimes empty
            if(count($dt['dataTypeMeta']) == 0) {
                $dtm = null;
            }
            else {
                $dtm = $dt['dataTypeMeta'][0];
            }
            $datatype_data[$dt_num]['dataTypeMeta'] = $dtm;
            $datatype_data[$dt_num]['masterDataType'] = $derived_dt_data[$dt_id];

            // Scrub irrelevant data from the datatype's createdBy and updatedBy properties
            $datatype_data[$dt_num]['createdBy'] = UserUtility::cleanUserData( $dt['createdBy'] );
            $datatype_data[$dt_num]['updatedBy'] = UserUtility::cleanUserData( $dt['updatedBy'] );


            // ----------------------------------------
            // Organize the datafields by their datafield_id instead of a random number
            $new_datafield_array = array();
            foreach ($dt['dataFields'] as $df_num => $df) {
                $df_id = $df['id'];
                $typeclass = $df['dataFieldMeta'][0]['fieldType']['typeClass'];

                // Flatten datafield_meta and masterDatafield of each datafield
                $dfm = $df['dataFieldMeta'][0];
                $df['dataFieldMeta'] = $dfm;

                // Scrub irrelevant data from the datafield's createdBy property
                $df['createdBy'] = UserUtility::cleanUserData( $df['createdBy'] );

                // Attach the id of this datafield's masterDatafield if it exists
                $df['masterDataField'] = $derived_df_data[$df_id];

                // Flatten radio options if they exist
                // They're ordered by displayOrder, so preserve $ro_num
                foreach ($df['radioOptions'] as $ro_num => $ro) {
                    $rom = $ro['radioOptionMeta'][0];
                    $df['radioOptions'][$ro_num]['radioOptionMeta'] = $rom;
                }
                if ( count($df['radioOptions']) == 0 )
                    unset( $df['radioOptions'] );

                // Flatten tags if they exist
                $tag_list = array();
                foreach ($df['tags'] as $t_num => $t) {
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
        $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
        $datatree_array = self::getDatatreeArray();

        $dt = $datatype;
        while (
            isset($datatree_array['descendant_of'][$dt->getId()])
            && $datatree_array['descendant_of'][$dt->getId()] !== ''
        ) {
            // Mark this (non-top-level) datatype as updated by this user
            $dt->setUpdatedBy($user);
            $dt->setUpdated(new \DateTime());
            $this->em->persist($dt);

            // Continue locating parent datatypes...
            $parent_dt_id = $datatree_array['descendant_of'][$dt->getId()];
            $dt = $repo_datatype->find($parent_dt_id);
        }

        // $dt is now guaranteed to be top-level
        $dt->setUpdatedBy($user);
        $dt->setUpdated(new \DateTime());
        $this->em->persist($dt);

        // Save all changes made
        $this->em->flush();


        // Child datatypes don't have their own cached entries, it's all contained within the
        //  cache entry for their top-level datatype
        $this->cache_service->delete('cached_datatype_'.$dt->getId());
    }


    /**
     * Should be called whenever the default sort order of datarecords within a datatype changes.
     *
     * @param int $datatype_id
     */
    public function resetDatatypeSortOrder($datatype_id)
    {
        // Delete the cached
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
}
