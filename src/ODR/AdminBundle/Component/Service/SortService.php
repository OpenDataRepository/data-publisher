<?php

/**
 * Open Data Repository Data Publisher
 * Sort Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get and rebuild the info required to sort lists of datarecords.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataFields;
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
     * @param SearchService $searchService
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entityManager,
        CacheService $cacheService,
        SearchService $searchService,
        Logger $logger
    ) {
        $this->em = $entityManager;
        $this->cache_service = $cacheService;
        $this->search_service = $searchService;
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
        if ($datatype->getIsMasterType())
            throw new ODRBadRequestException('getSortedDatarecordList() called on a master template', $exception_code);

        // Attempt to grab the sorted list of datarecords for this datatype from the cache
        $datarecord_list = $this->cache_service->get('datatype_'.$datatype_id.'_record_order');
        if ( $datarecord_list == false || count($datarecord_list) == 0 ) {
            // Going to need the datatype's sorting datafield, if it exists
            $sortfield = $datatype->getSortField();

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
            else {
                // Want to store all datarecords, not just a subset if it was passed in
                $datarecord_list = self::sortDatarecordsByDatafield($sortfield->getId());
            }

            // Store the sorted datarecord list back in the cache
            $this->cache_service->set('datatype_'.$datatype_id.'_record_order', $datarecord_list);
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
        if ($datafield->getIsMasterField())
            throw new ODRBadRequestException('sortDatarecordsByDatafield() called with master datafield', $exception_code);

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
}
