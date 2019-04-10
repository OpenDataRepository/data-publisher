<?php

/**
 * Open Data Repository Data Publisher
 * API Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Handles the OAuth-specific API routes.
 */

namespace ODR\AdminBundle\Controller;

use FOS\UserBundle\Command\ActivateUserCommand;
use HWI\Bundle\OAuthBundle\Tests\Fixtures\FOSUser;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Form\LongVarcharForm;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Image;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatarecordExportService;
use ODR\AdminBundle\Component\Service\DatatypeExportService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;


class APIController extends ODRCustomController
{

    /**
     * Provides basic user information to entities using OAuth.
     *
     * @param string $version
     * @param Request $request
     *
     * @return Response
     */
    public function userdataAction($version, Request $request)
    {
        try {
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if ($user !== 'anon.' /*&& $user->hasRole('ROLE_JUPYTERHUB_USER')*/ ) {
                $user_array = array(
                    'id' => $user->getEmail(),
                    'username' => $user->getUserString(),
                    'realname' => $user->getUserString(),
                    'email' => $user->getEmail(),
                    'baseurl' => $this->getParameter('site_baseurl'),
                );

                if ( $this->has('odr.jupyterhub_bridge.username_service') )
                    $user_array['jupyterhub_username'] = $this->get('odr.jupyterhub_bridge.username_service')->getJupyterhubUsername($user);


                // Symfony already knows the request format due to use of the _format parameter in the route
                $format = $request->getRequestFormat();
                $data = $this->get('templating')->render(
                    'ODRAdminBundle:API:userdata.'.$format.'.twig',
                    array(
                        'user_data' => $user_array,
                    )
                );

                // Symfony should automatically set the response format based on the request format
                return new Response($data);
            }

            // Otherwise, user isn't allowed to do this
            throw new ODRForbiddenException();
        }
        catch (\Exception $e) {
            $source = 0xfd346a45;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns an array of all top-level datatypes the user can view.
     *
     * @param string $version
     * @param string $type
     * @param Request $request
     *
     * @return Response
     */
    public function getDatatypeListAction($version, $type, Request $request)
    {
        try {
            // Default to only showing top-level datatypes...
            $show_child_datatypes = false;
            if ($request->query->has('display') && $request->query->get('display') == 'all')
                // ...but show child datatypes upon request
                $show_child_datatypes = true;

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;

            // This action can list both regular databases and master templates
            // It doesn't make sense to have both in the same output
            $is_master_type = 0;
            if ($type === 'templates')
                $is_master_type = 1;


            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Get the user's permissions if applicable
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = $pm_service->getDatatypePermissions($user);


            $top_level_datatype_ids = $dti_service->getTopLevelDatatypes();
            $datatree_array = $dti_service->getDatatreeArray();


            // ----------------------------------------
            $results = array();
            if ($show_child_datatypes) {
                // Build/execute a query to get basic info on all datatypes
                $query = $em->createQuery(
                   'SELECT
                       dt.id AS database_id, dtm.shortName AS database_name, dtm.searchSlug AS search_slug,
                       dtm.description AS database_description, dtm.publicDate AS public_date,
                       dt.unique_id AS unique_id, mdt.unique_id AS template_id
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                    LEFT JOIN ODRAdminBundle:DataType AS mdt WITH dt.masterDataType = mdt
                    WHERE dt.setup_step IN (:setup_steps)
                    AND dt.is_master_type = :is_master_type AND dt.metadata_for IS NULL
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'setup_steps' => DataType::STATE_VIEWABLE,
                        'is_master_type' => $is_master_type,
                    )
                );
                $results = $query->getArrayResult();
            }
            else {
                // Build/execute a query to get basic info on all top-level datatypes
                $query = $em->createQuery(
                    'SELECT
                       dt.id AS database_id, dtm.shortName AS database_name, dtm.searchSlug AS search_slug,
                       dtm.description AS database_description, dtm.publicDate AS public_date,
                       dt.unique_id AS unique_id, mdt.unique_id AS template_id
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                    LEFT JOIN ODRAdminBundle:DataType AS mdt WITH dt.masterDataType = mdt
                    WHERE dt.id IN (:datatype_ids) AND dt.setup_step IN (:setup_steps)
                    AND dt.is_master_type = :is_master_type AND dt.metadata_for IS NULL
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'datatype_ids' => $top_level_datatype_ids,
                        'setup_steps' => DataType::STATE_VIEWABLE,
                        'is_master_type' => $is_master_type,
                    )
                );
                $results = $query->getArrayResult();
            }


            // ----------------------------------------
            // Filter the query results by what the user is allowed to see
            $datatype_data = array();
            foreach ($results as $num => $dt) {
                // Store whether the user has permission to view this datatype
                $dt_id = $dt['database_id'];
                $can_view_datatype = false;
                if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dt_view']) )
                    $can_view_datatype = true;

                // If the datatype is public, or the user doesn't have permission to view this datatype...
                $public_date = $dt['public_date']->format('Y-m-d H:i:s');
                unset( $results[$num]['public_date'] );

                if ($can_view_datatype || $public_date !== '2200-01-01 00:00:00')
                    // ...save it in the results array
                    $datatype_data[$dt_id] = $results[$num];
            }


            // ----------------------------------------
            // Organize the datatype data into a new array if needed
            $final_datatype_data = array();

            if ($show_child_datatypes) {
                // Need to recursively turn this array of datatypes into an inflated array
                foreach ($datatype_data as $dt_id => $dt) {
                    if ( in_array($dt_id, $top_level_datatype_ids) ) {
                        $tmp = self::inflateDatatypeArray($datatype_data, $datatree_array, $dt_id);
                        if ( count($tmp) > 0 )
                            $dt['child_databases'] = array_values($tmp);

                        $final_datatype_data[$dt_id] = $dt;
                    }
                }
            }
            else {
                // Otherwise, this is just the top-level dataypes
                $final_datatype_data = $datatype_data;
            }

            $final_datatype_data = array('databases' => array_values($final_datatype_data));

            // Symfony already knows the request format due to use of the _format parameter in the route
            $format = $request->getRequestFormat();
            $data = $this->get('templating')->render(
                'ODRAdminBundle:API:datatype_list.'.$format.'.twig',
                array(
                    'datatype_list' => $final_datatype_data,
                )
            );


            // ----------------------------------------
            // Set up a response to send the datatype list back to the user
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="Datatype_list.'.$request->getRequestFormat().'";');
            }

            // Symfony should automatically set the response format based on the request format
            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x5dc89429;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Utility function to recursively inflate the datatype array for self::datatypelistAction()
     * Can't use the one in the DatatypeInfoService because this array has a different structure
     *
     * @param array $source_data
     * @param array $datatree_array  @see DatatypeInfoService::getDatatreeArray()
     * @param integer $parent_datatype_id
     *
     * @return array
     */
    private function inflateDatatypeArray($source_data, $datatree_array, $parent_datatype_id) {
        $child_datatype_data = array();

        // Search for any children of the parent datatype
        foreach ($datatree_array['descendant_of'] as $child_dt_id => $parent_dt_id) {
            // If a child was found, and it exists in the source data array...
            if ($parent_dt_id == $parent_datatype_id && isset($source_data[$child_dt_id])) {
                // ...store the child datatype's data
                $child_datatype_data[$child_dt_id] = $source_data[$child_dt_id];

                // ...find all of this datatype's children, if it has any
                $tmp = self::inflateDatatypeArray($source_data, $datatree_array, $child_dt_id);
                if ( count($tmp) > 0 )
                    $child_datatype_data[$child_dt_id]['child_databases'] = array_values($tmp);
            }
        }

        return $child_datatype_data;
    }


    /**
     * Renders and returns the json/XML version of the given Datatype.
     *
     * @param string $version
     * @param string $datatype_uuid
     * @param string $type
     * @param Request $request
     *
     * @return Response
     */
    public function getDatatypeExportAction($version, $datatype_uuid, $type, Request $request)
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

            // This action can list both regular databases and master templates
            // It doesn't make sense to have both in the same output
            $is_master_type = 0;
            if ($type === 'templates')
                $is_master_type = 1;


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeExportService $dte_service */
            $dte_service = $this->container->get('odr.datatype_export_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $datatype_uuid,
                    'is_master_type' => $is_master_type
                )
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();


            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ( !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('Only permitted on top-level datatypes');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Render the requested datatype
            $data = $dte_service->getData(
                $version,
                $datatype_id,
                $request->getRequestFormat(),
                $display_metadata,
                $user,
                $this->container->getParameter('site_baseurl')
            );

            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="Datatype_'.$datatype_id.'.'.$request->getRequestFormat().'";');
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x43dd4818;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns a list of top-level datarecords for the given datatype that the user is allowed to see.
     *
     * @param string $version
     * @param string $datatype_uuid
     * @param integer $limit
     * @param integer $offset
     * @param Request $request
     *
     * @return Response
     */
    public function getDatarecordListAction($version, $datatype_uuid, $limit, $offset, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;


            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $datatype_uuid
                )
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            $top_level_datatype_ids = $dti_service->getTopLevelDatatypes();
            if ( !in_array($datatype_id, $top_level_datatype_ids) )
                throw new ODRBadRequestException('Datatype must be top-level');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $can_view_datarecord = $pm_service->canViewNonPublicDatarecords($user, $datatype);

            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
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

            // $offset is currently the index of the "first" datarecord the user wants...turn $limit
            //  into the index of the "last" datarecord the user wants
            $limit = $offset + $limit;


            // ----------------------------------------
            // Load all top-level datarecords of this datatype that the user can see

            // The contents of this database query depend greatly on whether the datatype has an
            //  external_id or name datafields set...therefore, building the query is considerably
            //  quicker/easier when using the Doctrine querybuilder
            $qb = $em->createQueryBuilder();
            $qb->select('dr.id AS internal_id')
                ->addSelect('dr.unique_id AS unique_id')
                ->from('ODRAdminBundle:DataRecord', 'dr')
                ->join('ODRAdminBundle:DataRecordMeta', 'drm', 'WITH', 'drm.dataRecord = dr')
                ->where('dr.dataType = :datatype_id')
                ->andWhere('dr.deletedAt IS NULL')->andWhere('drm.deletedAt IS NULL')
                ->setParameter('datatype_id', $datatype_id);

            // TODO - add sql limit?

            // If the user isn't allowed to view non-public datarecords, add that requirement in
            if (!$can_view_datarecord)
                $qb->andWhere('drm.publicDate != :public_date')->setParameter('public_date', '2200-01-01 00:00:00');

            // If this datatype has an external_id field, make sure the query selects it for the
            //  JSON response
            if ($datatype->getExternalIdField() !== null) {
                $external_id_field = $datatype->getExternalIdField()->getId();
                $external_id_fieldtype = $datatype->getExternalIdField()->getFieldType()->getTypeClass();

                $qb->addSelect('e_1.value AS external_id')
                    ->leftJoin('ODRAdminBundle:DataRecordFields', 'drf_1', 'WITH', 'drf_1.dataRecord = dr')
                    ->leftJoin('ODRAdminBundle:'.$external_id_fieldtype, 'e_1', 'WITH', 'e_1.dataRecordFields = drf_1')
                    ->andWhere('e_1.dataField = :external_id_field')
                    ->andWhere('drf_1.deletedAt IS NULL')->andWhere('e_1.deletedAt IS NULL')
                    ->setParameter('external_id_field', $external_id_field);
            }

            // If this datatype has a name field, make sure the query selects it for the JSON response
            if ($datatype->getNameField() !== null) {
                $name_field = $datatype->getNameField()->getId();
                $name_field_fieldtype = $datatype->getNameField()->getFieldType()->getTypeClass();

                $qb->addSelect('e_2.value AS record_name')
                    ->leftJoin('ODRAdminBundle:DataRecordFields', 'drf_2', 'WITH', 'drf_2.dataRecord = dr')
                    ->leftJoin('ODRAdminBundle:'.$name_field_fieldtype, 'e_2', 'WITH', 'e_2.dataRecordFields = drf_2')
                    ->andWhere('e_2.dataField = :name_field')
                    ->andWhere('drf_2.deletedAt IS NULL')->andWhere('e_2.deletedAt IS NULL')
                    ->setParameter('name_field', $name_field);
            }

            $query = $qb->getQuery();
            $results = $query->getArrayResult();

            if ($offset > count($results))
                throw new ODRBadRequestException('This database only has '.count($results).' viewable records, but a starting offset of '.$offset.' was specified.');

            // Organize the datarecord list by their internal id
            $dr_list = array();
            foreach ($results as $result)
                $dr_list[ $result['internal_id'] ] = $result;


            // ----------------------------------------
            // Get the sorted list of datarecords
            $sorted_datarecord_list = $sort_service->getSortedDatarecordList($datatype_id);


            // $sorted_datarecord_list and $dr_list both contain all datarecords of this datatype
            $count = 0;
            $final_datarecord_list = array();
            foreach ($sorted_datarecord_list as $dr_id => $sort_value) {
                // Only save datarecords inside the window that the user specified
                if ( isset($dr_list[$dr_id]) && $count >= $offset && $count < $limit )
                    $final_datarecord_list[] = $dr_list[$dr_id];

                $count++;
            }

            // The list needs to be wrapped in another array...
            $final_datarecord_list = array('records' => $final_datarecord_list);


            // ----------------------------------------
            // Symfony already knows the request format due to use of the _format parameter in the route
            $format = $request->getRequestFormat();
            $data = $this->get('templating')->render(
                'ODRAdminBundle:API:datarecord_list.'.$format.'.twig',
                array(
                    'datarecord_list' => $final_datarecord_list,
                )
            );

            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="Datatype_'.$datatype_uuid.'_list.'.$request->getRequestFormat().'";');
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0xd12ec6ee;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns a list of top-level datatypes that are derived from the given template.
     *
     * @param string $version
     * @param string $datatype_uuid
     * @param integer $limit
     * @param integer $offset
     * @param Request $request
     *
     * @return Response
     */
    public function getTemplateDatatypeListAction($version, $datatype_uuid, $limit, $offset, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;


            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');


            /** @var DataType $template_datatype */
            $template_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $datatype_uuid,
                    'is_master_type' => 1
                )
            );
            if ($template_datatype == null)
                throw new ODRNotFoundException('Datatype');
            $template_datatype_id = $template_datatype->getId();

            $top_level_datatype_ids = $dti_service->getTopLevelDatatypes();
            if ( !in_array($template_datatype_id, $top_level_datatype_ids) )
                throw new ODRBadRequestException('Datatype must be top-level');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // TODO - enforce permissions on template?
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
            // Load all top-level datatypes that are derived from this template
            $query = $em->createQuery(
               'SELECT
                    dt.id AS database_id, dt.unique_id AS unique_id, dtm.shortName AS database_name,
                    dtm.description AS database_description, dtm.publicDate AS public_date,
                    dtm.searchSlug AS search_slug, mdt.unique_id AS template_id
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                JOIN ODRAdminBundle:DataType AS mdt WITH dt.masterDataType = mdt
                WHERE mdt.unique_id = :template_uuid
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND mdt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'template_uuid' => $datatype_uuid
                )
            );
            $results = $query->getArrayResult();

