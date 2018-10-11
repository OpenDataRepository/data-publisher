<?php

/**
 * Open Data Repository Data Publisher
 * Facade Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Temporary(?) controller to redirect some alternate API routes to the actual API controller.
 */

namespace ODR\OpenRepository\SearchBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class FacadeController extends Controller
{

    /**
     * Determines datatype id via search slug, then forwards to the equivalent function in ODRAdminBundle:APIController.
     *
     * @param string $search_slug
     * @param Request $request
     *
     * @return Response
     */
    public function getDatatypeExportAction($search_slug, Request $request)
    {
        try
        {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataTypeMeta $datatype_meta */
            $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy( array('searchSlug' => $search_slug) );
            if ($datatype_meta == null)
                throw new ODRNotFoundException('Datatype');

            $datatype = $datatype_meta->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Let the APIController do the rest of the error-checking
            return $this->forward(
                'ODRAdminBundle:API:getDatatypeExport',
                array(
                    'version' => 'v1',
                    'datatype_id' => $datatype->getId(),
                    '_format' => $request->getRequestFormat()
                ),
                $request->query->all()
            );
        }
        catch (\Exception $e) {
            $source = 0x9ab9a4bf;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Determines datatype id via search slug, then forwards to the equivalent function in ODRAdminBundle:APIController.
     *
     * @param string $search_slug
     * @param Request $request
     *
     * @return Response
     */
    public function getDatarecordListAction($search_slug, Request $request)
    {
        try
        {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataTypeMeta $datatype_meta */
            $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy( array('searchSlug' => $search_slug) );
            if ($datatype_meta == null)
                throw new ODRNotFoundException('Datatype');

            $datatype = $datatype_meta->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // Let the APIController do the rest of the error-checking
            return $this->forward(
                'ODRAdminBundle:API:getDatarecordList',
                array(
                    'version' => 'v1',
                    'datatype_id' => $datatype->getId(),
                    '_format' => $request->getRequestFormat()
                ),
                $request->query->all()
            );
        }
        catch (\Exception $e) {
            $source = 0x100ae284;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Determines datatype id via search slug, then forwards to the equivalent function in ODRAdminBundle:APIController.
     *
     * @param string $search_slug
     * @param integer $datarecord_id
     * @param Request $request
     *
     * @return Response
     */
    public function getDatarecordExportAction($search_slug, $datarecord_id, Request $request)
    {
        try
        {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataTypeMeta $datatype_meta */
            $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy( array('searchSlug' => $search_slug) );
            if ($datatype_meta == null)
                throw new ODRNotFoundException('Datatype');

            $datatype = $datatype_meta->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');


            // Let the APIController do the rest of the error-checking
            return $this->forward(
                'ODRAdminBundle:API:getDatarecordExport',
                array(
                    'version' => 'v1',
                    'datatype_id' => $datatype->getId(),
                    'datarecord_id' => $datarecord->getId(),
                    '_format' => $request->getRequestFormat(),
                ),
                $request->query->all()
            );
        }
        catch (\Exception $e) {
            $source = 0x50cf3669;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * GET template data by "template_uuid".
     *
     * This is the inspector that returns available fields and databases that are available for use as search filters, etc.
     *
     * The public data from this inspector may be cached and refreshed when updated_at timestamps change.
     *
     * Test URL:
     * http://office_dev/app_dev.php/api/v1/search/template/5b44ab8 - 404 not a master template
     * http://office_dev/app_dev.php/api/v1/search/template/2ea627b - Master Template...
     * http://office_dev/app_dev.php/api/v1/search/template/1dcf67e - Metadata Template...
     *
     *
     * @param $template_uuid
     * @param $version [v1]
     * @param Request $request
     */
    public function getTemplateAction($template_uuid, $version, Request $request) {


        // Use this action for datatype export
        // ODRAdminBundle:APIController:getDatatypeExportAction(

        try
        {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')
                ->findOneBy(
                    array(
                        'is_master_type' => 1,
                        'unique_id' => $template_uuid
                    )
                );

            if ($datatype == null || $datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Let the APIController do the rest of the error-checking
            $all = $request->query->all();
            $all['download'] = 'stream';
            $all['metadata'] = 'true';
            return $this->forward(
                'ODRAdminBundle:API:getDatatypeExport',
                array(
                    'version' => $version,
                    'datatype_id' => $datatype->getId(),
                    '_format' => 'json', //$request->getRequestFormat(),
                ),
                $all
            );
            // $request->query->all()
        }
        catch (\Exception $e) {
            $source = 0x982adfe2;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }

    /**
     * GET field data by "template_field_uuid".
     *
     * This is the inspector that returns information about a database field including available options, name, etc.
     *
     * The public data from this inspector may be cached and refreshed when updated_at timestamps change.
     *
     * @param $template_field_uuid
     * @param Request $request
     */
    public function getfieldAction($template_field_uuid, Request $request) {

        try
        {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataTypeMeta $datatype_meta */
            $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy( array('searchSlug' => $search_slug) );
            if ($datatype_meta == null)
                throw new ODRNotFoundException('Datatype');

            $datatype = $datatype_meta->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');


            // Let the APIController do the rest of the error-checking
            return $this->forward(
                'ODRAdminBundle:API:getDatarecordExport',
                array(
                    'version' => 'v1',
                    'datatype_id' => $datatype->getId(),
                    'datarecord_id' => $datarecord->getId(),
                    '_format' => $request->getRequestFormat(),
                ),
                $request->query->all()
            );
        }
        catch (\Exception $e) {
            $source = 0x50cf3669;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }

    /**
     * GETs a record and returns a JSON object containing the record.
     *
     * This example returns an example organization based on the organization template (sub-template of AHED Core Metadata).
     *
     * http://office_dev/app_dev.php/api/v1/search/record/160439.json
     *
     * @param $datarecord_id
     * @param Request $request
     */
    public function getrecordAction($datarecord_id, Request $request) {

        // http://office_dev/app_dev.php/admin/datarecord_export/160439

        try
        {

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');



            // Let the APIController do the rest of the error-checking
            $all = $request->query->all();
            $all['download'] = 'stream';
            $all['metadata'] = 'true';
            return $this->forward(
                'ODRAdminBundle:API:getDatarecordExport',
                array(
                    'version' => 'v1',
                    'datatype_id' => $datarecord->getDataType()->getId(),
                    'datarecord_id' => $datarecord->getId(),
                    '_format' => $request->getRequestFormat(),
                ),
                $all
            );

        }
        catch (\Exception $e) {
            $source = 0x50cf3669;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }

    /**
     * GET a search result using a Base64 encoded search_key of the format listed below.
     *
     * Limit - number of records returned per page (use '0' for all records which may be slow)
     * Offset - number of pages (at limit per page) to start response from ('0' is first page).
     * The "search_key" should be encoded in Base64 and passed in the request URL. This may result
     * in long URLs and URLs should be checked to ensure they do not exceed browser limits (2047 characters).
     * Note: the "field_name" and "name" fields are optional and are provided in this example only for context.
     * In production requests, only the "template_uuid", "template_field_uuid", and the "template_radio_option_uuid"
     * fields are required to identify the selected options. The "general" field holds a text based search string that
     * will match against all fields in the database.
     *
     * The "database_uuid" field in the search response is used to build the URL to forward the user to the actual
     * database the metadata represents. The URL will follow the format:
     *
     * https://{server}.odr.io/{database_uuid}
     *
     * The test server is "zeta" or https://zeta.odr.io
     * (Specific Database search needed?)
     *
     * Sample URL:
     *
     * http://office_dev/app_dev.php/api/v1/search/ew0KICAgICJmaWVsZHMiOiBbDQogICAgICAgIHsNCiAgICAgICAgICAgICJmaWVsZF9uYW1lIjogIlJlc291cmNlIFR5cGUiLA0KICAgICAgICAgICAgInNlbGVjdGVkX29wdGlvbnMiOiBbDQogICAgICAgICAgICAgICAgew0KICAgICAgICAgICAgICAgICAgICAibmFtZSI6ICJDb2xsZWN0aW9uIiwNCiAgICAgICAgICAgICAgICAgICAgInRlbXBsYXRlX3JhZGlvX29wdGlvbl91dWlkIjogIm1jMWthc2Rmc2oiDQogICAgICAgICAgICAgICAgfQ0KICAgICAgICAgICAgXSwNCiAgICAgICAgICAgICJ0ZW1wbGF0ZV9maWVsZF91dWlkIjogIm1jODJrZGtnaCINCiAgICAgICAgfSwNCiAgICAgICAgew0KICAgICAgICAgICAgImZpZWxkX25hbWUiOiAiRnVuZGluZyBTb3VyY2VzIiwNCiAgICAgICAgICAgICJzZWxlY3RlZF9vcHRpb25zIjogWw0KICAgICAgICAgICAgICAgIHsNCiAgICAgICAgICAgICAgICAgICAgIm5hbWUiOiAiRm9yZWlnbiAmZ3Q7IEdvdmVybm1lbnQgJmd0OyBFU0EgKEVVKSIsDQogICAgICAgICAgICAgICAgICAgICJ0ZW1wbGF0ZV9yYWRpb19vcHRpb25fdXVpZCI6ICIyOGRrdm1hc284Ig0KICAgICAgICAgICAgICAgIH0NCiAgICAgICAgICAgIF0sDQogICAgICAgICAgICAidGVtcGxhdGVfZmllbGRfdXVpZCI6ICJteGNudnUybmQiDQogICAgICAgIH0NCiAgICBdLA0KICAgICJnZW5lcmFsIjogIiIsDQogICAgInNvcnRfYnkiOiBbDQogICAgICAgIHsNCiAgICAgICAgICAgICJkaXIiOiAiYXNjIiwNCiAgICAgICAgICAgICJ0ZW1wbGF0ZV9maWVsZF91dWlkIjogIjcyZGh1d2tkayINCiAgICAgICAgfQ0KICAgIF0sDQogICAgInRlbXBsYXRlX25hbWUiOiAiQUhFRCBDb3JlIDEuMCIsDQogICAgInRlbXBsYXRlX3V1aWQiOiAiMmVhNjI3YiINCn0=/0/0
     *
     * @param $search_key
     * @param $limit
     * @param $offset
     * @param Request $request
     */
    public function searchAction($search_key, $limit, $offset, Request $request) {

        try
        {

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $search_key_data = base64_decode($search_key);

            $search_data = json_decode($search_key_data);

            $template_uuid = $search_data->template_uuid;

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')
                ->findOneBy(
                    array(
                        'is_master_type' => 1,
                        'unique_id' => $template_uuid
                    )
                );

            if ($datatype == null || $datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // Find all records for datatypes with this master_template_id
            $datatype_array = $em->getRepository('ODRAdminBundle:DataType')
                ->findBy(
                    array(
                        'masterDataType' => $datatype->getId(),
                        'is_master_type' => 0
                    )
                );

            $records = array();

            /** @var DataType $dt */
            foreach($datatype_array as $dt) {
                // Find record
                $results = $em->getRepository('ODRAdminBundle:DataRecord')
                    ->findBy(
                        array(
                            'dataType' => $dt->getId()
                        )
                    );

                // Add record object to array
                if(count($results) > 0) {
                    $records[] = $results[0];
                }
            }

            // Use get record to build array
            $output_records = array();
            /** @var DataRecord $record */
            foreach($records as $record) {

                // Let the APIController do the rest of the error-checking
                $all = $request->query->all();
                $all['download'] = 'raw';
                $all['metadata'] = 'true';
                $result = $this->forward(
                    'ODRAdminBundle:API:getDatarecordExport',
                    array(
                        'version' => 'v1',
                        'datatype_id' => $record->getDataType()->getId(),
                        'datarecord_id' => $record->getId(),
                        '_format' => $request->getRequestFormat(),
                    ),
                    $all
                );

                $parsed_result = json_decode($result->getContent());

                if(
                    !property_exists($parsed_result, 'error')
                    && property_exists($parsed_result, 'records')
                    && is_array($parsed_result->records)
                ) {
                    array_push($output_records, $parsed_result->records['0']);
                }
            }

            // So we have a bunch of records here...
            // Let's check for a general result or a matched field

            $matched_records = array();
            foreach($output_records as $record) {
                $score = self::checkRecord($record, $search_data, 0, true);
                $record->score = $score;
                if($score > 0) {
                    $matched_records[] = $record;
                }
            }

            // Sort records by score to produce output
            if(property_exists($search_data, 'sort_by')) {
                $sorted_records = self::sortRecords($matched_records, $search_data->sort_by);
            }
            else {
                $sorted_records = $matched_records;
            }

            // Return array of records
            // $response = new Response(json_encode($search_data));
            // $response = new Response(json_encode($matched_records));
            $response = new Response(json_encode($sorted_records));
            // $response = new Response(json_encode($output_records));
            $response->headers->set('Content-Type', 'application/json');
            return $response;

        }
        catch (\Exception $e) {
            $source = 0x50cf3669;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }


    /**
     * @param $template_uuid
     * @param $template_field_uuid
     * @param Request $request
     * @return Response
     */
    public function search_field_statsAction($template_uuid, $template_field_uuid, Request $request) {

        try
        {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')
                ->findOneBy(
                    array(
                        'is_master_type' => 1,
                        'unique_id' => $template_uuid
                    )
                );

            if ($datatype == null || $datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // Find all records for datatypes with this master_template_id
            $datatype_array = $em->getRepository('ODRAdminBundle:DataType')
                ->findBy(
                    array(
                        'masterDataType' => $datatype->getId(),
                        'is_master_type' => 0
                    )
                );

            $records = array();

            /** @var DataType $dt */
            foreach($datatype_array as $dt) {
                // Find record
                $results = $em->getRepository('ODRAdminBundle:DataRecord')
                    ->findBy(
                        array(
                            'dataType' => $dt->getId()
                        )
                    );

                // Add record object to array
                if(count($results) > 0) {
                    $records[] = $results[0];
                }
            }

            // Use get record to build array
            $output_records = array();
            /** @var DataRecord $record */
            foreach($records as $record) {
                // Let the APIController do the rest of the error-checking
                $all = $request->query->all();
                $all['download'] = 'raw';
                $all['metadata'] = 'true';
                $result = $this->forward(
                    'ODRAdminBundle:API:getDatarecordExport',
                    array(
                        'version' => 'v1',
                        'datatype_id' => $record->getDataType()->getId(),
                        'datarecord_id' => $record->getId(),
                        '_format' => $request->getRequestFormat(),
                    ),
                    $all
                );

                $parsed_result = json_decode($result->getContent());

                if(
                    !property_exists($parsed_result, 'error')
                    && property_exists($parsed_result, 'records')
                    && is_array($parsed_result->records)
                ) {
                    array_push($output_records, $parsed_result->records['0']);
                }
            }



            // Process to build options array matching field id
            $options_data = array();
            foreach($output_records as $record) {
                self::optionStats($record, $template_field_uuid, $options_data);
            }

            // Return array of records
            $response = new Response(json_encode($options_data));
            $response->headers->set('Content-Type', 'application/json');
            return $response;

        }
        catch (\Exception $e) {
            $source = 0x883def33;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }



    function optionStats($record, $field_uuid, &$options_data) {

        self::checkOptions($record->fields, $field_uuid, $options_data);

        // Check child records (calls check record)
        if(property_exists($record, 'child_records')) {
            foreach ($record->child_records as $child_record) {
                foreach($child_record->records as $child_data_record) {
                    self::checkOptions($child_data_record->fields, $field_uuid, $options_data);
                }
            }
        }

        // Check linked records (calls check record)
        if(property_exists($record, 'linked_records')) {
            foreach ($record->linked_records as $child_record) {
                foreach($child_record->records as $child_data_record) {
                    self::checkOptions($child_data_record->fields, $field_uuid, $options_data);
                }
            }
        }
    }

    function checkOptions($record_fields, $field_uuid, &$options_data) {
        foreach($record_fields as $field) {
            // We are only checking option fields
            if(
                $field->template_field_uuid == $field_uuid
                && property_exists($field, 'value')
                && is_array($field->value)
            ) {
                foreach($field->value as $option_id => $option) {
                    foreach($option as $key => $selected_option) {
                        if (preg_match("/\s\&gt;\s/", $selected_option->name)) {
                            // We need to split and process
                            $option_data = preg_split("/\s\&gt;\s/", $selected_option->name);
                            for ($i = 0; $i < count($option_data); $i++) {
                                if ($i == 0) {
                                    if (!isset($options_data[$option_data[0]])) {
                                        $options_data[$option_data[0]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]]['count']++;
                                    if(count($option_data) == 1) {
                                        $options_data[$option_data[0]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 1) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]])) {
                                        $options_data[$option_data[0]][$option_data[1]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]]['count']++;
                                    if(count($option_data) == 2) {
                                        $options_data[$option_data[0]][$option_data[1]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 2) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]][$option_data[2]])) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]][$option_data[2]]['count']++;
                                    if(count($option_data) == 3) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 3) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]])) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]]['count']++;
                                    if(count($option_data) == 4) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 4) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]])) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]]['count']++;
                                    if(count($option_data) == 5) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 5) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]])) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]]['count']++;
                                    if(count($option_data) == 6) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 6) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]][$option_data[6]])) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]][$option_data[6]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]][$option_data[6]]['count']++;
                                    if(count($option_data) == 7) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]][$option_data[6]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                            }
                        } else {
                            if (!isset($options_data[$selected_option->name])) {
                                $options_data[$selected_option->name] = array(
                                    'count' => 0,
                                );
                            }
                            $options_data[$selected_option->name]['count']++;
                            $options_data[$selected_option->name]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                        }
                    }
                }
            }
        }
    }





















    function sortRecords($records, $sort_array) {
        $flattened_array = array();

        // TODO Currently can only sort by string or number fields in the top-level field object
        foreach($records as $record) {
            $flat = array();
            foreach($record->fields as $field) {
                if(!is_array($field->value)) {
                    $flat[$field->template_field_uuid] = $field->value;
                }
            }
            $flat['score'] = $record->score;
            $flat['id'] = $record->internal_id;
            $flat['record'] = $record;
            $flattened_array[$record->internal_id] = $flat;
        }

        // Now sort by fields in sort array
        if(count($sort_array) == 3) {
            array_multisort(
                array_column($flattened_array, $sort_array[0]->template_field_uuid),  ($sort_array[0]->dir == "asc")?SORT_ASC:SORT_DESC,
                array_column($flattened_array, $sort_array[1]->template_field_uuid),  ($sort_array[1]->dir == "asc")?SORT_ASC:SORT_DESC,
                array_column($flattened_array, $sort_array[2]->template_field_uuid),  ($sort_array[2]->dir == "asc")?SORT_ASC:SORT_DESC,
                $flattened_array);
        }
        else if(count($sort_array) == 2) {
            array_multisort(
                array_column($flattened_array, $sort_array[0]->template_field_uuid),  ($sort_array[0]->dir == "asc")?SORT_ASC:SORT_DESC,
                array_column($flattened_array, $sort_array[1]->template_field_uuid),  ($sort_array[1]->dir == "asc")?SORT_ASC:SORT_DESC,
                $flattened_array);
        }
        else if(count($sort_array) == 1) {
            array_multisort(
                array_column($flattened_array, $sort_array[0]->template_field_uuid),  ($sort_array[0]->dir == "asc")?SORT_ASC:SORT_DESC,
                $flattened_array);
        }

        $output_sorted = array();
        foreach($flattened_array as $sorted_record) {
            $output_sorted[] = $sorted_record['record'];
        }
        return $output_sorted;
    }

    function checkRecord($record, $search_data, $score = 0, $full_match_required = false) {

        // Ensure we've got what we need to work with
        if(!property_exists($search_data, 'general')) {
            $search_data->general = "";
        }
        if(!property_exists($search_data, 'fields')) {
            $search_data->fields = array();
        }
        if(!property_exists($record, 'fields')) {
            $record->fields = array();
        }

        $score += self::checkFields($search_data->fields, $record->fields, $search_data->general, $full_match_required);

        // Check child records (calls check record)
        if(property_exists($record, 'child_records')) {
            foreach ($record->child_records as $child_record) {
                $score += self::checkRecord($child_record, $search_data, $score, $full_match_required);
            }
        }

        // Check linked records (calls check record)
        if(property_exists($record, 'linked_records')) {
            foreach ($record->linked_records as $child_record) {
                $score += self::checkRecord($child_record, $search_data, $score, $full_match_required);
            }
        }

        return $score;
    }

    function checkFields($fields_to_match, $field_array, $general = "", $full_match_required = false) {
        $field_score = 0;
        // Check fields
        foreach($field_array as $field_id => $field) {
            // Checking general search (value 1)
            if($general !== "") {
                if (
                    !is_object($field->value)
                    && !is_array($field->value)
                    && preg_match("/" . $general . "/i", $field->value)
                ) {
                    // this is a general search match
                    $field_score += 1;
                }
                if (
                    is_object($field->value)
                ) {
                    foreach($field->value as $radio_option_id => $radio_option) {
                        if(preg_match("/". $general . "/i", $radio_option->name)) {
                            $field_score += 1;
                        }
                    }
                }
            }

            foreach($fields_to_match as $match_field) {
                if(
                    property_exists($match_field, 'selected_options')
                    && is_array($match_field->selected_options)
                    && is_array($field->value)
                ) {
                    // Process radio options if $field is a radio field
                    foreach($field->value as $radio_option_id => $radio_option_data) {
                        foreach ($radio_option_data as $radio_info => $radio_option) {
                            foreach ($match_field->selected_options as $selected_option) {
                                if ($selected_option->template_radio_option_uuid == $radio_option->template_radio_option_uuid) {
                                    $selected_option->matched = 1;
                                    $field_score += 3;
                                }
                            }
                        }
                    }
                }
                else if(
                    !property_exists($match_field, 'selected_options')
                    && property_exists($match_field, 'value')
                    && !is_array($match_field->value)
                    && !is_array($field->value)
                ) {
                    // Match values
                    if(
                        !is_array($field->value)
                        && preg_match("/". $match_field->value . "/i", $field->value)
                    ) {
                        $field_score += 3;
                    }
                }
            }
        }

        if($full_match_required) {
            foreach($fields_to_match as $match_field) {
                foreach ($match_field->selected_options as $selected_option) {
                    if(
                        !property_exists($selected_option, 'matched')
                        || $selected_option->matched == 0
                    ) {
                        $field_score = 0;
                    }
                    else {
                        // set matched back to zero for next pass
                        $selected_option->matched = 0;
                    }
                }
            }
        }

        return $field_score;
    }
}
