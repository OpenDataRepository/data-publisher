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
     * @param $record_d
     * @param Request $request
     */
    public function getrecordAction($record_d, Request $request) {

        // http://office_dev/app_dev.php/admin/datarecord_export/160439

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
}