            // Only save the datatypes the user is allowed to see
            $datatype_list = array();
            foreach ($results as $result) {
                $is_public = true;
                if ( $result['public_date']->format('Y-m-d') === '2200-01-01' )
                    $is_public = false;
                unset( $result['public_date'] );

                $dt_id = $result['database_id'];
                $can_view_datatype = false;
                if ( isset($datatype_permissions[$dt_id])
                    && isset($datatype_permissions[$dt_id]['dt_view'])
                ) {
                    $can_view_datatype = true;
                }

                // Organize the datatype list by their internal id
                if ( $is_public || $can_view_datatype )
                    $datatype_list[$dt_id] = $result;
            }

            if ($offset > count($datatype_list))
                throw new ODRBadRequestException('This template only has '.count($datatype_list).' viewable databases, but a starting offset of '.$offset.' was specified.');


            // ----------------------------------------
            // Apply limit/offset to the list and wrap in another array
            $datatype_list = array('databases' => array_slice($datatype_list, $offset, $limit));

            // Symfony already knows the request format due to use of the _format parameter in the route
            $format = $request->getRequestFormat();
            $data = $this->get('templating')->render(
                'ODRAdminBundle:API:datatype_list.'.$format.'.twig',
                array(
                    'datatype_list' => $datatype_list,
                )
            );

            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="template_'.$datatype_uuid.'_list.'.$request->getRequestFormat().'";');
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x1c7b55d0;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates a dataset by cloning the requested master template.
     * Requires a valid master template with metadata template
     *
     * @param $version
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function createdatasetAction($version, Request $request)
    {

        try {

            // Accept JSON or POST?
            // POST Params
            // user_email:nancy.drew@detectivemysteries.com
            // first_name:Nancy
            // last_name:Drew
            // dataset_name:A New Dataset
            // template_uuid:uuid of a template


            $user_email = $_POST['user_email'];
            $template_uuid = $_POST['template_uuid'];

            // Check if user exists & throw user not found error
            // Save which user started this creation process
            $user_manager = $this->container->get('fos_user.user_manager');
            $user = $user_manager->findUserBy(array('email' => $user_email));
            if (is_null($user))
                throw new ODRNotFoundException('User');

            // Check if template is valid
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $master_datatype */
            $master_datatype = $em->getRepository('ODRAdminBundle:DataType')
                ->findOneBy( array('unique_id' => $template_uuid) );

            if ($master_datatype == null)
                throw new ODRNotFoundException('Datatype');


            // If a metadata datatype is loaded directly, need to create full template
            if($metadata_for = $master_datatype->getMetadataFor()) {
                $master_datatype = $metadata_for;
            }

            /** @var DatatypeCreateService $dtc_service */
            $dtc_service = $this->container->get('odr.datatype_create_service');

            /** @var DataType $datatype */
            $datatype = $dtc_service->direct_add_datatype(
                $master_datatype->getId(),
                0,
                $user,
                true
            );


            // Return dataset URL  (201 - created)

            // Return metadata datatype if one exists
            if($metadata_datatype = $datatype->getMetadataDatatype()) {
                $datatype = $metadata_datatype;
            }

            // If this is a metadata type get the first record

            // Retrieve first (and only) record ...
            /** @var DataRecord $metadata_record */
            $metadata_record = $em->getRepository('ODRAdminBundle:DataRecord')
                ->findOneBy( array('dataType' => $datatype->getId()));

            if ( !$metadata_record ) {
                // A metadata datarecord doesn't exist...create one
                /** @var EntityCreationService $entity_create_service */
                $entity_create_service = $this->container->get('odr.entity_creation_service');

                $delay_flush = true;
                $metadata_record = $entity_create_service
                    ->createDatarecord($user, $datatype, $delay_flush);

                // Datarecord is ready, remove provisioned flag
                // TODO Naming is a little weird here
                $metadata_record->setProvisioned(false);
                $em->flush();
            }

            $response = new Response('Created', 201);
            $url = $this->generateUrl('odr_api_get_datarecord_single', array(
                'version' => $version,
                'datarecord_uuid' => $metadata_record->getUniqueId()
            ), false);
            $response->headers->set('Location', $url);


            return $this->redirect($url);

        } catch (\Exception $e) {
            $source = 0x89adf33e;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }


    /**
     * @param $str
     * @return false|string
     * @throws \Exception
     */
    public function mockInternalIdFilter($str)
    {
        try {
            $str = rand(50000000,99999999);
            return $str;
        }
        catch (\Exception $e) {
            throw new \Exception("Error executing Date Now filter");
        }
    }

    /**
     * Creates a mysql compatible date string
     * @param $str
     * @return bool|string
     * @throws \Exception
     */
    public function dateNowFilter($str)
    {
        try {
            $str = date("Y-m-d H:i:s");
            return $str;
        }
        catch (\Exception $e) {
            throw new \Exception("Error executing Date Now filter");
        }
    }

    /**
     * Generates a unique id
     *
     * @param $str
     * @return bool|string
     * @throws \Exception
     */
    public function uniqueIdFilter($str)
    {
        try {
            $str = UniqueUtility::uniqueIdReal();
            return $str;
        }
        catch (\Exception $e) {
            throw new \Exception("Error executing Unique Id filter");
        }
    }

    /**
     * @param $dataset
     * @param $orig_dataset
     * @param $user
     * @param $changed
     * @return mixed
     * @throws \Exception
     */
    private function datasetDiff($dataset, $orig_dataset, $user, &$changed) {
        // Check if radio options are added or updated
        /*
            {
                "name": "geochemistry",
                "template_radio_option_uuid": "0730d71",
                "updated_at": "2018-09-25 16:44:54",
                "id": 58272,
                "selected": "1"
            },
        */

        // Check if fields are added or updated
        try {
            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Check Fields
            /** @var DataRecord $data_record */
            $data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                array(
                    'id' => $dataset['internal_id']
                )
            );


            if(isset($dataset['fields'])) {
                for($i=0; $i < count($dataset['fields']); $i++) {
                    $field = $dataset['fields'][$i];
                    if(is_array($field['value'])) {
                        for($j = 0; $j < count($field['value']); $j++) {
                            $option = $field['value'][$j];
                            // This option exists - replace all options or difference list
                            // Probably should difference list with existing system.



                            if(!isset($option['updated_at'])) {



                                $field['updated_at'] = self::dateNowFilter('');
                                $option['updated_at'] = self::dateNowFilter('');
                                $option['internal_id'] = self::mockInternalIdFilter('');
                                $field['value'][$j] = $option;
                                $dataset['fields'][$i] = $field;

                                // Need to deal with 'other here' - field is not an option, etc.
                                // Create option and set parent...

                            }
                        }
                    }
                    else if($orig_dataset) {
                        foreach($orig_dataset['fields'] as $o_field) {
                            if(!is_array($o_field['value'])
                                && (
                                    $o_field['template_field_uuid'] == $field['template_field_uuid']
                                    || $o_field['field_uuid'] == $field['field_uuid']
                                )
                                && $o_field['value'] !== $field['value']
                            ) {
                                // Update value to new value (delete and enter new data)
                                /** @var DataRecordFields $drf */
                                $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                                    array(
                                        'dataRecord' => $dataset['internal_id'],
                                        'dataField' => $field['id']
                                    )
                                );

                                /** @var DataFields $data_field */
                                $data_field = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                                    array(
                                        'id' => $field['id']
                                    )
                                );

                                $existing_field = null;
                                if(!$drf) {
                                    // If drf entry doesn't exist, create new
                                    $drf = new DataRecordFields();
                                    $drf->setCreatedBy($user);
                                    $drf->setCreated(new \DateTime());
                                    $drf->setDataField($data_field);
                                    $drf->setDataRecord($data_record);
                                    $em->persist($drf);
                                }
                                else {
                                    switch($data_field->getFieldType()->getId()) {
                                        case '4':
                                            $existing_field = $em->getRepository('ODRAdminBundle:IntegerValue')
                                                ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                            break;
                                        case '5':
                                            $existing_field = $em->getRepository('ODRAdminBundle:LongText')
                                                ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                            break;
                                        case '6':
                                            $existing_field = $em->getRepository('ODRAdminBundle:LongVarchar')
                                                ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                            break;
                                        case '7':
                                            $existing_field = $em->getRepository('ODRAdminBundle:MediumVarchar')
                                                ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                            break;
                                        case '9':
                                            $existing_field = $em->getRepository('ODRAdminBundle:ShortVarchar')
                                                ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                            break;
                                        case '16':
                                            $existing_field = $em->getRepository('ODRAdminBundle:DecimalValue')
                                                ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                            break;
                                    }

                                }

                                switch($data_field->getFieldType()->getId()) {
                                    case '4':
                                        /** @var IntegerValue $new_field */
                                        $new_field = new IntegerValue();
                                        if($existing_field) {
                                            // clone and update?
                                            $new_field = clone $existing_field;
                                        }
                                        else {
                                            $new_field->setDataField($data_field);
                                            $new_field->setDataRecord($data_record);
                                            $new_field->setDataRecordFields($drf);
                                            $new_field->setFieldType($data_field->getFieldType());
                                        }

                                        $new_field->setCreatedBy($user);
                                        $new_field->setUpdatedBy($user);
                                        $new_field->setCreated(new \DateTime());
                                        $new_field->setUpdated(new \DateTime());
                                        $new_field->setValue($field['value']);

                                        $em->persist($new_field);
                                        if($existing_field) {
                                            $em->remove($existing_field);
                                        }
                                        $changed = true;
                                        break;
                                    case '5':
                                        /** @var LongText $new_field */
                                        $new_field = new LongText();
                                        if($existing_field) {
                                            // clone and update?
                                            $new_field = clone $existing_field;
                                        }
                                        else {
                                            $new_field->setDataField($data_field);
                                            $new_field->setDataRecord($data_record);
                                            $new_field->setDataRecordFields($drf);
                                            $new_field->setFieldType($data_field->getFieldType());
                                        }

                                        $new_field->setCreatedBy($user);
                                        $new_field->setUpdatedBy($user);
                                        $new_field->setCreated(new \DateTime());
                                        $new_field->setUpdated(new \DateTime());
                                        $new_field->setValue($field['value']);

                                        $em->persist($new_field);
                                        if($existing_field) {
                                            $em->remove($existing_field);
                                        }
                                        $changed = true;
                                        break;

                                    case '6':
                                        /** @var LongVarchar $new_field */
                                        $new_field = new LongVarchar();
                                        if($existing_field) {
                                            // clone and update?
                                            $new_field = clone $existing_field;
                                        }
                                        else {
                                            $new_field->setDataField($data_field);
                                            $new_field->setDataRecord($data_record);
                                            $new_field->setDataRecordFields($drf);
                                            $new_field->setFieldType($data_field->getFieldType());
                                        }

                                        $new_field->setCreatedBy($user);
                                        $new_field->setUpdatedBy($user);
                                        $new_field->setCreated(new \DateTime());
                                        $new_field->setUpdated(new \DateTime());
                                        $new_field->setValue($field['value']);

                                        $em->persist($new_field);
                                        if($existing_field) {
                                            $em->remove($existing_field);
                                        }
                                        $changed = true;
                                        break;
                                    case '7':
                                        /** @var MediumVarchar $new_field */
                                        $new_field = new MediumVarchar();
                                        if($existing_field) {
                                            // clone and update?
                                            $new_field = clone $existing_field;
                                        }
                                        else {
                                            $new_field->setDataField($data_field);
                                            $new_field->setDataRecord($data_record);
                                            $new_field->setDataRecordFields($drf);
                                            $new_field->setFieldType($data_field->getFieldType());
                                        }

                                        $new_field->setCreatedBy($user);
                                        $new_field->setUpdatedBy($user);
                                        $new_field->setCreated(new \DateTime());
                                        $new_field->setUpdated(new \DateTime());
                                        $new_field->setValue($field['value']);

                                        $em->persist($new_field);
                                        if($existing_field) {
                                            $em->remove($existing_field);
                                        }
                                        $changed = true;
                                        break;
                                    case '9':
                                        /** @var ShortVarchar $new_field */
                                        $new_field = new ShortVarchar();
                                        if($existing_field) {
                                            // clone and update?
                                            $new_field = clone $existing_field;
                                        }
                                        else {
                                            $new_field->setDataField($data_field);
                                            $new_field->setDataRecord($data_record);
                                            $new_field->setDataRecordFields($drf);
                                            $new_field->setFieldType($data_field->getFieldType());
                                        }

                                        $new_field->setCreatedBy($user);
                                        $new_field->setUpdatedBy($user);
                                        $new_field->setCreated(new \DateTime());
                                        $new_field->setUpdated(new \DateTime());
                                        $new_field->setValue($field['value']);

                                        $em->persist($new_field);
                                        if($existing_field) {
                                            $em->remove($existing_field);
                                        }
                                        $changed = true;
                                        break;
                                    case '16':
                                        /** @var DecimalValue $new_field */
                                        $new_field = new DecimalValue();
                                        if($existing_field) {
                                            // clone and update?
                                            $new_field = clone $existing_field;
                                        }
                                        else {
                                            $new_field->setDataField($data_field);
                                            $new_field->setDataRecord($data_record);
                                            $new_field->setDataRecordFields($drf);
                                            $new_field->setFieldType($data_field->getFieldType());
                                        }

                                        $new_field->setCreatedBy($user);
                                        $new_field->setUpdatedBy($user);
                                        $new_field->setCreated(new \DateTime());
                                        $new_field->setUpdated(new \DateTime());
                                        $new_field->setValue($field['value']);

                                        $em->persist($new_field);
                                        if($existing_field) {
                                            $em->remove($existing_field);
                                        }
                                        $changed = true;
                                        break;
                                    default:
                                        break;
                                }
                            }
                        }
                    }
                }
            }

