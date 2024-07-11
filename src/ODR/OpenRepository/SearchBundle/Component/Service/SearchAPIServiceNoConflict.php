<?php

/**
 * Open Data Repository Data Publisher
 * Search API Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This holds the endpoint functions required to setup and run a full-fledged ODR search.
 *
 */

namespace ODR\OpenRepository\SearchBundle\Component\Service;

// Entities
use ODR\AdminBundle\Component\Service\DatarecordExportService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
// Services
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\CacheService;
// Other
use Doctrine\ORM\EntityManager;
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\OpenRepository\GraphBundle\Plugins\SearchPluginInterface;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
use Symfony\Bridge\Monolog\Logger;


class SearchAPIServiceNoConflict
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatatreeInfoService
     */
    private $dti_service;

    /**
     * @var DatarecordExportService
     */
    private $dre_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var SearchCacheService
     */
    private $search_cache_service;

    /**
     * @var SearchKeyService
     */
    private $search_key_service;

    /**
     * @var SortService
     */
    private $sort_service;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * SearchAPIService constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatatreeInfoService $datatree_info_service
     * @param DatarecordExportService $datarecord_export_service
     * @param SearchService $search_service
     * @param SearchCacheService $search_cache_service
     * @param SearchKeyService $search_key_service
     * @param SortService $sort_service
     * @param CacheService $cache_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatatreeInfoService $datatree_info_service,
        DatarecordExportService $datarecord_export_service,
        SearchService $search_service,
        SearchCacheService $search_cache_service,
        SearchKeyService $search_key_service,
        SortService $sort_service,
        CacheService $cache_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dti_service = $datatree_info_service;
        $this->dre_service = $datarecord_export_service;
        $this->search_service = $search_service;
        $this->search_cache_service = $search_cache_service;
        $this->search_key_service = $search_key_service;
        $this->sort_service = $sort_service;
        $this->cache_service = $cache_service;
        $this->logger = $logger;
    }


    /**
     * Returns an array of searchable datafield ids, filtered to what the user can see, and
     * organized by their datatype id.
     *
     * @param int[] $top_level_datatype_ids
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     *
     * @return array
     */
    public function getSearchableDatafieldsForUser($top_level_datatype_ids, $user_permissions, $search_as_super_admin = false)
    {
        throw new ODRException('Please use the regular SearchAPIService instead, this one does not return correct results');

        // Going to need to filter the resulting list based on the user's permissions
        $datatype_permissions = array();
        $datafield_permissions = array();
        if ( isset($user_permissions['datatypes']) )
            $datatype_permissions = $user_permissions['datatypes'];
        if ( isset($user_permissions['datafields']) )
            $datafield_permissions = $user_permissions['datafields'];


        $all_searchable_datafields = array();
        foreach ($top_level_datatype_ids as $num => $top_level_datatype_id) {
            // Get all possible datafields that can be searched on for this datatype
            $searchable_datafields = $this->search_service->getSearchableDatafields($top_level_datatype_id);
            foreach ($searchable_datafields as $dt_id => $datatype_data) {
                $is_public = true;

                // Attempt at date-based public date searches
                /*
                $public_date = \DateTime::createFromFormat ( "Y-m-d H:i:s", $datatype_data['dt_public_date']);
                if ($public_date > new \DateTime("now", new \DateTimeZone('UTC')))
                    $is_public = false;
                */

                if ($datatype_data['dt_public_date'] === '2200-01-01')
                    $is_public = false;


                $can_view_dt = false;
                if ($search_as_super_admin)
                    $can_view_dt = true;
                else if (isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dt_view']))
                    $can_view_dt = true;

                if (!$is_public && !$can_view_dt) {
                    // User can't view datatype, filter it out
                    unset($searchable_datafields[$dt_id]);
                }
                else {
                    // User can view datatype, filter datafields if needed...public datafields are always
                    //  visible when the datatype can be viewed
                    foreach ($datatype_data['datafields']['non_public'] as $df_id => $datafield_data) {
                        $can_view_df = false;
                        if ($search_as_super_admin)
                            $can_view_df = true;
                        else if (isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['view']))
                            $can_view_df = true;

                        // If user can view datafield, move it out of the non_public section
                        if ($can_view_df)
                            $searchable_datafields[$dt_id]['datafields'][$df_id] = $datafield_data;
                    }

                    // Get rid of the non_public section
                    unset($searchable_datafields[$dt_id]['datafields']['non_public']);

                    // Only want the array of datafield ids that the user can see
                    $searchable_datafields[$dt_id] = $searchable_datafields[$dt_id]['datafields'];
                }
            }

            foreach ($searchable_datafields as $dt_id => $data)
                $all_searchable_datafields[$dt_id] = $data;
        }

        // Return the final list
        return $all_searchable_datafields;
    }


    /**
     * Returns a search key that is filtered to what the user can see...at the moment, ODR will
     * forcibly redirect the user to the filtered search key if it's different than what they
     * originally attempted to access, but this could change to be more refined in the future...
     *
     * @param DataType $datatype
     * @param string $search_key
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     *
     * @return string
     */
    public function filterSearchKeyForUser($datatype, $search_key, $user_permissions, $search_as_super_admin = false)
    {
        throw new ODRException('Please use the regular SearchAPIService instead, this one does not return correct results');

        // Convert the search key into array format...
        $search_params = $this->search_key_service->decodeSearchKey($search_key);
        $filtered_search_params = array();

        // Get all the datatypes/datafields the user is allowed to search on...
        $searchable_datafields = self::getSearchableDatafieldsForUser(array($datatype->getId()), $user_permissions, $search_as_super_admin);

        foreach ($search_params as $key => $value) {
            if ($key === 'dt_id' || $key === 'gen') {
                // Don't need to do anything special with these keys
                $filtered_search_params[$key] = $value;
            }
            else if ( is_numeric($key) ) {
                // This is a datafield entry...
                $df_id = intval($key);

                foreach ($searchable_datafields as $dt_id => $datafields) {
                    if ( isset($datafields[$df_id]) ) {
                        // User can search on this datafield
                        $filtered_search_params[$key] = $value;
                    }
                }
            }
            else {
                $pieces = explode('_', $key);

                if ( is_numeric($pieces[0]) && count($pieces) === 2 ) {
                    // This is a DatetimeValue field...
                    $df_id = intval($pieces[0]);

                    foreach ($searchable_datafields as $dt_id => $datafields) {
                        if ( isset($datafields[$df_id]) ) {
                            // User can search on this datafield
                            $filtered_search_params[$key] = $value;
                        }
                    }
                }
                else {
                    // $key is one of the modified/created/modifiedBy/createdBy/publicStatus entries
                    $dt_id = intval($pieces[1]);

                    if ( isset($searchable_datafields[$dt_id]) ) {
                        // User can search on this datatype
                        $filtered_search_params[$key] = $value;
                    }
                }
            }
        }

        // Convert the filtered set of search parameters back into a search key and return it
        $filtered_search_key = $this->search_key_service->encodeSearchKey($filtered_search_params);
        return $filtered_search_key;
    }

    /**
     * @param DataType $datatype
     * @param $baseurl
     * @param $params
     * @return array
     * @throws \Exception
     */
    public function fullTemplateSearch($datatype, $baseurl, $params) {

        $master_datatype_id = $datatype->getId();
        /* Probably will expand to include all actual datasets as well someday
         *
        if($datatype->getMetadataFor()) {
            $master_datatype_id = $datatype->getMetadataFor()->getId();
        }
         */

        // Determine all datatypes derived from master template including linked data types.
        // Possibly use unique_id and _template group_ .... (would not get user-linked)
        /*
            SELECT * FROM odr_data_type
            where master_datatype_id = 670
            and deletedAt IS NULL
            and setup_step LIKE 'operational'
            and (preload_status is NULL OR preload_status LIKE 'issued')
            order by id desc
            LIMIT 1000;
            LEFT JOIN dt.grandparent AS gp
         */
        $dt_query = $this->em->createQuery(
            'SELECT
            DISTINCT dt.id, dt.unique_id
            FROM ODRAdminBundle:DataType AS dt
            LEFT JOIN dt.dataTypeMeta AS dtm
            WHERE 
                dt.masterDataType = :master_datatype_id
                AND dt.deletedAt IS NULL 
                AND dt.setup_step LIKE \'operational\'
                AND (dt.preload_status is NULL OR dt.preload_status LIKE \'issued\' OR dt.preload_status like \'\')
                AND dtm.publicDate <= :now
            ')
            ->setParameters(array(
                'master_datatype_id' => $master_datatype_id,
                'now' => new \DateTime()
            ));

        /*
            LEFT JOIN dt.dataTypeMeta AS dtm
        */
        $datatype_result = $dt_query->getArrayResult();
        // print var_export($datatype_result, true);exit();

        $datatype_id_array = array();
        // $datatype_associations = array();
        foreach($datatype_result as $datatype_info) {
            $associated_datatypes = $this->dti_service->getAssociatedDatatypes($datatype_info['id']);
            // $datatype_associations[$datatype_info['unique_id']] = "||" . join('||', $associated_datatypes) . "||";
            array_push($datatype_id_array, $datatype_info['id']);
            $datatype_id_array = array_merge($datatype_id_array, $associated_datatypes);

            $more_associated_datatypes = $this->dti_service->getChildDescendants($associated_datatypes);
            if(count($more_associated_datatypes) > 0) {
                // $datatype_associations[$datatype_info['unique_id']] .= join('||', $more_associated_datatypes) . "||";
                $datatype_id_array = array_merge($datatype_id_array, $more_associated_datatypes);
            }
        }

        /*
         $template_groups = select data_type.unique_id from data_type where master_datatype_id = 670
         $dt_ids = select data_type.id from data_type where template_group_id in template_groups
         select distinct dr.unique_id from dr join dr.data_fields where df.dt_id in ($dt_ids);
         */

        /*
         * {
    "fields": [
        {
            "selected_tags": [
                {
                    "template_tag_uuid": "23d621f"
                },
                {
                    "template_tag_uuid": "301b3fa"
                },
                {
                    "template_tag_uuid": "5138dc3"
                },
                {
                    "template_tag_uuid": "2848fb3"
                },
                {
                    "template_tag_uuid": "c9cca56"
                },
                {
                    "template_tag_uuid": "0d46376"
                }
            ],
            "template_field_uuid": "38faf260047f3009ea75f0f8bf86"
        }
    ],
    "sort_by": [
        {
            "dir": "asc",
            "template_field_uuid": "08088a9"
        }
    ],
    "template_name": "AHED Core 1.0",
    "template_uuid": "2ea627b"
}
         */

        // Get dr.unique_id
        $query_base = 'SELECT
            distinct dr.unique_id, dr.id, par.id as parent_id, gp.id as grandparent_id

            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.dataRecordMeta AS drm
            LEFT JOIN dr.parent AS par
            LEFT JOIN dr.grandparent AS gp
            
            LEFT JOIN dr.dataType AS dt
            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dt.masterDataType AS mdt
            
            LEFT JOIN dt.dataFields AS df_dt
            LEFT JOIN df_dt.dataFieldMeta AS dfm_dt
            LEFT JOIN dfm_dt.fieldType AS ft_dt
            LEFT JOIN dt.metadata_for AS mf

            LEFT JOIN dr.dataRecordFields AS drf
            
            LEFT JOIN drf.file AS e_f
            LEFT JOIN e_f.fileMeta AS e_fm

            LEFT JOIN drf.image AS e_i
            LEFT JOIN e_i.imageMeta AS e_im
            LEFT JOIN e_i.parent AS e_ip
            LEFT JOIN e_ip.imageMeta AS e_ipm
            LEFT JOIN e_i.imageSize AS e_is

            LEFT JOIN drf.boolean AS e_b
            LEFT JOIN drf.integerValue AS e_iv
            LEFT JOIN drf.decimalValue AS e_dv
            LEFT JOIN drf.longText AS e_lt
            LEFT JOIN drf.longVarchar AS e_lvc
            LEFT JOIN drf.mediumVarchar AS e_mvc
            LEFT JOIN drf.shortVarchar AS e_svc
            LEFT JOIN drf.datetimeValue AS e_dtv
            LEFT JOIN drf.radioSelection AS rs
            LEFT JOIN rs.radioOption AS ro
            LEFT JOIN ro.radioOptionMeta AS rom
            LEFT JOIN drf.tagSelection AS ts
            LEFT JOIN ts.tag AS t
            LEFT JOIN t.tagMeta AS tm


            WHERE
                dt.id IN (:datatype_id_array)
                AND drm.publicDate <= :now
        ';
        /*
         * For some reason, this is slowing things down dramatically
         * Leaving out may affect fields that have been deleted...
         *
        LEFT JOIN drf.dataField AS df
            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN dfm.fieldType AS ft
        */

        // Parameters array
        // Each field is an "and" requirement
        // General is also an "and"
        $search_array = [];
        $search_performed = false;
        $search_datetime = new \DateTime();
        foreach($params['fields'] as $field) {
            if(isset($field['selected_tags'])) {
                $parameters = array();
                $parameters['datatype_id_array'] = array_unique($datatype_id_array);
                $parameters['now'] = $search_datetime;
                // print var_export($parameters, true);
                $tag_uuids = $field['selected_tags'];
                // $tag_uuids = array_merge($tag_uuids, $field['selected_tags']);
                if(count($tag_uuids) > 0) {
                    $qs = $query_base;
                    $qs .= ' AND t.tagUuid IN (:selected_tag_uuids)';
                    $qs .= ' AND ts.deletedAt IS NULL';
                    $qs .= ' AND ts.selected = 1';
                    $parameters['selected_tag_uuids'] = $tag_uuids;

                    $search_array[] = self::runSearchQuery($qs, $parameters, $master_datatype_id);
                    $search_performed = true;
                }

            }
            if(isset($field['selected_options'])) {
                $parameters = array();
                $parameters['datatype_id_array'] = array_unique($datatype_id_array);
                $parameters['now'] = $search_datetime;
                // print var_export($parameters, true);
                $radio_uuids = $field['selected_options'];
                // $radio_uuids = array_merge($radio_uuids, $field['selected_options']);
                if(count($radio_uuids) > 0) {
                    $qs = $query_base;
                    $qs .= ' AND ro.radioOptionUuid IN (:selected_radio_option_uuids)';
                    $qs .= ' AND rs.deletedAt IS NULL';
                    $qs .= ' AND rs.selected = 1';
                    $parameters['selected_radio_option_uuids'] = $radio_uuids;
                    $search_array[] = self::runSearchQuery($qs, $parameters, $master_datatype_id);
                    $search_performed = true;
                }
            }
        }

        // Add General Search
        if(isset($params['general']) && $params['general'] !== '') {
            $parameters = array();
            $parameters['datatype_id_array'] = array_unique($datatype_id_array);
            $parameters['now'] = $search_datetime;
            // print var_export($parameters, true);
            if(preg_match('/\|\|/', $params['general'])) {
                // We need to split and generate a general_terms array
                $terms = preg_split('/\|\|/', $params['general']);
                $qs = $query_base;
                $qs .= ' AND ( ';
                for($i = 0; $i < count($terms); $i++) {
                    $term = $terms[$i];
                    self::addGeneralParameters($qs, $parameters, trim($term), $i);
                    if($i < count($terms) - 1) {
                        $qs .= ' OR ';
                    }
                    else {
                        $qs .= ' ) ';
                    }
                }
                $search_array[] = self::runSearchQuery($qs, $parameters, $master_datatype_id);
                $search_performed = true;
            }
            else {
                $qs = $query_base;
                $qs .= ' AND ';
                self::addGeneralParameters($qs, $parameters, $params['general'], 0);
                $search_array[] = self::runSearchQuery($qs, $parameters, $master_datatype_id);
                $search_performed = true;
            }
        }

        if(!$search_performed) {
            $parameters = array();
            $parameters['datatype_id_array'] = array_unique($datatype_id_array);
            $parameters['now'] = $search_datetime;
            $search_array[] = self::runSearchQuery($query_base, $parameters, $master_datatype_id);
        }

        // print $qs; exit();
        // print var_export($search_array,true);exit();
        $result = [];
        if(count($search_array) == 1) {
            $result = $search_array[0];
        }
        else if(count($search_array) > 1) {
            $tmp_array = [];
            $base_array = $search_array[0];
            for($i=1; $i < count($search_array); $i++) {
                $result_array = $search_array[$i];
                foreach($result_array as $record) {
                    foreach($base_array as $base_record) {
                        if($base_record['unique_id'] == $record['unique_id']) {
                            array_push($tmp_array, $record);
                        }
                    }
                }
                $base_array = $tmp_array;
                $tmp_array = [];
            }
            $result = $base_array;
        }

        $records = array();
        foreach($result as $record_info) {
                // Attempt with the default UUID for this datatype
            $metadata_record = $this->cache_service
                ->get('json_record_' . $record_info['unique_id']);

            if(!$metadata_record) {
                // need to populate record using record builder
                $metadata_record = self::getRecordData(
                    'v3',
                    $record_info['unique_id'],
                    $baseurl,
                    'json',
                    true,
                    null,
                    false
                );
            }

            if($metadata_record) {
                array_push($records, json_decode($metadata_record, true));
            }
        }

        // Sort by Updated Date first
        /*
            "_record_metadata": {
                "_create_date": "2018-09-25 18:15:57",
                "_updated_date": "2019-08-05 07:58:19",
                "_create_auth": "Barbara Lafuente",
                "_public_date": "2018-09-25 20:11:45"
            },
         */

        $sort_array = [];
        $sort_dir = $params['sort_by']['0']['dir'];

        switch($params['sort_by']['0']['template_field_uuid']) {
            case 'create_date':
                $sort_type = SORT_NUMERIC;
                foreach($records as $record) {
                    $sort_array[strtotime($record['_record_metadata']['_create_date'])] = $record;
                }
                break;
            case 'updated_date':
                // Sort by updated
                $sort_type = SORT_NUMERIC;
                foreach($records as $record) {
                    $sort_array[strtotime($record['_record_metadata']['_updated_date'])] = $record;
                }
                break;
            case 'default':  // For Default passed as template_field_uuid
            case 'public_date':
                // Sort by public/release date
                $sort_type = SORT_NUMERIC;
            foreach($records as $record) {
                $sort_array[strtotime($record['_record_metadata']['_public_date'])] = $record;
            }
            break;
            default:
                $sort_type = SORT_STRING;
                // Template field ID
                // Sort by dataset name
                foreach($records as $record) {
                    if(isset($record['fields'])) {
                        foreach($record['fields'] as $field) {
                            if($field['template_field_uuid'] == $params['sort_by']['0']['template_field_uuid']) {
                                $sort_array[strtolower($field['value'])] = $record;
                            }
                        }
                    }
                }
                break;
        }

        // var_dump($records);exit();
        if($sort_dir === "asc") {
            ksort($sort_array, $sort_type);
        }
        else {
            // Reverse sort
            krsort($sort_array, $sort_type);
        }

        // Use splice to do limit & offset
        $records = array_values($sort_array);

        return $records;
    }

    function addGeneralParameters(&$qs, &$parameters, $term, $index) {
        $qs .= ' (MATCH(e_lt.value) AGAINST (:general_' . $index . ') > 0';
        $qs .= ' OR MATCH(e_lvc.value) AGAINST (:general_' . $index . ') > 0';
        $qs .= ' OR MATCH(e_mvc.value) AGAINST (:general_' . $index . ') > 0';
        $qs .= ' OR MATCH(e_svc.value) AGAINST (:general_' . $index . ') > 0';
        $qs .= ' OR MATCH(e_im.caption) AGAINST (:general_' . $index . ') > 0';
        $qs .= ' OR MATCH(e_im.originalFileName) AGAINST (:general_' . $index . ') > 0';
        $qs .= ' OR MATCH(e_ipm.caption) AGAINST (:general_' . $index . ') > 0';
        $qs .= ' OR MATCH(e_ipm.originalFileName) AGAINST (:general_' . $index . ') > 0';
        $qs .= ' OR MATCH(e_fm.description) AGAINST (:general_' . $index . ') > 0';
        $qs .= ' OR MATCH(e_fm.originalFileName) AGAINST (:general_' . $index . ') > 0';
        $qs .= ' OR MATCH(rom.optionName) AGAINST (:general_' . $index . ') > 0';
        $qs .= ' OR MATCH(tm.tagName) AGAINST (:general_' . $index . ') > 0';
        $qs .= ')';

        $parameters['general_' . $index] = $term;

        /*
        // Add General Search
        $qs .= ' (e_lt.value LIKE :general_' . $index;
        $qs .= ' OR e_lvc.value LIKE :general_' . $index;
        $qs .= ' OR e_mvc.value LIKE :general_' . $index;
        $qs .= ' OR e_svc.value LIKE :general_' . $index;
        $qs .= ' OR e_im.caption LIKE :general_' . $index;
        $qs .= ' OR e_im.originalFileName LIKE :general_' . $index;
        $qs .= ' OR e_ipm.caption LIKE :general_' . $index;
        $qs .= ' OR e_ipm.originalFileName LIKE :general_' . $index;
        $qs .= ' OR e_fm.description LIKE :general_' . $index;
        $qs .= ' OR e_fm.originalFileName LIKE :general_' . $index;
        $qs .= ' OR rom.optionName LIKE :general_' . $index;
        $qs .= ' OR tm.tagName LIKE :general_' . $index;
        $qs .= ')';

        $parameters['general_' . $index] = '%' . $term . '%';
        */
    }

    private function runSearchQuery($qs, $parameters, $master_datatype_id) {

        $query = $this->em->createQuery($qs);
        $query->setParameters($parameters);

        // print $query->getSQL(); exit();
        $result = $query->getArrayResult();
        // var_dump($result);exit();



        // Run the raw query
        $sql = '
        select 
            oldt_e.ancestor_id as e, 
            oldt_d.ancestor_id as d, 
            oldt_c.ancestor_id as c, 
            oldt_b.ancestor_id as b, 
            oldt_a.ancestor_id as a, 
            oldt_e.descendant_id as orig 
            from odr_linked_data_tree oldt_e
            left join odr_linked_data_tree oldt_d on oldt_d.descendant_id = oldt_e.ancestor_id
            left join odr_linked_data_tree oldt_c on oldt_c.descendant_id = oldt_d.ancestor_id
            left join odr_linked_data_tree oldt_b on oldt_b.descendant_id = oldt_c.ancestor_id
            left join odr_linked_data_tree oldt_a on oldt_a.descendant_id = oldt_b.ancestor_id
            where oldt_e.descendant_id IN (:record_ids)
        ';
        // $sql = 'select * from odr_linked_data_tree where descendant_id = 174430';
        $found_record_ids = array();
        foreach($result as $record) {
            array_push($found_record_ids,  $record['id']);
            // gets grandparent for child records
            array_push($found_record_ids,  $record['parent_id']);
            array_push($found_record_ids,  $record['grandparent_id']);
        }
        // print var_export($found_record_ids, true);exit();
        // print $sql; exit();
        $conn = $this->em->getConnection();
        $stmt = $conn->executeQuery(
            $sql,
            array('record_ids' => $found_record_ids),
            array('record_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
        );
        $result = $stmt->fetchAll();
        // var_dump($result);exit();

        $possible_records = array();
        foreach($result as $record) {
            if($record['a'] !== null) array_push($possible_records, $record['a']);
            if($record['b'] !== null) array_push($possible_records, $record['b']);
            if($record['c'] !== null) array_push($possible_records, $record['c']);
            if($record['d'] !== null) array_push($possible_records, $record['d']);
            if($record['e'] !== null) array_push($possible_records, $record['e']);
            if($record['orig'] !== null) array_push($possible_records, $record['orig']);
        }
        $possible_records = array_merge($found_record_ids, $possible_records);
        // var_dump(array_unique($possible_records));exit();

        // Get only the records that are top level
        $qs = 'SELECT
            distinct dr.unique_id, dr.id, mdt.id 

            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.dataRecordMeta AS drm
            
            LEFT JOIN dr.dataType AS dt
            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dt.masterDataType AS mdt
       
            WHERE
                dr.id IN (:possible_records)
                AND drm.publicDate <= :now
                AND mdt = :master_datatype_id
        ';

        $parameters = array();
        $parameters['possible_records'] = $possible_records;
        $parameters['now'] = new \DateTime();
        $parameters['master_datatype_id'] = $master_datatype_id;

        $query = $this->em->createQuery($qs);
        $query->setParameters($parameters);

        $result = $query->getArrayResult();

        return $result;

    }

    /**
     * @param $version
     * @param $datarecord_uuid - record uuid or record ID
     * @param $baseurl
     * @param $format
     * @param bool $display_metadata
     * @param null $user
     * @param bool $flush
     * @return array|bool|string
     */
    public function getRecordData(
        $version,
        $datarecord_uuid,
        $baseurl,
        $format,
        $display_metadata = false,
        $user = null,
        $flush = false
    ) {
        // ----------------------------------------

        // /** @var PermissionsManagementService $pm_service */
        // $pm_service = $this->container->get('odr.permissions_management_service');

        /** @var DataRecord $datarecord */
        $datarecord = $this->em
            ->getRepository('ODRAdminBundle:DataRecord')
            ->findOneBy(
                array('unique_id' => $datarecord_uuid)
            );

        if ($datarecord == null) {
            $datarecord = $this->em
                ->getRepository('ODRAdminBundle:DataRecord')
                ->findOneBy(
                    array('id' => $datarecord_uuid)
                );
        }

        if ($datarecord == null)
            throw new ODRNotFoundException('Datarecord');

        $datarecord_id = $datarecord->getId();
        $datarecord_uuid = $datarecord->getUniqueId();

        $datatype = $datarecord->getDataType();
        if (!$datatype || $datatype->getDeletedAt() != null)
            throw new ODRNotFoundException('Datatype');

        if ($datarecord->getId() != $datarecord->getGrandparent()->getId())
            throw new ODRBadRequestException('Only permitted on top-level datarecords');

        // ----------------------------------------
        // Determine user privileges
        /** @var ODRUser $user */
        $user = 'anon.';
        /*
        if ($user === null) {
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
        }
        */

        // If either the datatype or the datarecord is not public, and the user doesn't have
        //  the correct permissions...then don't allow them to view the datarecord
        /*
        if (!$pm_service->canViewDatatype($user, $datatype))
            throw new ODRForbiddenException();

        if (!$pm_service->canViewDatarecord($user, $datarecord))
            throw new ODRForbiddenException();
        */


        // TODO - system needs to delete these keys when record is updated elsewhere
        /** @var CacheService $cache_service */
        $data = $this->cache_service
            ->get('json_record_' . $datarecord_uuid);

        // $flush = true;
        if (!$data || $flush) {
            // Render the requested datarecord
            $data = $this->dre_service->getData(
                $version,
                array($datarecord_id),
                $format,
                $display_metadata,
                $user,
                $baseurl,
                0
            );

            // Cache this data for faster retrieval
            // TODO work out how to expire this data...
            /** @var CacheService $cache_service */
            $this->cache_service->set(
                'json_record_' . $datarecord_uuid,
                $data
            );
        }

        return $data;
    }


    /**
     * Runs a cross-template search specified by the given $search_key.  The end result is filtered
     * based on the user's permissions.
     *
     * @param string $search_key
     * @param array $user_permissions
     * @param bool $search_as_super_admin If true, bypass all permissions checking
     *
     * @return array
     */
    public function performTemplateSearch($search_key, $user_permissions, $search_as_super_admin = false)
    {
        throw new ODRException('Please use the regular SearchAPIService instead, this one does not return correct results');

        // ----------------------------------------
        // Unlike regular searching, this function doesn't need to filter the search key with the
        //  list of searchable datafields...that'll take place later on during the actual searching
        $criteria = $this->search_key_service->convertSearchKeyToTemplateCriteria($search_key);

        $template_uuid = $criteria['template_uuid'];
        unset( $criteria['template_uuid'] );

        // Extract sort information from the search key if it exists
        $sort_df_uuid = null;
        $sort_ascending = true;
        if ( isset($criteria['sort_by']) ) {
            $sort_df_uuid = $criteria['sort_by']['sort_df_uuid'];

            if ( $criteria['sort_by']['sort_dir'] === 'desc' )
                $sort_ascending = false;

            // Sort criteria extracted, get rid of it so the search isn't messed up
            unset( $criteria['sort_by'] );
        }


        // Need to grab hydrated versions of the datafields/datatypes being searched on
        $hydrated_entities = self::hydrateCriteria($criteria);

        // No longer need what type of search this is
        unset( $criteria['search_type'] );
        // ...or the list of all templates
        unset( $criteria['all_templates'] );

        // With regards to a cross-template search, the top-level datatypes are the ones derived
        //  from the template being searched on, which is typically a shorter list than the datatypes
        //  where their id equals their grandparent id...having these in a list makes the rest of the
        //  search routine easier to deal with
        $top_level_datatype_ids = array();
        foreach ($hydrated_entities['datatype'] as $dt_id => $dt) {
            /** @var DataType $dt */
            if ( $dt->getMasterDataType()->getUniqueId() === $template_uuid )
                $top_level_datatype_ids[] = $dt_id;
        }

        // ----------------------------------------
        // Convert the search key into a format suitable for searching
        $searchable_datafields = self::getSearchableDatafieldsForUser($top_level_datatype_ids, $user_permissions, $search_as_super_admin);

        // TODO - figure out a way to merge this section with hydrateCriteria()?
        // Each datatype being searched on (or the datatype of a datafield being search on) needs
        //  to be initialized to "-1" (does not match) before the results of each facet search
        //  are merged together into the final array
        $affected_datafields = array();
        foreach ($criteria as $dt_uuid => $dt_criteria) {
            // Datafields being searched via general search can't be marked as "-1" (needs to match)
            //  to begin with...doing so will typically cause child datatypes that are also searched
            //  to "not match", and therefore exclude their parents from the search results.
            // The final merge still works when the datarecords with the affected datafields start
            //  out with a value of "0" (doesn't matter)
            if ( $dt_criteria['merge_type'] === 'AND' ) {
                foreach ($dt_criteria['search_terms'] as $df_uuid => $df_criteria)
                    $affected_datafields[$df_uuid] = 1;
            }
        }
        $affected_datafields = array_keys($affected_datafields);

        // Need to get all datatypes that have the template datafields being searched on...
        $query = $this->em->createQuery(
           'SELECT dt.id AS dt_id
            FROM ODRAdminBundle:DataFields AS mdf
            JOIN ODRAdminBundle:DataType AS mdt WITH mdf.dataType = mdt
            JOIN ODRAdminBundle:DataType AS dt WITH dt.masterDataType = mdt
            WHERE mdf.fieldUuid IN (:field_uuids)
            AND mdf.deletedAt IS NULL AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters( array('field_uuids' => $affected_datafields) );
        $results = $query->getArrayResult();

        $affected_datatypes = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $affected_datatypes[$dt_id] = 1;
        }
        $affected_datatypes = array_keys($affected_datatypes);


        // ----------------------------------------
        // Get the base information needed so getSearchArrays() can properly setup the search arrays
        $search_permissions = self::getSearchPermissionsArray(
            $hydrated_entities['datatype'],
            $affected_datatypes,
            $user_permissions,
            $search_as_super_admin
        );

        // Going to need these two arrays to be able to accurately determine which datarecords
        //  end up matching the query
        $search_arrays = self::getSearchArrays($top_level_datatype_ids, $search_permissions);
        $flattened_list = $search_arrays['flattened'];
        $inflated_list = $search_arrays['inflated'];


        // An "empty" search run with no criteria needs to return all top-level datarecord ids
        $return_all_results = true;

        // Need to keep track of the result list for each facet separately...they end up merged
        //  together after all facets are searched on
        $facet_dr_list = array();
        foreach ($criteria as $facet => $facet_data) {
            // Don't return all top-level datarecord ids at the end
            $return_all_results = false;

            // Need to keep track of the matches for each facet individually
            $facet_dr_list[$facet] = null;
            $merge_type = $facet_data['merge_type'];
            $search_terms = $facet_data['search_terms'];

            // For each search term within this facet...
            foreach ($search_terms as $key => $search_term) {
                // ...extract the entity for this search term
                $entity_type = $search_term['entity_type'];
                $entity_id = $search_term['entity_id'];
                /** @var DataType|DataFields $entity */
                $entity = $hydrated_entities[$entity_type][$entity_id];

                // Run/load the desired query based on the criteria
                $results = array();
//                if ($key === 'created')
//                    $dr_list = $this->search_service->searchCreatedDate($entity, $search_term['before'], $search_term['after']);
//                else if ($key === 'createdBy')
//                    $dr_list = $this->search_service->searchCreatedBy($entity, $search_term['user']);
//                else if ($key === 'modified')
//                    $dr_list = $this->search_service->searchModifiedDate($entity, $search_term['before'], $search_term['after']);
//                else if ($key === 'modifiedBy')
//                    $dr_list = $this->search_service->searchModifiedBy($entity, $search_term['user']);
//                else if ($key === 'publicStatus')
//                    $dr_list = $this->search_service->searchPublicStatus($entity, $search_term['value']);
//                else {
                    // Datafield search depends on the typeclass of the field
                    $typeclass = $entity->getFieldType()->getTypeClass();

                    if ($typeclass === 'Boolean') {
                        // Only split from the text/number searches to avoid parameter confusion
                        $results = $this->search_service->searchBooleanTemplateDatafield($entity, $search_term['value']);
                    }
                    else if ($typeclass === 'Radio' && $facet === 'general') {
                        // General search only provides a string, and only wants selected radio options
                        $results = $this->search_service->searchForSelectedTemplateRadioOptions($entity, $search_term['value']);
                    }
                    else if ($typeclass === 'Radio' && $facet === 'field_stats') {
                        // The fieldstats controller action is only interested in which radio options
                        //  are selected and how many datarecords they're selected in...
                        $results = $this->search_service->searchTemplateRadioOptionFieldStats($entity);

                        // Apply the permissions filter and return without executing the rest of
                        //  the search routine
                        return self::getfieldstatsFilter($results['records'], $results['labels'], $searchable_datafields, $flattened_list);
                    }
                    else if ($typeclass === 'Radio') {
                        // The more specific version of searching a radio datafield provides an array of selected/deselected options
                        $results = $this->search_service->searchRadioTemplateDatafield($entity, $search_term['selections'], $search_term['combine_by_OR']);
                    }
                    else if ($typeclass === 'Tag' && $facet === 'general') {
                        // General search only provides a string, and only wants selected tags
                        $results = $this->search_service->searchForSelectedTemplateTags($entity, $search_term['value']);
                    }
                    else if ($typeclass === 'Tag' && $facet === 'field_stats') {
                        // The fieldstats controller action is only interested in which tags are
                        //  selected and how many datarecords they're selected in...
                        $results = $this->search_service->searchTemplateTagFieldStats($entity);

                        // Apply the permissions filter and return without executing the rest of
                        //  the search routine
                        return self::getfieldstatsFilter($results['records'], $results['labels'], $searchable_datafields, $flattened_list);
                    }
                    else if ($typeclass === 'Tag') {
                        // The more specific version of searching a tag datafield provides an array of selected/deselected options
                        $results = $this->search_service->searchTagTemplateDatafield($entity, $search_term['selections'], $search_term['combine_by_OR']);
                    }
                    else if ($typeclass === 'File' || $typeclass === 'Image') {
                        // TODO - implement searching based on public status of file/image?
                        // Searches on Files/Images are effectively interchangable
                        $results = $this->search_service->searchFileOrImageTemplateDatafield($entity, $search_term['filename'], $search_term['has_files']);
                    }
                    else if ($typeclass === 'DatetimeValue') {
                        // DatetimeValue needs to worry about before/after...
                        $results = $this->search_service->searchDatetimeTemplateDatafield($entity, $search_term['before'], $search_term['after']);
                    }
                    else {
                        // Short/Medium/LongVarchar, Paragraph Text, and Integer/DecimalValue
                        $results = $this->search_service->searchTextOrNumberTemplateDatafield($entity, $search_term['value']);
                    }
//                }


                // ----------------------------------------
                // Filter out the results from $dr_list that are from datatypes/datafields the user
                //  isn't allowed to see...
                $tmp_dr_list = array();
                foreach ($results as $dt_id => $df_list) {
                    if ( isset($searchable_datafields[$dt_id]) ) {
                        foreach ($df_list as $df_id => $dr_list) {
                            if ( isset($searchable_datafields[$dt_id][$df_id]) ) {
                                foreach ($dr_list as $dr_id => $num)
                                    $tmp_dr_list[$dr_id] = 1;
                            }
                        }
                    }
                }
                // ...after the filtering is done, we only care about the datarecord ids
                $dr_list = array(
                    'records' => $tmp_dr_list
                );


                // Need to merge this result with the existing matches for this facet
                if ($merge_type === 'OR') {
                    if ( is_null($facet_dr_list[$facet]) )
                        $facet_dr_list[$facet] = array();

                    // Merging by 'OR' criteria...every datarecord returned from the search matches
                    foreach ($dr_list['records'] as $dr_id => $num)
                        $facet_dr_list[$facet][$dr_id] = $num;
                }
                else {
                    // Merging by 'AND' criteria...if this is the first (or only) criteria...
                    if ( is_null($facet_dr_list[$facet]) ) {
                        // ...use the datarecord list returned by the first search
                        $facet_dr_list[$facet] = $dr_list['records'];
                    }
                    else {
                        // Otherwise, intersect the list returned by the search with the existing list
                        $facet_dr_list[$facet] = array_intersect_key($facet_dr_list[$facet], $dr_list['records']);
                    }
                }
            }
        }


        // ----------------------------------------
        // In most cases, there will be a number of different datarecord lists by this point...
        if (!$return_all_results) {
            // Perform the final merge, getting all facets down into a single list of matching datarecords
            $final_dr_list = null;
            foreach ($facet_dr_list as $facet => $dr_list) {
                if (is_null($final_dr_list))
                    $final_dr_list = $dr_list;
                else
                    $final_dr_list = array_intersect_key($final_dr_list, $dr_list);
            }

            // Need to transfer the values from $facet_dr_list into $flattened_list...
            if (!is_null($final_dr_list)) {
                foreach ($final_dr_list as $dr_id => $num) {
                    // ...but only if they're not excluded because of public status
                    if ( isset($flattened_list[$dr_id]) && $flattened_list[$dr_id] >= -1 )
                        $flattened_list[$dr_id] = 1;
                }
            }
            else if (count($criteria) === 0) {
                // If a search was run without criteria, then everything that the user can see
                //  matches the search
                foreach ($flattened_list as $dr_id => $num) {
                    if ($num >= -1)
                        $flattened_list[$dr_id] = 1;
                }
            }
        }
        else {
            // ...but when no search criteria was specified, then every datarecord that the user
            // can see needs to be marked as "matching" the search
            foreach ($flattened_list as $dr_id => $num) {
                if ($num >= -1)
                    $flattened_list[$dr_id] = 1;
            }
        }


        // ----------------------------------------
        // Need to transfer the values from $flattened_list into the tree structure of $inflated_list
        self::mergeSearchArrays($flattened_list, $inflated_list);

        // Traverse $inflated_list to get the final set of datarecords that match the search
        $datarecord_ids = self::getMatchingDatarecords($flattened_list, $inflated_list);
        $datarecord_ids = array_keys($datarecord_ids);

        // Traverse the top-level of $inflated_list to get the grandparent datarecords that match
        //  the search
        $grandparent_ids = array();
        foreach ($inflated_list as $dt_id => $dr_list) {
            foreach ($dr_list as $gp_id => $something) {
                if ($flattened_list[$gp_id] == 1)
                    $grandparent_ids[] = $gp_id;
            }
        }


        // Sort the resulting array
        $sorted_datarecord_list = array();
        if ( !is_null($sort_df_uuid) ) {
            $sorted_datarecord_list = $this->sort_service->sortDatarecordsByTemplateDatafield($sort_df_uuid, $sort_ascending, implode(',', $grandparent_ids));

            // Convert from ($dr_id => $sort_value) into ($num => $dr_id)
            $sorted_datarecord_list = array_keys($sorted_datarecord_list);
        }
        else {
            // list is already in ($num => $dr_id) format
            $sorted_datarecord_list = $grandparent_ids;
        }


        // ----------------------------------------
        // Save/return the end result
        $search_result = array(
            'complete_datarecord_list' => $datarecord_ids,
            'grandparent_datarecord_list' => $sorted_datarecord_list,
        );

        // There's not really any need or point to caching the end result
        return $search_result;
    }


    /**
     * APIController::getfieldstatsAction() needs to return a count of how many datarecords have
     * a specific radio option or tag selected across all instances of a template datafield.  This
     * function filters the raw search results by the user's permissions before the controller
     * action gets it.
     *
     * @param array $records
     * @parm array labels
     * @param array $searchable_datafields @see self::getSearchableDatafieldsForUser()
     * @param array $flattened_list @see self::getSearchArrays()
     *
     * @return array
     */
    private function getfieldstatsFilter($records, $labels, $searchable_datafields, $flattened_list)
    {
        foreach ($records as $dt_id => $df_list) {
            // Filter out datatypes the user can't see...
            if ( !isset($searchable_datafields[$dt_id]) ) {
                unset( $records[$dt_id] );
            }
            else {
                foreach ($df_list as $df_id => $dr_list) {
                    // Filter out datafields the user can't see...
                    if ( !isset($searchable_datafields[$dt_id][$df_id]) ) {
                        unset( $records[$dt_id][$df_id] );
                    }
                    else {
                        // Filter out non-public datarecords the user can't see
                        foreach ($dr_list as $dr_id => $ro_list) {
                            if ( $flattened_list[$dr_id] === -2 ) {
                                unset( $records[$dt_id][$df_id][$dr_id] );
                            }
                        }
                    }
                }
            }
        }

        // Return the filtered list back to the APIController
        return array(
            'labels' => $labels,
            'records' => $records
        );
    }


    /**
     * Runs a search specified by the given $search_key.  The contents of the search key are
     * silently tweaked based on the user's permissions.
     *
     * @param DataType $datatype
     * @param string $search_key
     * @param array $user_permissions     The permissions of the user doing the search, or an empty
     *                                    array when not logged in
     * @param int $sort_df_id             The id of the datafield to sort by, or 0 to sort by
     *                                    whatever is default for the datatype
     * @param bool $sort_ascending        If true, sort ascending...if false, sort descending
     *                                    instead
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     *
     * @return array
     */
    public function performSearch($datatype, $search_key, $user_permissions, $sort_df_id = 0, $sort_ascending = true, $search_as_super_admin = false)
    {
        throw new ODRException('Please use the regular SearchAPIService instead, this one does not return correct results');

        // ----------------------------------------
        // This really shouldn't be null, but just in case...
        if ( is_null($datatype) ) {
            $search_params = $this->search_key_service->decodeSearchKey($search_key);
            $datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find( $search_params['dt_id'] );
        }


        // ----------------------------------------
        // Convert the search key into a format suitable for searching
        $searchable_datafields = self::getSearchableDatafieldsForUser(array($datatype->getId()), $user_permissions, $search_as_super_admin);
        $criteria = $this->search_key_service->convertSearchKeyToCriteria($search_key, $searchable_datafields, $user_permissions, $search_as_super_admin);

        // Need to grab hydrated versions of the datafields/datatypes being searched on
        $hydrated_entities = self::hydrateCriteria($criteria);

        // Each datatype being searched on (or the datatype of a datafield being search on) needs
        //  to be initialized to "-1" (does not match) before the results of each facet search
        //  are merged together into the final array
        $affected_datatypes = $criteria['affected_datatypes'];
        unset( $criteria['affected_datatypes'] );

        // Also don't want the list of all datatypes anymore either
        unset( $criteria['all_datatypes'] );
        // ...or what type of search this is
        unset( $criteria['search_type'] );


        // ----------------------------------------
        // Get the base information needed so getSearchArrays() can properly setup the search arrays
        $search_permissions = self::getSearchPermissionsArray($hydrated_entities['datatype'], $affected_datatypes, $user_permissions, $search_as_super_admin);

        // Going to need three arrays so mergeSearchResults() can correctly determine which records
        //  end up matching the search
        $search_arrays = self::getSearchArrays( array($datatype->getId()), $search_permissions );
        $flattened_list = $search_arrays['flattened'];
        $inflated_list = $search_arrays['inflated'];
        $search_datatree = $search_arrays['search_datatree'];

        // An "empty" search run with no criteria needs to return all top-level datarecord ids
        $return_all_results = true;

        // Need to keep track of the result list for each facet separately...they end up merged
        //  together after all facets are searched on
        $facet_dr_list = array();
        foreach ($criteria as $dt_id => $facet_list) {
            // Need to keep track of the matches for each datatype individually...
            $facet_dr_list[$dt_id] = array();

            foreach ($facet_list as $facet_num => $facet) {
                // ...and also keep track of the matches for each facet within this datatype individually
                $facet_dr_list[$dt_id][$facet_num] = null;

                $facet_type = $facet['facet_type'];
                $merge_type = $facet['merge_type'];
                $search_terms = $facet['search_terms'];

                // For each search term within this facet...
                foreach ($search_terms as $key => $search_term) {
                    // Don't return all top-level datarecord ids at the end
                    $return_all_results = false;

                    // ...extract the entity for this search term
                    $entity_type = $search_term['entity_type'];
                    $entity_id = $search_term['entity_id'];
                    /** @var DataType|DataFields $entity */
                    $entity = $hydrated_entities[$entity_type][$entity_id];

                    // Run/load the desired query based on the criteria
                    $dr_list = array();
                    if ($key === 'created')
                        $dr_list = $this->search_service->searchCreatedDate($entity, $search_term['before'], $search_term['after']);
                    else if ($key === 'createdBy')
                        $dr_list = $this->search_service->searchCreatedBy($entity, $search_term['user']);
                    else if ($key === 'modified')
                        $dr_list = $this->search_service->searchModifiedDate($entity, $search_term['before'], $search_term['after']);
                    else if ($key === 'modifiedBy')
                        $dr_list = $this->search_service->searchModifiedBy($entity, $search_term['user']);
                    else if ($key === 'publicStatus')
                        $dr_list = $this->search_service->searchPublicStatus($entity, $search_term['value']);
                    else if ( isset($hydrated_entities['renderPlugin'][$entity_id]) ) {
                        // The render plugin is already loaded, stored by the id of the datafield
                        //  that is using it
                        $tmp = $hydrated_entities['renderPlugin'][$entity_id];
                        /** @var SearchPluginInterface $rp */
                        $rp = $tmp['renderPlugin'];
                        $rpo = $tmp['renderPluginOptions'];

                        // The plugin will return the same format that the regular searches do
                        $dr_list = $rp->searchPluginField($entity, $search_term, $rpo);
                    }
                    else {
                        // Datafield search depends on the typeclass of the field
                        $typeclass = $entity->getFieldType()->getTypeClass();

                        if ($typeclass === 'Boolean') {
                            // Only split from the text/number searches to avoid parameter confusion
                            $dr_list = $this->search_service->searchBooleanDatafield($entity, $search_term['value']);
                        }
                        else if ($typeclass === 'Radio' && $facet_type === 'general') {
                            // General search only provides a string, and only wants selected radio options
                            $dr_list = $this->search_service->searchForSelectedRadioOptions($entity, $search_term['value']);
                        }
                        else if ($typeclass === 'Radio' && $facet_type !== 'general') {
                            // The more specific version of searching a radio datafield provides an array of selected/deselected options
                            $dr_list = $this->search_service->searchRadioDatafield($entity, $search_term['selections'], $search_term['combine_by_OR']);
                        }
                        else if ($typeclass === 'Tag' && $facet_type === 'general') {
                            // General search only provides a string, and only wants selected tags
                            $dr_list = $this->search_service->searchForSelectedTags($entity, $search_term['value']);
                        }
                        else if ($typeclass === 'Tag' && $facet_type !== 'general') {
                            // The more specific version of searching a tag datafield provides an array of selected/deselected options
                            $dr_list = $this->search_service->searchTagDatafield($entity, $search_term['selections'], $search_term['combine_by_OR']);
                        }
                        else if ($typeclass === 'File' || $typeclass === 'Image') {
                            // TODO - implement searching based on public status of file/image?
                            // Searches on Files/Images are effectively interchangable
                            $dr_list = $this->search_service->searchFileOrImageDatafield($entity, $search_term['filename'], $search_term['has_files']);
                        }
                        else if ($typeclass === 'DatetimeValue') {
                            // DatetimeValue needs to worry about before/after...
                            $dr_list = $this->search_service->searchDatetimeDatafield($entity, $search_term['before'], $search_term['after']);
                        }
                        else {
                            // Short/Medium/LongVarchar, Paragraph Text, and Integer/DecimalValue
                            $dr_list = $this->search_service->searchTextOrNumberDatafield($entity, $search_term['value']);
                        }
                    }


                    // ----------------------------------------
                    // Need to merge this result with the existing matches for this facet
                    if ($merge_type === 'OR') {
                        if ( is_null($facet_dr_list[$dt_id][$facet_num]) )
                            $facet_dr_list[$dt_id][$facet_num] = array();

                        // When merging by 'OR', every datarecord returned by the SearchService
                        //  functions ends up matching
                        foreach ($dr_list['records'] as $dr_id => $num)
                            $facet_dr_list[$dt_id][$facet_num][$dr_id] = $num;
                    }
                    else {
                        // When merging by 'AND'...if this is the first (or only) facet of criteria...
                        if ( is_null($facet_dr_list[$dt_id][$facet_num]) ) {
                            // ...use the datarecord list returned by the first SearchService call
                            $facet_dr_list[$dt_id][$facet_num] = $dr_list['records'];
                        }
                        else {
                            // ...otherwise, intersect the list returned by the search with the
                            //  currently stored list
                            $facet_dr_list[$dt_id][$facet_num] = array_intersect_key($facet_dr_list[$dt_id][$facet_num], $dr_list['records']);
                        }
                    }
                }
            }
        }


        // ----------------------------------------
        // Now that the individual search queries have been run...
        if ( $return_all_results ) {
            // When no search criteria is specified, then every datarecord that the user can see
            //  needs to be marked as "matching" the search
            foreach ($flattened_list as $dr_id => $num) {
                if ( !($num & SearchAPIService::CANT_VIEW) )
                    $flattened_list[$dr_id] |= SearchAPIService::MATCHES_BOTH;
            }
        }
        else {
            // Determine whether a 'general' search was executed...
            $was_general_seach = false;
            if ( isset($facet_dr_list['general']) )
                $was_general_seach = true;

            // Determine whether an 'advanced' search was executed...
            $was_advanced_search = false;
            foreach ($facet_dr_list as $key => $facet_list) {
                // ...then just need to save that an advanced search was run
                if ( is_numeric($key) && !empty($facet_list) ) {
                    $was_advanced_search = true;
                    break;
                }
            }

            // ...if both types of searches were executed, then the merging algorithm needs to
            //  separately track which type of search each record matched (they could match both too)
            $differentiate_search_types = $was_general_seach && $was_advanced_search;
            self::mergeSearchResults($criteria, true, $datatype->getId(), $search_datatree[$datatype->getId()], $facet_dr_list, $flattened_list, $differentiate_search_types);
        }


        // ----------------------------------------
        // If the user needs a list of datarecords that includes child/linked descendants...
        if ( $return_complete_list ) {
            // ...then traverse $inflated_list to get the final set of datarecords that match the search
            $datarecord_ids = self::getMatchingDatarecords($flattened_list, $inflated_list);
            $datarecord_ids = array_keys($datarecord_ids);

            // There's no correct method to sort this list, so might as well return immediately
            return $datarecord_ids;
        }


        // Otherwise, the user only wanted a list of the grandparent datarecords that matched the
        //  search...can traverse the top-level of $inflated list for that
        $grandparent_ids = array();
        if ( isset($inflated_list[$datatype->getId()]) ) {
            foreach ($inflated_list[$datatype->getId()] as $gp_id => $data) {
                if ( ($flattened_list[$gp_id] & SearchAPIService::MATCHES_BOTH) === SearchAPIService::MATCHES_BOTH )
                    $grandparent_ids[] = $gp_id;
            }
        }


        // Sort the resulting array if any results were found
        $sorted_datarecord_list = array();
        if ( !empty($grandparent_ids) ) {
            $source_dt_id = $datatype->getId();
            $grandparent_ids_for_sorting = implode(',', $grandparent_ids);

            // Want to use SortService::getSortedDatarecordList() unless the provided sort datafields
            //  or directions are different from the datatype's default sort order
            $has_sortfields = false;
            $is_default_sort_order = true;
            foreach ($sort_directions as $num => $dir) {
                if ( $dir !== 'asc' )
                    $is_default_sort_order = false;
            }
            foreach ($datatype->getSortFields() as $display_order => $df) {
                $has_sortfields = true;
                if ( !isset($sort_datafields[$display_order]) || $df->getId() !== $sort_datafields[$display_order] )
                    $is_default_sort_order = false;
            }
            if ( $has_sortfields && $is_default_sort_order )
                $sort_datafields = $sort_directions = array();

            // ----------------------------------------
            if ( empty($sort_datafields) ) {
                // No sort datafields defined for this request, use the datatype's default ordering
                $sorted_datarecord_list = $this->sort_service->getSortedDatarecordList($source_dt_id, $grandparent_ids_for_sorting);
            }
            else if ( count($sort_datafields) === 1 ) {
                // If the user wants to only use one datafield for sorting, then it's better to call
                //  the relevant functions in SortService directly
                $sort_df_id = $sort_datafields[0];
                $sort_dir = $sort_directions[0];

                if ( isset($searchable_datafields[$source_dt_id][$sort_df_id]) ) {
                    // The sort datafield belongs to the datatype being searched on
                    $sorted_datarecord_list = $this->sort_service->sortDatarecordsByDatafield($sort_df_id, $sort_dir, $grandparent_ids_for_sorting);
                }
                else {
                    // The sort datafield belongs to some linked datatype TODO - ...or child, eventually?
                    $sorted_datarecord_list = $this->sort_service->sortDatarecordsByLinkedDatafield($source_dt_id, $sort_df_id, $sort_dir, $grandparent_ids_for_sorting);
                }
            }
            else {
                // If more than one datafield is needed for sorting, then multisort has to be used
                $linked_datafields = array();
                $numeric_datafields = array();

                foreach ($sort_datafields as $display_order => $sort_df_id) {
                    // It's easier to determine whether this is a linked field or not here instead
                    //  of inside the multisort function
                    if ( isset($searchable_datafields[$source_dt_id][$sort_df_id]) )
                        $linked_datafields[$display_order] = false;
                    else
                        $linked_datafields[$display_order] = true;

                    // Same deal with whether the datafield is an integer/decimal field or not
                    foreach ($searchable_datafields as $sort_df_dt_id => $fields) {
                        // The field may not belong to $source_dt_id...
                        if ( isset($fields[$sort_df_id]) ) {
                            $typeclass = $fields[$sort_df_id]['typeclass'];
                            if ( $typeclass === 'IntegerValue' || $typeclass === 'DecimalValue' )
                                $numeric_datafields[$display_order] = true;
                            else
                                $numeric_datafields[$display_order] = false;

                            // Don't continue looking for this field
                            break;
                        }
                    }
                }

                $sorted_datarecord_list = $this->sort_service->multisortDatarecordList($source_dt_id, $sort_datafields, $sort_directions, $linked_datafields, $numeric_datafields, $grandparent_ids_for_sorting);
            }

            // Convert from ($dr_id => $sort_value) into ($num => $dr_id)
            $sorted_datarecord_list = array_keys($sorted_datarecord_list);
        }


        // ----------------------------------------
        // There's no point to caching the end result...it depends heavily on the user's permissions
        return $sorted_datarecord_list;
    }

    /**
     * Extracts all datafield/datatype entities listed in $criteria, and returns them as hydrated
     * objects in an array.
     *
     * @param array $criteria
     *
     * @return array
     */
    private function hydrateCriteria($criteria)
    {
        // ----------------------------------------
        // Searching is *just* different enough between datatypes and templates to be a pain...
        $search_type = $criteria['search_type'];
        unset( $criteria['search_type'] );

        // Want to find all datafield entities listed in the criteria array
        $datafield_ids = array();
        foreach ($criteria as $facet => $data) {
            // Only bother with keys that have search data
            if ( isset($data['search_terms']) ) {
                foreach ($data['search_terms'] as $key => $search_params) {
                    // Extract the entity from the criteria array
                    $entity_type = $search_params['entity_type'];
                    $entity_id = $search_params['entity_id'];

                    if ($entity_type === 'datafield')
                        $datafield_ids[$entity_id] = 1;
                }
            }
        }
        $datafield_ids = array_keys($datafield_ids);


        // ----------------------------------------
        // Need to hydrate all of the datafields/datatypes so the search functions work
        $datafields = array();
        if ( !empty($datafield_ids) )
            $datafields = self::hydrateDatafields($search_type, $datafield_ids);


        // Because of permissions, need to hydrate all datatypes...
        $datatypes = array();
        if ( $search_type === 'datatype' )
            $datatypes = self::hydrateDatatypes($search_type, $criteria['all_datatypes']);
        else
            $datatypes = self::hydrateDatatypes($search_type, $criteria['all_templates']);


        // ----------------------------------------
        // Return the hydrated arrays
        return array(
            'datafield' => $datafields,
            'datatype' => $datatypes
        );
    }


    /**
     * The hydration requirements are slightly different between "regular" searches and "template"
     * searches...
     *
     * TODO - is hydration even required, technically?
     *
     * @param string $search_type
     * @param array $datafield_ids
     *
     * @return DataFields[]
     */
    private function hydrateDatafields($search_type, $datafield_ids)
    {
        $datafields = array();

        if ($search_type === 'datatype') {
            // For a regular search, need to hydrate all datafields being searched on
            $params = array(
                'datafield_ids' => $datafield_ids
            );

            $query = $this->em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                WHERE df.id IN (:datafield_ids)
                AND df.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters($params);
            $results = $query->getResult();

            /** @var DataFields[] $results */
            foreach ($results as $df)
                $datafields[ $df->getId() ] = $df;
        }
        else {
            // For a template search, only want to hydrate the master template fields...otherwise
            //  we would have to hydrate every single datafield that uses the searched template
            //  fields as their master datafields.
            // Not really a good idea, especially since the actual searching functions can just
            //  have the database queries return a datafield id for permissions purposes
            $params = array(
                'field_uuids' => $datafield_ids
            );

            $query = $this->em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                WHERE df.fieldUuid IN (:field_uuids) AND df.is_master_field = 1
                AND df.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters($params);
            $results = $query->getResult();

            /** @var DataFields[] $results */
            foreach ($results as $df)
                $datafields[ $df->getFieldUuid() ] = $df;
        }

        return $datafields;
    }


    /**
     * They hydration requirements are slightly different between "regular" searches and "template"
     * searches...
     *
     * TODO - is hydration even required, technically?
     *
     * @param string $search_type
     * @param int[]|string $datatype_ids
     *
     * @return DataType[]
     */
    private function hydrateDatatypes($search_type, $datatype_ids)
    {
        $results = array();
        if ($search_type === 'datatype') {
            // For a regular search, need to hydrate all datatypes that could be searched on
            // Otherwise, we can't deal with permissions properly
            $params = array(
                'datatype_ids' => $datatype_ids
            );

            $query = $this->em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataType AS dt
                WHERE dt.id IN (:datatype_ids)
                AND dt.deletedAt IS NULL'
            )->setParameters($params);
            $results = $query->getResult();
        }
        else {
            // For a template search, we still need to hydrate all the non-template datatypes that
            //  are being searched on...otherwise, we can't deal with permissions properly
            $params = array(
                'template_uuids' => $datatype_ids
            );

            $query = $this->em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataType AS mdt
                JOIN ODRAdminBundle:DataType AS dt WITH dt.masterDataType = mdt
                JOIN ODRAdminBundle:DataType AS gp WITH dt.grandparent = gp
                WHERE mdt.unique_id IN (:template_uuids)
                AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL AND gp.deletedAt IS NULL'
            )->setParameters($params);
            $results = $query->getResult();
        }

        /** @var DataType[] $results */
        $datatypes = array();
        foreach ($results as $dt)
            $datatypes[ $dt->getId() ] = $dt;

        return $datatypes;
    }


    /**
     * It's easier for performSearch() when getSearchArrays() returns arrays that already contain
     * the user's permissions and which datatypes are being searched on...this utility function
     * gathers that required info in a single spot.
     *
     * @param DataType[] $hydrated_datatypes
     * @param int[] $affected_datatypes @see SearchKeyService::convertSearchKeyToCriteria()
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     *
     * @return array
     */
    private function getSearchPermissionsArray($hydrated_datatypes, $affected_datatypes, $user_permissions, $search_as_super_admin = false)
    {
        // Going to need to filter based on the user's permissions
        $datatype_permissions = array();
        if ( isset($user_permissions['datatypes']) )
            $datatype_permissions = $user_permissions['datatypes'];

        $search_permissions = array();
        foreach ($hydrated_datatypes as $dt_id => $dt) {
            // User needs to be able to view the datatype in order for them to search on it...
            $can_view_datatype = false;
            if ($search_as_super_admin)
                $can_view_datatype = true;
            else if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dt_view']) )
                $can_view_datatype = true;
            else if ($dt->isPublic())
                $can_view_datatype = true;


            if ( !$can_view_datatype ) {
                // If the user can't view this datatype, then there's no point checking other
                //  permissions or gathering various lists of datarecords
                $search_permissions[$dt_id] = array(
                    'can_view_datatype' => $can_view_datatype
                );
            }
            else {
                // If user can't view non-public datarecords, then need to get a list of them so
                //  they can be properly excluded from the search results
                $can_view_datarecord = false;
                if ($search_as_super_admin)
                    $can_view_datarecord = true;
                else if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dr_view']) )
                    $can_view_datarecord = true;


                $non_public_datarecords = array();
                if (!$can_view_datarecord) {
                    $ret = $this->search_service->searchPublicStatus($dt, false);
                    $non_public_datarecords = $ret['records'];
                }

                $search_permissions[$dt_id] = array(
                    'datatype' => $dt,
                    'can_view_datatype' => $can_view_datatype,
                    'can_view_datarecord' => $can_view_datarecord,
                    'non_public_datarecords' => $non_public_datarecords,
                );

                // Also, store whether this datatype is being searched on
                if (in_array($dt_id, $affected_datatypes))
                    $search_permissions[$dt_id]['affected'] = true;
                else
                    $search_permissions[$dt_id]['affected'] = false;
            }
        }

        return $search_permissions;
    }


    /**
     * Returns two arrays that are required for determining which datarecords match a search.
     * Technically a search run on a top-level datatype doesn't need all this, but any search that
     * involves a child/linked datatype does.
     *
     * The first array contains <datarecord_id> => <num> pairs, where num is one of four values...
     *  - num == -2 -- this datarecord is excluded because the user can't view it
     *  - num == -1 -- this datarecord is currently excluded...it must match the search being run
     *  - num ==  0 -- this datarecord is not being searched on, or part of a general search
     *  - num ==  1 -- set in performSearch(), indicates this datarecord matches the search being run
     * For more detailed explanation, @see self::mergeSearchArrays_worker()
     *
     * This "flattened" array is used so performSearch() doesn't have to deal with recursion while
     *  collating search results.
     *
     * The second array is an "inflated" version of all datarecords that could potentially match
     * a search being run on $top_level_datatype_id, assuming the user has permissions to see
     * everything.  This array is recursively traversed by mergeSearchArrays() to determine all the
     * datarecords which matched the search.
     *
     * @param int[] $top_level_datatype_ids
     * @param array $permissions_array @see self::getSearchPermissionsArray()
     *
     * @return array
     */
    private function getSearchArrays($top_level_datatype_ids, $permissions_array)
    {
        // ----------------------------------------
        // Intentionally not caching the results of this function for two reasons
        // 1) these arrays need to be initialized based on the search being run, and the
        //     permissions of the user running the search
        // 2) these arrays contain ids of datarecords across all datatypes related to the datatype
        //     being searched on...determining when to clear this entry, especially when linked
        //     datatypes are involved, would be nightmarish


        // ----------------------------------------
        // In order to properly build the search arrays, all child/linked datatypes with some
        //  connection to this datatype need to be located first
        $datatree_array = $this->dti_service->getDatatreeArray();

        // Base setup for both arrays...
        $flattened_list = array();
        $inflated_list = array(0 => array());
        foreach ($top_level_datatype_ids as $num => $dt_id)
            $inflated_list[0][$dt_id] = array();

        // Flip this array so isset() can be used instead of in_array() later on
        $top_level_datatype_ids = array_flip($top_level_datatype_ids);


        // ----------------------------------------
        foreach ($permissions_array as $dt_id => $permissions) {
            // Ensure that the user is allowed to view this datatype before doing anything with it
            if ( !$permissions['can_view_datatype'] )
                continue;


            // If the datatype is linked...then the backend query to rebuild the cache entry is
            //  different, as is the insertion of the resulting datarecords into the "inflated" list
            $is_linked_type = false;
            if ( isset($datatree_array['linked_from'][$dt_id]) )
                $is_linked_type = true;

            // If this is the datatype being searched on (or one of the datatypes directly derived
            //  from the template being searched on), then $is_linked_type needs to be false, so
            //  getCachedSearchDatarecordList() will return all datarecords...otherwise, it'll only
            //  return those that are linked to from somewhere (which is usually desired for when
            //  searching a linked datatype)
            if ( isset($top_level_datatype_ids[$dt_id]) )
                $is_linked_type = false;

            // Attempt to load this datatype's datarecords and their parents from the cache...
            $list = $this->search_service->getCachedSearchDatarecordList($dt_id, $is_linked_type);


            // Storing the datarecord ids in the flattened list is easy...
            foreach ($list as $dr_id => $value) {
                if ( isset($permissions['non_public_datarecords'][$dr_id]) )
                    $flattened_list[$dr_id] = -2;
                else if ( $permissions['affected'] === true )
                    $flattened_list[$dr_id] = -1;
                else
                    $flattened_list[$dr_id] = 0;
            }


            // Inserting into $inflated_list depends on what type of datatype this is...
            // @see self::buildDatarecordTree() for the eventual structure
            if ( isset($top_level_datatype_ids[$dt_id]) ) {
                // These are top-level datarecords for a top-level datatype...the 0 is in there
                //  to make recursion in buildDatarecordTree() easier
                foreach ($list as $dr_id => $value)
                    $inflated_list[0][$dt_id][$dr_id] = '';
            }
            else if (!$is_linked_type) {
                // These datarecords are for a child datatype
                foreach ($list as $dr_id => $parent_dr_id) {
                    if ( !isset($inflated_list[$parent_dr_id]) )
                        $inflated_list[$parent_dr_id] = array();
                    if ( !isset($inflated_list[$parent_dr_id][$dt_id]) )
                        $inflated_list[$parent_dr_id][$dt_id] = array();

                    $inflated_list[$parent_dr_id][$dt_id][$dr_id] = '';
                }
            }
            else {
                // These datarecords are for a linked datatype
                foreach ($list as $dr_id => $parents) {
                    foreach ($parents as $parent_dr_id => $value) {
                        if ( !isset($inflated_list[$parent_dr_id]) )
                            $inflated_list[$parent_dr_id] = array();
                        if ( !isset($inflated_list[$parent_dr_id][$dt_id]) )
                            $inflated_list[$parent_dr_id][$dt_id] = array();

                        $inflated_list[$parent_dr_id][$dt_id][$dr_id] = '';
                    }
                }
            }
        }


        // ----------------------------------------
        // Sort the flattened list for easier debugging
        ksort($flattened_list);

        // Actually inflate the "inflated" list...
        $inflated_list = self::buildDatarecordTree($inflated_list, 0);

        // ...and then return the end result
        return array(
            'flattened' => $flattened_list,
            'inflated' => $inflated_list,
        );
    }


    /**
     * Turns the originally flattened $descendants_of_datarecord array into a recursive tree
     *  structure of the form...
     *
     * parent_datarecord_id => array(
     *     child_datatype_1_id => array(
     *         child_datarecord_1_id of child_datatype_1 => '',
     *         child_datarecord_2_id of child_datatype_1 => '',
     *         ...
     *     ),
     *     child_datatype_2_id => array(
     *         child_datarecord_1_id of child_datatype_2 => '',
     *         child_datarecord_2_id of child_datatype_2 => '',
     *         ...
     *     ),
     *     ...
     * )
     *
     * If child_datarecord_X_id has children of its own, then it is also a parent datarecord, and
     *  it points to another recursive tree structure of this type instead of an empty string.
     * Linked datatypes/datarecords are handled identically to child datatypes/datarecords.
     *
     * The tree's root looks like...
     *
     * 0 => array(
     *     target_datatype_id => array(
     *         top_level_datarecord_1_id => ...
     *         top_level_datarecord_2_id => ...
     *         ...
     *     )
     * )
     *
     * @param array $descendants_of_datarecord
     * @param string|integer $current_datarecord_id
     *
     * @return string|array
     */
    private function buildDatarecordTree($descendants_of_datarecord, $current_datarecord_id)
    {
        if ( !isset($descendants_of_datarecord[$current_datarecord_id]) ) {
            // $current_datarecord_id has no children...intentionally returning empty string
            //  because of recursive assignment
            return '';
        }
        else {
            // $current_datarecord_id has children
            $result = array();

            // For every child datatype this datarecord has...
            foreach ($descendants_of_datarecord[$current_datarecord_id] as $dt_id => $datarecords) {
                // For every child datarecord of this child datatype...
                foreach ($datarecords as $dr_id => $tmp) {
                    // NOTE - doing it this way to cut out recursive calls that just return ''
                    if ( isset($descendants_of_datarecord[$dr_id]) ) {
                        // ...get all children of this child datarecord and store them
                        $result[$dt_id][$dr_id] = self::buildDatarecordTree($descendants_of_datarecord, $dr_id);
                    }
                    else {
                        // ...the child datarecord has no children of its own
                        $result[$dt_id][$dr_id] = '';
                    }
                }
            }

            return $result;
        }
    }


    /**
     * Recursively traverses the datarecord tree and deletes all datarecords that either didn't
     * match the search that was just run, or were excluded because the user can't see them.
     *
     * @param array $flattened_list
     * @param array $inflated_list
     */
    private function mergeSearchArrays(&$flattened_list, &$inflated_list)
    {
        foreach ($inflated_list as $top_level_dt_id => $top_level_datarecords) {
            foreach ($top_level_datarecords as $dr_id => $child_dt_list) {

                if ( !is_array($child_dt_list) ) {
                    // No child datarecords...only save if it matched the search result
                    if ( $flattened_list[$dr_id] !== 1 )
                        unset( $inflated_list[$top_level_dt_id][$dr_id] );
                }
                else {
                    $votes = array();
                    foreach ($child_dt_list as $child_dt_id => $child_dr_list) {
                        //
                        $vote = self::mergeSearchArrays_worker($flattened_list, $child_dr_list);
                        if ($vote === -1) {
                            // None of the child datarecords of this child datatype match...so by
                            //  definition, this datarecord doesn't match either
                            $flattened_list[$dr_id] = -1;
                            break;
                        }
                        else {
                            // At least one of the child datarecords of this child datatype have a
                            //  value other than -1...so whether this datarecord ends up matching
                            //  or not depends on other factors
                            $votes[$child_dt_id] = $vote;
                        }
                    }

                    // This datarecord wasn't excluded due to its children not matching...but in
                    //  order to actually match the search, at least one of its children must have
                    //  matched the search
                    foreach ($votes as $child_dt_id => $vote) {
                        if ($vote === 1) {
                            $flattened_list[$dr_id] = 1;
                            break;
                        }
                    }
                }
            }
        }
    }


    /**
     * After searching is done, $flattened_list will be in a ($dr_id => $vote) format.  There are
     * four possible values for $vote...
     *
     * -2: This datarecord is excluded because the user can't view it...this has no effect on
     *      whether its parent datarecord is included or not, but all of its children are
     *      immediately excluded
     * -1: This datarecord did not match the search...this datarecord and its children are excluded
     *      from the search, and if all datarecords of this datatype also "don't match", then this
     *      datarecord's parent will be excluded as well
     *  0: This datarecord was not searched on...it'll be included in the search results if its
     *      parents aren't excluded somehow, and its grandparent datarecord ends up matching the
     *      search
     *  1: This datarecord matched the search...it'll be included in the search results if it's not
     *      somehow excluded by the negative values overriding it
     *
     * -2 is intentionally different from -1...the presence (or lack thereof) of child datarecords
     * that the user can't view MUST NOT affect whether the parent datarecord in question matches
     * the search or not.
     *
     * @param array $flattened_list
     * @param array $dr_list
     *
     * @return int
     */
    private function mergeSearchArrays_worker(&$flattened_list, &$dr_list)
    {
        $include = false;
        $exclude = false;
        foreach ($dr_list as $dr_id => $child_dt_list) {

            if ( $flattened_list[$dr_id] === -2 ) {
                // This datarecord is non-public, doesn't matter if it has child datarecords
                // This is different from
            }
            else if ($flattened_list[$dr_id] === -1 ) {
                // This datarecord didn't match the search...doesn't matter if it has children
                $exclude = true;
            }
            else {
                //
                if ( !is_array($child_dt_list) ) {
                    // If has no children, then this datarecord is included if it matched the search
                    if ( $flattened_list[$dr_id] === 1 )
                        $include = true;
                }
                else {
                    $votes = array();
                    foreach ($child_dt_list as $child_dt_id => $child_dr_list) {
                        //
                        $vote = self::mergeSearchArrays_worker($flattened_list, $child_dr_list);
                        if ($vote === -1) {
                            // None of the child datarecords of this child datatype match...so by
                            //  definition, this datarecord doesn't match either
                            $flattened_list[$dr_id] = -1;
                            $exclude = true;
                            break;
                        }
                        else {
                            // At least one of the child datarecords of this child datatype have a
                            //  value other than -1...so whether this datarecord ends up matching
                            //  or not depends on other factors
                            $votes[$child_dt_id] = $vote;
                        }
                    }

                    // This datarecord wasn't excluded due to its children not matching...but in
                    //  order to actually match the search, at least one of its children must have
                    //  matched the search
                    foreach ($votes as $child_dt_id => $vote) {
                        if ($vote === 1) {
                            $flattened_list[$dr_id] = 1;
                            $include = true;
                            break;
                        }
                    }
                }
            }
        }

        if ($include) {
            // At least one datarecord matches the search, so the parent datarecord could possibly
            //  be considered to match the search as well
            return 1;
        }
        else if ($exclude) {
            // All the child datarecords of at least one child datatype didn't match the search
            // Therefore, the parent datarecord should be excluded as well
            return -1;
        }
        else {
            // Otherwise, the results from this child datatype doesn't matter
            return 0;
        }
    }


    /**
     * In order for a top-level datarecord to match the search being run, the datarecord itself, or
     * at least one of its child datarecords, must also match the search.
     *
     * The recursion looks a little strange in order to reduce the number of recursive calls made.
     *
     * @param array $flattened_list
     * @param array $inflated_list
     *
     * @return array
     */
    private function getMatchingDatarecords($flattened_list, $inflated_list)
    {
        $matching_datarecords = array();
        foreach ($inflated_list as $top_level_dt_id => $top_level_datarecords) {
            foreach ($top_level_datarecords as $dr_id => $child_dt_list) {
                // Only care about this top-level datarecord when it either matches the search, or
                //  has a child datarecord that matched the search
                if ( $flattened_list[$dr_id] === 1 ) {
                    $matching_datarecords[$dr_id] = 1;

                    if ( is_array($child_dt_list) ) {
                        //
                        $matching_children = self::getMatchingDatarecords_worker($flattened_list, $child_dt_list);
                        foreach ($matching_children as $child_dr_id => $tmp)
                            $matching_datarecords[$child_dr_id] = 1;
                    }
                }
            }
        }

        return $matching_datarecords;
    }


    /**
     * This just recursively relays whether any of this datarecord's child datarecords ended up
     * matching the search.
     *
     * The recursion looks a little strange in order to reduce the number of recursive calls made.
     *
     * @param array $flattened_list
     * @param array $dt_list
     *
     * @return array
     */
    private function getMatchingDatarecords_worker($flattened_list, $dt_list)
    {
        $matching_datarecords = array();
        foreach ($dt_list as $dt_id => $dr_list) {
            foreach ($dr_list as $dr_id => $child_dt_list) {
                //
                if ( $flattened_list[$dr_id] >= 0 ) {
                    $matching_datarecords[$dr_id] = 1;

                    if ( is_array($child_dt_list) ) {
                        $matching_children = self::getMatchingDatarecords_worker($flattened_list, $child_dt_list);
                        foreach ($matching_children as $child_dr_id => $tmp)
                            $matching_datarecords[$child_dr_id] = 1;
                    }
                }
            }
        }

        return $matching_datarecords;
    }
}
