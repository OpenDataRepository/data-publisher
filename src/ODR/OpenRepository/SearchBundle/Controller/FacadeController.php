<?php

/**
 * Open Data Repository Data Publisher
 * Facade Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller redirects searching/API requests to the controller that can actually respond to
 * them.
 */

namespace ODR\OpenRepository\SearchBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatarecordExportService;
use ODR\AdminBundle\Component\Service\DatatypeCreateService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// use FOS\UserBundle\Model\UserManagerInterface;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
// Other
use FOS\UserBundle\Util\TokenGenerator;


class FacadeController extends Controller
{

    /**
     * Determines datatype id via search slug, then forwards to the equivalent function in
     * ODRAdminBundle:APIController.
     *
     * @param string $search_slug
     * @param Request $request
     *
     * @return Response
     */
    public function getDatatypeExportAction($search_slug, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataTypeMeta $datatype_meta */
            $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy(array('searchSlug' => $search_slug));
            if ($datatype_meta == null)
                throw new ODRNotFoundException('Datatype');

            // TODO - filter out metadata datatype?

            $datatype = $datatype_meta->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            $type = 'databases';
            if ($datatype->getIsMasterType())
                $type = 'templates';

            // Let the APIController do the rest of the error-checking
            return $this->forward(
                'ODRAdminBundle:API:getDatatypeExport',
                array(
                    'version' => 'v1',
                    'datatype_uuid' => $datatype->getUniqueId(),
                    '_format' => $request->getRequestFormat(),
                    'type' => $type,
                ),
                $request->query->all()
            );
        } catch (\Exception $e) {
            $source = 0x9ab9a4bf;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Determines datatype id via search slug, then forwards to the equivalent function in
     * ODRAdminBundle:APIController.
     *
     * @param string $search_slug
     * @param Request $request
     *
     * @return Response
     */
    public function getDatarecordListAction($search_slug, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataTypeMeta $datatype_meta */
            $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy(array('searchSlug' => $search_slug));
            if ($datatype_meta == null)
                throw new ODRNotFoundException('Datatype');

            // TODO - filter out metadata datatype?

            $datatype = $datatype_meta->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // TODO - apparently this demands the limit/offset parameters are defined beforehand?
            // Let the APIController do the rest of the error-checking
            return $this->forward(
                'ODRAdminBundle:API:getDatarecordList',
                array(
                    'version' => 'v1',
                    'datatype_uuid' => $datatype->getUniqueId(),
                    '_format' => $request->getRequestFormat()
                ),
                $request->query->all()
            );
        } catch (\Exception $e) {
            $source = 0x100ae284;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Determines datatype id via search slug, then forwards to the equivalent function in
     * ODRAdminBundle:APIController.
     *
     * @param string $search_slug
     * @param integer $datarecord_id
     * @param Request $request
     *
     * @return Response
     */
    public function getDatarecordExportAction($search_slug, $datarecord_id, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataTypeMeta $datatype_meta */
            $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy(array('searchSlug' => $search_slug));
            if ($datatype_meta == null)
                throw new ODRNotFoundException('Datatype');

            // TODO - filter out metadata datatype?

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
        } catch (\Exception $e) {
            $source = 0x50cf3669;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Attempts to convert the given json-encoded key into a format usable by ODR, and then attempts
     * to run a search that returns results across multiple datatypes.
     *
     * @param string $version
     * @param string $json_key
     * @param integer $limit
     * @param integer $offset
     * @param Request $request
     *
     * @return Response
     */
    public function searchTemplateGetAction($version, $json_key, $limit, $offset, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to only showing all info about the datatype/template...
            $display_metadata = true;
            if ($request->query->has('metadata') && $request->query->get('metadata') == 'false')
                // ...but restrict to only the most useful info upon request
                $display_metadata = false;

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordExportService $dre_service */
            $dre_service = $this->container->get('odr.datarecord_export_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var TokenGenerator $tokenGenerator */
            $tokenGenerator = $this->container->get('fos_user.util.token_generator');


            // ----------------------------------------
            // Validate the given search information
            $search_key = $search_key_service->convertBase64toSearchKey($json_key);
            $search_key_service->validateTemplateSearchKey($search_key);

            // Now that the search key is valid, load the datatype being searched on
            $params = $search_key_service->decodeSearchKey($search_key);
            $dt_uuid = $params['template_uuid'];

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dt_uuid
                )
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // TODO - enforce permissions on template side?
            // If either the datatype or the datarecord is not public, and the user doesn't have
            //  the correct permissions...then don't allow them to view the datarecord
//            if ( !$pm_service->canViewDatatype($user, $datatype) )
//                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Allow users to specify positive integer values less than a billion for these variables
            $offset = intval($offset);
            $limit = intval($limit);

            // If limit is set to 0, then return all results
            if ($limit === 0)
                $limit = 999999999;

            // TODO - Is this necessary?
            if ($offset >= 1000000000)
                throw new ODRBadRequestException('Offset must be less than a billion');
            if ($limit >= 1000000000)
                throw new ODRBadRequestException('Limit must be less than a billion');


            // ----------------------------------------
            // Run the search
            $search_results = $search_api_service->performTemplateSearch($search_key, $user_permissions);
            $datarecord_list = $search_results['grandparent_datarecord_list'];

            // Apply limit/offset to the results
            // TODO Querying with limit and offset would be much faster most likely
            $datarecord_list = array_slice($datarecord_list, $offset, $limit);

            // Render the resulting list of datarecords into a single chunk of export data
            $baseurl = $this->container->getParameter('site_baseurl');
            $data = $dre_service->getData($version, $datarecord_list, $request->getRequestFormat(), $display_metadata, $user, $baseurl);


            // ----------------------------------------
            // Set up a response to return the datarecord list
            $response = new Response();

            if ($download_response) {
                // Generate a token for this download
                $token = substr($tokenGenerator->generateToken(), 0, 15);

                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="' . $token . '.' . $request->getRequestFormat() . '";');
            }

            $response->setContent($data);
            return $response;
        } catch (\Exception $e) {
            $source = 0x9c2fcbde;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    public function searchTemplatePostTestAction($version, $limit, $offset, Request $request) {

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        $master_datatype_id = 670;
        // $template_id =

        // Otherwise...get all non-layout data for the requested grandparent datarecord
        /*
        $query = $this->em->createQuery(
            'SELECT
               dr, partial drm.{id, publicDate}, partial p_dr.{id}, partial gp_dr.{id},
               partial dr_cb.{id, username, email, firstName, lastName},
               partial dr_ub.{id, username, email, firstName, lastName},

               dt, partial gp_dt.{id}, partial mdt.{id, unique_id}, partial mf.{id, unique_id}, df_dt, dfm_dt, ft_dt,
               dtm, partial dt_eif.{id}, partial dt_nf.{id}, partial dt_sf.{id},

               drf, partial df.{id, fieldUuid, templateFieldUuid}, partial dfm.{id, fieldName, xml_fieldName }, partial ft.{id, typeClass, typeName},
               e_f, e_fm, partial e_f_cb.{id, username, email, firstName, lastName},
               e_i, e_im, e_ip, e_ipm, e_is, partial e_ip_cb.{id, username, email, firstName, lastName},

               e_b, e_iv, e_dv, e_lt, e_lvc, e_mvc, e_svc, e_dtv, rs, ro, ts, t,

               partial e_b_ub.{id, username, email, firstName, lastName},
               partial e_iv_ub.{id, username, email, firstName, lastName},
               partial e_dv_ub.{id, username, email, firstName, lastName},
               partial e_lt_ub.{id, username, email, firstName, lastName},
               partial e_lvc_ub.{id, username, email, firstName, lastName},
               partial e_mvc_ub.{id, username, email, firstName, lastName},
               partial e_svc_ub.{id, username, email, firstName, lastName},
               partial e_dtv_ub.{id, username, email, firstName, lastName},
               partial rs_ub.{id, username, email, firstName, lastName},
               partial ts_ub.{id, username, email, firstName, lastName},

               partial cdr.{id}, partial cdr_dt.{id},
               ldt, partial ldr.{id}, partial ldr_dt.{id}

            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.dataRecordMeta AS drm
            LEFT JOIN dr.createdBy AS dr_cb
            LEFT JOIN dr.updatedBy AS dr_ub
            LEFT JOIN dr.parent AS p_dr
            LEFT JOIN dr.grandparent AS gp_dr

            LEFT JOIN dr.dataType AS dt
            LEFT JOIN dt.grandparent AS gp_dt
            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dt.dataFields AS df_dt
            LEFT JOIN df_dt.dataFieldMeta AS dfm_dt
            LEFT JOIN dfm_dt.fieldType AS ft_dt
            LEFT JOIN dt.masterDataType AS mdt
            LEFT JOIN dt.metadata_for AS mf
            LEFT JOIN dtm.externalIdField AS dt_eif
            LEFT JOIN dtm.nameField AS dt_nf
            LEFT JOIN dtm.sortField AS dt_sf

            LEFT JOIN dr.dataRecordFields AS drf
            LEFT JOIN drf.file AS e_f
            LEFT JOIN e_f.fileMeta AS e_fm
            LEFT JOIN e_f.createdBy AS e_f_cb

            LEFT JOIN drf.image AS e_i
            LEFT JOIN e_i.imageMeta AS e_im
            LEFT JOIN e_i.parent AS e_ip
            LEFT JOIN e_ip.imageMeta AS e_ipm
            LEFT JOIN e_i.imageSize AS e_is
            LEFT JOIN e_ip.createdBy AS e_ip_cb

            LEFT JOIN drf.boolean AS e_b
            LEFT JOIN e_b.updatedBy AS e_b_ub
            LEFT JOIN drf.integerValue AS e_iv
            LEFT JOIN e_iv.updatedBy AS e_iv_ub
            LEFT JOIN drf.decimalValue AS e_dv
            LEFT JOIN e_dv.updatedBy AS e_dv_ub
            LEFT JOIN drf.longText AS e_lt
            LEFT JOIN e_lt.updatedBy AS e_lt_ub
            LEFT JOIN drf.longVarchar AS e_lvc
            LEFT JOIN e_lvc.updatedBy AS e_lvc_ub
            LEFT JOIN drf.mediumVarchar AS e_mvc
            LEFT JOIN e_mvc.updatedBy AS e_mvc_ub
            LEFT JOIN drf.shortVarchar AS e_svc
            LEFT JOIN e_svc.updatedBy AS e_svc_ub
            LEFT JOIN drf.datetimeValue AS e_dtv
            LEFT JOIN e_dtv.updatedBy AS e_dtv_ub
            LEFT JOIN drf.radioSelection AS rs
            LEFT JOIN rs.updatedBy AS rs_ub
            LEFT JOIN rs.radioOption AS ro
            LEFT JOIN drf.tagSelection AS ts
            LEFT JOIN ts.updatedBy AS ts_ub
            LEFT JOIN ts.tag AS t

            LEFT JOIN drf.dataField AS df
            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN dfm.fieldType AS ft

            LEFT JOIN dr.children AS cdr
            LEFT JOIN cdr.dataType AS cdr_dt

            LEFT JOIN dr.linkedDatarecords AS ldt
            LEFT JOIN ldt.descendant AS ldr
            LEFT JOIN ldr.dataType AS ldr_dt

            WHERE
                dt.masterDataType = :master_datatype_id
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND df.deletedAt IS NULL
                AND (e_i.id IS NULL OR e_i.original = 0)'
        )->setParameters(array('master_datatype_id' => $master_datatype_id));
        */

        // This should get all of the data for all records in databases derived from the template.
        /*
        dr,
               partial drm.{id, publicDate},
               partial p_dr.{id},
               partial gp_dr.{id},

               dt,
               partial gp_dt.{id},
               partial mdt.{id, unique_id},
               partial mf.{id, unique_id},
               df_dt,
               dfm_dt,
               ft_dt,

               dtm,
               partial dt_eif.{id},
               partial dt_nf.{id},
               partial dt_sf.{id},

               drf,
               partial df.{id, fieldUuid, templateFieldUuid},
               partial dfm.{id, fieldName, xml_fieldName },
               partial ft.{id, typeClass, typeName},

               e_f,
               e_fm,

               e_i,
               e_im,
               e_ip,
               e_ipm,
               e_is,

               e_b,
               e_iv,
               e_dv,
               e_lt,
               e_lvc,
               e_mvc,
               e_svc,
               e_dtv,
               rs,
               ro,
               ts,
               t,

               partial cdr.{id}, partial cdr_dt.{id},
               ldt, partial ldr.{id}, partial ldr_dt.{id}
        */
        $query = $em->createQuery(
            'SELECT
               dr.id 

            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.dataRecordMeta AS drm
            LEFT JOIN dr.parent AS p_dr
            LEFT JOIN dr.grandparent AS gp_dr

            LEFT JOIN dr.dataType AS dt
            LEFT JOIN dt.grandparent AS gp_dt
            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dt.dataFields AS df_dt
            LEFT JOIN df_dt.dataFieldMeta AS dfm_dt
            LEFT JOIN dfm_dt.fieldType AS ft_dt
            LEFT JOIN dt.masterDataType AS mdt
            LEFT JOIN dt.metadata_for AS mf
            LEFT JOIN dtm.externalIdField AS dt_eif
            LEFT JOIN dtm.nameField AS dt_nf
            LEFT JOIN dtm.sortField AS dt_sf

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
            LEFT JOIN drf.tagSelection AS ts
            LEFT JOIN ts.tag AS t

            LEFT JOIN drf.dataField AS df
            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN dfm.fieldType AS ft

            LEFT JOIN dr.children AS cdr
            LEFT JOIN cdr.dataType AS cdr_dt

            LEFT JOIN dr.linkedDatarecords AS ldt
            LEFT JOIN ldt.descendant AS ldr
            LEFT JOIN ldr.dataType AS ldr_dt

            WHERE
                dt.masterDataType = :master_datatype_id
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND df.deletedAt IS NULL
                AND (e_i.id IS NULL OR e_i.original = 0)'
        )->setParameters(array('master_datatype_id' => $master_datatype_id));

        /*
         *
         *
               partial drm.{id, publicDate},
               partial p_dr.{id},
               partial gp_dr.{id},

               dt,
               partial gp_dt.{id},
               partial mdt.{id, unique_id},
               partial mf.{id, unique_id},
               df_dt,
               dfm_dt,
               ft_dt,

               dtm,
               partial dt_eif.{id},
               partial dt_nf.{id},
               partial dt_sf.{id},

               drf,
               partial df.{id, fieldUuid, templateFieldUuid},
               partial dfm.{id, fieldName, xml_fieldName },
               partial ft.{id, typeClass, typeName},

               e_f,
               e_fm,

               e_i,
               e_im,
               e_ip,
               e_ipm,
               e_is,

               e_b,
               e_iv,
               e_dv,
               e_lt,
               e_lvc,
               e_mvc,
               e_svc,
               e_dtv,
               rs,
               ro,
               ts,
               t,

               partial cdr.{id}, partial cdr_dt.{id},
               ldt, partial ldr.{id}, partial ldr_dt.{id}
         *
         *
        e_f.id IS NOT NULL
        AND e_fm.id IS NOT NULL
        AND e_i.id IS NOT NULL
        AND e_im.id IS NOT NULL
        AND e_ip.id IS NOT NULL
        AND e_ipm.id IS NOT NULL
        AND e_is.id IS NOT NULL
        AND e_b.id IS NOT NULL
        AND e_iv.id IS NOT NULL
        AND e_dv.id IS NOT NULL
        AND e_lt.id IS NOT NULL
        AND e_lvc.id IS NOT NULL
        AND e_mvc.id IS NOT NULL
        AND e_svc.id IS NOT NULL
        AND e_dtv.id IS NOT NULL
        AND rs.id IS NOT NULL
        AND ro.id IS NOT NULL
        AND ts.id IS NOT NULL
        AND t.id IS NOT NULL

        */

        // This should get all of the data for all records in databases derived from the template.

        // How long does this take to run.

        // Need to add list of user permissible field type ids found by permission array for user

        // How do we get linked datatypes? Array of master datatypes and linked datatypes?

        // Add order by limit and offset...

        // Filter by public private for anon users...

        var_export($query->getSQL()); // print the SQL query - you will need to replace the parameters in the query
        // var_dump($query->getParams());exit();
        exit();
        // $datarecord_data = $query->getArrayResult();

        // print_r($master_datatype_id);
    }

    /**
     * Attempts to convert a POST request into a format usable by ODR, and then attempts to run a
     * search that returns results across multiple datatypes.
     *
     * @param string $version
     * @param integer $limit
     * @param integer $offset
     * @param Request $request
     *
     * @return Response
     */
    public function searchTemplatePostAction($version, $limit, $offset, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to only showing all info about the datatype/template...
            $display_metadata = true;
            if ($request->query->has('metadata') && $request->query->get('metadata') == 'false')
                // ...but restrict to only the most useful info upon request
                $display_metadata = false;

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordExportService $dre_service */
            $dre_service = $this->container->get('odr.datarecord_export_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var TokenGenerator $tokenGenerator */
            $tokenGenerator = $this->container->get('fos_user.util.token_generator');


            // ----------------------------------------
            // Validate the given search information
            $post = $request->request->all();
            if ( !isset($post['search_key']) )
                throw new ODRBadRequestException();
            $base64 = $post['search_key'];

            $search_key = $search_key_service->convertBase64toSearchKey($base64);
            $search_key_service->validateTemplateSearchKey($search_key);

            // Now that the search key is valid, load the datatype being searched on
            $params = $search_key_service->decodeSearchKey($search_key);
            $dt_uuid = $params['template_uuid'];

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dt_uuid
                )
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            // Only public records currently...
            // TODO Determine a better way to determine how API Users should get public/private records
            // TODO - act as user should be passed on this call?
            $user = "anon.";
            // $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // TODO - enforce permissions on template side?
            // If either the datatype or the datarecord is not public, and the user doesn't have
            //  the correct permissions...then don't allow them to view the datarecord
//            if ( !$pm_service->canViewDatatype($user, $datatype) )
//                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Allow users to specify positive integer values less than a billion for these variables
            $offset = intval($offset);
            $limit = intval($limit);

            // If limit is set to 0, then return all results
            if ($limit === 0)
                $limit = 999999999;

            if ($offset >= 1000000000)
                throw new ODRBadRequestException('Offset must be less than a billion');
            if ($limit >= 1000000000)
                throw new ODRBadRequestException('Limit must be less than a billion');


            // ----------------------------------------
            // Run the search
            $search_results = $search_api_service->performTemplateSearch($search_key, $user_permissions);
            $datarecord_list = $search_results['grandparent_datarecord_list'];

            // Apply limit/offset to the results
            $datarecord_list = array_slice($datarecord_list, $offset, $limit);

            // Render the resulting list of datarecords into a single chunk of export data
            $baseurl = $this->container->getParameter('site_baseurl');
            $data = $dre_service->getData(
                $version,
                $datarecord_list,
                $request->getRequestFormat(),
                $display_metadata,
                $user,
                $baseurl,
                1,
                true

            );


            // ----------------------------------------
            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                // Generate a token for this download
                $token = substr($tokenGenerator->generateToken(), 0, 15);

                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="'.$token.'.'.$request->getRequestFormat().'";');
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x7f543ec7;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