            // Need to check for child & linked records
            // Create child if new one added
            // Create link if needed (possibly creating record in link)
            // Search for record to link??
            if(isset($dataset['records'])) {
                for($i = 0; $i < count($dataset['records']); $i++) {
                    $record = $dataset['records'][$i];
                    if($orig_dataset && isset($orig_dataset['records'])) {
                        for($j = 0; $j < count($orig_dataset['records']); $j++) {
                            $o_record = $orig_dataset['records'][$j];
                            if($record['template_uuid'] == $o_record->template_uuid) {
                                $output = self::datasetDiff($record, $o_record, $user, $changed);
                                $dataset['records'][$i] = $output;
                            }
                        }
                    }
                }
            }

            if($changed) {
                // Mark this datarecord as updated
                $em->flush();
                $cache_service->get('cached_datarecord_'.$data_record->getId());
                $dri_service->updateDatarecordCacheEntry($data_record, $user);
            }

            // Check Related datatypes
            return $dataset;
        }
        catch(\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }


    /**
     * @param $version
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function updatedatasetAction($version, Request $request)
    {

        try {
            $content = $request->getContent();
            if (!empty($content))
            {
                $dataset_data = json_decode($content, true); // 2nd param to get as array
                $dataset = $dataset_data['dataset'];

                // Accept JSON or POST?
                // POST Params
                // user_email:nancy.drew@detectivemysteries.com
                // first_name:Nancy
                // last_name:Drew
                // dataset_name:A New Dataset
                // template_uuid:uuid of a template

                $user_email = $dataset_data['user_email'];
                $record_uuid = $dataset['record_uuid'];


                // Check API permission level (if SuperAPI - override user)
                // API Super should be able to administer datatypes derived from certain templates
                $user_manager = $this->container->get('fos_user.user_manager');
                // TODO fix this to use API Credential
                /** @var FOSUser $admin */
                $admin = $user_manager->findUserBy(array('email' => 'nate@opendatarepository.org'));
                if (is_null($admin))
                    throw new ODRNotFoundException('User');

                /** @var CacheService $cache_service */
                $cache_service = $this->container->get('odr.cache_service');
                $metadata_record = $cache_service
                    ->get('json_record_' . $record_uuid . '_' . $admin->getId());


                if(!$metadata_record) {
                    // Need to pull record using getExport...
                    $metadata_record = self::getRecordData(
                        $version,
                        $record_uuid,
                        $request->getRequestFormat(),
                        $admin
                    );
                }
                else {
                    // Check if dataset has public attribute
                    $metadata_record = json_decode($metadata_record, true);
                }

                // User to act as for changes
                // Need to check permissions on each elemen
                $user_manager = $this->container->get('fos_user.user_manager');
                // TODO fix this to use API Credential
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('User');

                // Generate internal ids or database uuids as needed
                // TODO Incorporate actual user here for permissions
                $changed = false;
                $dataset = self::datasetDiff($dataset, $metadata_record, $user, $changed);

                if($changed) {
                    // Get dataset again

                    // This will also cache the updated record
                    $metadata_record = self::getRecordData(
                        $version,
                        $record_uuid,
                        $request->getRequestFormat(),
                        false,
                        $admin,
                        true
                    );

                }

                // Respond and redirect to record
                $response = new Response('Updated', 200);
                $url = $this->generateUrl('odr_api_get_datarecord_single', array(
                    'version' => $version,
                    'datarecord_uuid' => $record_uuid
                ), false);
                $response->headers->set('Location', $url);

                return $this->redirect($url);

            }
            else {
                throw new ODRException('No dataset data to update.');
            }

        } catch (\Exception $e) {
            $source = 0x89adf33e;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }


    /**
     * @param $version
     * @param $datarecord_uuid
     * @param Request $request
     */
    public function getRecordAction($version, $datarecord_uuid, Request $request) {

        try {

            // Check API permission level (if SuperAPI - override user)
            // API Super should be able to administer datatypes derived from certain templates

            $user_manager = $this->container->get('fos_user.user_manager');
            // TODO fix this to use API Credential
            $user = $user_manager->findUserBy(array('email' => 'nate@opendatarepository.org'));
            if (is_null($user))
                throw new ODRNotFoundException('User');

            return $this->getDatarecordExportAction(
                $version,
                $datarecord_uuid,
                $request,
                $user
            );


        } catch (\Exception $e) {
            $source = 0x722347a6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }

    /**
     * @param $version
     * @param $datarecord_uuid
     * @param $format
     * @param bool $display_metadata
     * @param null $user
     * @param bool $flush
     * @return array|bool|string
     */
    private function getRecordData(
        $version,
        $datarecord_uuid,
        $format,
        $display_metadata = false,
        $user = null,
        $flush = false
    ) {
        // ----------------------------------------
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var DatarecordExportService $dre_service */
        $dre_service = $this->container->get('odr.datarecord_export_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');


        /** @var DataRecord $datarecord */
        $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
            array('unique_id' => $datarecord_uuid)
        );
        if ($datarecord == null)
            throw new ODRNotFoundException('Datarecord');

        $datarecord_id = $datarecord->getId();

        $datatype = $datarecord->getDataType();
        if (!$datatype || $datatype->getDeletedAt() != null)
            throw new ODRNotFoundException('Datatype');

        if ($datarecord->getId() != $datarecord->getGrandparent()->getId())
            throw new ODRBadRequestException('Only permitted on top-level datarecords');


        // ----------------------------------------
        // Determine user privileges
        /** @var ODRUser $user */
        if($user === null) {
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
        }

        // If either the datatype or the datarecord is not public, and the user doesn't have
        //  the correct permissions...then don't allow them to view the datarecord
        if ( !$pm_service->canViewDatatype($user, $datatype) )
            throw new ODRForbiddenException();

        if ( !$pm_service->canViewDatarecord($user, $datarecord) )
            throw new ODRForbiddenException();


        // TODO - system needs to delete these keys when record is updated elsewhere
        /** @var CacheService $cache_service */
        $cache_service = $this->container->get('odr.cache_service');
        $data = $cache_service
            ->get('json_record_' . $datarecord_uuid . '_' . $user->getId());

        if(!$data || $flush) {
            // Render the requested datarecord
            $data = $dre_service->getData(
                $version,
                array($datarecord_id),
                $format,
                $display_metadata,
                $user,
                $this->container->getParameter('site_baseurl'),
               0
            );

            // Cache this data for faster retrieval
            // TODO work out how to expire this data...
            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            $cache_service->set(
                'json_record_' . $datarecord_uuid . '_'. $user->getId(),
                $data
            );
        }

        return $data;
    }

    /**
     * Renders and returns the json/XML version of the given DataRecord.
     *
     * @param $version
     * @param $datarecord_uuid
     * @param Request $request
     * @param null $user
     * @return Response
     */
    public function getDatarecordExportAction($version, $datarecord_uuid, Request $request, $user = null)
    {
        try {
            // ----------------------------------------
            // Default to only showing all info about the datarecord
            $display_metadata = true;
            if ($request->query->has('metadata') && $request->query->get('metadata') == 'false')
                // ...but restrict to only the most useful info upon request
                $display_metadata = false;

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;


            $data = self::getRecordData(
                $version,
                $datarecord_uuid,
                $request->getRequestFormat(),
                $display_metadata,
                $user
            );


            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set(
                    'Content-Disposition',
                    'attachment; filename="Datarecord_'.$data['internal_id'].'.'.$request->getRequestFormat().'";'
                );
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x722347a6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function tokenAction(Request $request)
    {


        $api_key = $_POST['api_key'];
        $api_secret = $_POST['api_secret'];
        $user_manager = $this->container->get('fos_user.user_manager');
        /** @var FOSUser $user */
        $user = $user_manager->findUserBy(array('email' => $api_key));

        if (!$user) {
            throw $this->createNotFoundException();
        }
        $isValid = $this->get('security.password_encoder')
            ->isPasswordValid($user, $api_secret);

        if (!$isValid) {
            throw new BadCredentialsException();
        }
        $token = $this->get('lexik_jwt_authentication.encoder')
            ->encode([
                'username' => $user->getUsername(),
                'exp' => time() + 3600 // 1 hour expiration
            ]);
        return new JsonResponse(['token' => 'AAAlA -- ' . $token]);
    }


    /**
     * Returns a list of radio options in the given template field, and a count of how many
     * datarecords from datatypes derived from the given template have those options selected.
     *
     * @param $version
     * @param $template_uuid
     * @param $template_field_uuid
     * @param Request $request
     */
    public function getfieldstatsAction(
        $version,
        $template_uuid,
        $template_field_uuid,
        Request $request
    ) {
        try {
            // ----------------------------------------

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;

            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');

            /** @var DataType $template_datatype */
            $template_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $template_uuid,
                    'is_master_type' => 1    // require master template
                )
            );
            if ($template_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataFields $template_datafield */
            $template_datafield = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                array(
                    'dataType' => $template_datatype->getId(),
                    'fieldUuid' => $template_field_uuid
                )
            );
            if ($template_datafield == null)
                throw new ODRNotFoundException('Datafield');

            $typeclass = $template_datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio' && $typeclass !== 'Tag')
                throw new ODRBadRequestException('Getting field stats only makes sense for Radio or Tag fields');

            $item_label = 'template_radio_option_uuid';
            if ($typeclass === 'Tag')
                $item_label = 'template_tag_uuid';

            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $token = $this->container->get('security.token_storage')->getToken();   // <-- will return 'anon.' when nobody is logged in
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // TODO - should permissions get involved on the template side?
/*
            // If either the datatype or the datarecord is not public, and the user doesn't have
            //  the correct permissions...then don't allow them to view the datarecord
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            if ( !$pm_service->canViewDatarecord($user, $datarecord) )
                throw new ODRForbiddenException();
*/

            // ----------------------------------------
            // Craft a search key specifically for this API call
            $params = array(
                "template_uuid" => $template_uuid,
                "field_stats" => $template_field_uuid,
            );
            $search_key = $search_key_service->encodeSearchKey($params);

            // Don't need to validate the search key...don't want people to be able to run this
            //  type of search without going through this action anyways

            $result = $search_api_service->performTemplateSearch($search_key, $user_permissions);

            $labels = $result['labels'];
            $records = $result['records'];

            // Translate the two provided arrays into a a slightly different format
            $data = array();
            foreach ($records as $dt_id => $df_list) {
                foreach ($df_list as $df_id => $dr_list) {
                    foreach ($dr_list as $dr_id => $item_list) {
                        foreach ($item_list as $num => $item_uuid) {
                            $item_name = $labels[$item_uuid];
                            if ( !isset($data[$item_name]) ) {
                                $data[$item_name] = array(
                                    'count' => 0,
                                    $item_label => $item_uuid
                                );
                            }

                            $data[$item_name]['count']++;
                        }
                    }
                }
            }

            // Sort the options in descending order by number of datarecords where they're selected
            uasort($data, function($a, $b) {
                if ( $a['count'] < $b['count'] )
                    return 1;
                else if ( $a['count'] == $b['count'] )
                    return 0;
                else
                    return -1;
            });


            // ----------------------------------------
            // Render the data in the requested format
            $format = $request->getRequestFormat();
            $templating = $this->get('templating');
            $data = $templating->render(
                'ODRAdminBundle:API:field_stats.'.$format.'.twig',
                array(
                    'field_stats' => $data
                )
            );

            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="Datafield_'.$template_field_uuid.'.'.$request->getRequestFormat().'";');
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x883def33;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Assuming the user has permissions to do so, creates a Symfony StreamedResponse for a file download
     *
     * @param string $version
     * @param integer $file_id
     * @param Request $request
     *
     * @return JsonResponse|StreamedResponse
     */
    public function filedownloadAction($version, $file_id, Request $request)
    {
        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');

            $datafield = $file->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datarecord = $file->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');
            $datarecord = $datarecord->getGrandparent();

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files that aren't done encrypting shouldn't be downloaded
            if ($file->getProvisioned() == true)
                throw new ODRNotFoundException('File');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if ( !$pm_service->canViewFile($user, $file) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // Only allow this action for files smaller than 5Mb?
            $filesize = $file->getFilesize() / 1024 / 1024;
            if ($filesize > 5)
                throw new ODRNotImplementedException('Currently not allowed to download files larger than 5Mb');


            $filename = 'File_'.$file_id.'.'.$file->getExt();
            if ( !$file->isPublic() )
                $filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId()).'.'.$file->getExt();

            $local_filepath = realpath( $this->getParameter('odr_web_directory').'/'.$file->getUploadDir().'/'.$filename );
            if (!$local_filepath)
                $local_filepath = $crypto_service->decryptFile($file->getId(), $filename);

            $handle = fopen($local_filepath, 'r');
            if ($handle === false)
                throw new FileNotFoundException($local_filepath);


            // Attach the original filename to the download
            $display_filename = $file->getOriginalFileName();
            if ($display_filename == null)
                $display_filename = 'File_'.$file->getId().'.'.$file->getExt();

            // Set up a response to send the file back
            $response = new StreamedResponse();
            $response->setPrivate();
            $response->headers->set('Content-Type', mime_content_type($local_filepath));
            $response->headers->set('Content-Length', filesize($local_filepath));        // TODO - apparently this isn't sent?
            $response->headers->set('Content-Disposition', 'attachment; filename="'.$display_filename.'";');
/*
            // Have to specify all these properties just so that the last one can be false...otherwise Flow.js can't keep track of the progress
            $response->headers->setCookie(
                new Cookie(
                    'fileDownload', // name
                    'true',         // value
                    0,              // duration set to 'session'
                    '/',            // default path
                    null,           // default domain
                    false,          // don't require HTTPS
                    false           // allow cookie to be accessed outside HTTP protocol
                )
            );
*/
            //$response->sendHeaders();

            // Use symfony's StreamedResponse to send the decrypted file back in chunks to the user

            $response->setCallback(function () use ($handle) {
                while (!feof($handle)) {
                    $buffer = fread($handle, 65536);    // attempt to send 64Kb at a time
                    echo $buffer;
                    flush();
                }
                fclose($handle);
            });

            // If file is non-public, delete the decrypted version off the server
            if ( !$file->isPublic() )
                unlink( $local_filepath );

            return $response;
        }
        catch (\Exception $e) {
            // Returning an error...do it in json
            $request->setRequestFormat('json');

            $source = 0xbbaafae5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Assuming the user has permissions to do so, creates a Symfony StreamedResponse for an image download
     *
     * @param string $version
     * @param integer $image_id
     * @param Request $request
     *
     * @return JsonResponse|StreamedResponse
     */
    public function imagedownloadAction($version, $image_id, Request $request)
    {
        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var Image $image */
            $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');

            $datafield = $image->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');
            $datarecord = $datarecord->getGrandparent();

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Images that aren't done encrypting shouldn't be downloaded
            if ($image->getEncryptKey() == '')
                throw new ODRNotFoundException('Image');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if ( !$pm_service->canViewImage($user, $image) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // TODO - Only allow this action for images smaller than 5Mb?  filesize isn't being stored in the database though...
/*
            $filesize = $image->->getFilesize() / 1024 / 1024;
            if ($filesize > 5)
                throw new ODRNotImplementedException('Currently not allowed to download files larger than 5Mb');
*/

            // Ensure image exists before attempting to download it
            $filename = 'Image_'.$image_id.'.'.$image->getExt();
            if ( !$image->isPublic() )
                $filename = md5($image->getOriginalChecksum().'_'.$image_id.'_'.$user->getId()).'.'.$image->getExt();

            // Ensure the image exists in decrypted format
            $image_path = realpath( $this->getParameter('odr_web_directory').'/'.$filename );     // realpath() returns false if file does not exist
            if ( !$image->isPublic() || !$image_path )
                $image_path = $crypto_service->decryptImage($image_id, $filename);

            $handle = fopen($image_path, 'r');
            if ($handle === false)
                throw new FileNotFoundException($image_path);


            // Attach the original filename to the download
            $display_filename = $image->getOriginalFileName();
            if ($display_filename == null)
                $display_filename = 'Image_'.$image->getId().'.'.$image->getExt();

            // Set up a response to send the image back
            $response = new StreamedResponse();
            $response->setPrivate();
            $response->headers->set('Content-Type', mime_content_type($image_path));
            $response->headers->set('Content-Length', filesize($image_path));        // TODO - apparently this isn't sent?
            $response->headers->set('Content-Disposition', 'attachment; filename="'.$display_filename.'";');
/*
            // Have to specify all these properties just so that the last one can be false...otherwise Flow.js can't keep track of the progress
            $response->headers->setCookie(
                new Cookie(
                    'fileDownload', // name
                    'true',         // value
                    0,              // duration set to 'session'
                    '/',            // default path
                    null,           // default domain
                    false,          // don't require HTTPS
                    false           // allow cookie to be accessed outside HTTP protocol
                )
            );
*/
            //$response->sendHeaders();

            // Use symfony's StreamedResponse to send the decrypted image back in chunks to the user

            $response->setCallback(function () use ($handle) {
                while (!feof($handle)) {
                    $buffer = fread($handle, 65536);    // attempt to send 64Kb at a time
                    echo $buffer;
                    flush();
                }
                fclose($handle);
            });

            // If image is non-public, delete the decrypted version off the server
            if ( !$image->isPublic() )
                unlink( $image_path );

            return $response;
        }
        catch (\Exception $e) {
            // Returning an error...do it in json
            $request->setRequestFormat('json');

            $source = 0x8a8b2309;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
