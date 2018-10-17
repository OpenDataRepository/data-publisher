<?php

/**
 * Open Data Repository Data Publisher
 * Datarecord Info Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get and rebuild the cached version of the datatype array, as
 * well as several other utility functions related to lists of datatypes.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
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
use ODR\AdminBundle\Component\Utility\UniqueUtility;
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
     * @param string $odr_web_dir
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        $odr_web_dir,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->odr_web_dir = $odr_web_dir;
        $this->logger = $logger;
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
/*
        $timing = true;
        $timing = false;

        $t0 = $t1 = $t2 = null;
        if ($timing)
            $t0 = microtime(true);
*/
        // This function is only called when the cache entry doesn't exist

        // Going to need the datatree array to rebuild this
        $datatree_array = self::getDatatreeArray();

        // Get all non-layout data for the requested datatype
        $query = $this->em->createQuery(
           'SELECT
                dt, dtm, 
                partial md.{id},
                partial mf.{id},
                partial dt_cb.{id, username, email, firstName, lastName},
                partial dt_ub.{id, username, email, firstName, lastName},

                dt_rp, dt_rpi, dt_rpo, dt_rpm, dt_rpf, dt_rpm_df,

                df, dfm, ft,
                partial df_cb.{id, username, email, firstName, lastName},

                ro, rom,
                df_rp, df_rpi, df_rpo, df_rpm

            FROM ODRAdminBundle:DataType AS dt
            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dt.createdBy AS dt_cb
            LEFT JOIN dt.updatedBy AS dt_ub
            LEFT JOIN dt.metadata_datatype AS md
            LEFT JOIN dt.metadata_for AS mf

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
/*
        if ($timing) {
            $t1 = microtime(true);
            $diff = $t1 - $t0;
            print 'buildDatatypeData('.$datatype_id.')'."\n".'query execution in: '.$diff."\n";
        }
*/
        // The entity -> entity_metadata relationships have to be one -> many from a database
        // perspective, even though there's only supposed to be a single non-deleted entity_metadata
        // object for each entity.  Therefore, the preceding query generates an array that needs
        // to be somewhat flattened in a few places.
        foreach ($datatype_data as $dt_num => $dt) {
            $dt_id = $dt['id'];

            // Flatten datatype meta
            $dtm = $dt['dataTypeMeta'][0];
            $datatype_data[$dt_num]['dataTypeMeta'] = $dtm;

            // Scrub irrelevant data from the datatype's createdBy and updatedBy properties
            $datatype_data[$dt_num]['createdBy'] = UserUtility::cleanUserData( $dt['createdBy'] );
            $datatype_data[$dt_num]['updatedBy'] = UserUtility::cleanUserData( $dt['updatedBy'] );


            // ----------------------------------------
            // Organize the datafields by their datafield_id instead of a random number
            $new_datafield_array = array();
            foreach ($dt['dataFields'] as $df_num => $df) {
                $df_id = $df['id'];

                // Flatten datafield_meta of each datafield
                $dfm = $df['dataFieldMeta'][0];
                $df['dataFieldMeta'] = $dfm;

                // Scrub irrelevant data from the datafield's createdBy property
                $df['createdBy'] = UserUtility::cleanUserData( $df['createdBy'] );

                // Flatten radio options if they exist
                // They're ordered by displayOrder, so preserve $ro_num
                foreach ($df['radioOptions'] as $ro_num => $ro) {
                    $rom = $ro['radioOptionMeta'][0];
                    $df['radioOptions'][$ro_num]['radioOptionMeta'] = $rom;
                }
                if ( count($df['radioOptions']) == 0 )
                    unset( $df['radioOptions'] );

                $new_datafield_array[$df_id] = $df;
            }

            unset( $datatype_data[$dt_num]['dataFields'] );
            $datatype_data[$dt_num]['dataFields'] = $new_datafield_array;


            // ----------------------------------------
            // Build up a list of child/linked datatypes and their basic information
            // I don't think the 'is_link' and 'multiple_allowed' properties are used, but meh
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


        // Organize by datatype id
        $formatted_datatype_data = array();
        foreach ($datatype_data as $num => $dt_data) {
            $dt_id = $dt_data['id'];

            $formatted_datatype_data[$dt_id] = $dt_data;
        }

/*
        if ($timing) {
            $t1 = microtime(true);
            $diff = $t2 - $t1;
            print 'buildDatatypeData('.$datatype_id.')'."\n".'array formatted in: '.$diff."\n";
        }
*/

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
     * Returns an array of sorted datarecord ids for the given datatype, optionally filtered to only
     * include ids that are in a comma-separated list of datarecord ids.
     *
     * If the datatype has a sort datafield set, then the contents of that datafield are used to
     * sort in ascending order.  Otherwise, the list is sorted by datarecord ids.
     *
     * @param integer $datatype_id
     * @param null|string $subset_str   If specified, the returned string will only contain datarecord ids from $subset_str
     *
     * @return array An ordered list of datarecord_id => sort_value
     */
    public function getSortedDatarecordList($datatype_id, $subset_str = null)
    {
        // Attempt to grab the sorted list of datarecords for this datatype from the cache
        $datarecord_list = $this->cache_service->get('datatype_'.$datatype_id.'_record_order');
        if ( $datarecord_list == false || count($datarecord_list) == 0 ) {
            // Going to need the datatype's sorting datafield, if it exists
            $datarecord_list = array();

            /** @var DataType $datatype */
            $datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            $sortfield = $datatype->getSortField();

            if ($sortfield == null) {
                // Need a list of all datarecords for this datatype
                $query = $this->em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :datatype AND dr.provisioned = false
                    AND dr.deletedAt IS NULL
                    ORDER BY dr.id'
                )->setParameters( array('datatype' => $datatype_id) );
                $results = $query->getArrayResult();

                // The datatype doesn't have a sortfield, so going to order by datarecord id
                foreach ($results as $num => $dr) {
                    $dr_id = $dr['dr_id'];
                    $datarecord_list[$dr_id] = $dr_id;
                }

                // Don't need a natural sort because the ids are guaranteed to just be numeric
                asort($datarecord_list);
            }
            else {
                // Want to store all datarecords, not just a subset if it was passed in
                $datarecord_list = self::sortDatarecordsByDatafield($sortfield->getId());
            }

            // Store the sorted datarecord list back in the cache
            $this->cache_service->set('datatype_'.$datatype_id.'_record_order', $datarecord_list);
        }


        if ( is_null($subset_str) ) {
            // User just wanted the entire list of sorted datarecords
            return $datarecord_list;
        }
        else if ($subset_str == '') {
            // User requested a sorted list but didn't specify any datarecords...return an empty array
            return array();
        }
        else {
            // User specified they only wanted a subset of datarecords sorted...
            $dr_subset = explode(',', $subset_str);

            // array_flip() + isset() is orders of magnitude faster than in_array()...
            $dr_subset = array_flip($dr_subset);
            foreach ($datarecord_list as $dr_id => $sort_value) {
                // ...then only save the datarecord id if it's in the specified subset
                if ( !isset($dr_subset[$dr_id]) )
                    unset( $datarecord_list[$dr_id] );
            }

            // Return the filtered array of sorted datarecords
            return $datarecord_list;
        }
    }


    /**
     * Uses the values stored in the given datafield to sort all datarecords of that datafield's
     * datatype.
     *
     * @param int $datafield_id
     * @param bool $sort_ascending
     * @param null|string $subset_str If specified, the returned array will only contain datarecord ids from $subset_str
     *
     * @throws ODRException
     *
     * @return array An ordered list of datarecord_id => sort_value
     */
    public function sortDatarecordsByDatafield($datafield_id, $sort_ascending = true, $subset_str = null)
    {
        /** @var DataFields $datafield */
        $datafield = $this->em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
        if ($datafield == null)
            throw new ODRNotFoundException('Datafield', false, 0x55059289);

        $datatype = $datafield->getDataType();
        if ( !is_null($datatype->getDeletedAt()) )
            throw new ODRNotFoundException('Datatype', false, 0x55059289);

        // Doesn't make sense to sort some fieldtypes
        $typename = $datafield->getFieldType()->getTypeName();
        switch ($typename) {
            // Can sort these by value
            case 'Boolean':
            case 'Integer':
            case 'Decimal':
            case 'Short Text':
            case 'Medium Text':
            case 'Long Text':
            case 'Paragraph Text':
            case 'DateTime':
                break;
            // Can sort these by which radio option is currently selected
            case 'Single Radio':
            case 'Single Select':
                break;

            // Can sort these by filename if the only permit a single upload...doesn't make sense
            //  if there's more than one file/image uploaded to the datafield
            case 'File':
            case 'Image':
                // TODO - implementing this would require the theme system to block multiple-allowed files/images from being put in table themes...
//                if ($datafield->getAllowMultipleUploads())
//                    throw new ODRBadRequestException('Unable to sort a "'.$typename.'" that allows multiple uploads', 0x55059289);
                break;

            case 'Multiple Radio':
            case 'Multiple Select':
            case 'Markdown':
                throw new ODRBadRequestException('Unable to sort a "'.$typename.'" datafield', 0x55059289);
        }


        // ----------------------------------------
        // Check whether this list is already cached or not...
        $sorted_datarecord_list = $this->cache_service->get('cached_search_df_'.$datafield_id.'_ordering');
        if ( !$sorted_datarecord_list )
            $sorted_datarecord_list = array();

        // TODO - only store the ascending order, then array_reverse() if descending is wanted?
        $key = 'ASC';
        if (!$sort_ascending)
            $key = 'DESC';


        // ----------------------------------------
        $datarecord_list = array();
        if ( !isset($sorted_datarecord_list[$key]) ) {
            // The requested list isn't in the cache...need to rebuild it

            // Need a list of all datarecords for this datatype
            $query = $this->em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :datatype AND dr.provisioned = false
                AND dr.deletedAt IS NULL
                ORDER BY dr.id'
            )->setParameters(array('datatype' => $datatype->getId()));
            $results = $query->getArrayResult();

            // Due to design decisions, ODR isn't guaranteed to have datarecordfield and/or storage
            //  entity entries for every datafield.  If either of those entries is missing, the
            //  upcoming query WILL NOT have an entry for that datarecord in its result set
            foreach ($results as $num => $dr) {
                $dr_id = $dr['dr_id'];
                $datarecord_list[$dr_id] = '';
            }

            // Locate this datafield's value for each datarecord of this datatype
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass == 'File' || $typeclass == 'Image') {
                // Get the list of file names...have to left join the file table because datarecord
                //  id is required, but there may not always be a file uploaded
                $query = $this->em->createQuery(
                   'SELECT em.originalFileName AS file_name, dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                    LEFT JOIN ODRAdminBundle:'.$typeclass.' AS e WITH e.dataRecordFields = drf
                    LEFT JOIN ODRAdminBundle:'.$typeclass.'Meta AS em WITH em.'.strtolower($typeclass).' = e
                    WHERE dr.dataType = :datatype AND drf.dataField = :datafield
                    AND e.deletedAt IS NULL AND em.deletedAt IS NULL AND drf.deletedAt IS NULL
                    AND dr.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'datatype' => $datatype->getId(),
                        'datafield' => $datafield->getId(),
                    )
                );
                $results = $query->getArrayResult();

                // Store the value of the datafield for each datarecord
                foreach ($results as $num => $result) {
                    $dr_id = $result['dr_id'];
                    $filename = $result['file_name'];

                    $datarecord_list[$dr_id] = $filename;
                }
            }
            else if ($typeclass == 'Radio') {
                $query = $this->em->createQuery(
                   'SELECT rom.optionName AS option_name, dr.id AS dr_id
                    FROM ODRAdminBundle:RadioOptions AS ro
                    JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOption = ro
                    JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.radioOption = ro
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH rs.dataRecordFields = drf
                    JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                    WHERE dr.dataType = :datatype AND drf.dataField = :datafield AND rs.selected = 1
                    AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL AND rs.deletedAt IS NULL
                    AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'datatype' => $datatype->getId(),
                        'datafield' => $datafield->getId()
                    )
                );
                $results = $query->getArrayResult();

                // Store the value of the datafield for each datarecord
                foreach ($results as $num => $result) {
                    $option_name = $result['option_name'];
                    $dr_id = $result['dr_id'];

                    $datarecord_list[$dr_id] = $option_name;
                }
            }
            else {
                // All other sortable fieldtypes have a value field that should be used
                $query = $this->em->createQuery(
                   'SELECT dr.id AS dr_id, e.value AS sort_value
                    FROM ODRAdminBundle:DataRecord AS dr
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                    JOIN ODRAdminBundle:'.$typeclass.' AS e WITH e.dataRecordFields = drf
                    WHERE dr.dataType = :datatype AND e.dataField = :datafield
                    AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'datatype' => $datatype->getId(),
                        'datafield' => $datafield->getId()
                    )
                );
                $results = $query->getArrayResult();

                // Store the value of the datafield for each datarecord
                foreach ($results as $num => $result) {
                    $value = $result['sort_value'];
                    $dr_id = $result['dr_id'];

                    if ($typeclass == 'IntegerValue') {
                        $value = intval($value);
                    }
                    else if ($typeclass == 'DecimalValue') {
                        $value = floatval($value);
                    }
                    else if ($typeclass == 'DatetimeValue') {
                        $value = $value->format('Y-m-d');
                        if ($value == '9999-12-31')
                            $value = '';
                    }

                    $datarecord_list[$dr_id] = $value;
                }
            }

            // Natural sort works in most cases...
            $flag = SORT_NATURAL;
            if ($typeclass == 'IntegerValue' || $typeclass == 'DecimalValue')
                $flag = SORT_NUMERIC;   // ...but not for these two typeclasses

            if ($sort_ascending)
                asort($datarecord_list, $flag);
            else
                arsort($datarecord_list, $flag);


            // Store the result back in the cache
            $sorted_datarecord_list[$key] = $datarecord_list;
            $this->cache_service->set('cached_search_df_'.$datafield_id.'_ordering', $sorted_datarecord_list);
        }
        else {
            // Otherwise, the list for this request was in the cache
            $datarecord_list = $sorted_datarecord_list[$key];
        }


        // ----------------------------------------
        // Now that we have the correct list of sorted datarecords...
        if ( is_null($subset_str) ) {
            // User just wanted the entire list of sorted datarecords
            return $datarecord_list;
        }
        else if ($subset_str == '') {
            // User requested a sorted list but didn't specify any datarecords...return an empty array
            return array();
        }
        else {
            // User specified they only wanted a subset of datarecords sorted...
            $dr_subset = explode(',', $subset_str);
            // array_flip() + isset() is orders of magnitude faster than in_array() on larger arrays
            $dr_subset = array_flip($dr_subset);

            foreach ($datarecord_list as $dr_id => $sort_value) {
                // ...then only save the datarecord id if it's in the specified subset
                if ( !isset($dr_subset[$dr_id]) )
                    unset( $datarecord_list[$dr_id] );
            }

            // Return the filtered array of sorted datarecords
            return $datarecord_list;
        }
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
     * Generates and returns a unique_id string that doesn't collide with any other datatype's
     * "unique_id" property.  Shouldn't be used for the datatype's "template_group" property, as
     * those should be based off of the grandparent datatype's "unique_id".
     *
     * @return string
     */
    public function generateDatatypeUniqueId()
    {
        // Need to get all current ids in use in order to determine uniqueness of a new id...
        $query = $this->em->createQuery(
           'SELECT dt.unique_id
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $existing_ids = array();
        foreach ($results as $num => $result)
            $existing_ids[ $result['unique_id'] ] = 1;


        // Keep generating ids until one that's not in
        $unique_id = UniqueUtility::uniqueIdReal();
        while ( isset($existing_ids[$unique_id]) )
            $unique_id = UniqueUtility::uniqueIdReal();

        return $unique_id;
    }
}
