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
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\Tags;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class SortService
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
     * @var EntityMetaModifyService
     */
    private $emm_service;

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
     * @param EntityManager $entityManager
     * @param CacheService $cacheService
     * @param EntityMetaModifyService $entityMetaModifyService
     * @param SearchService $searchService
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        EntityMetaModifyService $entity_meta_modify_service,
        SearchService $search_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->emm_service = $entity_meta_modify_service;
        $this->search_service = $search_service;
        $this->logger = $logger;
    }


    /**
     * All of these subsequent sorting functions need the ability to only return a desired subset of
     * datarecords from the entire sorted list.
     *
     * @param array $datarecord_list
     * @param null|string $subset_datarecords
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
            // Going to need the datatype's sorting datafield, if it exists
            $sortfield = $datatype->getSortField();
            $cache_result = true;

            // NOTE - $sortfield->getDataType() IS NOT GUARANTEED to be the same as $datatype...it's
            //  possible that $datatype is using a field from one of its linked descendants

            $datarecord_list = array();
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
            else if ( $sortfield->getDataType()->getId() === $datatype->getId() ) {
                // Want to store all datarecords, not just a subset if it was passed in
                $datarecord_list = self::sortDatarecordsByDatafield($sortfield->getId());
            }
            else {
                // If the datatype's sort field belongs to another datatype, then a different sort
                //  function is needed...
                $datarecord_list = self::sortDatarecordsByLinkedDatafield($datatype->getId(), $sortfield->getId());

                // Can't cache a datarecord list returned by this function call...there's too many
                //  events that can require it to be rebuilt
                $cache_result = false;
            }

            if ( $cache_result ) {
                // Store the sorted datarecord list back in the cache
                $this->cache_service->set('datatype_'.$datatype_id.'_record_order', $datarecord_list);
            }
        }


        // ----------------------------------------
        // Now that we have the correct list of sorted datarecords...
        return self::applySubsetFilter($datarecord_list, $subset_str);
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
        $exception_code = 0x55059289;

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
            default:
                throw new ODRBadRequestException('Unable to sort a "'.$typename.'" datafield', $exception_code);
        }


        // ----------------------------------------
        // Check whether this list is already cached or not...
        $sorted_datarecord_list = $this->cache_service->get('cached_search_df_'.$datafield_id.'_ordering');
        if ( !$sorted_datarecord_list )
            $sorted_datarecord_list = array();

        // TODO - only store the ascending order, then array_reverse() if descending is wanted?
        $key = 'asc';
        if (!$sort_ascending)
            $key = 'desc';


        // ----------------------------------------
        $datarecord_list = array();
        if ( !isset($sorted_datarecord_list[$key]) ) {
            // The requested list isn't in the cache...need to rebuild it

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

            // Case-insensitive natural sort works in most cases...
            $flag = SORT_NATURAL | SORT_FLAG_CASE;
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
        return self::applySubsetFilter($datarecord_list, $subset_str);
    }


    /**
     * Uses the values stored in the given datafield to sort all datarecords of that datafield's
     * datatype.
     *
     * @param string $datafield_uuid
     * @param bool $sort_ascending
     * @param null|string $subset_str If specified, the returned array will only contain datarecord ids from $subset_str
     *
     * @throws ODRException
     *
     * @return array An ordered list of datarecord_id => sort_value
     */
    public function sortDatarecordsByTemplateDatafield($datafield_uuid, $sort_ascending = true, $subset_str = null)
    {
        $exception_code = 0xbc1b337d;

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
            default:
                throw new ODRBadRequestException('Unable to sort a "'.$typename.'" datafield', $exception_code);
        }


        // ----------------------------------------
        // Check whether this list is already cached or not...
        $sorted_datarecord_list = $this->cache_service->get('cached_search_template_df_'.$datafield_uuid.'_ordering');
        if ( !$sorted_datarecord_list )
            $sorted_datarecord_list = array();

        // TODO - only store the ascending order, then array_reverse() if descending is wanted?
        $key = 'asc';
        if (!$sort_ascending)
            $key = 'desc';


        // ----------------------------------------
        $datarecord_list = array();
        if ( !isset($sorted_datarecord_list[$key]) ) {
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
            if ($typeclass == 'IntegerValue' || $typeclass == 'DecimalValue')
                $flag = SORT_NUMERIC;   // ...but not for these two typeclasses

            if ($sort_ascending)
                asort($datarecord_list, $flag);
            else
                arsort($datarecord_list, $flag);


            // Store the result back in the cache
            $sorted_datarecord_list[$key] = $datarecord_list;
            $this->cache_service->set('cached_search_template_df_'.$datafield_uuid.'_ordering', $sorted_datarecord_list);
        }
        else {
            // Otherwise, the list for this request was in the cache
            $datarecord_list = $sorted_datarecord_list[$key];
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
     * @param bool $sort_ascending
     * @param null $subset_str
     *
     * @return array
     */
    public function sortDatarecordsByLinkedDatafield($local_datatype_id, $linked_datafield_id, $sort_ascending = true, $subset_str = null)
    {
        $exception_code = 0x5f4c106c;

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

        // Check whether this list is already cached or not...
//        $sorted_datarecord_list = $this->cache_service->get(<cache_entry_key>);
//        if ( !$sorted_datarecord_list )
//            $sorted_datarecord_list = array();

//        // TODO - only store the ascending order, then array_reverse() if descending is wanted?
//        $key = 'asc';
//        if (!$sort_ascending)
//            $key = 'desc';


        // ----------------------------------------
        $datarecord_list = array();
//        if ( !isset($sorted_datarecord_list[$key]) ) {
            // Need a sorted list of the datarecords in $linked_datatype
            // Don't pass the subset str, it's for records of $local_datatype, not $linked_datatype
            $sorted_linked_dr_list = self::sortDatarecordsByDatafield($linked_datafield->getId(), $sort_ascending);

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
            if ($typeclass == 'IntegerValue' || $typeclass == 'DecimalValue')
                $flag = SORT_NUMERIC;   // ...but not for these two typeclasses

            if ($sort_ascending)
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
     */
    public function sortRadioOptionsByName($user, $datafield)
    {
        // Don't do anything if this datafield isn't sorting its radio options by name
        if (!$datafield->getRadioOptionNameSort())
            return;

        $query = $this->em->createQuery(
           'SELECT ro, rom
            FROM ODRAdminBundle:RadioOptions AS ro
            JOIN ro.radioOptionMeta AS rom
            WHERE ro.dataField = :datafield
            AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL'
        )->setParameters( array('datafield' => $datafield->getId()) );
        /** @var RadioOptions[] $results */
        $results = $query->getResult();

        // Organize by the name of the radio option, and then sort the list
        /** @var RadioOptions[] $radio_option_list */
        $radio_option_list = array();
        foreach ($results as $result) {
            $option_name = $result->getOptionName();
            $radio_option_list[$option_name] = $result;
        }
        ksort($radio_option_list);

        // Save any changes in the sort order
        $index = 0;
        $changes_made = false;
        foreach ($radio_option_list as $option_name => $ro) {
            if ( $ro->getDisplayOrder() !== $index ) {
                // This radio option should be in a different spot
                $properties = array(
                    'displayOrder' => $index,
                );
                $this->emm_service->updateRadioOptionsMeta($user, $ro, $properties, true);    // don't flush immediately...
                $changes_made = true;
            }

            $index++;
        }

        // Flush now that all changes have been made
        if ($changes_made)
            $this->em->flush();
    }


    /**
     * Sorts this datafield's tags based on their current name.
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     *
     * @return bool true if the sort order changed, false otherwise
     */
    public function sortTagsByName($user, $datafield)
    {
        // Don't do anything if this datafield isn't sorting its tags by name
        if ( !$datafield->getRadioOptionNameSort() )
            return false;

        // Need to create a lookup of tags incase any property needs changed later...
        $query = $this->em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Tags AS t
            WHERE t.dataField = :datafield_id
            AND t.deletedAt IS NULL'
        )->setParameters( array('datafield_id' => $datafield->getId()) );
        /** @var Tags[] $results */
        $results = $query->getResult();

        // Organize the tags by their id...
        /** @var Tags[] $tag_list */
        $tag_list = array();
        foreach ($results as $tag)
            $tag_list[ $tag->getId() ] = $tag;


        // Also need the actual tag names to sort on
        $query = $this->em->createQuery(
           'SELECT t.id AS tag_id, tm.tagName, p_t.id AS parent_tag_id
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
            $parent_tag_id = $result['parent_tag_id'];

            if ( is_null($parent_tag_id) )
                $parent_tag_id = 0;

            // Each of the tags needs to be "grouped" by its parent
            if ( !isset($tag_groups[$parent_tag_id]) )
                $tag_groups[$parent_tag_id] = array();
            $tag_groups[$parent_tag_id][$tag_id] = $tag_name;
        }


        // ----------------------------------------
        // Each "group" of tags can then be sorted individually
        foreach ($tag_groups as $parent_tag_id => $tag_group) {
            $tmp = $tag_group;
            uasort($tmp, "self::tagSort_name");
            $tag_groups[$parent_tag_id] = $tmp;
        }

        // Now that each "group" of tags is sorted...
        $changes_made = false;
        foreach ($tag_groups as $parent_tag_id => $tag_group) {
            $index = 0;
            foreach ($tag_group as $tag_id => $tag_name) {
                $tag = $tag_list[$tag_id];

                if ( $tag->getDisplayOrder() !== $index ) {
                    // ...update each tag's displayOrder to match the sorted list
                    $properties = array(
                        'displayOrder' => $index
                    );
                    $this->emm_service->updateTagMeta($user, $tag, $properties, true);    // don't flush immediately...
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
     * Custom function to sort tags by name.
     *
     * @param string $a
     * @param string $b
     *
     * @return int
     */
    private function tagSort_name($a, $b)
    {
        return strnatcasecmp($a, $b);
    }
}
