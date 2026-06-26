<?php

/**
 * Open Data Repository Data Publisher
 * Search API Service No Conflict
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This holds the endpoint functions required to setup and run a full-fledged ODR search.
 *
 */

namespace ODR\OpenRepository\SearchBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatarecordExportService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
// Other
use Doctrine\ORM\EntityManager;
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
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * SearchAPIServiceNoConflict constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatatreeInfoService $datatree_info_service
     * @param DatarecordExportService $datarecord_export_service
     * @param CacheService $cache_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatatreeInfoService $datatree_info_service,
        DatarecordExportService $datarecord_export_service,
        CacheService $cache_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dti_service = $datatree_info_service;
        $this->dre_service = $datarecord_export_service;
        $this->cache_service = $cache_service;
        $this->logger = $logger;
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
}
