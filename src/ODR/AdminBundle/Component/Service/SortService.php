<?php

/**
 * Open Data Repository Data Publisher
 * Sort Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get and rebuild the info required to sort lists of datarecords.
 *
 * Also contains the functions to re-sort the contents of a Radio Option or Tag datafield by
 * option/tag name, and save the results back to the database.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\Tags;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\OpenRepository\GraphBundle\Plugins\SortOverrideInterface;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;


class SortService
{

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var EntityMetaModifyService
     */
    private $entity_modify_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * SortService constructor.
     *
     * @param ContainerInterface $container
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param SearchService $search_service
     * @param Logger $logger
     */
    public function __construct(
        ContainerInterface $container,
        EntityManager $entity_manager,
        CacheService $cache_service,
        EntityMetaModifyService $entity_meta_modify_service,
        SearchService $search_service,
        Logger $logger
    ) {
        $this->container = $container;
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->entity_modify_service = $entity_meta_modify_service;
        $this->search_service = $search_service;
        $this->logger = $logger;
    }


    /**
     * All of these subsequent sorting functions need the ability to only return a desired subset of
     * datarecords from the entire sorted list.
     *
     * @param array $datarecord_list An array where the datarecord_ids are keys
     * TODO - why is this a string and not an array?  Seems like this adds conversion from array to string to array.
     * @param null|string $subset_str
     *
     * @return array
     */
    private function applySubsetFilter($datarecord_list, $subset_str)
    {
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
     * Returns an array of sorted datarecord ids for the given datatype, optionally filtered to only
     * include ids that are in a comma-separated list of datarecord ids.
     *
     * Effectively identical to {@see self::getSortedDatarecordList()}, except it operates with the
     * datatype's name fields instead of its sort fields.
     *
     * @param integer $datatype_id    The id of the datatype being named
     * @param null|string $subset_str If specified, the returned string will only contain datarecord ids from $subset_str
     *
     * @return array An ordered list of datarecord_id => name_value
     */
    public function getNamedDatarecordList($datatype_id, $subset_str = null)
    {
        $exception_code = 0x278dfcc6;

        /** @var DataType $datatype */
        $datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        if ($datatype == null)
            throw new ODRNotFoundException('Datatype', false, $exception_code);

        // Templates shouldn't need to call this, but it doesn't break anything if they do
//        if ($datatype->getIsMasterType())
//            throw new ODRBadRequestException('getSortedDatarecordList() called on a master template', $exception_code);

        // Not sure if this is going to be called often enough to justify caching, but might as well
        $datarecord_list = $this->cache_service->get('datatype_'.$datatype_id.'_record_names');
        if ( $datarecord_list == false || count($datarecord_list) == 0 ) {
            // Going to need the datatype's name fields, if any exist
            $namefields = $datatype->getNameFields();

            // Get a sorted datarecord list based off the datatype's name fields
            $datarecord_list = self::getDatarecordList($datatype, $namefields);

            // Store the sorted datarecord list back in the cache
            $this->cache_service->set('datatype_'.$datatype_id.'_record_names', $datarecord_list);
        }

        // Now that we have the correct list of sorted datarecords...
        return self::applySubsetFilter($datarecord_list, $subset_str);
    }


    /**
     * Returns an array of sorted datarecord ids for the given datatype, optionally filtered to only
     * include ids that are in a comma-separated list of datarecord ids.
     *
     * If the datatype has been configured to use at least one datafield for sorting, then the
     * contents of those datafields are used to sort in ascending order.  Otherwise, the list is
     * sorted by datarecord ids.
     *
     * TODO - special handling for sorting child datatypes?  not sure it's strictly necessary...
     *
     * @param integer $datatype_id    The id of the datatype being sorted
     * @param null|string $subset_str If specified, the returned string will only contain datarecord ids from $subset_str
     *
     * @return array An ordered list of datarecord_id => sort_value
     */
    public function getSortedDatarecordList($datatype_id, $subset_str = null)
    {
        $exception_code = 0xc83ac445;

        /** @var DataType $datatype */
        $datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        if ($datatype == null)
            throw new ODRNotFoundException('Datatype', false, $exception_code);

        // Templates shouldn't need to call this, but it doesn't break anything if they do
//        if ($datatype->getIsMasterType())
//            throw new ODRBadRequestException('getSortedDatarecordList() called on a master template', $exception_code);

        // Attempt to grab the sorted list of datarecords for this datatype from the cache
        $datarecord_list = $this->cache_service->get('datatype_'.$datatype_id.'_record_order');
        if ( $datarecord_list == false || count($datarecord_list) == 0 ) {
            // Going to need the datatype's sort fields, if any exist
            $sortfields = $datatype->getSortFields();

            // Get a sorted datarecord list based off the datatype's sort fields
            $datarecord_list = self::getDatarecordList($datatype, $sortfields);

            // Store the sorted datarecord list back in the cache
            $this->cache_service->set('datatype_'.$datatype_id.'_record_order', $datarecord_list);
        }

        // Now that we have the correct list of sorted datarecords...
        return self::applySubsetFilter($datarecord_list, $subset_str);
    }


    /**
     * As far as ODR is concerned, there's not a fundamental difference between a "name field" and
     * a "sort field"...they're both text/number fields, just used in different places for different
     * reasons.
     *
     * Since these special fields share the same fieldtypes, getting the lists can technically be
     * performed the exact same way.  Actually sorting the results for a name field is something of
     * a wasted effort, but I'm not going to willingly implement a different function to optimize it.
     *
     * @param DataType $datatype
     * @param DataFields[] $fields
     *
     * @return array An ordered list of datarecord_id => value
     */
    private function getDatarecordList($datatype, $fields)
    {
        // NOTE - These fields ARE NOT GUARANTEED to belong to $datatype...it's possible that
        //  $datatype is referring to fields from one of its linked descendants
        $datarecord_list = array();

        if ( empty($fields) ) {
            // Need a list of all datarecords for this datatype
            $query = $this->em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :datatype AND dr.provisioned = false
                AND dr.deletedAt IS NULL
                ORDER BY dr.id'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $results = $query->getArrayResult();

            // The datatype doesn't have one of whatever special field, so have to use record ids
            foreach ($results as $num => $dr) {
                $dr_id = $dr['dr_id'];
                $datarecord_list[$dr_id] = $dr_id;
            }

            // Don't need a natural sort because the ids are guaranteed to just be numeric
            asort($datarecord_list);
        }
        else if ( count($fields) === 1 ) {
            // If the datatype only has one of this special field, then don't need the multisort
            $field = $fields[0];
            if ( $field->getDataType()->getId() === $datatype->getId() )
                $datarecord_list = self::sortDatarecordsByDatafield( $field->getId() );
            else
                $datarecord_list = self::sortDatarecordsByLinkedDatafield( $datatype->getId(), $field->getId() );
        }
        else {
            // If the datatype uses more than one of these fields, then need to multisort

            // While technically sorting ascending don't need a multisort, there are other places
            //  in ODR that need to sort in multiple directions...so this is kept similar
            $sort_datafields = array();
            $sort_directions = array();
            $linked_datafields = array();
            $numeric_datafields = array();
            foreach ($fields as $display_order => $df) {
                $sort_datafields[$display_order] = $df->getId();
                $sort_directions[$display_order] = 'asc';

                // It's easier to determine whether this is a linked field or not here instead
                //  of inside the multisort function
                if ( $df->getDataType()->getId() === $datatype->getId() )
                    $linked_datafields[$display_order] = false;
                else
                    $linked_datafields[$display_order] = true;

                // Same deal with whether the datafield is an integer/decimal field or not
                $typeclass = $df->getFieldType()->getTypeClass();
                if ( $typeclass === 'IntegerValue' || $typeclass === 'DecimalValue' )
                    $numeric_datafields[$display_order] = true;
                else
                    $numeric_datafields[$display_order] = false;
            }

            $datarecord_list = self::multisortDatarecordList($datatype->getId(), $sort_datafields, $sort_directions, $linked_datafields, $numeric_datafields);
        }

        // Not filtering the list here, since it's probably going to get cached
        return $datarecord_list;
    }


    /**
     * Returns an array of sorted datarecord ids for the given datatype, optionally filtered to only
     * include ids that are in a comma-separated list of datarecord ids.
     *
     * @param int $datatype_id           The id of the datatype being sorted
     * @param int[] $sort_datafields     The ids of the datafields used to sort the datatype
     * @param string[] $sort_directions  For each of the sort fields, 'asc' or 'desc' to specify the sort direction
     * @param bool[] $linked_datafields  For each of the sort fields, true/false to indicate whether it belongs to the given $datatype_id
     * @param bool[] $numeric_datafields For each of the sort fields, true/false to indicate whether the field should use SORT_NUMERIC or not
     * @param string|null $subset_str    If specified, the returned string will only contain datarecord ids from $subset_str
     *
     * @return array An ordered list of datarecord_id => sort_value
     */
    public function multisortDatarecordList($datatype_id, $sort_datafields, $sort_directions, $linked_datafields, $numeric_datafields, $subset_str = null)
    {
        // ----------------------------------------
        $exception_code = 0xc9c204c3;

        // Need all four arrays to have the same number of elements...
        if ( count($sort_datafields) !== count($sort_directions) )
            throw new ODRBadRequestException('SortService::multisortDatarecordList() number of $sort_datafields does not match number of $sort_directions', $exception_code);
        if ( count($sort_datafields) !== count($linked_datafields) )
            throw new ODRBadRequestException('SortService::multisortDatarecordList() number of $sort_datafields does not match number of $linked_datafields', $exception_code);
        if ( count($sort_datafields) !== count($numeric_datafields) )
            throw new ODRBadRequestException('SortService::multisortDatarecordList() number of $sort_datafields does not match number of $numeric_datafields', $exception_code);

        // ...and to have the same keys
        foreach ($sort_datafields as $display_order => $df_id) {
            if ( !isset($sort_directions[$display_order]) )
                throw new ODRBadRequestException('SortService::multisortDatarecordList() keys in $sort_datafields does not match keys of $sort_directions', $exception_code);
        }
        foreach ($sort_datafields as $display_order => $df_id) {
            if ( !isset($linked_datafields[$display_order]) )
                throw new ODRBadRequestException('SortService::multisortDatarecordList() keys in $sort_datafields does not match keys of $linked_datafields', $exception_code);
        }
        foreach ($sort_datafields as $display_order => $df_id) {
            if ( !isset($numeric_datafields[$display_order]) )
                throw new ODRBadRequestException('SortService::multisortDatarecordList() keys in $sort_datafields does not match keys of $numeric_datafields', $exception_code);
        }


        // ----------------------------------------
        // This service also creates orderings of datarecords by individual datafields, which is the
        //  fastest way to get the values for a single datafield from all datarecords of a datatype
        $datarecord_lists = array();
        foreach ($sort_datafields as $display_order => $sort_df_id) {
            $is_linked_datafield = $linked_datafields[$display_order];
            if ( !$is_linked_datafield )
                $datarecord_lists[$display_order] = self::sortDatarecordsByDatafield($sort_df_id);
            else
                $datarecord_lists[$display_order] = self::sortDatarecordsByLinkedDatafield($datatype_id, $sort_df_id);
        }

        // Going to attempt to use array_multisort() to do the ordering.  The previously loaded lists
        //  can already be treated as individual columns for array_multisort(), but they can't be
        //  used directly because the first row in the first list likely belongs to a different
        //  datarecord as the first row from the second list...
        $columns = array();
        // Also might as well combine the values for each datarecord into a single string at this
        //  point, since other parts of ODR will want it
        $combined_sortvalues = array();

        // Using the first datarecord list to get the datarecord id...
        foreach ($datarecord_lists[0] as $dr_id => $sort_value) {
            // ...loop through all the datarecord lists...
            foreach ($datarecord_lists as $display_order => $data) {
                // ...and end up storing the data for each datarecord at the same index
                $columns[$display_order][] = $data[$dr_id];

                if ( $display_order == 0 )
                    $combined_sortvalues[$dr_id] = $data[$dr_id];
                else
                    $combined_sortvalues[$dr_id] .= ' '.$data[$dr_id];
            }

            // Need one more column of data to match the values to the associated datarecord ids
            $columns[$display_order+1][] = $dr_id;
        }

        // Due to needing to handle an unknown number of sort fields, this function needs to build
        //  an array of arguments to pass to call_user_func_array()...
        $args = array();
        foreach ($sort_directions as $display_order => $sort_dir) {
            // array_multisort() requires the data...
            $args[] = $columns[$display_order];

            // ...then the sort direction for this data...
            if ( $sort_dir === 'asc' )
                $args[] = SORT_ASC;
            else
                $args[] = SORT_DESC;

            // ...and then ODR needs to specify which type of sort to use
            if ( $numeric_datafields[$display_order] )
                $args[] = SORT_NUMERIC;
            else
                $args[] = SORT_NATURAL | SORT_FLAG_CASE;
        }

        // The final argument needs to be the list of datarecord ids, otherwise array_multisort()
        //  will appear to do nothing
        $args[] = &$columns[$display_order+1];
        call_user_func_array('array_multisort', $args);

        // array_multisort() will have modified the final argument...
        $sorted_dr_ids = array_pop($args);
        // ...which is used to rebuild the (dr_id => sort_value) array for returning
        $datarecord_list = array();
        foreach ($sorted_dr_ids as $num => $dr_id)
            $datarecord_list[$dr_id] = $combined_sortvalues[$dr_id];

        // ----------------------------------------
        // Now that we have the correct list of sorted datarecords...
        return self::applySubsetFilter($datarecord_list, $subset_str);
    }


    /**
     * Uses the values stored in the given datafield to sort all datarecords of that datafield's
     * datatype.
     *
     * @param int $datafield_id
     * @param string $sort_dir
     * @param null|string $subset_str If specified, the returned array will only contain datarecord ids from $subset_str
     *
     * @throws ODRException
     *
     * @return array An ordered list of datarecord_id => sort_value
     */
    public function sortDatarecordsByDatafield($datafield_id, $sort_dir = 'asc', $subset_str = null)
    {
        $exception_code = 0x55059289;

        if ( $sort_dir !== 'asc' && $sort_dir !== 'desc' )
            throw new ODRBadRequestException('sortDatarecordsByDatafield() given a non-string $sort_dir', $exception_code);

        /** @var DataFields $datafield */
        $datafield = $this->em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
        if ($datafield == null)
            throw new ODRNotFoundException('Datafield', false, $exception_code);

        // Templates shouldn't need to call this, but it doesn't break anything if they do
//        if ($datafield->getIsMasterField())
//            throw new ODRBadRequestException('sortDatarecordsByDatafield() called with master datafield', $exception_code);

        $datatype = $datafield->getDataType();
        if ( !is_null($datatype->getDeletedAt()) )
            throw new ODRNotFoundException('Datatype', false, $exception_code);

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

            // Can sort these by filename if they only permit a single upload...doesn't make sense
            //  if there's more than one file/image uploaded to the datafield
            case 'File':
            case 'Image':
                // TODO - implementing this would require the theme system to block multiple-allowed files/images from being put in table themes...
//                if ($datafield->getAllowMultipleUploads())
//                    throw new ODRBadRequestException('Unable to sort a "'.$typename.'" that allows multiple uploads', $exception_code);
                break;

            case 'Multiple Radio':
            case 'Multiple Select':
            case 'Markdown':
            case 'Tags':
            case 'XYZ Data':
            default:
                throw new ODRBadRequestException('Unable to sort a "'.$typename.'" datafield', $exception_code);
        }


        // ----------------------------------------
        // Check whether this list is already cached or not...
        $sorted_datarecord_list = $this->cache_service->get('cached_search_df_'.$datafield_id.'_ordering');
        if ( !$sorted_datarecord_list )
            $sorted_datarecord_list = array();


        // ----------------------------------------
        $datarecord_list = array();
        if ( !isset($sorted_datarecord_list[$sort_dir]) ) {
            // The requested list isn't in the cache...need to rebuild it

            // Due to sort_override, the main queries may need to use "e.converted_value" instead of
            //  "e.value"
            $use_converted_value = false;


            // ----------------------------------------
            // Due to sort_override plugins only being allowed on datafields, the query below does not
            //  have to use the renderPluginMap table
            $query = $this->em->createQuery(
               'SELECT rp.pluginClassName AS plugin_classname, rpom.value AS rpom_value, rpod.name AS rpod_name
                FROM ODRAdminBundle:RenderPlugin AS rp
                JOIN ODRAdminBundle:RenderPluginInstance AS rpi WITH rpi.renderPlugin = rp
                JOIN ODRAdminBundle:DataFields AS df WITH rpi.dataField = df
                LEFT JOIN ODRAdminBundle:RenderPluginOptionsMap AS rpom WITH rpom.renderPluginInstance = rpi
                LEFT JOIN ODRAdminBundle:RenderPluginOptionsDef AS rpod WITH rpom.renderPluginOptionsDef = rpod
                WHERE rp.overrideSort = :override_sort AND rp.active = 1 AND df.id IN (:datafield_id)
                AND rp.deletedAt IS NULL AND rpi.deletedAt IS NULL
                AND rpom.deletedAt IS NULL AND rpod.deletedAt IS NULL
                AND df.deletedAt IS NULL'
            )->setParameters(
                array(
                    'override_sort' => true,
                    'datafield_id' => $datafield_id,
                )
            );
            $results = $query->getArrayResult();

            // Should never be more than one result...
            $plugin_classname = null;
            $render_plugin_options = array();
            foreach ($results as $result) {
                $plugin_classname = $result['plugin_classname'];
                $option_name = $result['rpod_name'];
                $option_value = $result['rpom_value'];

                if ( !is_null($option_name) )
                    $render_plugin_options[$option_name] = $option_value;
            }

            if ( !is_null($plugin_classname) ) {
                /** @var SortOverrideInterface $plugin */
                $plugin = $this->container->get($plugin_classname);
                $use_converted_value = $plugin->useConvertedValue($render_plugin_options);
            }


            // ----------------------------------------
            // Need a list of all datarecords for this datatype
            $dr_list = $this->search_service->getCachedSearchDatarecordList($datatype->getId());

            // Due to design decisions, ODR isn't guaranteed to have datarecordfield and/or storage
            //  entity entries for every datafield.  If either of those entries is missing, the
            //  upcoming query WILL NOT have an entry for that datarecord in its result set
            foreach ($dr_list as $dr_id => $parents)
                $datarecord_list[$dr_id] = '';


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
                $results = array();
                if ( !$use_converted_value ) {
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
                }
                else {
                    $query = $this->em->createQuery(
                       'SELECT dr.id AS dr_id, e.converted_value AS sort_value
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
                }


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

                    // NOTE - if it turns out that uniqueness checks need to be case-insensitive,
                    //  then the easiest implementation would be to convert $value to lowercase here
                    // NOTE - CSVImportController's lookup of external id values is currently case sensitive
                    $datarecord_list[$dr_id] = $value;
                }
            }

            // Case-insensitive natural sort works in most cases...
            $flag = SORT_NATURAL | SORT_FLAG_CASE;
            if ($datafield->getForceNumericSort() || $typeclass == 'IntegerValue' || $typeclass == 'DecimalValue')
                $flag = SORT_NUMERIC;   // ...but not for these two typeclasses

            if ($sort_dir === 'asc')
                asort($datarecord_list, $flag);
            else
                arsort($datarecord_list, $flag);


            // Store the result back in the cache
            $sorted_datarecord_list[$sort_dir] = $datarecord_list;
            $this->cache_service->set('cached_search_df_'.$datafield_id.'_ordering', $sorted_datarecord_list);
        }
        else {
            // Otherwise, the list for this request was in the cache
            $datarecord_list = $sorted_datarecord_list[$sort_dir];
        }


        // ----------------------------------------
        // Now that we have the correct list of sorted datarecords...
        return self::applySubsetFilter($datarecord_list, $subset_str);
    }


    /**
     * Uses the values stored in the given datafield to sort all datarecords of that datafield's
     * datatype.
     *
     * @param string $datafield_uuid
     * @param string $sort_dir
     * @param null|string $subset_str If specified, the returned array will only contain datarecord ids from $subset_str
     *
     * @throws ODRException
     *
     * @return array An ordered list of datarecord_id => sort_value
     */
    public function sortDatarecordsByTemplateDatafield($datafield_uuid, $sort_dir = 'asc', $subset_str = null)
    {
        $exception_code = 0xbc1b337d;

        if ( $sort_dir !== 'asc' && $sort_dir !== 'desc' )
            throw new ODRBadRequestException('sortDatarecordsByTemplateDatafield() given a non-string $sort_dir', $exception_code);

        /** @var DataFields $template_datafield */
        $template_datafield = $this->em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
            array(
                'fieldUuid' => $datafield_uuid,
                'is_master_field' => true,
            )
        );
        if ($template_datafield == null)
            throw new ODRNotFoundException('Datafield', false, $exception_code);

        // Regular datafields should not call this...it makes no sense if they do
        if (!$template_datafield->getIsMasterField())
            throw new ODRBadRequestException('sortDatarecordsByTemplateDatafield() called with regular datafield', $exception_code);

        $template_datatype = $template_datafield->getDataType();
        if ( !is_null($template_datatype->getDeletedAt()) )
            throw new ODRNotFoundException('Datatype', false, $exception_code);

        // Doesn't make sense to sort some fieldtypes
        $typename = $template_datafield->getFieldType()->getTypeName();
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
//                    throw new ODRBadRequestException('Unable to sort a "'.$typename.'" that allows multiple uploads', $exception_code);
                break;

            case 'Multiple Radio':
            case 'Multiple Select':
            case 'Markdown':
            case 'Tag':
            case 'XYZ Data':
            default:
                throw new ODRBadRequestException('Unable to sort a "'.$typename.'" datafield', $exception_code);
        }


        // ----------------------------------------
        // Check whether this list is already cached or not...
        $sorted_datarecord_list = $this->cache_service->get('cached_search_template_df_'.$datafield_uuid.'_ordering');
        if ( !$sorted_datarecord_list )
            $sorted_datarecord_list = array();


        // ----------------------------------------
        $datarecord_list = array();
        if ( !isset($sorted_datarecord_list[$sort_dir]) ) {
            // The requested list isn't in the cache...need to rebuild it

            // Need a list of all datarecords for all datatypes with the given master template
            $list = $this->search_service->getCachedTemplateDatarecordList($template_datatype->getUniqueId());

            // Due to design decisions, ODR isn't guaranteed to have datarecordfield and/or storage
            //  entity entries for every datafield.  If either of those entries is missing, the
            //  upcoming query WILL NOT have an entry for that datarecord in its result set
            foreach ($list as $dt_id => $dr_list) {
                foreach ($dr_list as $dr_id => $num) {
                    $datarecord_list[$dr_id] = '';
                }
            }

            // Locate this datafield's value for each datarecord of this datatype
            $typeclass = $template_datafield->getFieldType()->getTypeClass();
            if ($typeclass == 'File' || $typeclass == 'Image') {
                // Get the list of file names...have to left join the file table because datarecord
                //  id is required, but there may not always be a file uploaded
                $query = $this->em->createQuery(
                   'SELECT em.originalFileName AS file_name, dr.id AS dr_id
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                    JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                    LEFT JOIN ODRAdminBundle:'.$typeclass.' AS e WITH e.dataRecordFields = drf
                    LEFT JOIN ODRAdminBundle:'.$typeclass.'Meta AS em WITH em.'.strtolower($typeclass).' = e
                    WHERE dt.masterDataType = :template_datatype AND df.masterDataField = :template_datafield
                    AND e.deletedAt IS NULL AND em.deletedAt IS NULL AND drf.deletedAt IS NULL
                    AND df.deletedAt IS NULL AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'template_datatype' => $template_datatype->getId(),
                        'template_datafield' => $template_datafield->getId(),
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
                    JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                    JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                    JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
                    WHERE dt.masterDataType = :template_datatype AND df.masterDataField = :template_datafield AND rs.selected = 1
                    AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL AND rs.deletedAt IS NULL
                    AND drf.deletedAt IS NULL AND df.deletedAt IS NULL AND dr.deletedAt IS NULL
                    AND dt.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'template_datatype' => $template_datatype->getId(),
                        'template_datafield' => $template_datafield->getId(),
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
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                    JOIN ODRAdminBundle:DataFields AS df WITH drf.dataField = df
                    JOIN ODRAdminBundle:'.$typeclass.' AS e WITH e.dataRecordFields = drf
                    WHERE dt.masterDataType = :template_datatype AND df.masterDataField = :template_datafield
                    AND e.deletedAt IS NULL AND drf.deletedAt IS NULL
                    AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'template_datatype' => $template_datatype->getId(),
                        'template_datafield' => $template_datafield->getId(),
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

            // Case-insensitive natural sort works in most cases...
            $flag = SORT_NATURAL | SORT_FLAG_CASE;
            if ($template_datafield->getForceNumericSort() || $typeclass == 'IntegerValue' || $typeclass == 'DecimalValue')
                $flag = SORT_NUMERIC;   // ...but not for these two typeclasses

            if ($sort_dir === 'asc')
                asort($datarecord_list, $flag);
            else
                arsort($datarecord_list, $flag);


            // Store the result back in the cache
            $sorted_datarecord_list[$sort_dir] = $datarecord_list;
            $this->cache_service->set('cached_search_template_df_'.$datafield_uuid.'_ordering', $sorted_datarecord_list);
        }
        else {
            // Otherwise, the list for this request was in the cache
            $datarecord_list = $sorted_datarecord_list[$sort_dir];
        }


        // ----------------------------------------
        // Now that we have the correct list of sorted datarecords...
        return self::applySubsetFilter($datarecord_list, $subset_str);
    }


    /**
     * Attempts to sort the datarecords belonging to $local_datatype_id, using the values from a
     * $linked_datafield_id belonging to a different datatype.
     *
     * Currently, this function doesn't actually check that $linked_datafield_id belongs to a
     * datatype that is linked to by $local_datatype...if a link doesn't exist, then there won't
     * actually be any values to sort with, resulting in a useless sort order.
     *
     * @param int $local_datatype_id
     * @param int $linked_datafield_id
     * @param string $sort_dir
     * @param null $subset_str
     *
     * @return array
     */
    public function sortDatarecordsByLinkedDatafield($local_datatype_id, $linked_datafield_id, $sort_dir = 'asc', $subset_str = null)
    {
        $exception_code = 0x5f4c106c;

        if ( $sort_dir !== 'asc' && $sort_dir !== 'desc' )
            throw new ODRBadRequestException('sortDatarecordsByLinkedDatafield() given a non-string $sort_dir', $exception_code);

        // ----------------------------------------
        /** @var DataFields $linked_datafield */
        $linked_datafield = $this->em->getRepository('ODRAdminBundle:DataFields')->find($linked_datafield_id);
        if ($linked_datafield == null)
            throw new ODRNotFoundException('Datafield', false, $exception_code);
        $typeclass = $linked_datafield->getDataFieldMeta()->getFieldType()->getTypeClass();

        $linked_datatype = $linked_datafield->getDataType();
        if ( $linked_datatype->getDeletedAt() != null )
            throw new ODRNotFoundException('Linked Datatype', false, $exception_code);


        // Templates shouldn't need to call this, but it doesn't break anything if they do
//        if ($datafield->getIsMasterField())
//            throw new ODRBadRequestException('sortDatarecordsByDatafield() called with master datafield', $exception_code);

        /** @var DataType $local_datatype */
        $local_datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find($local_datatype_id);
        if ($local_datatype == null)
            throw new ODRNotFoundException('Datatype', false, $exception_code);


        // ----------------------------------------
        /*
         * This function is used to sort a "local datatype" (e.g. mineral samples) by a datafield
         * that belongs to a "remote datatype" (e.g. the 'mineral name' datafield in a mineral list)
         *
         * This could be done with the query...
         * SELECT local_dr.id, remote_df.value
         * FROM odr_data_record AS local_dr
         * LEFT JOIN odr_linked_data_tree AS ldt ON ldt.ancestor_id = local_dr.id
         * LEFT JOIN odr_data_record AS remote_dr ON ldt.descendant_id = remote_dr.id
         * LEFT JOIN odr_data_record_fields AS remote_drf ON remote_drf.data_record_id = remote_dr.id
         * LEFT JOIN <storage_entity> AS e ON e.data_record_fields_id = remote_drf.id
         * WHERE remote_drf.data_fields_id = $sort_df_id
         *
         * Fortunately, the search system already has this data cached...but it's spread across
         * three cache entries.
         * 1) 'cached_search_dt_<local_datatype_id>_dr_parents': array of local datarecord ids
         *      ...array(local_dr_id => local_dr_parent_id)
         *
         * 2) 'cached_search_dt_<remote_datatype_id>_linked_dr_parents': effectively cached array
         *      of the linked_datatree, organized by remote_dataype_id
         *      ...array(remote_dr_id => array( linked_ancestor_dr_id_1 => "", linked_ancestor_dr_id_2 => "", ... )
         *
         * 3) 'cached_search_df_<linked_datafield_id>_ordering': array of remote datarecord ids and
         *      their associated values...array(remote_dr_id => "<sort_value>")
         *
         * All of these cache entries are required by the search system, so they typically should
         * already exist when this function is called.
         */


        // ----------------------------------------
        // TODO - ...for right now, going to intentionally NOT cache this entry, a lot of changes would require updating/recaching
        // TODO - 1) adding/deleting records from local datatype
        // TODO - 2) creating/deleting links between local and remote datatypes
        // TODO - 3) creating/deleting links between local and remote datarecords
        // TODO - 4) deleting one of the remote datarecords that the local datatype links to
        // TODO - 5) changes to $sort_df_id's values in a remote datarecord

        // TODO - ...note that the results of this are still cached in self::getSortedDatarecordList(), mostly because that one is easier to clear

        // Check whether this list is already cached or not...
//        $sorted_datarecord_list = $this->cache_service->get(<cache_entry_key>);
//        if ( !$sorted_datarecord_list )
//            $sorted_datarecord_list = array();


        // ----------------------------------------
        $datarecord_list = array();
//        if ( !isset($sorted_datarecord_list[$key]) ) {
            // Need a sorted list of the datarecords in $linked_datatype
            // Don't pass the subset str, it's for records of $local_datatype, not $linked_datatype
            $sorted_linked_dr_list = self::sortDatarecordsByDatafield($linked_datafield->getId(), $sort_dir);

            // Need a list of all datarecords for the datatype to be ordered
            $dr_list = $this->search_service->getCachedSearchDatarecordList($local_datatype->getId());

            // Need to get a list of datarecords of the linked datatype
            $linked_dr_parents = $this->search_service->getCachedSearchDatarecordList($linked_datatype->getId(), true);


            // $dr_list is currently  <dr_id> => <dr_id>  for compliance elsewhere...
            foreach ($dr_list as $local_dr_id => $num)
                $dr_list[$local_dr_id] = '';

            // For all records in the remote datatype that are linked to by something...
            foreach ($linked_dr_parents as $remote_dr_id => $parent_ids) {
                // ...look through all records that link to the remote datatype...
                foreach ($parent_ids as $parent_dr_id => $empty_str) {
                    // ...and if a record in $dr_list links to $remote_dr_id...
                    if ( isset($dr_list[$parent_dr_id]) ) {
                        // ...then use the value for $linked_datafield_id as the sort value for the
                        //  local record in $dr_list
                        $dr_list[$parent_dr_id] = $sorted_linked_dr_list[$remote_dr_id];
                    }
                }
            }


            // ----------------------------------------
            // Case-insensitive natural sort works in most cases...
            $flag = SORT_NATURAL | SORT_FLAG_CASE;
            if ($linked_datafield->getForceNumericSort() || $typeclass == 'IntegerValue' || $typeclass == 'DecimalValue')
                $flag = SORT_NUMERIC;   // ...but not for these two typeclasses

            if ($sort_dir === 'asc')
                asort($dr_list, $flag);
            else
                arsort($dr_list, $flag);

            // Done sorting $dr_list
            $datarecord_list = $dr_list;


            // Store the result back in the cache
//            $sorted_datarecord_list[$key] = $datarecord_list;
//            $this->cache_service->set(<cache_entry_key>, $sorted_datarecord_list);
//        }
//        else {
//            // Otherwise, the list for this request was in the cache
//            $datarecord_list = $sorted_datarecord_list[$key];
//        }


        // ----------------------------------------
        // Now that we have the correct list of sorted datarecords...
        return self::applySubsetFilter($datarecord_list, $subset_str);
    }


    /**
     * Sorts all radio options of the given datafield by name
     *
     * @param ODRUser $user
     * @param Datafields $datafield
     *
     * @return bool true if any radio options had their displayOrder changed, false otherwise
     */
    public function sortRadioOptionsByName($user, $datafield)
    {
        // Don't do anything if this datafield isn't sorting its radio options by name
        if (!$datafield->getRadioOptionNameSort())
            return false;

        // Need to potentially look up radio options if their displayOrder gets changed
        $repo_radio_options = $this->em->getRepository('ODRAdminBundle:RadioOptions');
        // NOTE - individually looking radio options up paradoxically reduces the number of queries made...thanks, doctrine's hydrator

        // Need the actual radio option names to sort on
        $query = $this->em->createQuery(
           'SELECT ro.id AS ro_id, rom.optionName, rom.displayOrder
            FROM ODRAdminBundle:RadioOptions AS ro
            JOIN ro.radioOptionMeta AS rom
            WHERE ro.dataField = :datafield_id
            AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL'
        )->setParameters( array('datafield_id' => $datafield->getId()) );
        $results = $query->getArrayResult();

        $radio_options = array();
        foreach ($results as $result) {
            $ro_id = $result['ro_id'];
            $ro_name = $result['optionName'];
            $display_order = $result['displayOrder'];

            $radio_options[$ro_id] = array('name' => $ro_name, 'order' => $display_order);
        }


        // ----------------------------------------
        // Sort the radio options by name
        uasort($radio_options, function($a, $b) {    // need to preserve array keys, since they're entity ids
            return strnatcasecmp($a['name'], $b['name']);
        });

        // Save any changes in the sort order
        $index = 0;
        $changes_made = false;
        foreach ($radio_options as $option_id => $option_data) {
            $display_order = $option_data['order'];

            if ( $display_order !== $index ) {
                // ...if a radio option is not in the correct order, then hydrate it...
                /** @var RadioOptions $ro */
                $ro = $repo_radio_options->find($option_id);

                // ...so it can be updated to match the sorted list
                $properties = array(
                    'displayOrder' => $index,
                );
                $this->entity_modify_service->updateRadioOptionsMeta($user, $ro, $properties, true);    // don't flush immediately...
                $changes_made = true;
            }

            $index++;
        }

        // Flush now that all changes have been made
        if ($changes_made)
            $this->em->flush();

        return $changes_made;
    }


    /**
     * Sorts this datafield's tags based on their current name.
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     *
     * @return bool true if any tags had their displayOrder changed, false otherwise
     */
    public function sortTagsByName($user, $datafield)
    {
        // Don't do anything if this datafield isn't sorting its tags by name
        if ( !$datafield->getRadioOptionNameSort() )
            return false;

        // Need to potentially look up tags if their displayOrder gets changed
        $repo_tags = $this->em->getRepository('ODRAdminBundle:Tags');
        // NOTE - individually looking tags up paradoxically reduces the number of queries made...thanks, doctrine's hydrator

        // Need the actual tag names to sort on
        $query = $this->em->createQuery(
           'SELECT t.id AS tag_id, tm.tagName, tm.displayOrder, p_t.id AS parent_tag_id
            FROM ODRAdminBundle:Tags AS t
            JOIN ODRAdminBundle:TagMeta AS tm WITH tm.tag = t
            LEFT JOIN ODRAdminBundle:TagTree AS tt WITH tt.child = t
            LEFT JOIN ODRAdminBundle:Tags AS p_t WITH tt.parent = p_t
            WHERE t.dataField = :datafield_id
            AND t.deletedAt IS NULL AND tm.deletedAt IS NULL
            AND tt.deletedAt IS NULL AND p_t.deletedAt IS NULL'
        )->setParameters( array('datafield_id' => $datafield->getId()) );
        $results = $query->getArrayResult();

        $tag_groups = array();
        foreach ($results as $result) {
            $tag_id = $result['tag_id'];
            $tag_name = $result['tagName'];
            $display_order = $result['displayOrder'];
            $parent_tag_id = $result['parent_tag_id'];

            if ( is_null($parent_tag_id) )
                $parent_tag_id = 0;

            // Each of the tags needs to be "grouped" by its parent
            if ( !isset($tag_groups[$parent_tag_id]) )
                $tag_groups[$parent_tag_id] = array();
            $tag_groups[$parent_tag_id][$tag_id] = array('name' => $tag_name, 'order' => $display_order);
        }


        // ----------------------------------------
        // Each "group" of tags can then be sorted individually
        foreach ($tag_groups as $parent_tag_id => $tag_group) {
            $tmp = $tag_group;
            uasort($tmp, function($a, $b) {    // need to preserve array keys, since they're tag ids
                return strnatcasecmp($a['name'], $b['name']);
            });
            $tag_groups[$parent_tag_id] = $tmp;
        }

        // Now that each "group" of tags is sorted...
        $changes_made = false;
        foreach ($tag_groups as $parent_tag_id => $tag_group) {
            $index = 0;
            foreach ($tag_group as $tag_id => $tag_data) {
                $display_order = $tag_data['order'];

                if ( $display_order !== $index ) {
                    // ...if a tag is not in the correct order, then hydrate it...
                    /** @var Tags $tag */
                    $tag = $repo_tags->find($tag_id);

                    // ...so it can be updated to match the sorted list
                    $properties = array(
                        'displayOrder' => $index
                    );
                    $this->entity_modify_service->updateTagMeta($user, $tag, $properties, true);    // don't flush immediately...
                    $changes_made = true;
                }

                $index++;
            }
        }

        // Flush now that all changes have been made
        if ($changes_made)
            $this->em->flush();

        // Return whether any changes were made
        return $changes_made;
    }


    /**
     * Returns true when the given value has already been saved to an instance of the given
     * datafield, and false otherwise.  If a datarecord is specified, this function will return
     * false when that datarecord has the given value.
     *
     * For example...let datarecord 1 has value "123" in df, datarecord 2 has value "456" in df
     * * valueAlreadyExists(df, "123") => true
     * * valueAlreadyExists(df, "789") => false
     * * valueAlreadyExists(df, "456", dr_1) => true, because dr_2 already has "456"
     * * valueAlreadyExists(df, "456", dr_2) => false, because dr_2 is allowed to have "456"
     *
     *
     * Additionally, due to the selected collation, a MYSQL search for the string "zyk" will match
     * the string "Zýk".  This PHP function, on the other hand, is both case sensitive and does not
     * perform any UTF-8 collation.  This difference is (currently) intended.
     *
     * @param DataFields $datafield
     * @param string $value
     * @param DataRecord|null $datarecord
     *
     * @return bool
     */
    public function valueAlreadyExists($datafield, $value, $datarecord = null)
    {
        // ----------------------------------------
        // Don't continue if called on a datafield that can't be unique
        if ( !$datafield->getFieldType()->getCanBeUnique() )
            throw new ODRBadRequestException('valueAlreadyExists() called with '.$datafield->getFieldType()->getTypeName().' datafield', 0xdd175c30);

        // Also don't continue if the datafield and the datarecord don't belong to the same datatype
        if ( !is_null($datarecord) && $datarecord->getDataType()->getId() !== $datafield->getDataType()->getId() )
            throw new ODRBadRequestException("Datafield and Datarecord don't belong to the same Datatype", 0xdd175c30);

        // Determine whether this is a request for a child datarecord...
        $is_child_record = false;
        if ( !is_null($datarecord) && $datarecord->getId() !== $datarecord->getParent()->getId() )
            $is_child_record = true;


        // ----------------------------------------
        // MYSQL's collation treats certain "special" characters as equivalent to certain ASCII characters
        //  e.g.  "Zýk" == "Zyk"   (note that this doesn't apply to all characters..."Sør" != "Sor", for instance)
        // This is extremely useful for searching purposes, but incorrect for uniqueness purposes

        // Therefore, need to get a list of the existing values in this datafield...
        $dr_list = self::sortDatarecordsByDatafield($datafield->getId());

        if ( !$is_child_record ) {
            // If this isn't a child record, or no record was specified, then only need to check
            //  whether the requested value already exists in this list

            // Don't need to do anything here
        }
        else {
            // If this is a child record, then we have to check against its sibling records, and not
            //  every single record of this childtype
            $sibling_records = array();
            $parent_datarecord_id = $datarecord->getParent()->getId();

            // SearchService is the faster way to get the parents of the child records
            $search_dr_list = $this->search_service->getCachedSearchDatarecordList($datafield->getDataType()->getId());
            foreach ($search_dr_list as $child_dr_id => $parent_dr_id) {
                // The "sibling" records are all records with the same parent
                if ( $parent_dr_id === $parent_datarecord_id )
                    $sibling_records[$child_dr_id] = $dr_list[$child_dr_id];
            }

            // Replace the list of all datarecords of this childtype with just the siblings
            $dr_list = $sibling_records;
        }

        // NOTE - array_search() is faster than array_flip()+isset() when looking up a single string
        // NOTE - array_search() is case sensitive...this is acceptable for ODR's purposes, at the moment
        $dr_id = array_search($value, $dr_list);


        // ----------------------------------------
        if ( $dr_id === false ) {
            // The search didn't find anything, so the value isn't already in use
            return false;
        }
        else if ( is_null($datarecord) ) {
            // No datarecord specified, so this is likely a request from a fake record...since the
            //  search found something, the value already exists
            return true;
        }
        else if ( $dr_id === $datarecord->getId() ) {
            // The specified datarecord already contains the specified value...uniquness constraints
            //  will not be violated if the specified datarecord is saved
            return false;
        }
        else {
            // A different datarecord contains the specified value...uniqueness constraints will be
            //  violated if the specified datarecord is allowed to save this value
            return true;
        }
    }
}
