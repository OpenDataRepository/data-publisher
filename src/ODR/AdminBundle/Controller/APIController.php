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

// Entities
use ODR\AdminBundle\Entity\Boolean as ODRBoolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Entity\TagMeta;
use ODR\AdminBundle\Entity\TagSelection;
use ODR\AdminBundle\Entity\TagTree;
use ODR\AdminBundle\Entity\UserGroup;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
use ODR\AdminBundle\Component\Event\DatarecordLinkStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordPublicStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatatypeModifiedEvent;
use ODR\AdminBundle\Component\Event\DatatypePublicStatusChangedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordExportService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\DatatypeCreateService;
use ODR\AdminBundle\Component\Service\DatatypeExportService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityDeletionService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRUploadService;
use ODR\AdminBundle\Component\Service\ODRUserGroupMangementService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\UUIDService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Doctrine\ORM\EntityManager;
use FOS\UserBundle\Doctrine\UserManager;
use HWI\Bundle\OAuthBundle\Tests\Fixtures\FOSUser;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;


class APIController extends ODRCustomController
{

    /**
     * Returns basic information about the currently logged-in user to the API.
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

            if ($user != 'anon.' /*&& $user->hasRole('ROLE_JUPYTERHUB_USER')*/) {
                $user_array = array(
                    'id' => $user->getEmail(),
                    'username' => $user->getUserString(),
                    'realname' => $user->getUserString(),
                    'email' => $user->getEmail(),
                    'baseurl' => $this->getParameter('site_baseurl'),
                );

                if ($this->has('odr.jupyterhub_bridge.username_service'))
                    $user_array['jupyterhub_username'] = $this->get('odr.jupyterhub_bridge.username_service')->getJupyterhubUsername($user);


                // Symfony already knows the request format due to use of the _format parameter in the route
                $format = $request->getRequestFormat();
                $data = $this->get('templating')->render(
                    'ODRAdminBundle:API:userdata.' . $format . '.twig',
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns an array of identifying information for all datatypes the user can view.
     *
     * By default, returns top-level datatypes as json to the browser...however, it can also display
     * child datatypes and/or return the response as a file.
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
            if ($type === 'master_templates')
                $is_master_type = 1;


            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
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

                if ( $can_view_datatype || $public_date !== '2200-01-01 00:00:00' )
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
                'ODRAdminBundle:API:datatype_list.' . $format . '.twig',
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
                $response->headers->set('Content-Disposition', 'attachment; filename="Datatype_list.' . $request->getRequestFormat() . '";');
            }

            // Symfony should automatically set the response format based on the request format
            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x5dc89429;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Utility function to recursively inflate the datatype array for self::datatypelistAction()
     * Can't use the one in the DatabaseInfoService because this array has a different structure
     *
     * @param array $source_data
     * @param array $datatree_array @see DatatreeInfoService::getDatatreeArray()
     * @param integer $parent_datatype_id
     *
     * @return array
     */
    private function inflateDatatypeArray($source_data, $datatree_array, $parent_datatype_id)
    {
        $child_datatype_data = array();

        // Search for any children of the parent datatype
        foreach ($datatree_array['descendant_of'] as $child_dt_id => $parent_dt_id) {
            // If a child was found, and it exists in the source data array...
            if ($parent_dt_id == $parent_datatype_id && isset($source_data[$child_dt_id])) {
                // ...store the child datatype's data
                $child_datatype_data[$child_dt_id] = $source_data[$child_dt_id];

                // ...find all of this datatype's children, if it has any
                $tmp = self::inflateDatatypeArray($source_data, $datatree_array, $child_dt_id);
                if (count($tmp) > 0)
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
            // /api/v4/template/{datatype_uuid} now returns a datatype template
            // /api/v4/master/{datatype_uuid} now returns a master datatype template
            // previous API versions return master templates
            $is_master_type = 0;
            if ($type === 'master_template' && $version !== 'v4')
                $is_master_type = 1;


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeExportService $dte_service */
            $dte_service = $this->container->get('odr.datatype_export_service');
            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
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
                $response->headers->set('Content-Disposition', 'attachment; filename="Datatype_' . $datatype_id . '.' . $request->getRequestFormat() . '";');
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x43dd4818;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
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

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
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

            $str =
               'SELECT dr.id AS dr_id, dr.unique_id AS dr_uuid
                FROM ODRAdminBundle:DataRecord AS dr
                LEFT JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                WHERE dr.dataType = :datatype_id
                AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL';
            $params = array('datatype_id' => $datatype_id);
            if ( !$can_view_datarecord ) {
                $str .= ' AND drm.publicDate != :public_date';
                $params['public_date'] = '2200-01-01 00:00:00';
            }

            $query = $em->createQuery($str)->setParameters($params);
            $results = $query->getArrayResult();

            if ( $offset > count($results) )
                throw new ODRBadRequestException('This database only has '.count($results).' viewable records, but a starting offset of '.$offset.' was specified.');

            $dr_list = array();
            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $dr_uuid = $result['dr_uuid'];

                $dr_list[$dr_id] = array(
                    'internal_id' => $dr_id,
                    'unique_id' => $dr_uuid,
                    'external_id' => '',
                    'record_name' => '',
                );
            }

            // If this datatype has an external_id field, then its values should be in the output
            if ( !is_null($datatype->getExternalIdField()) ) {
                // The field's contents are likely cached via the SortService, so use that
                $external_id_values = $sort_service->sortDatarecordsByDatafield($datatype->getExternalIdField()->getId());
                foreach ($external_id_values as $dr_id => $value)
                    $dr_list[$dr_id]['external_id'] = $value;
            }

            // If this datatype has a name field, then those values should also be in the output
            if ( !empty($datatype->getNameFields()) ) {
                // Might as well use the sort service for this too, but it's slightly trickier
                //  since the name values could be combined from multiple fields...
                foreach ($datatype->getNameFields() as $name_df) {
                    $values = $sort_service->sortDatarecordsByDatafield($name_df->getId());
                    foreach ($values as $dr_id => $value) {
                        if ($dr_list[$dr_id]['record_name'] === '')
                            $dr_list[$dr_id]['record_name'] = $value;
                        else
                            $dr_list[$dr_id]['record_name'] .= ' '.$value;
                    }
                }
            }


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
                'ODRAdminBundle:API:datarecord_list.' . $format . '.twig',
                array(
                    'datarecord_list' => $final_datarecord_list,
                )
            );

            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="Datatype_' . $datatype_uuid . '_list.' . $request->getRequestFormat() . '";');
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0xd12ec6ee;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
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

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $template_datatype */
            $template_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $datatype_uuid,
                    'is_master_type' => 1
                )
            );
            if ($template_datatype == null)
                throw new ODRNotFoundException('Template Datatype');
            $template_datatype_id = $template_datatype->getId();

            $top_level_datatype_ids = $dti_service->getTopLevelDatatypes();
            if (!in_array($template_datatype_id, $top_level_datatype_ids))
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
                if ($result['public_date']->format('Y-m-d') === '2200-01-01')
                    $is_public = false;
                unset($result['public_date']);

                $dt_id = $result['database_id'];
                $can_view_datatype = false;
                if (isset($datatype_permissions[$dt_id])
                    && isset($datatype_permissions[$dt_id]['dt_view'])
                ) {
                    $can_view_datatype = true;
                }

                // Organize the datatype list by their internal id
                if ($is_public || $can_view_datatype)
                    $datatype_list[$dt_id] = $result;
            }

            if ($offset > count($datatype_list))
                throw new ODRBadRequestException('This template only has ' . count($datatype_list) . ' viewable databases, but a starting offset of ' . $offset . ' was specified.');


            // ----------------------------------------
            // Apply limit/offset to the list and wrap in another array
            $datatype_list = array('databases' => array_slice($datatype_list, $offset, $limit));

            // Symfony already knows the request format due to use of the _format parameter in the route
            $format = $request->getRequestFormat();
            $data = $this->get('templating')->render(
                'ODRAdminBundle:API:datatype_list.' . $format . '.twig',
                array(
                    'datatype_list' => $datatype_list,
                )
            );

            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="template_' . $datatype_uuid . '_list.' . $request->getRequestFormat() . '";');
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x1c7b55d0;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates a datarecord for an existing dataset.
     * Requires a valid dataset and user permissions.
     *
     * @param string $version
     * @param string $dataset_uuid
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function createrecordAction($version, $dataset_uuid, Request $request)
    {
        try {
            $data = $request->request->all();

            // Only used if SuperAdmin & Present
            $user_email = null;
            if ( isset($data['user_email']) )
                $user_email = $data['user_email'];

            if ( is_null($dataset_uuid) || strlen($dataset_uuid) < 1 )
                throw new ODRNotFoundException('Datatype');


            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array('unique_id' => $dataset_uuid)
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            /** @var ODRUser $user */
            $user = null;

            if ( is_null($user_email) ) {
                // If a user email wasn't provided, then use the admin user for this action
                $user = $logged_in_user;
            }
            else if ( !is_null($user_email) && $logged_in_user->hasRole('ROLE_SUPER_ADMIN') ) {
                // If a user email was provided, and the user calling this action is a super-admin,
                //  then attempt to locate the user for the given email
                /** @var UserManager $user_manager */
                $user_manager = $this->container->get('fos_user.user_manager');
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if ($user == null)
                    throw new ODRNotFoundException('The User "'.$user_email.'" does not exist', true);
            }

            if ($user == null)
                throw new ODRNotFoundException('User');

            // Ensure this user can create a record for this datatype
            if ( !$pm_service->canAddDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------

            // This API action allows the user to set the created/updated date to something other
            //  than today...
            $created = null;
            if ( isset($data['created']) )
                $created = new \DateTime($data['created']);

            // ...the user is also allowed to immediately set the public date of the new record
            $public_date = null;
            if ( isset($data['public_date']) )
                $public_date = new \DateTime($data['public_date']);


            // Create the new record
            $datarecord = $ec_service->createDatarecord(
                $user,
                $datatype,
                true,    // delay flush, incase public date needs to be set
                true,    // select default radio options
                $created // create the record on the given date
            );

            // Set the record's public date if it was requested
            if ( !is_null($public_date) ) {
                $datarecord_meta = $datarecord->getDataRecordMeta();
                $datarecord_meta->setPublicDate($public_date);
                $em->persist($datarecord_meta);
            }

            // Datarecord is ready, remove provisioned flag
            // TODO Naming is a little weird here
            $datarecord->setProvisioned(false);
            $em->persist($datarecord);

            // Save all changes
            $em->flush();


            // ----------------------------------------
            // This is wrapped in a try/catch block because any uncaught exceptions will abort
            //  creation of the new datarecord...
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordCreatedEvent($datarecord, $user);
                $dispatcher->dispatch(DatarecordCreatedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't particularly want to rethrow the error since it'll interrupt
                //  everything downstream of the event (such as file encryption...), but
                //  having the error disappear is less ideal on the dev environment...
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // ----------------------------------------
            $response = new Response('Created', 201);
            $url = $this->generateUrl(
                'odr_api_get_dataset_record',
                array(
                    'version' => $version,
                    'record_uuid' => $datarecord->getUniqueId()
                ),
                false
            );
            $response->headers->set('Location', $url);

            return $this->redirect($url);
        }
        catch (\Exception $e) {
            $source = 0x773df3ed;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * TODO - this action does nothing, and isn't referenced from the routing file...delete?
     *
     * @param string $version
     * @param Request $request
     */
    public function assignPermission($version, Request $request)
    {
        try {
            // Accept JSON or POST?
            // POST Params
            // user_email:nancy.drew@detectivemysteries.com
            // first_name:Nancy
            // last_name:Drew
            // dataset_name:A New Dataset
            // template_uuid:uuid of a template


            $user_email = null;
            if(isset($_POST['user_email']))
                $user_email = $_POST['user_email'];

            // We must check if the logged in user is acting as a user
            // When acting as a user, the logged in user must be a SuperAdmin
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ($user_email === '') {
                // User is setting up dataset for themselves - always allowed
                $user_email = $logged_in_user->getEmail();
            } else if (!$logged_in_user->hasRole('ROLE_SUPER_ADMIN')) {
                // We are acting as a user and do not have Super Permissions - Forbidden
                throw new ODRForbiddenException();
            }

            // Check if user exists & throw user not found error
            // Save which user started this creation process
            // Any user can create a dataset as long as they exist
            // No need to do further permissions checks.
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser $user */
            $user = $user_manager->findUserBy(array('email' => $user_email));
            if (is_null($user))
                throw new ODRNotFoundException('User');

        }
        catch (\Exception $e) {
            $source = 0x88a02ef3;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates a dataset by cloning the requested master template.
     * Requires a valid master template with metadata template
     * TODO
     *
     * @param string $version
     * @param Request $request
     *
     * @return RedirectResponse
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


            $user_email = null;
            if(isset($_POST['user_email']))
                $user_email = $_POST['user_email'];

            if(!isset($_POST['template_uuid']))
                throw new ODRBadRequestException("Template UUID is required.");

            $template_uuid = $_POST['template_uuid'];

            // we must check if the logged in user is acting as a user
            // when acting as a user, the logged in user must be a superadmin
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->gettoken()->getuser();   // <-- will return 'anon.' when nobody is logged in
            if($user_email === '') {
                // user is setting up dataset for themselves - always allowed
                $user_email = $logged_in_user->getEmail();
            }
            else if(!$logged_in_user->hasRole('role_super_admin')) {
                // we are acting as a user and do not have super permissions - forbidden
                throw new ODRForbiddenException();
            }

            // check if user exists & throw user not found error
            // save which user started this creation process
            // any user can create a dataset as long as they exist
            // no need to do further permissions checks.
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser $user */
            $user = $user_manager->finduserby(array('email' => $user_email));
            if (is_null($user))
                throw new ODRNotFoundException('user');

            // Check if template is valid
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            /** @var DataType $master_datatype */
            $master_datatype = $repo_datatype
                ->findOneBy(array('unique_id' => $template_uuid));

            if ($master_datatype == null)
                throw new ODRNotFoundException('Datatype');

            // If a metadata datatype is loaded directly, need to create full template
            if ($metadata_for = $master_datatype->getMetadataFor()) {
                $master_datatype = $metadata_for;
            }

            // Check here if a database exists in "preload" mode with correct version
            /** @var DataType[] $datatypes */
            $datatypes = $repo_datatype->findBy([
                'masterDataType' => $master_datatype->getMetadataDatatype()->getId(),
                'preload_status' => $master_datatype->getMetadataDatatype()->getDataTypeMeta()->getMasterRevision()
            ]);

            /*
            print $master_datatype->getMetadataDatatype()->getId() . " -- ";
            print $master_datatype->getMetadataDatatype()->getDataTypeMeta()->getMasterRevision() . " -- ";
            print count($datatypes) . " - ";
            print $datatypes[0]->getId(); exit();
            */

            $datatype = null;
            if(count($datatypes) > 0) {
                // Use the prebuilt datatype
                // This is a metadata datatype
                /** @var DataType $metadata_datatype */
                $metadata_datatype = $datatypes[0];

                /** @var \DateTime $date_value */
                $date_value = new \DateTime();

                /** @var DataType[] $related_datatypes */
                $related_datatypes = $repo_datatype->findBy([
                    'template_group' => $metadata_datatype->getTemplateGroup()
                ]);
                foreach($related_datatypes as $related_datatype) {
                    $related_datatype->setCreatedBy($user);
                    $related_datatype->setUpdatedBy($user);
                    $related_datatype->setCreated($date_value);
                    $related_datatype->setUpdated($date_value);
                    $related_datatype->setPreloadStatus('issued');
                    $permission_groups = $related_datatype->getGroups();

                    /** @var Group[] $permission_groups */
                    foreach($permission_groups as $group) {
                        if ($group->getPurpose() == 'admin') {
                            $user_group = new UserGroup();
                            $user_group->setUser($user);
                            $user_group->setGroup($group);
                            $user_group->setCreated($date_value);
                            $user_group->setCreatedBy($user);
                            $em->persist($user_group);
                        }
                    }
                }

                /** @var DataRecord $metadata_record */
                $metadata_record = $em->getRepository('ODRAdminBundle:DataRecord')
                    ->findOneBy(array('dataType' => $metadata_datatype->getId()));

                $metadata_record->setCreated($date_value);
                $metadata_record->setCreatedBy($user);
                $metadata_record->setUpdated($date_value);
                $metadata_record->setUpdatedBy($user);

                $em->persist($metadata_record);
                // Updating datatype info
                $em->flush();

                /** @var CacheService $cache_service */
                $cache_service = $this->container->get('odr.cache_service');
                $cache_service->delete('user_'.$user->getId().'_permissions');

                // Now get the json record and update it with the correct user_id ant date times
                $json_metadata_record = $cache_service
                    ->get('json_record_' . $metadata_record->getUniqueId());


                if (!$json_metadata_record) {
                    // Need to pull record using getExport...
                    $json_metadata_record = self::getRecordData(
                        'v3',
                        $metadata_record->getUniqueId(),
                        'json',
                        $user
                    );

                    if ($json_metadata_record) {
                        $json_metadata_record = json_decode($json_metadata_record, true);
                    }
                } else {
                    // Check if dataset has public attribute
                    $json_metadata_record = json_decode($json_metadata_record, true);
                }

                // parse through and fix metadata
                $json_metadata_record = self::checkRecord($json_metadata_record, $user, $date_value);

                $cache_service->set('json_record_' . $metadata_record->getUniqueId(),  json_encode($json_metadata_record));

                // set the "datatype" to the metadata datatype
                $datatype = $metadata_datatype;
            }
            else {
                /** @var DatatypeCreateService $dtc_service */
                $dtc_service = $this->container->get('odr.datatype_create_service');

                /** @var DataType $datatype */
                $datatype = $dtc_service->direct_add_datatype(
                    $master_datatype->getId(),
                    0,
                    $user,
                    true
                );

                // ----------------------------------------
                // Both paths of $dtc_service->direct_add_datatype() call CloneMasterDatatypeService,
                //  so don't need to fire off a DatatypeCreated event for the new datatype here
//                try {
//                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
//                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
//                    /** @var EventDispatcherInterface $event_dispatcher */
//                    $dispatcher = $this->get('event_dispatcher');
//                    $event = new DatatypeCreatedEvent($datatype, $user);
//                    $dispatcher->dispatch(DatatypeCreatedEvent::NAME, $event);
//                }
//                catch (\Exception $e) {
//                    // ...don't want to rethrow the error since it'll interrupt everything after this
//                    //  event
////                if ( $this->container->getParameter('kernel.environment') === 'dev' )
////                    throw $e;
//                }

                // Return metadata datatype if one exists
                if ($metadata_datatype = $datatype->getMetadataDatatype()) {
                    $datatype = $metadata_datatype;
                }
            }
            // If this is a metadata type get the first record

            // Retrieve first (and only) record ...
            /** @var DataRecord $metadata_record */
            $metadata_record = $em->getRepository('ODRAdminBundle:DataRecord')
                ->findOneBy(array('dataType' => $datatype->getId()));

            if (!$metadata_record) {
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

                // This is wrapped in a try/catch block because any uncaught exceptions will abort
                //  creation of the new datarecord...
                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new DatarecordCreatedEvent($metadata_record, $user);
                    $dispatcher->dispatch(DatarecordCreatedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't particularly want to rethrow the error since it'll interrupt
                    //  everything downstream of the event (such as file encryption...), but
                    //  having the error disappear is less ideal on the dev environment...
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }

            // Retrieve first (and only) record ...
            if($datatype->getMetadataFor()) {

                /** @var DataRecord $metadata_record */
                $actual_data_record = $em->getRepository('ODRAdminBundle:DataRecord')
                    ->findOneBy(array('dataType' => $datatype->getMetadataFor()->getId()));

                if (!$actual_data_record) {
                    // A metadata datarecord doesn't exist...create one
                    /** @var EntityCreationService $entity_create_service */
                    $entity_create_service = $this->container->get('odr.entity_creation_service');

                    $delay_flush = true;
                    $actual_data_record = $entity_create_service
                        ->createDatarecord($user, $datatype->getMetadataFor(), $delay_flush);

                    // Datarecord is ready, remove provisioned flag
                    // TODO Naming is a little weird here
                    $actual_data_record->setProvisioned(false);
                    $em->flush();

                    // This is wrapped in a try/catch block because any uncaught exceptions will abort
                    //  creation of the new datarecord...
                    try {
                        // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                        //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                        /** @var EventDispatcherInterface $event_dispatcher */
                        $dispatcher = $this->get('event_dispatcher');
                        $event = new DatarecordCreatedEvent($actual_data_record, $user);
                        $dispatcher->dispatch(DatarecordCreatedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't particularly want to rethrow the error since it'll interrupt
                        //  everything downstream of the event (such as file encryption...), but
                        //  having the error disappear is less ideal on the dev environment...
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }
                }

            }
//            // Set name field?
//
//            /** @var DataFields $name_field */
//            $name_field = $datatype->getNameField();
//            if ($name_field) {
//                // We have a name field
//                /** @var DataRecordFields $drf */
//                $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
//                    array(
//                        'dataRecord' => $metadata_record->getId(),
//                        'dataField' => $name_field->getId()
//                    )
//                );
//
//                if ($drf) {
//                    /** @var LongText $new_field */
//                    $new_field = new LongText();
//                    switch ($name_field->getFieldType()) {
//                        case '5':
//                            /** @var LongText $new_field */
//                            $new_field = new LongText();
//                            break;
//                        case '6':
//                            /** @var LongVarchar $new_field */
//                            $new_field = new LongVarchar();
//                            break;
//                        case '7':
//                            /** @var MediumVarchar $new_field */
//                            $new_field = new MediumVarchar();
//                            break;
//                        case '9':
//                            /** @var ShortVarchar $new_field */
//                            $new_field = new ShortVarchar();
//                            break;
//                    }
//
//                    $new_field->setDataField($name_field);
//                    $new_field->setDataRecord($metadata_record);
//                    $new_field->setDataRecordFields($drf);
//                    $new_field->setFieldType($name_field->getFieldType());
//
//                    $new_field->setCreatedBy($user);
//                    $new_field->setUpdatedBy($user);
//                    $new_field->setCreated(new \DateTime());
//                    $new_field->setUpdated(new \DateTime());
//                    $new_field->setValue($dataset_name);
//                    $em->persist($new_field);
//
//                    $em->flush();
//                }
//            }

            $response = new Response('Created', 201);
            $url = $this->generateUrl('odr_api_get_dataset_record', array(
                'version' => $version,
                'record_uuid' => $metadata_record->getUniqueId()
            ), false);
            $response->headers->set('Location', $url);

            return $this->redirect($url);
        }
        catch (\Exception $e) {
            $source = 0x89adf33e;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param array $record
     * @param ODRUser $user
     * @param \DateTime $datetime_value
     *
     * @return array
     */
    private function checkRecord(&$record, $user, $datetime_value) {
        if(isset($record['_record_metadata'])) {
            $record['_record_metadata']['_create_auth'] = $user->getEmailCanonical();
            $record['_record_metadata']['_create_date'] = $datetime_value->format('Y-m-d H:i:s');
        }

        $output_records = array();
        foreach($record['records'] as $child_record) {
            $output_records[] = self::checkRecord($child_record, $user, $datetime_value);
        }
        $record['records'] = $output_records;
        return $record;
    }

    /**
     * @param array $tag_tree
     * @param array $selected_tags
     */
    private function selectedTags($tag_tree, &$selected_tags = array())
    {
        foreach ($tag_tree as $tag) {
            if (isset($tag['selected']) && $tag['selected'] == 1) {
                array_push($selected_tags, $tag['template_tag_uuid']);
            }

            if (isset($tag['children']) && is_array($tag['children']) && count($tag['children']) > 0) {
                self::selectedTags($tag['children'], $selected_tags);
            }
        }
    }


    /**
     * Checks if the changes can successfully be completed by the user.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param PermissionsManagementService $pm_service
     * @param ODRUser $user
     * @param DataType $datatype The datatype of the record the user might be modifying
     * @param array $dataset The array of potential changes the user wants to make to the record
     * @param array $orig_dataset The current version of the record the user might be modifying
     * @return bool true if changes are being made, false otherwise
     * @throws ODRException when the user is not permitted to make the changes they want
     */
    private function checkUpdatePermissions($em, $pm_service, $user, $datatype, $dataset, $orig_dataset)
    {
        // Need to track whether any changes are made
        $changed = false;

        /** @var Logger $logger */
        $logger = $this->container->get('logger');

        try {
            // ----------------------------------------
            // Because the user could be creating a new datarecord, there's no guarantee that a
            //  database entry exists...
            /** @var DataRecord|null $datarecord */
            $datarecord = null;
            if ( isset($dataset['record_uuid']) ) {
                // ...but if the user is specifying a record, then it must exist
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array(
                        'unique_id' => $dataset['record_uuid'],
                        'dataType' => $datatype->getId(),   // ensure the user doesn't try to change unrelated datarecords
                    )
                );
                if ($datarecord == null)
                    throw new ODRNotFoundException('Datarecord');
            }

            // TODO - enable this?
//            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
//                throw new ODRForbiddenException('Must be an admin to make changes');


            // Need to check whether the datarecord's public date is getting changed...
            if ( isset($dataset['public_date']) ) {
                if ( !ValidUtility::isValidDatetime($dataset['public_date'], "Y-m-d H:i:s") )
                    throw new ODRBadRequestException('public_date "'.$dataset['public_date'].'" is not a valid Datetime');

                $changed = true;
                if ( !is_null($datarecord) ) {
                    // Record currently exists
                    if ( !$pm_service->canChangePublicStatus($user, $datarecord) )
                        throw new ODRForbiddenException('Not allowed to change public status of the Record '.$datarecord->getUniqueId());
                }
                else {
                    // User attempting to make changes to a record that doesn't currently exist...
                    //  still need to check whether they can do so
                    if ( !$pm_service->canChangePublicStatus($user, null, $datatype) )
                        throw new ODRForbiddenException('Not allowed to change public status of Records for the Database '.$datatype->getUniqueId());
                }
            }

            // Need to check whether the datarecord is getting created, or its created date is
            //  getting changed...
            if ( isset($dataset['created']) ) {
                if ( !ValidUtility::isValidDatetime($dataset['created'], "Y-m-d H:i:s") )
                    throw new ODRBadRequestException('public_date "'.$dataset['created'].'" is not a valid Datetime');
            }

            if ( is_null($datarecord) || isset($dataset['created']) ) {
                $changed = true;
                if ( !$pm_service->canAddDatarecord($user, $datatype) )
                    throw new ODRForbiddenException('Not allowed to create a new Record for the Database '.$datatype->getUniqueId());
                // TODO - should this instead be based off whether the user can edit the record?
            }


            // ----------------------------------------
            // Need to check every field in the datarecord for changes...
            if ( !empty($dataset['fields']) ) {
                for ($i = 0; $i < count($dataset['fields']); $i++) {
                    $field = $dataset['fields'][$i];

                    // Load the requested datafield
                    /** @var DataFields $datafield */
                    $datafield = null;
                    if ( isset($field['template_field_uuid']) && $field['template_field_uuid'] !== null ) {
                        $datafield = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                            array(
                                'templateFieldUuid' => $field['template_field_uuid'],
                                'dataType' => $datatype->getId()   // ensure the user doesn't try to change unrelated datafields
                            )
                        );
                    }
                    else if ( isset($field['field_uuid']) ) {
                        $datafield = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                            array(
                                'fieldUuid' => $field['field_uuid'],
                                'dataType' => $datatype->getId()   // ensure the user doesn't try to change unrelated datafields
                            )
                        );
                    }
                    if ($datafield == null)
                        throw new ODRNotFoundException('Datafield');

                    $typeclass = $datafield->getFieldType()->getTypeClass();
                    $typename = $datafield->getFieldType()->getTypeName();

                    if ( $typeclass === 'File' || $typeclass === 'Image' ) {
                        if ( isset($field['files']) && is_array($field['files']) && count($field['files']) > 0 ) {
                            foreach ($field['files'] as $file) {
                                if ( isset($file['public_date']) ) {
                                    // This section is only used to change the file/image's public
                                    //  date...uploading/deleting files is done with other API calls
                                    if ( !ValidUtility::isValidDatetime($file['public_date'], "Y-m-d H:i:s") )
                                        throw new ODRBadRequestException('public_date "'.$file['public_date'].'" for File '.$file['file_uuid'].' is not a valid Datetime');

                                    // Need to ensure the file/image objects exist...
                                    /** @var FileMeta|ImageMeta $meta_entry */
                                    $meta_entry = null;
                                    switch ($typeclass ) {
                                        case 'File':
                                            /** @var File $file_obj */
                                            $file_obj = $em->getRepository('ODRAdminBundle:File')->findOneBy(
                                                array(
                                                    'unique_id' => $file['file_uuid'],
                                                    'dataField' => $datafield->getId(),   // ensure the user doesn't try to change unrelated files
                                                    'dataRecord' => $datarecord->getId(),
                                                )
                                            );
                                            if ($file_obj == null)
                                                throw new ODRNotFoundException('File');
                                            if ($file_obj->getDataField()->getDeletedAt() != null)
                                                throw new ODRNotFoundException('File');
                                            if ($file_obj->getDataRecord()->getDeletedAt() != null)
                                                throw new ODRNotFoundException('File');

                                            $meta_entry = $file_obj->getFileMeta();
                                            break;
                                        case 'Image':
                                            /** @var Image $image_obj */
                                            $image_obj = $em->getRepository('ODRAdminBundle:Image')->findOneBy(
                                                array(
                                                    'unique_id' => $file['file_uuid'],
                                                    'dataField' => $datafield->getId(),   // ensure the user doesn't try to change unrelated images
                                                    'dataRecord' => $datarecord->getId(),
                                                )
                                            );
                                            if ($image_obj == null)
                                                throw new ODRNotFoundException('Image');

                                            // If the unique id of a resized image was passed in,
                                            //  make changes to the original
                                            if ( !is_null($image_obj->getParent()) )
                                                $image_obj = $image_obj->getParent();

                                            if ($image_obj->getDataField()->getDeletedAt() != null)
                                                throw new ODRNotFoundException('Image');
                                            if ($image_obj->getDataRecord()->getDeletedAt() != null)
                                                throw new ODRNotFoundException('Image');

                                            $meta_entry = $image_obj->getImageMeta();
                                            break;

                                        default:
                                            throw new ODRBadRequestException('Structure for File/Image fields used with '.$typeclass.' Field '.$datafield->getFieldUuid());
                                    }

                                    // If the user wants to change the file/image's public date...
                                    if ( $meta_entry->getPublicDate()->format("Y-m-d H:i:s") !== $file['public_date'] ) {
                                        $changed = true;
                                        // ...then ensure they have the permissions to do so
                                        if ( !$pm_service->canEditDatafield($user, $datafield) )
                                            throw new ODRForbiddenException('Not allowed to change public status of '.$typeclass.'s for Field '.$datafield->getFieldUuid());
                                    }
                                }
                            }
                        }
                    }
                    else if ( $typeclass === 'Boolean' ) {
                        // Determine whether the user is allowed to make the changes they're
                        //  requesting to this Boolean field...
                        $ret = self::checkStorageFieldPermissions($em, $pm_service, $user, $datarecord, $datafield, $orig_dataset, $field);
                        if ($ret)
                            $changed = true;
                    }
                    else if ( isset($field['value']) && is_array($field['value']) ) {
                        // Need to verify this only gets run on radio/tag fields
                        switch ( $typename ) {
                            case 'Tags':
                                // Determine whether the user is allowed to make the changes they're
                                //  requesting to this field...
                                $ret = self::checkTagFieldPermissions($em, $pm_service, $user, $datarecord, $datafield, $orig_dataset, $field);
                                if ($ret)
                                    $changed = true;
                                break;

                            case 'Single Radio':
                            case 'Multiple Radio':
                            case 'Single Select':
                            case 'Multiple Select':
                                // Determine whether the user is allowed to make the changes they're
                                //  requesting to this field...
                                $ret = self::checkRadioFieldPermissions($em, $pm_service, $user, $datarecord, $datafield, $orig_dataset, $field);
                                if ($ret)
                                    $changed = true;
                                break;

                            default:
                                throw new ODRBadRequestException('Structure for Radio/Tag fields used with '.$typeclass.' Field '.$datafield->getFieldUuid());
                        }
                    }
                    else if ( isset($field['value']) ) {
                        // Need to verify this only gets run on text/number/date fields
                        switch ($typeclass) {
                            case 'IntegerValue':
                            case 'DecimalValue':
                            case 'LongText':
                            case 'LongVarchar':
                            case 'MediumVarchar':
                            case 'ShortVarchar':
                            case 'DatetimeValue':
                                // Determine whether the user is allowed to make the changes they're
                                //  requesting to this field...
                                $ret = self::checkStorageFieldPermissions($em, $pm_service, $user, $datarecord, $datafield, $orig_dataset, $field);
                                if ($ret)
                                    $changed = true;
                                break;

                            default:
                                throw new ODRBadRequestException('Structure for text/number/date fields used with '.$typeclass.' Field '.$datafield->getFieldUuid());
                        }
                    }
                }
            }

            // If at least one of the fields is going to get changed...
            if ( $changed ) {
                // ...then ensure the user can modify the record
                if ( !is_null($datarecord) ) {
                    if ( !$pm_service->canEditDatarecord($user, $datarecord) )
                        throw new ODRForbiddenException('Not allowed to edit the Record '.$datarecord->getUniqueId());
                }
                else {
                    // User attempting to make changes to a record that doesn't currently exist...
                    //  still need to check whether they can do so
                    if ( !$pm_service->canEditDatatype($user, $datatype) )
                        throw new ODRForbiddenException('Not allowed to edit Records belonging to the Database '.$datatype->getUniqueId());
                }
            }


            // ----------------------------------------
            // Need to delete child/linked records that no longer exist
            if ( $orig_dataset && !empty($orig_dataset['records']) ) {
                // Check if old record exists and delete if necessary...
                for ($i = 0; $i < count($orig_dataset['records']); $i++) {
                    $o_descendant_record = $orig_dataset['records'][$i];

                    $record_found = false;
                    // Check if record_uuid and template_uuid match - if so we're differencing
                    foreach ($dataset['records'] as $descendant_record) {
                        if ( !isset($descendant_record['database_uuid']) )
                            throw new ODRBadRequestException('No database uuid provided for Descendant Datarecord '.$descendant_record['record_uuid']);

                        // New records don't have UUIDs and need to be ignored in this check
                        if ( isset($descendant_record['record_uuid'])
                            && !empty($descendant_record['record_uuid'])
                            && $descendant_record['database_uuid'] == $o_descendant_record['database_uuid']
                            && $descendant_record['record_uuid'] == $o_descendant_record['record_uuid']
                        ) {
                            $record_found = true;
                        }
                    }

                    if (!$record_found) {
                        // The dataset submitted by the user doesn't have a record that currently
                        //  exists...
                        $query = $em->createQuery(
                           'SELECT dr
                            FROM ODRAdminBundle:DataRecord dr
                            JOIN ODRAdminBundle:DataType dt WITH dr.dataType = dt
                            WHERE dr.unique_id = :record_uuid AND dt.unique_id = :database_uuid
                            AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL'
                        )->setParameters(
                            array(
                                'record_uuid' => $o_descendant_record['record_uuid'],
                                'database_uuid' => $o_descendant_record['database_uuid'],    // ensure the user doesn't attempt to delete unrelated records
                            )
                        );
                        $result = $query->getResult();
                        if ( !isset($result[0]) )
                            throw new ODRNotFoundException('Del Datarecord');

                        /** @var DataRecord $del_record */
                        $del_record = $result[0];
                        $descendant_datatype = $del_record->getDataType();

                        // ...which means the existing record needs to get deleted/unlinked
                        $changed = true;

                        // Need to determine whether it's a child or a linked record
                        $grandparent_datatype = $datatype->getGrandparent();
                        $del_record_grandparent_datatype = $descendant_datatype->getGrandparent();

                        if ( $grandparent_datatype->getId() === $del_record_grandparent_datatype->getId() ) {
                            // $del_record is a child record, and will be deleted
                            if ( !$pm_service->canEditDatarecord($user, $del_record->getParent()) )
                                throw new ODRForbiddenException('Not allowed to delete the Record '.$del_record->getUniqueId());
                            if ( !$pm_service->canDeleteDatarecord($user, $del_record->getDataType()) )
                                throw new ODRForbiddenException('Not allowed to delete the Record '.$del_record->getUniqueId());

                            // Don't need to recursively locate children of this child...they'll
                            //  get deleted by EntityDeletionService::deleteDatarecord()
                        }
                        else {
                            // $del_record is a linked record...going to unlink instead of deleting it
                            if ( is_null($datarecord) )
                                throw new ODRException('Deletion of a child/linked descendant from a record that does not exist???');
                            if ( !$pm_service->canEditDatarecord($user, $datarecord) )
                                throw new ODRForbiddenException('Not allowed to unlink the Record '.$del_record->getUniqueId());

                            /** @var LinkedDataTree $ldt */
                            $ldt = $em->getRepository('ODRAdminBundle:LinkedDataTree')->findOneBy(
                                array(
                                    'ancestor' => $datarecord->getId(),
                                    'descendant' => $del_record->getId(),
                                )
                            );
                            if ($ldt == null)
                                throw new ODRNotFoundException('Datarecord Link');

                            // TODO - might still want to delete the record if the template_group matches?
                        }

                        // Deletion of top-level records is done via a different API action
                    }
                }
            }


            // ----------------------------------------
            // Need to check for child/linked records that don't exist yet
            if ( !empty($dataset['records']) ) {
                /** @var DataType[] $datatype_lookup */
                $datatype_lookup = array();

                // Keep track of whether this record has descendants for a given child/linked
                //  datatype, in order to make checking the 'multiple_allowed' condition easier
                $has_descendants = array();
                foreach ($dataset['records'] as $descendant_record) {
                    // Pull the identifying information for this descendant record from the array
                    //  the user submitted
//                    if ( !isset($descendant_record['database_uuid']) ) {
//                        if ( !is_null($datarecord) )
//                            $logger->debug('current datarecord: '.$datarecord->getId());
//                        else
//                            $logger->debug('current datarecord: null');
//
//                        $logger->debug('current datatype: '.$datatype->getId());
//                        $logger->debug('requested_datatype: '.$descendant_record['template_uuid']);
//
//                        throw new ODRBadRequestException('No database uuid provided for Descendant Datarecord '.$descendant_record['record_uuid']);
//                    }
//
//                    $descendant_datatype_uuid = $descendant_record['database_uuid'];
//
//                    if ( !isset($datatype_lookup[$descendant_datatype_uuid]) ) {
//                        /** @var DataType $dt */
//                        $dt = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
//                            array('unique_id' => $descendant_datatype_uuid)
//                        );
//                        if ($dt == null)
//                            throw new ODRNotFoundException('Datatype');
//
//                        $datatype_lookup[$descendant_datatype_uuid] = $dt;
//                    }

                    // TODO
                    $descendant_datatype = self::getDescendantDatatype($em, $datatype_lookup, $descendant_record);
                    $descendant_datatype_uuid = $descendant_datatype->getUniqueId();
                    $descendant_record['database_uuid'] = $descendant_datatype_uuid;

                    // Store that this record has at least one descendant record for this datatype
                    if ( !isset($has_descendants[$descendant_datatype_uuid]) )
                        $has_descendants[$descendant_datatype_uuid] = 0;
                    $has_descendants[$descendant_datatype_uuid] += 1;
                }

                foreach ($dataset['records'] as $descendant_record) {
                    // Due to the previous loop, don't need to check anything...
                    $descendant_datatype_uuid = $descendant_record['database_uuid'];
                    $descendant_datatype = $datatype_lookup[$descendant_datatype_uuid];

                    // Attempt to find the original version of the record the user specified
                    $record_found = false;
                    if ( $orig_dataset && !empty($orig_dataset['records']) ) {
                        // Check if record_uuid and template_uuid match - if so we're differencing
                        for ($i = 0; $i < count($orig_dataset['records']); $i++) {
                            $o_descendant_record = $orig_dataset['records'][$i];
                            if ( isset($descendant_record['database_uuid'])
                                && isset($descendant_record['record_uuid'])
                                && $descendant_record['database_uuid'] == $o_descendant_record['database_uuid']
                                && $descendant_record['record_uuid'] == $o_descendant_record['record_uuid']
                            ) {
                                // Found the expected child/linked descendant record in the submitted
                                //  dataset...
                                $record_found = true;

                                // ...determine if this descendant has any changes
                                $ret = self::checkUpdatePermissions(
                                    $em,
                                    $pm_service,
                                    $user,
                                    $descendant_datatype,
                                    $descendant_record,  // compare the user's requested changes...
                                    $o_descendant_record // ...against the existing record
                                );
                                if ($ret)
                                    $changed = true;
                            }
                        }
                    }

                    if ( !$record_found ) {
                        // User submitted a child/linked record that doesn't exist in the database...
                        $changed = true;

                        // Determine if the descendant datatype is a child or a link
                        $is_link = false;
                        /** @var DataTree $datatree */
                        $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                            array(
                                'ancestor' => $datatype->getId(),
                                'descendant' => $descendant_datatype->getId()
                            )
                        );
                        if ($datatree == null)
                            throw new ODRNotFoundException('Datatree');
                        if ($datatree->getIsLink())
                            $is_link = true;

                        // If a record uuid is specified...
                        if ( isset($descendant_record['record_uuid']) && strlen($descendant_record['record_uuid']) > 0) {
                            if ( !$is_link ) {
                                // ...then the descendant datatype must be a link
                                throw new ODRBadRequestException('New child records (non-linked) can not have pre-existing UUIDs.');
                            }
                            else {
                                // ...if it is a link, then the user needs to be able to modify the
                                //  ancestor record
                                if ( !is_null($datarecord) ) {
                                    if ( !$pm_service->canEditDatarecord($user, $datarecord) )
                                        throw new ODRForbiddenException('Not allowed to link records to the existing Record '.$datarecord->getUniqueId());
                                }
                                else {
                                    // User attempting to make changes to a record that doesn't
                                    //  currently exist...still need to check whether they can do so
                                    if ( !$pm_service->canEditDatatype($user, $datatype) )
                                        throw new ODRForbiddenException('Not allowed to edit Records belonging to the Database '.$datatype->getUniqueId());
                                }

                                // ...and the specified descendant record needs to already exist
                                /** @var DataRecord $linked_descendant_record */
                                $linked_descendant_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                                    array(
                                        'unique_id' => $descendant_record['record_uuid'],
                                        'dataType' => $descendant_datatype->getId(),
                                    )
                                );
                                if ($linked_descendant_record == null)
                                    throw new ODRNotFoundException('Datarecord '.$descendant_record['record_uuid']);


                                // Also need to verify that the user isn't attempting to link more
                                //  than one record into a link that only allows a single record...
                                if ( !$datatree->getMultipleAllowed() ) {
                                    // ...if they are, then throw an error
                                    if ( isset($has_descendants[$descendant_datatype_uuid]) && $has_descendants[$descendant_datatype_uuid] > 1 ) {
                                        if ( !is_null($datarecord) )
                                            throw new ODRBadRequestException('The Record '.$datarecord->getUniqueId().' is only allowed to have a single descendant of the Database '.$descendant_datatype_uuid);
                                        else
                                            throw new ODRBadRequestException('Records of the Database '.$datatype->getUniqueId().' are only allowed to have single descendants of the Database '.$descendant_datatype_uuid);
                                    }
                                }

                                // If no error thrown, then there will be a record linked
                                if ( !isset($has_descendants[$descendant_datatype_uuid]) )
                                    $has_descendants[$descendant_datatype_uuid] = 0;
                                $has_descendants[$descendant_datatype_uuid] += 1;


                                // Need to load the cached version of the datarecord that just got
                                //  linked, since it also needs to be run through datasetDiff()...
                                $new_linked_record = self::getRecordData(
                                    'v4',
                                    $descendant_record['record_uuid'],
                                    'json',
                                    true,    // TODO - the rest of the API stuff demands that metadata exist...
                                    $user
                                );
                                $new_linked_record = json_decode($new_linked_record, true);

                                // Also need to determine if the requested changes to the newly
                                //  linked record will cause any issues
                                self::checkUpdatePermissions(
                                    $em,
                                    $pm_service,
                                    $user,
                                    $descendant_datatype,
                                    $descendant_record, // compare the user's requested changes...
                                    $new_linked_record  // ...against the newly-linked record
                                );

                                // Don't need to check for $changed here...it's already true as a result
                                //  of creating a new child/linked record
                            }
                        }
                        else {
                            // ...otherwise, this is going to be a new child/linked record

                            // Also need to verify that the user isn't attempting to create more
                            //  than one child record when only one is allowed...
                            if ( !$datatree->getMultipleAllowed() ) {
                                // ...if they are, then throw an error
                                if ( isset($has_descendants[$descendant_datatype_uuid]) && $has_descendants[$descendant_datatype_uuid] > 1 ) {
                                    if ( !is_null($datarecord) )
                                        throw new ODRBadRequestException('The Record '.$datarecord->getUniqueId().' is only allowed to have a single descendant of the Database '.$descendant_datatype_uuid);
                                    else
                                        throw new ODRBadRequestException('Records of the Database '.$datatype->getUniqueId().' are only allowed to have single descendants of the Database '.$descendant_datatype_uuid);
                                }
                            }

                            // If no error thrown, then there will be a record created
                            if ( !isset($has_descendants[$descendant_datatype_uuid]) )
                                $has_descendants[$descendant_datatype_uuid] = 0;
                            $has_descendants[$descendant_datatype_uuid] += 1;

                            // Ensure the user can create new records in this descendant datatype
                            if ( !$pm_service->canAddDatarecord($user, $descendant_datatype) )
                                throw new ODRForbiddenException('Not allowed to create records for the Database '.$descendant_datatype->getUniqueId());

                            // If the user wants to link to the new record, ensure they can do that
                            if ($is_link) {
                                if ( !is_null($datarecord) ) {
                                    if ( !$pm_service->canEditDatarecord($user, $datarecord) )
                                        throw new ODRForbiddenException('Not allowed to link records to the existing Record '.$datarecord->getUniqueId());
                                }
                                else {
                                    // User attempting to make changes to a record that doesn't
                                    //  currently exist...still need to check whether they can do so
                                    if ( !$pm_service->canEditDatatype($user, $datatype) )
                                        throw new ODRForbiddenException('Not allowed to edit Records belonging to the Database '.$datatype->getUniqueId());
                                }
                            }


                            // Also need to determine if the requested changes to the newly created
                            //  child/linked record will cause any issues
                            self::checkUpdatePermissions(
                                $em,
                                $pm_service,
                                $user,
                                $descendant_datatype,
                                $descendant_record, // compare the user's requested changes...
                                array()             // ...against an empty (non-existent) record
                            );

                            // Don't need to check for $changed here...it's already true as a result
                            //  of creating a new child/linked record
                        }
                    }
                }
            }


            // ----------------------------------------
            // If we made it here, the user has permission
            return $changed;
        }
        catch (\Exception $e) {
            $source = 0x38a6ca95;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Descendant datatypes are ideally identified via database uuids, but can also be identified
     * via template group (uuids).  The latter is somewhat irritating to do, so both are handled in
     * their own function.
     *
     * TODO - the deletion check is different enough that it kind of needs its own function...
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array &$datatype_lookup
     * @param array $descendant_record
     *
     * @throws \Exception
     * @return DataType
     */
    private function getDescendantDatatype($em, &$datatype_lookup, $descendant_record)
    {
        if ( isset($descendant_record['database_uuid']) ) {
            // Hopefully this information is cached...
            $descendant_datatype_uuid = $descendant_record['database_uuid'];
            if ( isset($datatype_lookup['unique_id'][$descendant_datatype_uuid]) )
                return $datatype_lookup['unique_id'][$descendant_datatype_uuid];

            // ...but if not, then load and cache it
            /** @var DataType $dt */
            $dt = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array('unique_id' => $descendant_datatype_uuid)
            );

            $datatype_lookup['unique_id'][$descendant_datatype_uuid] = $dt;
            return $dt;
        }
        else if ( isset($descendant_record['template_uuid']) ) {
            // Hopefully this information is cached...
            // TODO - can't cache it like this
            $descendant_datatype_group = $descendant_record['template_uuid'];
//            if ( isset($datatype_lookup['template_group'][$descendant_datatype_group]) )
//                return $datatype_lookup['template_group'][$descendant_datatype_group];

            // ...but if not, then load it    TODO - how to cache it?
            if ( $descendant_record instanceof DataRecord ) {
                $params = array(
                    'template_group' => $descendant_datatype_group,
                    'record_uuid' => $descendant_record->getUniqueId(),
                );
            }
            else {
                $params = array(
                    'template_group' => $descendant_datatype_group,
                    'record_uuid' => $descendant_record['record_uuid'],    // TODO - this doesn't exist when checking a new child record
                );
            }

            $query = $em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataType AS mdt
                JOIN ODRAdminBundle:DataType AS dt WITH dt.masterDataType = mdt
                JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                WHERE mdt.unique_id = :template_group AND dr.unique_id = :record_uuid
                AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters( $params );
            $results = $query->getResult();

            $logger = $this->container->get('logger');
            $logger->debug( '<pre>'.print_r($params, true).'</pre>' );

            if ( empty($results) )
                throw new ODRNotFoundException('Master Datatype');
            $dt = $results[0];

            return $dt;
        }

        // If the given datarecord doesn't have either piece of identifying info, then complain
        throw new ODRBadRequestException('No database/template uuid provided for Descendant Datarecord '.$descendant_record['record_uuid']);
    }


    /**
     * The four radio typeclasses are handled identically when determining whether the user is
     * allowed to make any changes to them.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param PermissionsManagementService $pm_service
     * @param ODRUser $user
     * @param DataRecord|null $datarecord The datarecord the user might be modifying
     * @param DataFields $datafield The datafield the user might be modifying
     * @param array $orig_dataset The array version of the current record
     * @param array $field An array of the state the user wants to field to be in after datasetDiff()
     * @throws ODRException
     * @return bool
     */
    private function checkRadioFieldPermissions($em, $pm_service, $user, $datarecord, $datafield, $orig_dataset, $field)
    {
        // Going to need these
        $selected_options = $field['value'];
        $exception_source = 0x4b879e27;

        $repo_dataRecordFields = $em->getRepository('ODRAdminBundle:DataRecordFields');
        $repo_radioOptions = $em->getRepository('ODRAdminBundle:RadioOptions');
        $repo_radioSelections = $em->getRepository('ODRAdminBundle:RadioSelection');

        // If the datafield only allows a single radio selection...
        $typename = $datafield->getFieldType()->getTypeName();
        if ( $typename === 'Single Radio' || $typename === 'Single Select' ) {
            // ...then need to throw an error if the user wants to leave the field in a state where
            //  it has multiple radio options selected
            if ( count($selected_options) > 1 )
                throw new ODRBadRequestException('Field '.$datafield->getFieldUuid().' is not allowed to have multiple options selected', $exception_source);
        }


        // ----------------------------------------
        // Locate the currently selected options in the dataset
        $orig_selected_options = array();
        if ($orig_dataset) {
            foreach ($orig_dataset['fields'] as $o_field) {
                // The field can be matched by either template_field_uuid...
                if ( isset($o_field['template_field_uuid'])
                    && isset($field['template_field_uuid'])
                    && $o_field['template_field_uuid'] === $field['template_field_uuid']
                ) {
                    // Found the field, don't need to keep looking
                    $orig_selected_options = $o_field['value'];
                    break;
                }

                // ...or by field_uuid
                if ( isset($o_field['field_uuid'])
                    && isset($field['field_uuid'])
                    && $o_field['field_uuid'] === $field['field_uuid']
                ) {
                    // Found the field, don't need to keep looking
                    $orig_selected_options = $o_field['value'];
                    break;
                }
            }
        }

        // Determine whether the submitted dataset will select/create any options, or unselect an option
        $new_options = array();
        $deleted_options = array();

        // Check for new options
        foreach ($selected_options as $option) {
            $found = false;
            foreach ($orig_selected_options as $o_option) {
                if ($option == $o_option)
                    $found = true;
            }

            if (!$found)
                $new_options[] = $option['template_radio_option_uuid'];
            // In the case of completely new options, this "uuid" will actually be the new option's
            //  name...
        }

        // Check for deleted options
        foreach ($orig_selected_options as $o_option) {
            $found = false;
            foreach ($selected_options as $option) {
                if ($option == $o_option)
                    $found = true;
            }

            if (!$found)
                $deleted_options[] = $o_option['template_radio_option_uuid'];
        }


        // ----------------------------------------
        // Need to determine whether a change is taking place
        $changed = false;
        $drf = null;

        // Determine whether an option got deselected
        foreach ($deleted_options as $option_uuid) {
            if ( is_null($drf) ) {
                /** @var DataRecordFields $drf */
                $drf = $repo_dataRecordFields->findOneBy(
                    array(
                        'dataRecord' => $datarecord->getId(),
                        'dataField' => $datafield->getId()
                    )
                );
            }
            // The datarecord and drf entries are guaranteed to exist at this point...there wouldn't
            //  be anything to deselect otherwise

            /** @var RadioOptions $option */
            $option = $repo_radioOptions->findOneBy(
                array(
                    'radioOptionUuid' => $option_uuid,
                    'dataField' => $datafield->getId()
                )
            );
            /** @var RadioSelection $option_selection */
            $option_selection = $repo_radioSelections->findOneBy(
                array(
                    'radioOption' => $option->getId(),
                    'dataRecordFields' => $drf->getId()
                )
            );

            if ($option_selection) {
                // The option is currently selected...determine whether the user has the permissions
                //  to deselect it
                $changed = true;
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException('Not allowed to deselect options in the Field '.$datafield->getFieldUuid(), $exception_source);
            }
        }


        // Determine whether an existing option got selected, or a new option needs to get created
        foreach ($new_options as $option_uuid) {
            $changed = true;

            // Lookup Option by UUID
            /** @var RadioOptions $option */
            $option = $repo_radioOptions->findOneBy(
                array(
                    'radioOptionUuid' => $option_uuid,
                    'dataField' => $datafield->getId(),
                )
            );

            if (!$option) {
                // The specified option doesn't exist, so datasetDiff() will create it...ensure the
                //  user has permissions to do so first
                if ( !$pm_service->isDatatypeAdmin($user, $datafield->getDataType()) )
                    throw new ODRForbiddenException('Not allowed to create options for the Field '.$datafield->getFieldUuid(), $exception_source);
            }
            else {
                // The specified option exists, so datasetDiff() will select it...ensure the user
                //  has permissions to do so
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException('Not allowed to select options in the Field '.$datafield->getFieldUuid(), $exception_source);
            }

            // Note that ValidUtility has functions to check whether radio options or tags already
            //  exist...but those aren't needed here because the API will create them if needed
        }

        // If this point is reached, either the user has permissions, or no changes are being made
        return $changed;
    }


    /**
     * Checking whether changes can be made to Tag fields is mostly similar to Radio fields, but
     * Tags can have parent/child tags to deal with...
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param PermissionsManagementService $pm_service
     * @param ODRUser $user
     * @param DataRecord|null $datarecord The datarecord the user might be modifying
     * @param DataFields $datafield The datafield the user might be modifying
     * @param array $orig_dataset The array version of the current record
     * @param array $field An array of the state the user wants to field to be in after datasetDiff()
     * @throws ODRException
     * @return bool
     */
    private function checkTagFieldPermissions($em, $pm_service, $user, $datarecord, $datafield, $orig_dataset, $field)
    {
        // Going to need these
        $exception_source = 0x86151dd3;

        $repo_dataRecordFields = $em->getRepository('ODRAdminBundle:DataRecordFields');
        $repo_tags = $em->getRepository('ODRAdminBundle:Tags');
        $repo_tagSelection = $em->getRepository('ODRAdminBundle:TagSelection');


        // ----------------------------------------
        // Locate the currently selected options in the dataset
        $selected_tags = array();
        self::selectedTags($field['value'], $selected_tags);

        $orig_selected_tags = array();
        if ($orig_dataset) {
            foreach ($orig_dataset['fields'] as $o_field) {
                // The field can be matched by either template_field_uuid...
                if ( isset($o_field['template_field_uuid'])
                    && isset($field['template_field_uuid'])
                    && $o_field['template_field_uuid'] === $field['template_field_uuid']
                ) {
                    // Found the field, don't need to keep looking
                    self::selectedTags($o_field['value'], $orig_selected_tags);
                    break;
                }

                // ...or by field_uuid
                if ( isset($o_field['field_uuid'])
                    && isset($field['field_uuid'])
                    && $o_field['field_uuid'] === $field['field_uuid']
                ) {
                    // Found the field, don't need to keep looking
                    self::selectedTags($o_field['value'], $orig_selected_tags);
                    break;
                }
            }
        }


        // ----------------------------------------
        // Determine whether the submitted dataset will select/create any tags, or unselect a tag
        $new_tags = array();
        $deleted_tags = array();

        // check for new tags
        foreach ($selected_tags as $tag) {
            $found = false;
            foreach ($orig_selected_tags as $o_tag) {
                if ($tag == $o_tag)
                    $found = true;
            }

            if (!$found)
                $new_tags[] = $tag;
        }

        // Check for deleted tags
        foreach ($orig_selected_tags as $o_tag) {
            $found = false;
            foreach ($selected_tags as $tag) {
                if ($tag == $o_tag)
                    $found = true;
            }

            if (!$found)
                $deleted_tags[] = $o_tag;
        }


        // ----------------------------------------
        // Need to determine whether a change is taking place
        $changed = false;
        $drf = null;

        // Determine whether a tag got deselected
        foreach ($deleted_tags as $tag_uuid) {
            if ( is_null($drf) ) {
                /** @var DataRecordFields $drf */
                $drf = $repo_dataRecordFields->findOneBy(
                    array(
                        'dataRecord' => $datarecord->getId(),
                        'dataField' => $datafield->getId()
                    )
                );
            }
            // The datarecord and drf entries are guaranteed to exist at this point...there wouldn't
            //  be anything to deselect otherwise

            /** @var Tags $tag */
            $tag = $repo_tags->findOneBy(
                array(
                    'tagUuid' => $tag_uuid,
                    'dataField' => $datafield->getId()
                )
            );
            /** @var TagSelection $tag_selection */
            $tag_selection = $repo_tagSelection->findOneBy(
                array(
                    'tag' => $tag->getId(),
                    'dataRecordFields' => $drf->getId()
                )
            );

            if ($tag_selection) {
                // The tag exists, so it will get deselected
                $changed = true;
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException('Not allowed to deselect tags in the Field '.$datafield->getFieldUuid(), $exception_source);
            }
        }


        // Determine whether an existing tag got selected, or a new tag needs to get created
        foreach ($new_tags as $tag_uuid) {
            $changed = true;

            // Lookup Tag by UUID
            /** @var Tags $tag */
            $tag = $repo_tags->findOneBy(
                array(
                    'tagUuid' => $tag_uuid,
                    'dataField' => $datafield->getId()
                )
            );

            if (!$tag) {
                // The tag doesn't exist, so it will get created...determine whether the user has
                //  the permissions to do so
                if ( !$pm_service->isDatatypeAdmin($user, $datafield->getDataType()) )
                    throw new ODRForbiddenException('Not allowed to create tags for the Field '.$datafield->getFieldUuid(), $exception_source);
            }
            else {
                // The tag exists, so it will get selected...determine whether the user has the
                //  permissions to do so
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException('Not allowed to select tags in the Field '.$datafield->getFieldUuid(), $exception_source);
            }

            // Note that ValidUtility has functions to check whether radio options or tags are
            //  valid...but those aren't needed because the API will create them if they don't exist
        }

        // If this point is reached, either the user has permissions, or no changes are being made
        return $changed;
    }


    /**
     * Determines whether the user is allowed to make the requested changes to this field...Boolean,
     * Integer, Decimal, DateTime, and all four Varchar fields use the same logic.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param PermissionsManagementService $pm_service
     * @param ODRUser $user
     * @param DataRecord|null $datarecord The datarecord the user might be modifying
     * @param DataFields $datafield The datafield the user might be modifying
     * @param array $orig_dataset The array version of the current record
     * @param array $field An array of the state the user wants to field to be in after datasetDiff()
     * @throws ODRException
     * @return bool
     */
    private function checkStorageFieldPermissions($em, $pm_service, $user, $datarecord, $datafield, $orig_dataset, $field)
    {
        // Going to need these
        $exception_source = 0x215bb2e9;
        $typeclass = $datafield->getFieldType()->getTypeClass();

        // Each of the text/number/datetime fields use 'value', while Boolean fields use 'selected'
        //  instead
        $key = 'value';
        if ( $typeclass === 'Boolean' )
            $key = 'selected';

        if ( !isset($field[$key]) ) {
            if ( $typeclass === 'Boolean' )
                throw new ODRBadRequestException('Dataset attempted to use "value" instead of "selected" for '.$typeclass.' Field '.$datafield->getFieldUuid(), $exception_source);
            else
                throw new ODRBadRequestException('Dataset attempted to use "selected" instead of "value" for '.$typeclass.' Field '.$datafield->getFieldUuid(), $exception_source);
        }


        // The field may not necessarily have a value in the datarecord
        $changed = false;

        $orig_field = array();
        if ($orig_dataset) {
            foreach ($orig_dataset['fields'] as $o_field) {
                if ( isset($o_field[$key]) && !is_array($o_field[$key]) ) {
                    // The field can be matched by either template_field_uuid...
                    if ( isset($o_field['template_field_uuid'])
                        && isset($field['template_field_uuid'])
                        && $o_field['template_field_uuid'] === $field['template_field_uuid']
                    ) {
                        // Found the field, don't need to keep looking
                        $orig_field = $o_field;
                        break;
                    }

                    // ...or by field_uuid
                    if ( isset($o_field['field_uuid'])
                        && isset($field['field_uuid'])
                        && $o_field['field_uuid'] === $field['field_uuid']
                    ) {
                        // Found the field, don't need to keep looking
                        $orig_field = $o_field;
                        break;
                    }
                }
            }
        }

        // If the field doesn't have a value, or the value changed...
        if ( empty($orig_field) || $orig_field[$key] !== $field[$key]) {
            // ...then ensure that the user is allowed to change this field
            $changed = true;
            if ( !$pm_service->canEditDatafield($user, $datafield) )
                throw new ODRForbiddenException('Not allowed to make changes to the Field '.$datafield->getFieldUuid(), $exception_source);

            // Each of these fieldtypes also has a "prevent user edits" property that can be
            //  activated by an admin of the related datatype
            // TODO - should API users be able to bypass this?
            if ( $datafield->getPreventUserEdits() )
                throw new ODRBadRequestException('The contents of the Field '.$datafield->getFieldUuid().' cannot be changed via API', $exception_source);

            // Ensure the value being saved is valid for this fieldtype...no dissertations in
            //  DecimalValue fields, for instance
            $is_valid = true;
            switch ($typeclass) {
                case 'Boolean':
                    $is_valid = ValidUtility::isValidBoolean($field[$key]);
                    break;
                case 'IntegerValue':
                    $is_valid = ValidUtility::isValidInteger($field[$key]);
                    break;
                case 'DecimalValue':
                    $is_valid = ValidUtility::isValidDecimal($field[$key]);
                    break;
                case 'LongText':    // paragraph text, can accept any value
                    break;
                case 'LongVarchar':
                    $is_valid = ValidUtility::isValidLongVarchar($field[$key]);
                    break;
                case 'MediumVarchar':
                    $is_valid = ValidUtility::isValidMediumVarchar($field[$key]);
                    break;
                case 'ShortVarchar':
                    $is_valid = ValidUtility::isValidShortVarchar($field[$key]);
                    break;
                case 'DatetimeValue':
                    if ( $field[$key] !== '' )    // empty string is valid MassEdit or API entry, but isn't valid datetime technically
                        $is_valid = ValidUtility::isValidDatetime($field[$key]);
                    break;
            }

            if ( !$is_valid )
                throw new ODRBadRequestException('Invalid value given for the '.$typeclass.' Field '.$datafield->getFieldUuid(), $exception_source);
        }

        // If this point is reached, either the user has permissions, or no changes are being made
        return $changed;
    }


    /**
     * Updates the metadata in the submitted dataset to match the database.
     *
     * @param array $field
     * @param DataFields $datafield
     * @param mixed $new_field
     */
    private function fieldMeta(&$field, $datafield, $new_field) {
        if(isset($field['_field_metadata'])) {
            if(method_exists($new_field, 'getCreatedBy')) {
                $field['_field_metadata']['_create_auth'] = $new_field->getCreatedBy()->getEmailCanonical();
            }
            if(method_exists($new_field, 'getCreated')) {
                $field['_field_metadata']['_create_date'] = $new_field->getCreated()->format('Y-m-d H:i:s');
                // print $field['field_name']. " - ";
                // print $field['_field_metadata']['_create_date']. " - ";
            }
            if(method_exists($new_field, 'getUpdated')) {
                $field['_field_metadata']['_update_date'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                // print $field['_field_metadata']['_update_date'];
            }
            if(method_exists($datafield, 'getPublicDate')) {
                $field['_field_metadata']['_public_date'] = $datafield->getPublicDate()->format('Y-m-d H:i:s');
            }
        }
        unset($field['created']);
    }


    /**
     * Updates the given entity's created/updated dates, and createdBy/updatedBy values.
     *
     * @param mixed $db_obj
     * @param ODRUser $user
     * @param string|\DateTime $date
     */
    private function setDates($db_obj, $user, $date = null)
    {
        if ( !is_null($date) ) {
            if ( !($date instanceof \DateTime) )
                $date = new \DateTime($date);
        }
        else
            $date = new \DateTime();


        if ( method_exists($db_obj, 'setCreated') )
            $db_obj->setCreated($date);
        if ( method_exists($db_obj, 'setCreatedBy') )
            $db_obj->setCreatedBy($user);

        if ( method_exists($db_obj, 'setUpdated') )
            $db_obj->setUpdated($date);
        if ( method_exists($db_obj, 'setUpdatedBy') )
            $db_obj->setUpdatedBy($user);
    }


    /**
     * The param dataset is misnamed in this output.  This is really editing a single
     * record.  In the acase of a metadata dataset, there is only one record.  So, this
     * was using the term dataset when record is more appropriate.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EventDispatcherInterface $event_dispatcher
     * @param EntityCreationService $ec_service
     * @param EntityDeletionService $ed_service
     * @param EntityMetaModifyService $emm_service
     * @param ODRUser $user
     * @param array $dataset The array of potential changes the user wants to make to the record
     * @param array|null $orig_dataset The current version of the record the user might be modifying
     * @param bool $is_original_record Whether this is the top-level of recursion for datasetDiff()
     * @param array $link_change_datatypes An array of datatypes to fire LinkStatusChange events on
     *
     * @return array
     * @throws ODRException
     */
    private function datasetDiff($em, $event_dispatcher, $ec_service, $ed_service, $emm_service, $user, $dataset, $orig_dataset, $is_original_record, &$link_change_datatypes)
    {
        // Check if fields are added or updated
        $radio_option_created = false;
        $tag_created = false;

        try {
            // ----------------------------------------
            // The datarecord should always exist...checkUpdatePermissions() would've thrown an error
            //  if it didn't
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                array('unique_id' => $dataset['record_uuid'])
            );
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');
            $datarecord_meta = $datarecord->getDataRecordMeta();
            $datatype = $datarecord->getDataType();

            // Need to keep track of whether the user made a change to this datarecord...
            $record_updated = false;


            // The request may want to change the record's created/public date...
            $public_date = null;
            if ( isset($dataset['public_date']) )
                $public_date = new \DateTime($dataset['public_date']);
            $created = null;
            if ( isset($dataset['created']) )
                $created = new \DateTime($dataset['created']);

            // Handle a request to change the datarecord's public date...
            if ( isset($dataset['public_date']) ) {
                if ( $public_date->format("Y-m-d H:i:s") !== $datarecord_meta->getPublicDate()->format("Y-m-d H:i:s") ) {
                    // The public date is different, so the record will be modified
                    $record_updated = true;
                    $props = array('publicDate' => $public_date);
                    $datarecord_meta = $emm_service->updateDatarecordMeta($user, $datarecord, $props, false, $created);

                    // Updating the array version of the datarecord entry will happen later
                }

                // No longer want this entry
                unset( $dataset['public_date'] );
            }

            // Handle a request to change the datarecord's created date...
            if ( isset($dataset['created']) ) {
                if ( $created->format("Y-m-d H:i:s") !== $datarecord->getCreated()->format("Y-m-d H:i:s") ) {
                    // The created date is different, so the record will be modified
                    $record_updated = true;

                    self::setDates($datarecord, $user, $created);
                    $em->persist($datarecord);

                    self::setDates($datarecord_meta, $user, $created);
                    $em->persist($datarecord_meta);

                    $em->flush();
                    $em->refresh($datarecord);
                    $em->refresh($datarecord_meta);

                    // Updating the array version of the datarecord entry will happen later
                }

                // No longer want this entry
                unset( $dataset['created'] );
            }


            // ----------------------------------------
            // Need to check every field in the datarecord for changes...
            if ( isset($dataset['fields']) ) {
                foreach ($dataset['fields'] as $field) {
                    // Filling in this entry if it doesn't exist makes later logic easier
                    if ( !isset($field['created']) )
                        $field['created'] = null;

                    // Load the requested datafield
                    /** @var DataFields $datafield */
                    $datafield = null;
                    if ( isset($field['template_field_uuid']) && $field['template_field_uuid'] !== null ) {
                        $datafield = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                            array(
                                'templateFieldUuid' => $field['template_field_uuid'],
                                'dataType' => $datatype->getId()
                            )
                        );
                    }
                    else if ( isset($field['field_uuid']) ) {
                        $datafield = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                            array(
                                'fieldUuid' => $field['field_uuid'],
                                'dataType' => $datatype->getId()
                            )
                        );
                    }
                    if ($datafield == null)
                        throw new ODRNotFoundException('Datafield');

                    $typeclass = $datafield->getFieldType()->getTypeClass();
                    $typename = $datafield->getFieldType()->getTypeName();

                    // Need to keep track of whether the user made a change to this field...
                    $field_updated = false;
                    // ...and need the index of this field in the original dataset in case an
                    //  update is made
                    $field_index = self::findFieldIndex($orig_dataset, $datafield);


                    // ----------------------------------------
                    // Deal with files and images here
                    if ( $typeclass === 'File' || $typeclass === 'Image' ) {
                        if ( isset($field['files']) && is_array($field['files']) && count($field['files']) > 0 ) {
                            // $field_index is guaranteed to have a value here, since the field
                            //  has at least one file/image
                            $orig_field = $orig_dataset['fields'][$field_index];

                            // Update the database with the value the user submitted, if needed
                            $changed = false;
                            $new_field = self::updateFileImageField(
                                $em,
                                $emm_service,
                                $user,
                                $datafield,
                                $orig_field,
                                $field,
                                $changed
                            );

                            // If the field got modified...
                            if ($changed) {
                                // ...then need to fire off an event later
                                $field_updated = true;

                                // Ensure the cached version of the datarecord is correct
                                $orig_dataset['fields'][$field_index] = $new_field;
                            }
                        }
                    }
                    else if ( $typeclass === 'Boolean' ) {
                        // The original dataset isn't guaranteed to have an entry for the field
                        $orig_field = null;
                        if ( !is_null($field_index) )
                            $orig_field = $orig_dataset['fields'][$field_index];

                        // Update the database with the value the user submitted, if needed
                        $changed = false;
                        $new_field = self::updateStorageField(
                            $em,
                            $ec_service,
                            $emm_service,
                            $user,
                            $datarecord,
                            $datafield,
                            $orig_field,
                            $field,
                            $changed
                        );

                        // If the field got modified...
                        if ($changed) {
                            // ...then need to fire off an event later
                            $field_updated = true;

                            if ( !is_null($orig_field) ) {
                                // Ensure the cached version of the datarecord is correct
                                $orig_dataset['fields'][$field_index] = $new_field;
                            }
                            else {
                                // The cached version of the datarecord didn't have an entry for this
                                //  field, so save what self::updateStorageField() returned
                                $orig_dataset['fields'][] = $new_field;
                            }
                        }
                    }
                    else if (isset($field['value']) && is_array($field['value'])) {

                        switch ( $typename ) {

                            // Tag field - need to difference hierarchy
                            case 'Tags':
                                // Determine selected tags in original dataset
                                // Determine selected tags in current
                                // print $field['template_field_uuid']."\n";

                                $selected_tags = array();
                                self::selectedTags($field['value'], $selected_tags);

                                $orig_selected_tags = array();
                                $orig_tag_field = array();
                                if ($orig_dataset) {
                                    foreach ($orig_dataset['fields'] as $o_field) {
                                        if (
                                            isset($o_field['value']) &&
                                            isset($field['field_uuid']) &&
                                            $o_field['field_uuid'] == $field['field_uuid']
                                        ) {
                                            $orig_tag_field = $o_field['value'];
                                            self::selectedTags($o_field['value'], $orig_selected_tags);
                                        }
                                    }
                                }

                                $new_tags = array();
                                $deleted_tags = array();

                                // check for new tags
                                foreach ($selected_tags as $tag) {
                                    $found = false;
                                    foreach ($orig_selected_tags as $o_tag) {
                                        if ($tag == $o_tag) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($new_tags, $tag);
                                    }
                                }

                                // Check for deleted tags
                                foreach ($orig_selected_tags as $o_tag) {
                                    $found = false;
                                    foreach ($selected_tags as $tag) {
                                        if ($tag == $o_tag) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($deleted_tags, $o_tag);
                                    }
                                }

                                /** @var DataRecordFields $drf */
                                $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')
                                    ->findOneBy(
                                        array(
                                            'dataRecord' => $dataset['internal_id'],
                                            'dataField' => $datafield->getId()
                                        )
                                    );

                                // Delete deleted tags
                                foreach ($deleted_tags as $tag_uuid) {
                                    /** @var Tags $tag */
                                    $tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                                        array(
                                            'tagUuid' => $tag_uuid,
                                            'dataField' => $datafield->getId()
                                        )
                                    );
                                    /** @var TagSelection $tag_selection */
                                    $tag_selection = $em->getRepository('ODRAdminBundle:TagSelection')
                                        ->findOneBy(
                                            array(
                                                'tag' => $tag->getId(),
                                                'dataRecordFields' => $drf->getId()
                                            )
                                        );

                                    if ($tag_selection) {
                                        $em->remove($tag_selection);
                                        $fields_updated = true;
                                    }
                                }


                                // Check if new tag exists in template
                                // Add to template if not exists
                                foreach ($new_tags as $tag_uuid) {
                                    // Lookup Tag by UUID
                                    /** @var Tags $tag */
                                    $tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                                        array(
                                            'tagUuid' => $tag_uuid,
                                            'dataField' => $datafield->getId()
                                        )
                                    );

                                    // User Added Options
                                    if(!$tag) {
                                        if(!$tag) {
                                            // We need Datatype Admin Perms
//                                            if (
//                                                !$pm_service->isDatatypeAdmin($user, $datarecord->getDataType())
//                                                && !$pm_service->canEditDatatype($user, $datarecord->getDataType())
//                                            ) {
//                                                throw new ODRForbiddenException();
//                                            }
                                        }
                                        // Create tag and set as user created
                                        $tag = new Tags();
                                        $tag_created = true;

                                        // Option UUID gets overloaded with the name if a user created tag
                                        $tag->setTagName($tag_uuid);

                                        /** @var UUIDService $uuid_service */
                                        $uuid_service = $this->container->get('odr.uuid_service');
                                        $tag->setTagUuid($uuid_service->generateTagUniqueId());
                                        $tag->setCreatedBy($user);
                                        self::setDates($tag, $field['created']);
                                        $tag->setUserCreated(1);
                                        $tag->setDataField($datafield);
                                        $em->persist($tag);

                                        // Search $field['value'] for tag and find parent
                                        $tag_parent_uuid = null;
                                        foreach($field['value'] as $field_tag) {
                                            if($field_tag['template_tag_uuid'] == $tag_uuid) {
                                                // This is our tag
                                                $tag_parent_uuid = $field_tag['tag_parent_uuid'];
                                            }
                                        }

                                        if($tag_parent_uuid == null)
                                            throw new \Exception('Tag parent UUID is required when adding user-created tags');

                                        // Look up parent tag
                                        /** @var Tags $tag_parent */
                                        $tag_parent = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                                            array(
                                                'tagUuid' => $tag_parent_uuid,
                                                'dataField' => $datafield->getId()
                                            )
                                        );

                                        if(!$tag_parent)
                                            throw new \Exception('The parent tag is invalid or not found.');    // TODO - not all tags will have parents...

                                        /** @var TagTree $tag_tree */
                                        $tag_tree = new TagTree();
                                        $tag_tree->setChild($tag);
                                        $tag_tree->setParent($tag_parent);
                                        $tag_tree->setCreatedBy($user);
                                        self::setDates($tag_tree, $field['created']);
                                        $em->persist($tag_tree);

                                        /** @var TagMeta $tag_meta */
                                        $tag_meta = new TagMeta();
                                        $tag_meta->setTag($tag);
                                        $tag_meta->setTagName($tag_uuid);
                                        $tag_meta->setXmlTagName($tag_uuid);
                                        $tag_meta->setDisplayOrder(0);
                                        $tag_meta->setCreatedBy($user);
                                        self::setDates($tag_meta, $field['created']);
                                        $tag_meta->setUpdatedBy($user);
                                        $em->persist($tag_meta);
                                    }

                                    if (!$drf) {
                                        // If drf entry doesn't exist, create new
                                        $drf = new DataRecordFields();
                                        $drf->setCreatedBy($user);
                                        self::setDates($drf, $field['created']);
                                        $drf->setDataField($datafield);
                                        $drf->setDataRecord($datarecord);
                                        $em->persist($drf);
                                    }

                                    /** @var TagSelection $new_field */
                                    $new_field = new TagSelection();
                                    $new_field->setTag($tag);
                                    $new_field->setDataRecordFields($drf);
                                    $new_field->setDataRecord($datarecord);
                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setSelected(1);
                                    $em->persist($new_field);

                                    self::fieldMeta($field, $datafield, $new_field);
                                    $fields_updated = true;


                                    // Trying to do everything realtime - no waiting forever stuff
                                    // Maybe the references will be stored in the variable anyway?
                                    $em->flush();
                                    $em->refresh($new_field);

                                    // Added tags need to replace their value in the array
                                    for($j=0;$j < count($field['value']);$j++) {
                                        if(
                                            $field['value'][$j]['template_tag_uuid'] == $tag->getTagUuid()
                                            || (
                                                $tag->getUserCreated()
                                                && $field['value'][$j]['template_tag_uuid'] == $tag_uuid
                                            )
                                        ) {
                                            // replace this block
                                            $field['value'][$j]['test'] = 1;
                                            $field['value'][$j]['template_tag_uuid'] = $tag->getTagUuid();
                                            $field['value'][$j]['id'] = $new_field->getId();
                                            $field['value'][$j]['selected'] = 1;
                                            $field['value'][$j]['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                            $field['value'][$j]['created_at'] = $new_field->getCreated()->format('Y-m-d H:i:s');

                                            if ($tag_created) {
                                                $field['value'][$j]['name'] = $tag_uuid;
                                                $field['value'][$j]['user_created'] = 1;
                                            }
                                            else {
                                                $field['value'][$j]['name'] = $tag->getTagName();
                                                $field['value'][$j]['user_created'] = $tag->getUserCreated();
                                            }
                                        }
                                    }
                                }

                                // Get full definitions for fields from original dataset
                                for($j=0;$j<count($orig_tag_field);$j++) {
                                    for($k=0;$k<count($field['value']);$k++) {
                                        if($field['value'][$k]['template_tag_uuid'] == $orig_tag_field[$j]['template_tag_uuid']) {
                                            $field['value'][$k] = $orig_tag_field[$j];
                                            break;
                                        }
                                    }
                                }
                                // Assign the updated field back to the dataset.
                                $dataset['fields'][$i] = $field;

                                break;

                            case 'Single Radio':
                                // Determine selected options in original dataset
                                // Determine selected options in current
                                $selected_options = $field['value'];

                                $orig_selected_options = array();
                                if ($orig_dataset) {
                                    foreach ($orig_dataset['fields'] as $o_field) {
                                        if (
                                            isset($o_field['value']) &&
                                            isset($field['field_uuid']) &&
                                            $o_field['field_uuid'] == $field['field_uuid']
                                        ) {
                                            $orig_selected_options = $o_field['value'];
                                        }
                                    }
                                }

                                $new_options = array();
                                $deleted_options = array();

                                // check for new options
                                foreach ($selected_options as $option) {
                                    $found = false;
                                    foreach ($orig_selected_options as $o_option) {
                                        if ($option['template_radio_option_uuid'] == $o_option['template_radio_option_uuid']) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($new_options, $option['template_radio_option_uuid']);
                                    }
                                }

                                if (count($new_options) > 1) {
                                    throw new \Exception('Invalid option count: Field ' . $datafield['field_uuid']);    // TODO - the stuff that saves really can't be throwing errors
                                }

                                // Check for deleted options
                                foreach ($orig_selected_options as $o_option) {
                                    $found = false;
                                    foreach ($selected_options as $option) {
                                        if ($option['template_radio_option_uuid'] == $o_option['template_radio_option_uuid']) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($deleted_options, $o_option['template_radio_option_uuid']);
                                    }
                                }

                                /** @var DataRecordFields $drf */
                                $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                                    array(
                                        'dataRecord' => $dataset['internal_id'],
                                        'dataField' => $datafield->getId()
                                    )
                                );

                                // Delete deleted options
                                foreach ($deleted_options as $option_uuid) {
                                    /** @var RadioOptions $option */
                                    $option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                                        array(
                                            'radioOptionUuid' => $option_uuid,
                                            'dataField' => $datafield->getId()
                                        )
                                    );
                                    /** @var RadioSelection $option_selection */
                                    $option_selection = $em->getRepository('ODRAdminBundle:RadioSelection')->findOneBy(
                                        array(
                                            'radioOption' => $option->getId(),
                                            'dataRecordFields' => $drf->getId()
                                        )
                                    );

                                    if ($option_selection) {
                                        $em->remove($option_selection);
                                        $fields_updated = true;
                                    }
                                }


                                // Add or delete options as needed
                                // Check if new option exists in template
                                // Add to template if not exists
                                foreach ($new_options as $option_uuid) {
                                    // Lookup Option by UUID
                                    /** @var RadioOptions $option */
                                    $option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                                        array(
                                            'radioOptionUuid' => $option_uuid,
                                            'dataField' => $datafield->getId()
                                        )
                                    );

                                    // User Added Options
                                    if(!$option) {
                                        if (
                                            !$pm_service->isDatatypeAdmin($user, $datarecord->getDataType())
                                            && !$pm_service->canEditDatatype($user, $datarecord->getDataType())
                                        ) {
                                            throw new ODRForbiddenException();
                                        }

                                        // Create option and set as user created
                                        $option = new RadioOptions();
                                        $radio_option_created = true;

                                        // Option UUID gets overloaded with the name if a user created option
                                        $option->setOptionName($option_uuid);

                                        /** @var UUIDService $uuid_service */
                                        $uuid_service = $this->container->get('odr.uuid_service');
                                        $option->setRadioOptionUuid($uuid_service->generateTagUniqueId());
                                        $option->setCreatedBy($user);
                                        self::setDates($option, $field['created']);
                                        $option->setUserCreated(1);
                                        $option->setDataField($datafield);
                                        $em->persist($option);

                                        /** @var RadioOptionsMeta $option_meta */
                                        $option_meta = new RadioOptionsMeta();
                                        $option_meta->setRadioOption($option);
                                        $option_meta->setIsDefault(false);
                                        $option_meta->setCreatedBy($user);
                                        self::setDates($option_meta, $field['created']);
                                        $option_meta->setDisplayOrder(0);
                                        $option_meta->setXmlOptionName('');
                                        $option_meta->setOptionName($option_uuid);
                                        $em->persist($option_meta);
                                    }

                                    if (!$drf) {
                                        // If drf entry doesn't exist, create new
                                        $drf = new DataRecordFields();
                                        $drf->setCreatedBy($user);
                                        self::setDates($drf, $field['created']);
                                        $drf->setDataField($datafield);
                                        $drf->setDataRecord($datarecord);
                                        $em->persist($drf);
                                    }

                                    /** @var RadioSelection $new_field */
                                    $new_field = new RadioSelection();
                                    $new_field->setRadioOption($option);
                                    $new_field->setDataRecord($datarecord);
                                    $new_field->setDataRecordFields($drf);
                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setSelected(1);
                                    $em->persist($new_field);

                                    $fields_updated = true;

                                    // Trying to do everything realtime - no waiting forever stuff
                                    // Maybe the references will be stored in the variable anyway?
                                    $em->flush();
                                    $em->refresh($new_field);

                                    self::fieldMeta($field, $datafield, $new_field);

                                    // Added tags need to replace their value in the array
                                    for($j=0;$j < count($field['value']);$j++) {
                                        if(
                                            $field['value'][$j]['template_radio_option_uuid'] == $option->getRadioOptionUuid()
                                            || (
                                                $option->getUserCreated()
                                                && $field['value'][$j]['template_radio_option_uuid'] == $option_uuid
                                            )
                                        ) {
                                            // replace this block
                                            $field['value'][$j]['template_radio_option_uuid'] = $option->getRadioOptionUuid();
                                            $field['value'][$j]['id'] = $new_field->getId();
                                            $field['value'][$j]['selected'] = 1;
                                            $field['value'][$j]['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                            $field['value'][$j]['created_at'] = $new_field->getCreated()->format('Y-m-d H:i:s');

                                            if ($radio_option_created) {
                                                $field['value'][$j]['name'] = $option_uuid;
                                                $field['value'][$j]['user_created'] = 1;
                                            }
                                            else {
                                                $field['value'][$j]['name'] = $option->getOptionName();
                                                $field['value'][$j]['user_created'] = $option->getUserCreated();
                                            }
                                        }
                                    }
                                    // Assign the updated field back to the dataset.
                                    $dataset['fields'][$i] = $field;
                                }

                                break;

                            case 'Multiple Radio':
                                // Determine selected options in original dataset
                                // Determine selected options in current
                                $selected_options = $field['value'];

                                $orig_selected_options = array();
                                if ($orig_dataset) {
                                    foreach ($orig_dataset['fields'] as $o_field) {
                                        if (
                                            isset($o_field['value']) &&
                                            isset($field['field_uuid']) &&
                                            $o_field['field_uuid'] == $field['field_uuid']
                                        ) {
                                            $orig_selected_options = $o_field['value'];
                                        }
                                    }
                                }

                                $new_options = array();
                                $deleted_options = array();

                                // check for new options
                                foreach ($selected_options as $option) {
                                    $found = false;
                                    foreach ($orig_selected_options as $o_option) {
                                        if ($option == $o_option) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($new_options, $option['template_radio_option_uuid']);
                                    }
                                }

                                /*
                                if(count($new_options) > 1) {
                                    throw new \Exception('Invalid option count: Field ' . $datafield['field_uuid']);
                                }
                                */

                                // Check for deleted options
                                foreach ($orig_selected_options as $o_option) {
                                    $found = false;
                                    foreach ($selected_options as $option) {
                                        if ($option == $o_option) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($deleted_options, $o_option['template_radio_option_uuid']);
                                    }
                                }

                                /** @var DataRecordFields $drf */
                                $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                                    array(
                                        'dataRecord' => $dataset['internal_id'],
                                        'dataField' => $datafield->getId()
                                    )
                                );

                                // Delete deleted options
                                foreach ($deleted_options as $option_uuid) {
                                    /** @var RadioOptions $option */
                                    $option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                                        array(
                                            'radioOptionUuid' => $option_uuid,
                                            'dataField' => $datafield->getId()
                                        )
                                    );
                                    /** @var RadioSelection $option_selection */
                                    $option_selection = $em->getRepository('ODRAdminBundle:RadioSelection')->findOneBy(
                                        array(
                                            'radioOption' => $option->getId(),
                                            'dataRecordFields' => $drf->getId()
                                        )
                                    );

                                    if ($option_selection) {
                                        $em->remove($option_selection);
                                        $fields_updated = true;
                                    }
                                }


                                // Add or delete options as needed
                                // Check if new option exists in template
                                // Add to template if not exists
                                foreach ($new_options as $option_uuid) {
                                    // Lookup Option by UUID
                                    /** @var RadioOptions $option */
                                    $option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                                        array(
                                            'radioOptionUuid' => $option_uuid,
                                            'dataField' => $datafield->getId()

                                        )
                                    );

                                    // User Added Options
                                    if(!$option) {
                                        if (
                                            !$pm_service->isDatatypeAdmin($user, $datarecord->getDataType())
                                            && !$pm_service->canEditDatatype($user, $datarecord->getDataType())
                                        ) {
                                            throw new ODRForbiddenException();
                                        }

                                        // Create option and set as user created
                                        $option = new RadioOptions();
                                        $radio_option_created = true;

                                        // Option UUID gets overloaded with the name if a user created option
                                        $option->setOptionName($option_uuid);

                                        /** @var UUIDService $uuid_service */
                                        $uuid_service = $this->container->get('odr.uuid_service');
                                        $option->setRadioOptionUuid($uuid_service->generateTagUniqueId());
                                        $option->setCreatedBy($user);
                                        self::setDates($option, $field['created']);
                                        $option->setUserCreated(1);
                                        $option->setDataField($datafield);
                                        $em->persist($option);

                                        /** @var RadioOptionsMeta $option_meta */
                                        $option_meta = new RadioOptionsMeta();
                                        $option_meta->setRadioOption($option);
                                        $option_meta->setIsDefault(false);
                                        $option_meta->setCreatedBy($user);
                                        self::setDates($option_meta, $field['created']);
                                        $option_meta->setDisplayOrder(0);
                                        $option_meta->setXmlOptionName($option->getOptionName());
                                        $option_meta->setOptionName($option->getOptionName());
                                        $em->persist($option_meta);
                                    }

                                    if (!$drf) {
                                        // If drf entry doesn't exist, create new
                                        $drf = new DataRecordFields();
                                        $drf->setCreatedBy($user);
                                        self::setDates($drf, $field['created']);
                                        $drf->setDataField($datafield);
                                        $drf->setDataRecord($datarecord);
                                        $em->persist($drf);
                                    }

                                    /** @var RadioSelection $new_field */
                                    $new_field = new RadioSelection();
                                    $new_field->setRadioOption($option);
                                    $new_field->setDataRecord($datarecord);
                                    $new_field->setDataRecordFields($drf);
                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setSelected(1);
                                    $em->persist($new_field);

                                    $fields_updated = true;

                                    // Trying to do everything realtime - no waiting forever stuff
                                    // Maybe the references will be stored in the variable anyway?
                                    $em->flush();
                                    $em->refresh($new_field);

                                    // Added tags need to replace their value in the array
                                    for($j=0;$j < count($field['value']);$j++) {
                                        if(
                                            $field['value'][$j]['template_radio_option_uuid'] == $option->getRadioOptionUuid()
                                            || (
                                                $option->getUserCreated()
                                                && $field['value'][$j]['template_radio_option_uuid'] == $option_uuid
                                            )
                                        ) {
                                            // replace this block
                                            $field['value'][$j]['template_radio_option_uuid'] = $option->getRadioOptionUuid();
                                            $field['value'][$j]['id'] = $new_field->getId();
                                            $field['value'][$j]['selected'] = 1;
                                            $field['value'][$j]['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                            $field['value'][$j]['created_at'] = $new_field->getCreated()->format('Y-m-d H:i:s');
                                            if($option->getUserCreated()) {
                                                $field['value'][$j]['name'] = $option_uuid;
                                                $field['value'][$j]['user_created'] = 1;
                                            }
                                            else {
                                                $field['value'][$j]['name'] = $option->getOptionName();
                                            }
                                        }
                                    }
                                    // Assign the updated field back to the dataset.
                                    self::fieldMeta($field, $datafield, $new_field);
                                    $dataset['fields'][$i] = $field;
                                }
                                break;

                            case 'Single Select':
                                // Determine selected options in original dataset
                                // Determine selected options in current
                                $selected_options = $field['value'];

                                $orig_selected_options = array();
                                if ($orig_dataset) {
                                    foreach ($orig_dataset['fields'] as $o_field) {
                                        if (
                                            isset($o_field['value']) &&
                                            isset($field['field_uuid']) &&
                                            $o_field['field_uuid'] == $field['field_uuid']
                                        ) {
                                            $orig_selected_options = $o_field['value'];
                                        }
                                    }
                                }

                                $new_options = array();
                                $deleted_options = array();

                                // check for new options
                                foreach ($selected_options as $option) {
                                    $found = false;
                                    foreach ($orig_selected_options as $o_option) {
                                        if ($option['template_radio_option_uuid'] == $o_option['template_radio_option_uuid']) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($new_options, $option['template_radio_option_uuid']);
                                    }
                                }

                                /*
                                if(count($new_options) > 1) {
                                    throw new \Exception('Invalid option count: Field ' . $datafield['field_uuid']);
                                }
                                */

                                // Check for deleted options
                                foreach ($orig_selected_options as $o_option) {
                                    $found = false;
                                    foreach ($selected_options as $option) {
                                        if ($option['template_radio_option_uuid'] == $o_option['template_radio_option_uuid']) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($deleted_options, $o_option['template_radio_option_uuid']);
                                    }
                                }

                                /** @var DataRecordFields $drf */
                                $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                                    array(
                                        'dataRecord' => $dataset['internal_id'],
                                        'dataField' => $datafield->getId()
                                    )
                                );

                                // Delete deleted options
                                foreach ($deleted_options as $option_uuid) {
                                    /** @var RadioOptions $option */
                                    $option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                                        array(
                                            'radioOptionUuid' => $option_uuid,
                                            'dataField' => $datafield->getId()
                                        )
                                    );
                                    /** @var RadioSelection $option_selection */
                                    $option_selection = $em->getRepository('ODRAdminBundle:RadioSelection')->findOneBy(
                                        array(
                                            'radioOption' => $option->getId(),
                                            'dataRecordFields' => $drf->getId()
                                        )
                                    );

                                    if ($option_selection) {
                                        $em->remove($option_selection);
                                        $fields_updated = true;
                                    }
                                }


                                // Add or delete options as needed
                                // Check if new option exists in template
                                // Add to template if not exists
                                foreach ($new_options as $option_uuid) {
                                    // Lookup Option by UUID
                                    /** @var RadioOptions $option */
                                    $option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                                        array(
                                            'radioOptionUuid' => $option_uuid,
                                            'dataField' => $datafield->getId()

                                        )
                                    );

                                    // User Added Options
                                    if(!$option) {
                                        if (
                                            !$pm_service->isDatatypeAdmin($user, $datarecord->getDataType())
                                            && !$pm_service->canEditDatatype($user, $datarecord->getDataType())
                                        ) {
                                            throw new ODRForbiddenException();
                                        }

                                        // Create option and set as user created
                                        $option = new RadioOptions();
                                        $radio_option_created = true;

                                        // Option UUID gets overloaded with the name if a user created option
                                        $option->setOptionName($option_uuid);

                                        /** @var UUIDService $uuid_service */
                                        $uuid_service = $this->container->get('odr.uuid_service');
                                        $option->setRadioOptionUuid($uuid_service->generateTagUniqueId());
                                        $option->setCreatedBy($user);
                                        self::setDates($option, $field['created']);
                                        $option->setUserCreated(1);
                                        $option->setDataField($datafield);
                                        $em->persist($option);

                                        /** @var RadioOptionsMeta $option_meta */
                                        $option_meta = new RadioOptionsMeta();
                                        $option_meta->setRadioOption($option);
                                        $option_meta->setIsDefault(false);
                                        $option_meta->setCreatedBy($user);
                                        self::setDates($option_meta, $field['created']);
                                        $option_meta->setDisplayOrder(0);
                                        $option_meta->setXmlOptionName($option->getOptionName());
                                        $option_meta->setOptionName($option->getOptionName());
                                        $em->persist($option_meta);
                                    }

                                    if (!$drf) {
                                        // If drf entry doesn't exist, create new
                                        $drf = new DataRecordFields();
                                        $drf->setCreatedBy($user);
                                        self::setDates($drf, $field['created']);
                                        $drf->setDataField($datafield);
                                        $drf->setDataRecord($datarecord);
                                        $em->persist($drf);
                                    }

                                    /** @var RadioSelection $new_field */
                                    $new_field = new RadioSelection();
                                    $new_field->setRadioOption($option);
                                    $new_field->setDataRecord($datarecord);
                                    $new_field->setDataRecordFields($drf);
                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setSelected(1);
                                    $em->persist($new_field);

                                    $fields_updated = true;

                                    // Trying to do everything realtime - no waiting forever stuff
                                    // Maybe the references will be stored in the variable anyway?
                                    $em->flush();
                                    $em->refresh($new_field);
                                    self::fieldMeta($field, $datafield, $new_field);

                                    // Added tags need to replace their value in the array
                                    for($j=0;$j < count($field['value']);$j++) {
                                        if(
                                            $field['value'][$j]['template_radio_option_uuid'] == $option->getRadioOptionUuid()
                                            || (
                                                $option->getUserCreated()
                                                && $field['value'][$j]['template_radio_option_uuid'] == $option_uuid
                                            )
                                        ) {
                                            // replace this block
                                            $field['value'][$j]['template_radio_option_uuid'] = $option->getRadioOptionUuid();
                                            $field['value'][$j]['id'] = $new_field->getId();
                                            $field['value'][$j]['selected'] = 1;
                                            $field['value'][$j]['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                            $field['value'][$j]['created_at'] = $new_field->getCreated()->format('Y-m-d H:i:s');
                                            if($option->getUserCreated()) {
                                                $field['value'][$j]['name'] = $option_uuid;
                                                $field['value'][$j]['user_created'] = 1;
                                            }
                                            else {
                                                $field['value'][$j]['name'] = $option->getOptionName();
                                            }
                                        }
                                    }
                                    // Assign the updated field back to the dataset.
                                    $dataset['fields'][$i] = $field;
                                }
                                break;

                            case 'Multiple Select':
                                // Determine selected options in original dataset
                                // Determine selected options in current
                                $selected_options = $field['value'];

                                $orig_selected_options = array();
                                if ($orig_dataset) {
                                    foreach ($orig_dataset['fields'] as $o_field) {
                                        if (
                                            isset($o_field['value']) &&
                                            isset($field['field_uuid']) &&
                                            $o_field['field_uuid'] == $field['field_uuid']
                                        ) {
                                            $orig_selected_options = $o_field['value'];
                                        }
                                    }
                                }

                                $new_options = array();
                                $deleted_options = array();

                                // check for new options
                                foreach ($selected_options as $option) {
                                    $found = false;
                                    foreach ($orig_selected_options as $o_option) {
                                        if ($option == $o_option) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        // TODO Should we add a check for "new_option" in UUID and then read an option name field?
                                        array_push($new_options, $option['template_radio_option_uuid']);
                                    }
                                }

                                /*
                                if (count($new_options) > 1) {
                                    throw new \Exception('Invalid option count: Field ' . $datafield['field_uuid']);
                                }
                                */

                                // Check for deleted options
                                foreach ($orig_selected_options as $o_option) {
                                    $found = false;
                                    foreach ($selected_options as $option) {
                                        if ($option == $o_option) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($deleted_options, $o_option['template_radio_option_uuid']);
                                    }
                                }

                                /** @var DataRecordFields $drf */
                                $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                                    array(
                                        'dataRecord' => $dataset['internal_id'],
                                        'dataField' => $datafield->getId()
                                    )
                                );

                                // Delete deleted options
                                foreach ($deleted_options as $option_uuid) {
                                    /** @var RadioOptions $option */
                                    $option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                                        array(
                                            'radioOptionUuid' => $option_uuid,
                                            'dataField' => $datafield->getId()
                                        )
                                    );
                                    /** @var RadioSelection $option_selection */
                                    $option_selection = false;
                                    if($option) {
                                        $option_selection = $em->getRepository('ODRAdminBundle:RadioSelection')->findOneBy(
                                            array(
                                                'radioOption' => $option->getId(),
                                                'dataRecordFields' => $drf->getId()
                                            )
                                        );
                                    }

                                    if ($option_selection) {
                                        $em->remove($option_selection);
                                        $fields_updated = true;
                                    }
                                }


                                // Add or delete options as needed
                                // Check if new option exists in template
                                // Add to template if not exists
                                foreach ($new_options as $option_uuid) {
                                    // Lookup Option by UUID
                                    /** @var RadioOptions $option */
                                    $option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                                        array(
                                            'radioOptionUuid' => $option_uuid,
                                            'dataField' => $datafield->getId()

                                        )
                                    );

                                    // User Added Options
                                    // User added option requires Datatype Admin Permissions
                                    if(!$option) {
                                        if(
                                            !$pm_service->isDatatypeAdmin($user, $datarecord->getDataType())
                                            && !$pm_service->canEditDatatype($user, $datarecord->getDataType())
                                        ) {
                                            throw new ODRForbiddenException();
                                        }

                                        // Create option and set as user created
                                        /** @var RadioOptions $option */
                                        $option = new RadioOptions();
                                        $radio_option_created = true;

                                        // Option UUID gets overloaded with the name if a user created option
                                        $option->setOptionName($option_uuid);

                                        /** @var UUIDService $uuid_service */
                                        $uuid_service = $this->container->get('odr.uuid_service');
                                        $option->setRadioOptionUuid($uuid_service->generateTagUniqueId());
                                        $option->setCreatedBy($user);
                                        self::setDates($option, $field['created']);
                                        $option->setUserCreated(1);
                                        $option->setDataField($datafield);
                                        $em->persist($option);

                                        /** @var RadioOptionsMeta $option_meta */
                                        $option_meta = new RadioOptionsMeta();
                                        $option_meta->setRadioOption($option);
                                        $option_meta->setIsDefault(false);
                                        $option_meta->setCreatedBy($user);
                                        self::setDates($option_meta, $field['created']);
                                        $option_meta->setDisplayOrder(0);
                                        $option_meta->setXmlOptionName($option_uuid);
                                        $option_meta->setOptionName($option_uuid);
                                        $em->persist($option_meta);

                                    }

                                    if (!$drf) {
                                        // If drf entry doesn't exist, create new
                                        $drf = new DataRecordFields();
                                        $drf->setCreatedBy($user);
                                        self::setDates($drf, $field['created']);
                                        $drf->setDataField($datafield);
                                        $drf->setDataRecord($datarecord);
                                        $em->persist($drf);
                                    }

                                    /** @var RadioSelection $new_field */
                                    $new_field = new RadioSelection();
                                    $new_field->setRadioOption($option);
                                    $new_field->setDataRecord($datarecord);
                                    $new_field->setDataRecordFields($drf);
                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setSelected(1);
                                    $em->persist($new_field);

                                    $fields_updated = true;

                                    // Trying to do everything realtime - no waiting forever stuff
                                    // Maybe the references will be stored in the variable anyway?
                                    $em->flush();
                                    $em->refresh($new_field);

                                    self::fieldMeta($field, $datafield, $new_field);
                                    // Added tags need to replace their value in the array
                                    for($j=0;$j < count($field['value']);$j++) {
                                        if(
                                            $field['value'][$j]['template_radio_option_uuid'] == $option->getRadioOptionUuid()
                                            || (
                                                $option->getUserCreated()
                                                && $field['value'][$j]['template_radio_option_uuid'] == $option_uuid
                                            )
                                        ) {
                                            // replace this block
                                            $field['value'][$j]['template_radio_option_uuid'] = $option->getRadioOptionUuid();
                                            $field['value'][$j]['id'] = $new_field->getId();
                                            $field['value'][$j]['selected'] = 1;
                                            $field['value'][$j]['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                            $field['value'][$j]['created_at'] = $new_field->getCreated()->format('Y-m-d H:i:s');
                                            if($option->getUserCreated()) {
                                                $field['value'][$j]['name'] = $option_uuid;
                                                $field['value'][$j]['user_created'] = 1;
                                            }
                                            else {
                                                $field['value'][$j]['name'] = $option->getOptionName();
                                            }
                                        }
                                    }
                                    // Assign the updated field back to the dataset.test
                                    $dataset['fields'][$i] = $field;
                                }
                                break;
                        }
                    }
                    else if ( isset($field['value']) ) {
                        // This handles all text/number/datetime fields

                        // The original dataset isn't guaranteed to have an entry for the field
                        $orig_field = null;
                        if ( !is_null($field_index) )
                            $orig_field = $orig_dataset['fields'][$field_index];

                        // Update the database with the value the user submitted, if needed
                        $changed = false;
                        $new_field = self::updateStorageField(
                            $em,
                            $ec_service,
                            $emm_service,
                            $user,
                            $datarecord,
                            $datafield,
                            $orig_field,
                            $field,
                            $changed
                        );

                        // If the field got modified...
                        if ($changed) {
                            // ...then need to fire off an event later
                            $field_updated = true;

                            if ( !is_null($orig_field) ) {
                                // Ensure the cached version of the datarecord is correct
                                $orig_dataset['fields'][$field_index] = $new_field;
                            }
                            else {
                                // The cached version of the datarecord didn't have an entry for this
                                //  field, so save what self::updateStorageField() returned
                                $orig_dataset['fields'][] = $new_field;
                            }

                            // TODO - update the record_name...
                        }
                    }


                    // ----------------------------------------
                    // If this field got modified...
                    if ($field_updated) {
                        // ...fire off a DatafieldModified event
                        try {
                            $event = new DatafieldModifiedEvent($datafield, $user);
                            $event_dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't want to rethrow the error since it'll interrupt everything after this
                            //  event
//                            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                                throw $e;
                        }

                        // ...need to eventually fire off a DatarecordModified event too
                        $record_updated = true;
                    }
                }
            }


            // ----------------------------------------
            // Need to delete child/linked records that no longer exist
            // TODO Only allow if user can delete related records
            if ($orig_dataset && !empty($orig_dataset['records'])) {
                // Check if old record exists and delete if necessary...
                $deleted_records = array();

                for ($i = 0; $i < count($orig_dataset['records']); $i++) {
                    $o_descendant_record = $orig_dataset['records'][$i];

                    $record_found = false;
                    // Check if record_uuid and template_uuid match - if so we're differencing
                    foreach ($dataset['records'] as $descendant_record) {
                        if ( !isset($descendant_record['database_uuid']) )
                            throw new ODRBadRequestException('No database uuid provided for Descendant Datarecord '.$descendant_record['record_uuid']);

                        // New records don't have UUIDs and need to be ignored in this check
                        if ( isset($descendant_record['record_uuid'])
                            && !empty($descendant_record['record_uuid'])
                            && $descendant_record['database_uuid'] == $o_descendant_record['database_uuid']
                            && $descendant_record['record_uuid'] == $o_descendant_record['record_uuid']
                        ) {
                            $record_found = true;
                        }
                    }

                    if (!$record_found) {
                        // The dataset submitted by the user doesn't have a record that currently
                        //  exists...
                        /** @var DataRecord $del_record */
                        $del_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                            array('unique_id' => $o_descendant_record['record_uuid'])
                        );
                        if ($del_record == null)
                            throw new ODRNotFoundException('Del Datarecord');
                        $descendant_datatype = $del_record->getDataType();

                        // ...which means the existing record needs to get deleted/unlinked
                        $record_updated = true;

                        // Need to determine whether it's a child or a linked record...the deletion
                        //  of top-level records is done via a different API action
                        $grandparent_datatype = $datatype->getGrandparent();
                        $del_record_grandparent_datatype = $del_record->getDataType()->getGrandparent();

                        if ( $grandparent_datatype->getId() === $del_record_grandparent_datatype->getId() ) {
                            // $del_record is a child record, and will be deleted
                            $ed_service->deleteDatarecord(
                                $del_record,
                                $user,
                                false  // want datasetDiff() to fire the DatarecordModified Event instead
                            );

                            // Don't need to recursively locate children of this child...they've
                            //  been deleted by EntityDeletionService::deleteDatarecord()
                        }
                        else {
                            // $del_record is a linked record...going to unlink instead of deleting it
                            // TODO - might still want to delete the record if the template_group matches?
                            /** @var LinkedDataTree $ldt */
                            $ldt = $em->getRepository('ODRAdminBundle:LinkedDataTree')->findOneBy(
                                array(
                                    'ancestor' => $datarecord->getId(),
                                    'descendant' => $del_record->getId(),
                                )
                            );
                            if ($ldt == null)
                                throw new ODRNotFoundException('Datarecord Link');

                            $ldt->setDeletedBy($user);
                            $ldt->setDeletedAt(new \DateTime());
                            $em->persist($ldt);
                            $em->flush();

                            // LinkStatusChange events need the grandparent datarecord (which we have
                            //  due to recursion), and the datatype of the datarecord that got
                            //  linked/unliked to...so save it for later
                            $link_change_datatypes[ $descendant_datatype->getId() ] = 1;
                        }

                        // Don't want to leave this deleted record or its children in the cached
                        //  json array...but can't unset it right this moment because it'll screw
                        //  up the for loop
                        $deleted_records[$i] = 1;
                    }
                }

                // If at least one record was deleted...
                if ( !empty($deleted_records) ) {
                    // ...then redo the array of descendant records so that the keys are all
                    //  contiguous, ignoring the records that got deleted
                    $tmp = array();
                    foreach ($orig_dataset['records'] as $num => $dr) {
                        if ( !isset($deleted_records[$num]) )
                            $tmp[] = $dr;
                    }
                    $orig_dataset['records'] = $tmp;
                }
            }


            // ----------------------------------------
            // Need to check for child/linked records that don't exist yet
            if ( !empty($dataset['records']) ) {
                /** @var DataType[] $datatype_lookup */
                $datatype_lookup = array();

                // self::checkUpdatePermissions() already checked whether the 'multiple_allowed'
                //  property will be followed...don't need to do it again

                foreach ($dataset['records'] as $descendant_record) {
                    // Pull the identifying information for this descendant record from the array
                    //  the user submitted
//                    if ( !isset($descendant_record['database_uuid']) )
//                        throw new ODRBadRequestException('No database uuid provided for Descendant Datarecord '.$descendant_record['record_uuid']);
//                    $descendant_datatype_uuid = $descendant_record['database_uuid'];
//
//                    // TODO - this won't work for the AHED shit
//
//                    // TODO - https://github.com/OpenDataRepository/data-publisher/blob/4da207fc27b58f1f417d82e3a4b5731c2d8686d1/src/ODR/AdminBundle/Controller/APIController.php#L2862
//                    // TODO - ...is how it used to work...but that's not entirely correct either.
//
//                    // TODO - probably need something like this in all four of these places where this particular 400 error is otherwise thrown
////                    $descendant_datatype = self::determineDescendantDatatype($datarecord, $descendant_record, $datatype, $datatype_lookup);
//
//                    if ( !isset($datatype_lookup[$descendant_datatype_uuid]) ) {
//                        /** @var DataType $dt */
//                        $dt = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
//                            array('unique_id' => $descendant_datatype_uuid)
//                        );
//                        if ($dt == null)
//                            throw new ODRNotFoundException('Datatype');
//
//                        $datatype_lookup[$descendant_datatype_uuid] = $dt;
//                    }
//                    $descendant_datatype = $datatype_lookup[$descendant_datatype_uuid];

                    // TODO
                    $descendant_datatype = self::getDescendantDatatype($em, $datatype_lookup, $descendant_record);
                    $descendant_record['database_uuid'] = $descendant_datatype->getUniqueId();

                    // Attempt to find the original version of the record the user specified
                    $record_found = false;
                    if ( $orig_dataset && isset($orig_dataset['records']) ) {
                        // Check if record_uuid and template_uuid match - if so we're differencing
                        for ($i = 0; $i < count($orig_dataset['records']); $i++) {
                            $o_descendant_record = $orig_dataset['records'][$i];
                            if ( isset($descendant_record['database_uuid'])
                                && isset($descendant_record['record_uuid'])
                                && $descendant_record['database_uuid'] == $o_descendant_record['database_uuid']
                                && $descendant_record['record_uuid'] == $o_descendant_record['record_uuid']
                            ) {
                                // Found the expected child/linked descendant record in the submitted
                                //  dataset...
                                $record_found = true;

                                // Ensure that this child/linked datarecord is up to date
                                $new_record = self::datasetDiff(
                                    $em,
                                    $event_dispatcher,
                                    $ec_service,
                                    $ed_service,
                                    $emm_service,
                                    $user,
                                    $descendant_record,   // compare the user's requested changes...
                                    $o_descendant_record, // ...against the existing record
                                    false,                // this next call is no longer top-level
                                    $link_change_datatypes
                                );

                                // Save any modifications of the datarecord to the cached version
                                $orig_dataset['records'][$i] = $new_record;
                            }
                        }
                    }

                    if ( !$record_found ) {
                        // User submitted a child/linked record that doesn't exist in the database...
                        $record_updated = true;

                        // The user might have specified a create date...
                        $descendant_created = null;
                        if ( isset($descendant_record['created']) )
                            $descendant_created = new \DateTime($descendant_record['created']);
                        // ...and/or a public_date
                        $descendant_public_date = null;
                        if ( isset($descendant_record['public_date']) )
                            $descendant_public_date = new \DateTime($descendant_record['public_date']);

                        // Determine if the descendant datatype is a child or a link
                        $is_link = false;
                        /** @var DataTree $datatree */
                        $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                            array(
                                'ancestor' => $datatype->getId(),
                                'descendant' => $descendant_datatype->getId()
                            )
                        );
                        if ($datatree == null)
                            throw new ODRNotFoundException('Datatree');
                        if ($datatree->getIsLink())
                            $is_link = true;


                        // If a record uuid was specified...
                        if ( isset($descendant_record['record_uuid']) && strlen($descendant_record['record_uuid']) > 0) {
                            // ...then the descendant datatype will be a link, and the descendant
                            //  datarecord will already exist
                            /** @var DataRecord $linked_descendant_record */
                            $linked_descendant_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                                array(
                                    'unique_id' => $descendant_record['record_uuid'],
                                    'dataType' => $descendant_datatype->getId(),
                                )
                            );
                            if ($linked_descendant_record == null)
                                throw new ODRNotFoundException('Datarecord '.$descendant_record['record_uuid']);

                            // Create a link between the current datarecord and its descendant
                            $ec_service->createDatarecordLink(
                                $user,
                                $datarecord,
                                $linked_descendant_record,
                                $descendant_created    // Create the link on this date
                            );

                            // LinkStatusChange events need the grandparent datarecord (which we have
                            //  due to recursion), and the datatype of the datarecord that got
                            //  linked/unliked to...so save it for later
                            $link_change_datatypes[ $descendant_datatype->getId() ] = 1;

                            // Need to load the cached version of the datarecord that just got
                            //  linked, since it also needs to be run through datasetDiff()...
                            $new_linked_record = self::getRecordData(
                                'v4',
                                $descendant_record['record_uuid'],
                                'json',
                                true,    // TODO - the rest of the API stuff demands that metadata exist...
                                $user
                            );
                            $new_linked_record = json_decode($new_linked_record, true);

                            // Update the newly linked record with datasetDiff()
                            $new_linked_record = self::datasetDiff(
                                $em,
                                $event_dispatcher,
                                $ec_service,
                                $ed_service,
                                $emm_service,
                                $user,
                                $descendant_record,
                                $new_linked_record,
                                false,                // this next call is no longer top-level
                                $link_change_datatypes
                            );

                            // Save any changes made to the array
                            $orig_dataset['records'][] = $new_linked_record;
                        }
                        else {
                            // When a record_uuid isn't specified, create a new datarecord
                            $new_descendant_datarecord = $ec_service->createDatarecord(
                                $user,
                                $descendant_datatype,
                                true,    // don't flush
                                true,    // might as well select default radio options...
                                $descendant_created    // Create the record on this date
                            );

                            // If the user specified a public date, then set that
                            if ( !is_null($descendant_public_date) ) {
                                $new_dr_meta = $new_descendant_datarecord->getDataRecordMeta();
                                $new_dr_meta->setPublicDate($descendant_public_date);
                                $em->persist($new_dr_meta);
                            }

                            // If the API call is creating a child record...
                            if ( !$is_link ) {
                                // ...ensure the new record's parents are properly set
                                $new_descendant_datarecord->setParent($datarecord);
                                $new_descendant_datarecord->setGrandparent($datarecord->getGrandparent());
                            }

                            // This is wrapped in a try/catch block because any uncaught exceptions will abort
                            //  creation of the new datarecord...
                            try {
                                $event = new DatarecordCreatedEvent($new_descendant_datarecord, $user);
                                $event_dispatcher->dispatch(DatarecordCreatedEvent::NAME, $event);
                            }
                            catch (\Exception $e) {
                                // ...don't want to rethrow the error since it'll interrupt everything after this
                                //  event
//                                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                                    throw $e;
                            }

                            // New datarecord is ready...persist and flush
                            $new_descendant_datarecord->setProvisioned(false);
                            $em->persist($new_descendant_datarecord);
                            $em->flush();

                            // If this is a link...
                            if ( $is_link ) {
                                // ...then create a link between the current record and the new one
                                $ec_service->createDatarecordLink(
                                    $user,
                                    $datarecord,
                                    $new_descendant_datarecord,
                                    $descendant_created    // Create the link on this date
                                );

                                // LinkStatusChange events need the grandparent datarecord (which we
                                //  have due to recursion), and the datatype of the datarecord that
                                //  got linked/unliked to...so save it for later
                                $link_change_datatypes[ $descendant_datatype->getId() ] = 1;
                            }


                            // ----------------------------------------
                            // Need to fill in this entry in the user's submitted array, otherwise
                            //  the next level of datasetDiff() will choke
                            $descendant_record['record_uuid'] = $new_descendant_datarecord->getUniqueId();

                            // Create a block of json data for the new datarecord
                            $new_record = array(
                                'database_uuid' => $descendant_datatype->getUniqueId(),
                                'internal_id' => $new_descendant_datarecord->getId(),
                                'record_name' => $new_descendant_datarecord->getId(),    // records without namefield values use the id
                                'record_uuid' => $new_descendant_datarecord->getUniqueId(),

                                '_record_metadata' => array(
                                    '_create_date' => $new_descendant_datarecord->getCreated()->format("Y-m-d H:i:s"),
                                    '_update_date' => $new_descendant_datarecord->getUpdated()->format("Y-m-d H:i:s"),
                                    '_create_auth' => $new_descendant_datarecord->getCreatedBy()->getUserString(),
                                    '_public_date' => $new_descendant_datarecord->getPublicDate()->format("Y-m-d H:i:s"),
                                ),
                                'fields' => array(),
                                'records' => array(),
                            );
                            if ( !is_null($descendant_datatype->getMasterDataType()) )
                                $new_record['template_uuid'] = $descendant_datatype->getMasterDataType()->getUniqueId();

                            // Ensure the new child/linked record's contents are up to date
                            $new_record = self::datasetDiff(
                                $em,
                                $event_dispatcher,
                                $ec_service,
                                $ed_service,
                                $emm_service,
                                $user,
                                $descendant_record,
                                $new_record, // this is a new record, so it'll only have minimal entries
                                false,                // this next call is no longer top-level
                                $link_change_datatypes
                            );

                            // Save the modified data back in the array
                            $orig_dataset['records'][] = $new_record;
                        }
                    }
                }
            }


            // ----------------------------------------
            // If this is the top-level record...
            if ( $is_original_record ) {
                // ...and something got linked
                if ( !empty($link_change_datatypes) ) {
                    $link_change_datatypes = array_keys($link_change_datatypes);
                    $query = $em->createQuery(
                       'SELECT dt
                        FROM ODRAdminBundle:DataType dt
                        WHERE dt.id IN (:datatypes)'
                    )->setParameters( array('datatypes' => $link_change_datatypes) );
                    $results = $query->getResult();

                    // Need to fire off one event per descendant datatype that had a record linked/unliked
                    foreach ($results as $dt) {
                        /** @var DataType $dt */
                        try {
                            $event = new DatarecordLinkStatusChangedEvent(array($datarecord->getId()), $dt, $user);
                            $event_dispatcher->dispatch(DatarecordLinkStatusChangedEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't want to rethrow the error since it'll interrupt everything after this
                            //  event
//                            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                                throw $e;
                        }
                    }

                    // Also should update the record
                    $record_updated = true;
                }
            }

            // Fire off another event if this datarecord got modified...
            if ( $record_updated ) {
                // NOTE - need to fire off these events due to search caching...the setUpdated()
                //  calls "bubbling up" from child records is somewhat less than ideal, but currently
                //  don't seem bad enough to modify the event to stop...
                try {
                    // At the moment, there's no need to distinguish between a DatarecordModified
                    //  and a DatarecordPublicStatusChanged event...they currently trigger the same
                    //  cache rebuilds
                    $event = new DatarecordModifiedEvent($datarecord, $user);
                    $event_dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }

                // Update the record's metadata since something changed
                $em->refresh($datarecord);
                $orig_dataset['_record_metadata'] = array(
                    '_create_date' => $datarecord->getCreated()->format("Y-m-d H:i:s"),
                    '_update_date' => $datarecord->getUpdated()->format("Y-m-d H:i:s"),
                    '_create_auth' => $datarecord->getCreatedBy()->getUserString(),
                    '_public_date' => $datarecord->getPublicDate()->format("Y-m-d H:i:s"),
                );
            }

            if ( $radio_option_created || $tag_created ) {
                // Mark the new child datatype's parent as updated
                try {
                    $event = new DatatypeModifiedEvent($datatype, $user);
                    $event_dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }


            // ----------------------------------------
            // Return the current array version of this record (including updates)
            return $orig_dataset;
        }
        catch (\Exception $e) {
            $source = 0x8c60259a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Locates and returns the index of the given datafield in $orig_dataset, so that $orig_dataset
     * can be updated with any changes later on
     *
     * @param array $orig_dataset
     * @param DataFields $datafield
     * @return int|null
     */
    private function findFieldIndex($orig_dataset, $datafield)
    {
        // If this datarecord has no data in any of its fields, then this entry will not exist
        if ( empty($orig_dataset['fields']) )
            return null;

        for ($i = 0; $i < count($orig_dataset['fields']); $i++) {
            // Attempt to find the datafield by its field_uuid...
            $field = $orig_dataset['fields'][$i];
            if ($datafield->getFieldUuid() === $field['field_uuid'])
                return $i;
        }

        // ...if no matching field was found, then have to return null
        return null;
    }


    /**
     * Saves any modifications the user wants to make to this file/image field, and returns an
     * updated array entry to be saved back in the cache.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EntityMetaModifyService $emm_service
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param array $orig_field The currently cached version of the field's data
     * @param array $field The potential modification the user wants to make this field
     * @param bool $changed Returns whether a changed was made
     *
     * @return array The updated version of the currently cached field's data
     */
    private function updateFileImageField($em, $emm_service, $user, $datafield, $orig_field, $field, &$changed)
    {
        // Going to need these
        $typeclass = $datafield->getFieldType()->getTypeClass();

        foreach ($field['files'] as $file) {
            if ( isset($file['public_date']) ) {
                // Going to ensure this file/image has this public date...
                $public_date = new \DateTime($file['public_date']);

                // Because of how the cached array is structured, any changes made to an image need
                //  to also be made to all of its resized children... TODO - remove metadata from resized images in json export?
                $all_images = null;

                // Load the file/image the given uuid is referring to
                $obj = null;
                if ($typeclass === 'File') {
                    /** @var File $obj */
                    $obj = $em->getRepository('ODRAdminBundle:File')->findOneBy(
                        array('unique_id' => $file['file_uuid'])
                    );
                }
                else {
                    /** @var Image $obj */
                    $obj = $em->getRepository('ODRAdminBundle:Image')->findOneBy(
                        array('unique_id' => $file['file_uuid'])
                    );

                    // Only the original image has metadata, so use the original even if the user
                    //  specified a resized image
                    if ( !is_null($obj->getParent()) )
                        $obj = $obj->getParent();

                    // Grab all children of the original image...their cached array entries need
                    //  to be modified too
                    /** @var Image[] $all_images */
                    $all_images = $em->getRepository('ODRAdminBundle:Image')->findBy(
                        array('parent' => $obj->getId())
                    );
                    $all_images[] = $obj;
                }
                /** @var File|Image $obj */

                // If the public date has changed...
                if ($public_date->format("Y-m-d H:i:s") !== $obj->getPublicDate()->format("Y-m-d H:i:s") ) {
                    // ...then the database entity needs to be updated
                    $changed = true;
                    $props = array('publicDate' => $public_date);

                    if ($typeclass === 'File') {
                        // Make the change...
                        $emm_service->updateFileMeta($user, $obj, $props);

                        // ...then find the file in $orig_field that this entry is referring to, so
                        //  its array entry can be updated with the new public date
                        for ($i = 0; $i < count($orig_field['files']); $i++) {
                            $orig_file = $orig_field['files'][$i];
                            if ($orig_file['file_uuid'] === $obj->getUniqueId()) {
                                $orig_field['files'][$i]['_file_metadata']['_public_date'] = $public_date->format("Y-m-d H:i:s");
                                break;
                            }
                        }

                        if ( !$obj->isPublic() ) {
                            // If the file is no longer public, then need to delete the decrypted
                            //  version of the file, if it exists
                            $file_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/';
                            $filename = 'File_'.$obj->getId().'.'.$obj->getExt();
                            $absolute_path = realpath($file_upload_path).'/'.$filename;

                            if ( file_exists($absolute_path) )
                                unlink($absolute_path);
                        }
                    }
                    else {
                        // Make the change...
                        $emm_service->updateImageMeta($user, $obj, $props);

                        // ...then find all images in $orig_field that are related to this entry
                        //  so their array entries can be updated with the new public date
                        foreach ($all_images as $img) {
                            for ($i = 0; $i < count($orig_field['files']); $i++) {
                                $orig_img = $orig_field['files'][$i];
                                if ($orig_img['file_uuid'] === $img->getUniqueId()) {
                                    $orig_field['files'][$i]['_file_metadata']['_public_date'] = $public_date->format("Y-m-d H:i:s");
                                    break;
                                }
                            }
                        }

                        if ( !$obj->isPublic() ) {
                            // If the image is no longer public, then need to delete the decrypted
                            //  version of the image and all of its children, if any exist
                            foreach ($all_images as $img) {
                                $image_upload_path = $this->getParameter('odr_web_directory').'/uploads/images/';
                                $filename = 'Image_'.$img->getId().'.'.$img->getExt();
                                $absolute_path = realpath($image_upload_path).'/'.$filename;

                                if ( file_exists($absolute_path) )
                                    unlink($absolute_path);
                            }
                        }
                    }
                }

                // TODO - change the display order of images?
            }
        }

        return $orig_field;
    }


    private function updateRadioField()
    {

    }


    private function updateTagField()
    {

    }


    /**
     * Saves any modifications the user wants to make to this text/number/date field, and returns
     * an updated array entry to be saved back in the cache.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EntityCreationService $ec_service
     * @param EntityMetaModifyService $emm_service
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param DataFields $datafield
     * @param array $orig_field The currently cached version of the field's data
     * @param array $field The potential modification the user wants to make this field
     * @param bool $changed Returns whether a changed was made
     *
     * @return array The updated version of the currently cached field's data
     */
    private function updateStorageField($em, $ec_service, $emm_service, $user, $datarecord, $datafield, $orig_field, $field, &$changed)
    {
        $created = null;
        if ( isset($field['created']) )
            $created = new \DateTime($field['created']);

        $key = 'value';
        if ($datafield->getFieldType()->getTypeClass() === 'Boolean')
            $key = 'selected';

        // Because the user could submit a blank value, and we would prefer for ODR to have to
        //  create storage entities just to store a blank value...
        $desired_value = $field[$key];

        // ...need to attempt to load the storage entity first
        /** @var ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $storage_entity */
        $typeclass = $datafield->getFieldType()->getTypeClass();
        $storage_entity = $em->getRepository('ODRAdminBundle:'.$typeclass)->findOneBy(
            array(
                'dataRecord' => $datarecord->getId(),
                'dataField' => $datafield->getId()
            )
        );

        if ( is_null($storage_entity) && $desired_value !== '' ) {
            // If the storage entity does not exist and the user doesn't want a blank value in it,
            //  then create the entity with the desired value
            $changed = true;
            $storage_entity = $ec_service->createStorageEntity(
                $user,
                $datarecord,
                $datafield,
                $field[$key], // use this value when creating the entity
                true,         // always fire events
                $created      // use this date when creating the entity
            );

            // Most likely, the original array version doesn't have any data for this field, so
            //  construct and return the relevant data
            $new_field = array(
                'id' => $datafield->getId(),    // TODO - can this get renamed?
                'field_name' => $datafield->getFieldName(),
                'field_uuid' => $datafield->getFieldUuid(),
                'template_field_uuid' => $datafield->getTemplateFieldUuid(),

                $key => $storage_entity->getValue(),

                '_field_metadata' => array(
                    '_public_date' => $datafield->getPublicDate()->format("Y-m-d H:i:s"),
                    '_create_date' => $storage_entity->getCreated()->format("Y-m-d H:i:s"),
                    '_update_date' => $storage_entity->getUpdated()->format("Y-m-d H:i:s"),
                    '_create_auth' => $storage_entity->getCreatedBy()->getUserString(),
                )
            );

            return $new_field;
        }
        else if ( !is_null($storage_entity) && $storage_entity->getValue() != $desired_value ) {
            // If the storage entity exists and its value is different than what the user wants,
            //  then update the entity to have the desired value
            $changed = true;
            $props = array('value' => $field[$key]);

            $storage_entity = $emm_service->updateStorageEntity(
                $user,
                $storage_entity,
                $props,
                false,   // do not delay flush
                true,    // always fire events
                $created // make the database think the change happened on this date
            );

            // The original array version should exist for this field, so only need to update a
            //  few of its entries
            $orig_field[$key] = $storage_entity->getValue();
            $orig_field['_field_metadata']['_create_date'] = $storage_entity->getCreated()->format("Y-m-d H:i:s");
            $orig_field['_field_metadata']['_update_date'] = $storage_entity->getUpdated()->format("Y-m-d H:i:s");
            $orig_field['_field_metadata']['_create_auth'] = $storage_entity->getCreatedBy()->getUserString();

            return $orig_field;
        }
        else {
            // No change made...don't need to do anything
            return $orig_field;
        }
    }


    /**
     * @param array $records_to_delete
     * @param array $record
     */
    private function getRecordsToDelete(&$records_to_delete, $record) {
        array_push($records_to_delete, $record['record_uuid']);
        if(isset($record['records']) && count($record['records']) > 0) {
            foreach($record['records'] as $child_record) {
                self::getRecordsToDelete($records_to_delete, $child_record);
            }
        }
    }


    private function resetdataset($dataset, $is_top_level)
    {
        $tmp = $dataset;
        unset( $tmp['internal_id'] );
        unset( $tmp['record_name'] );
        if ( !$is_top_level )
            unset( $tmp['record_uuid'] );
        unset( $tmp['_record_metadata'] );
        unset( $tmp['metadata_for_uuid'] );

        foreach ($tmp['fields'] as $num => $df) {
            unset( $tmp['fields'][$num]['id'] );
            unset( $tmp['fields'][$num]['_field_metadata'] );
        }

        foreach ($tmp['records'] as $num => $dr)
            $tmp['records'][$num] = self::resetdataset($dr, false);

        return $tmp;
    }


    /**
     * Accepts wrapped JSON with $user_email or $dataset/record directly
     * Updates a dataset record
     *
     * @param string $version
     * @param Request $request
     *
     * @return Response
     */
    public function updatedatasetAction($version, Request $request)
    {
        try {
/**/
            // Extract the submitted data from the POST request
            $content = $request->getContent();
            if ( empty($content) )
                throw new ODRBadRequestException('No POST data');
            $content = json_decode($content, true); // 2nd param to get as array

            // This parameter is required...
            if ( !isset($content['dataset']) )
                throw new ODRBadRequestException('missing dataset parameter');
            $dataset = $content['dataset'];
            // ...as is the uuid of the record to update
            if ( !isset($dataset['record_uuid']) )
                throw new ODRBadRequestException('missing record_uuid parameter');
            $record_uuid = $dataset['record_uuid'];
/**/
            // This API call allows the user to "act as" somebody else in certain situations
            $user_email = null;
            if ( isset($content['user_email']) )
                $user_email = $content['user_email'];

//$user_email = 'test_1@opendatarepository.org';


//$record_uuid = '7ae1486800953d89475fcf8735ef';    // reference 1
//$record_uuid = '2c2999d7096bb793c40617e4d308';    // abelsonite
//$record_uuid = '3dc34968eecfb9a2abdd8bc8529c';    // R070007

//$record_uuid = '5e63acbb1e8523d3fd8d904362b3';    // R040054 raman spectra, has 3 children
//$record_uuid = 'd4ebd496977e7102462c2862e2e9';    // R050610 raman spectra, has spectra comment

//$record_uuid = '0ee36962990db9a13bc2582be4f7';    // cell parameter


//$record_uuid = '26debfe26d606e164e9d32879f0d';


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $event_dispatcher = $this->get('event_dispatcher');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityDeletionService $ed_service */
            $ed_service = $this->container->get('odr.entity_deletion_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                array('unique_id' => $record_uuid)
            );
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            if ( $datarecord->getId() !== $datarecord->getGrandparent()->getId() )
                throw new ODRBadRequestException('Not allowed to run on a child record');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            /** @var ODRUser $user */
            $user = null;

            if ( is_null($user_email) ) {
                // If a user email wasn't provided, then use the admin user for this action
                $user = $logged_in_user;
            }
            else if ( !is_null($user_email) && $logged_in_user->hasRole('ROLE_SUPER_ADMIN') ) {
                // If a user email was provided, and the user calling this action is a super-admin,
                //  then attempt to locate the user for the given email
                /** @var UserManager $user_manager */
                $user_manager = $this->container->get('fos_user.user_manager');
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if ($user == null)
                    throw new ODRNotFoundException('The User "'.$user_email.'" does not exist', true);
            }

            if ($user == null)
                throw new ODRNotFoundException('User');
            // ----------------------------------------


//$dataset = self::getRecordData('v4', $record_uuid, 'json', true, $logged_in_user, false);
//$dataset = json_decode($dataset, true);

// Don't want anything in here
//$dataset = array('record_uuid' => $record_uuid);
// Setting public date...
//$dataset['public_date'] = "2300-01-01 00:00:00";
// Setting create date...
//$dataset['created'] = "1900-01-01 00:00:00";

// Pretend the user is submitting everything
//$dataset = self::resetdataset($dataset, true);

// Delete child/linked records
//$dataset['records'] = array();
// Delete one child/linked record
//unset( $dataset['records'][0] );

// Change a field's value
//$dataset['fields'][0]['value'] = 'asdf';
// Give an invalid value to a shortvarchar field
//$dataset['fields'][3]['value'] = '1234567890123456789012345678901234567890';
// Give an invalid value to an integervalue field
//$dataset['fields'][8]['value'] = 'asdf';


// ----------------------------------------
// Change a value
//$dataset['fields'][0]['value'] = 'asdf';
//$dataset['fields'][0]['created'] = '1950-01-01 00:00:00';
//// Add a value to a field without a value
//$dataset['fields'][] = array(
//    'field_uuid' => '9bececb5e3d08eb6d8e9b3f14e49',
//    'value' => 'qwer',
//    'created' => '2000-01-01 00:00:00'
//);

// add a child record
//$dataset['records'][] = array(
//    'database_uuid' => '983063396ef3855b3446ea20d178',
//);
// add a value for a child record
//$dataset['records'][] = array(
//    'database_uuid' => '983063396ef3855b3446ea20d178',
//    'fields' => array(
//        0 => array(
//            'field_uuid' => '63c7253af136cff254e0d5fea189',
//            'value' => 1,
//            'created' => '1900-01-01 00:00:00'
//        )
//    )
//);
//$dataset['records'][] = array(
//    'database_uuid' => '983063396ef3855b3446ea20d178',
//    'fields' => array(
//        0 => array(
//            'field_uuid' => '63c7253af136cff254e0d5fea189',
//            'value' => 2,
//            'created' => '2000-01-01 00:00:00'
//        )
//    )
//);
//$dataset['records'][] = array(
//    'database_uuid' => '983063396ef3855b3446ea20d178',
//    'fields' => array(
//        0 => array(
//            'field_uuid' => '63c7253af136cff254e0d5fea189',
//            'value' => 3,
//            'created' => '2100-01-01 00:00:00'
//        )
//    )
//);

// ----------------------------------------
// Link to an existing record
//$dataset['records'][] = array(
//    'database_uuid' => 'e9d5f179f8bad32eaf7cc41f4eee',
//    'record_uuid' => '87fd9d7f83ed46ebaae3330c34d6',
////    'created' => '1900-01-01 00:00:00',
//);
// Link to an existing record and change its value
//$dataset['records'][] = array(
//    'database_uuid' => 'e9d5f179f8bad32eaf7cc41f4eee',
//    'record_uuid' => '87fd9d7f83ed46ebaae3330c34d6',
//    'fields' => array(
//        0 => array(
//            'field_uuid' => 'b1dee7aaa1c64928977ead068c56',
//            'value' => 'aaaaa'
//        )
//    )
//);

// Create and link to a new blank record
//$dataset['records'][] = array(
//    'database_uuid' => 'e9d5f179f8bad32eaf7cc41f4eee',
//);
// Create and link to a new blank record and set its value
//$dataset['records'][] = array(
//    'database_uuid' => 'e9d5f179f8bad32eaf7cc41f4eee',
//    'fields' => array(
//        0 => array(
//            'field_uuid' => 'b1dee7aaa1c64928977ead068c56',
//            'value' => 'aaaaa'
//        )
//    )
//);
//$dataset['records'][] = array(
//    'database_uuid' => 'e9d5f179f8bad32eaf7cc41f4eee',
//    'fields' => array(
//        0 => array(
//            'field_uuid' => 'b1dee7aaa1c64928977ead068c56',
//            'value' => 'bbbbb'
//        )
//    )
//);
//$dataset['records'][] = array(
//    'database_uuid' => 'e9d5f179f8bad32eaf7cc41f4eee',
//    'fields' => array(
//        0 => array(
//            'field_uuid' => 'b1dee7aaa1c64928977ead068c56',
//            'value' => 'ccccc'
//        )
//    )
//);

// ----------------------------------------
// Set a file to public
//foreach ($dataset['fields'] as $num => $field) {
//    if ( $field['field_uuid'] === '3fbd20317257402bdc8fa7df6f2e') {
//        $dataset['fields'][$num]['files'][0] = array(
//            'file_uuid' => $field['files'][0]['file_uuid'],
//            'public_date' => '1900-01-01 00:00:00',
//        );
//    }
//}
// Set all public files to not-public
//foreach ($dataset['fields'] as $num => $field) {
//    if ( $field['field_uuid'] === '3fbd20317257402bdc8fa7df6f2e') {
//        foreach ($field['files'] as $file_num => $file) {
//            if ( $file['_file_metadata']['_public_date'] !== '2200-01-01 00:00:00') {
//                $dataset['fields'][$num]['files'][$file_num] = array(
//                    'file_uuid' => $file['file_uuid'],
//                    'public_date' => '2300-01-01 00:00:00',
//                );
//            }
//        }
//    }
//}
// Set all non-public files to public
//foreach ($dataset['fields'] as $num => $field) {
//    if ( $field['field_uuid'] === '3fbd20317257402bdc8fa7df6f2e') {
//        foreach ($field['files'] as $file_num => $file) {
//            if ( $file['_file_metadata']['_public_date'] === '2200-01-01 00:00:00') {
//                $dataset['fields'][$num]['files'][$file_num] = array(
//                    'file_uuid' => $file['file_uuid'],
//                    'public_date' => '1900-01-01 00:00:00',
//                );
//            }
//        }
//    }
//}
// Set an image to public
//foreach ($dataset['fields'] as $num => $field) {
//    if ( $field['field_uuid'] === 'd33d623628542a991099823b1264') {
//        $dataset['fields'][$num]['files'][0] = array(
//            'file_uuid' => $field['files'][0]['file_uuid'],
//            'public_date' => '1900-01-01 00:00:00',
//        );
//    }
//}
// Set a different image to public (theoretically this one is a thumbnail)
//foreach ($dataset['fields'] as $num => $field) {
//    if ( $field['field_uuid'] === 'd33d623628542a991099823b1264') {
//        $dataset['fields'][$num]['files'][1] = array(
//            'file_uuid' => $field['files'][1]['file_uuid'],
//            'public_date' => '1900-01-01 00:00:00',
//        );
//    }
//}
// Set all images to not-public
//foreach ($dataset['fields'] as $num => $field) {
//    if ( $field['field_uuid'] === 'd33d623628542a991099823b1264') {
//        foreach ($field['files'] as $file_num => $file) {
//            if ( $file['_file_metadata']['_public_date'] !== '2200-01-01 00:00:00') {
//                $dataset['fields'][$num]['files'][$file_num] = array(
//                    'file_uuid' => $file['file_uuid'],
//                    'public_date' => '2300-01-01 00:00:00',
//                );
//                break;
//            }
//        }
//    }
//}

// Select a radio option when it has no currently selected options
//$dataset['fields'][] = array(
//    'field_uuid' => '26e135a2b17ec9c4afdc0c73717e',
//    'value' => array(
//        0 => array(
//            'template_radio_option_uuid' => '9546ac71a4dbae6bf01f36b74f94'
//        )
//    )
//);

// Select an additional radio option
//$dataset['fields'][1]['value'][] = array(
//    0 => array(
//        'template_radio_option_uuid' => '9546ac71a4dbae6bf01f36b74f94'
//    )
//);

// Deselect a radio option
//$dataset['fields'][1]['value'] = array();

// Select a different radio option
//$dataset['fields'][1]['value'] = array(
//    0 => array(
//        'template_radio_option_uuid' => 'asdf'
//    )
//);

// Splice in an existing tag
//$dataset['fields'][17]['value'][] = array('template_tag_uuid' => 'fbf65a111a946f581ee153f0974e');
// Splice in a new tag
//$dataset['fields'][17]['value'][] = array('template_tag_uuid' => 'fbf65a111a946f581ee153f0974f');
// Replace an existing tag
//$dataset['fields'][17]['value'][0] = array('template_tag_uuid' => 'fbf65a111a946f581ee153f0974e');
// Delete a pile of tags
//$dataset['fields'][17]['value'] = array();

            // Load the current version of the requested record in ODR
            $orig_dataset = self::getRecordData(
                $version,
                $record_uuid,
                'json',
                true,    // do want the metadata in the cached version
                $user
            );
            $orig_dataset = json_decode($orig_dataset, true);

// Pretend the original record is blank
//$orig_dataset = array(
//    'database_uuid' => $datarecord->getDataType()->getUniqueId(),
//    'record_uuid' => $datarecord->getUniqueId(),
//    'fields' => array(),
//    'records' => array(),
//);

            // If the user has permissions to make all the changes they're requesting...
            $response = null;
            if ( self::checkUpdatePermissions($em, $pm_service, $user, $datatype, $dataset, $orig_dataset) ) {
//                throw new ODRNotImplementedException('do not continue');

                // The top-level of recursion with datasetDiff() may need to fire off LinkStatusChange
                //  events...
                $link_change_datatypes = array();

                // ...then update the record with the requested changes
                $dataset = self::datasetDiff(
                    $em,
                    $event_dispatcher,
                    $ec_service,
                    $ed_service,
                    $emm_service,
                    $user,
                    $dataset,
                    $orig_dataset,
                    true,                // this is the initial call to datasetDiff()
                    $link_change_datatypes
                );

                // Save any changes made to the record
                $cache_service->set('json_record_'.$record_uuid, json_encode($dataset));

                // Don't need to fire off any events here...datasetDiff() already took care of them
                $response = new Response('Updated', 200);
            }
            else {
                // No changes made
                $response = new Response('OK', 200);
            }


            // ----------------------------------------
            // Redirect to record
            $response->headers->set('Content-Type', 'application/json');
            $response->setContent(json_encode($dataset));
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x388847de;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Gets a single record for a metadata dataset or one or more records for a
     * normal dataset.  If record_uuid is present, will return only that record without
     * the count wrapper.
     *
     * @param string $version
     * @param string $dataset_uuid
     * @param string|null $record_uuid
     * @param Request $request
     *
     * @return Response
     */
    public function getRecordsByDatasetUUIDAction($version, $dataset_uuid, $record_uuid = null, Request $request): Response
    {
        try {
            // TODO - record_uuid is never not null here...

            // ----------------------------------------
            // Default to only showing all info about the datatype/template...
            $display_metadata = true;
            if ($request->query->has('metadata') && $request->query->get('metadata') == 'false')
                // ...but restrict to only the most useful info upon request
                $display_metadata = false;


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Find datatype for Dataset UUID
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dataset_uuid
                )
            );
            if ($datatype == null)
                throw new ODRNotFoundException('DataType');


            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();    // Anon when nobody is logged in

            // $user_manager = $this->container->get('fos_user.user_manager');
            // $user = $user_manager->findUserBy(array('email' => ''));
            // We should allow anonymous access to records they can see...
            if ( is_null($user) || $user === 'anon.' )
                throw new ODRNotFoundException('User');

            $user_permissions = $pm_service->getUserPermissionsArray($user);
            $datatype_permissions = $user_permissions['datatypes'];

            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------

            // TODO - APIControllerTest::testGetDataRecord() seems to assume it gets back a single record without the wrapping
            // TODO - ...but nothing has fundamentally changed from https://github.com/OpenDataRepository/data-publisher/blob/develop/src/ODR/AdminBundle/Controller/APIController.php#L4445
            // TODO - $record_uuid is always null in this function, and whichever test creates the relevant dataset doesn't seem to have magically created a template datatype earlier

            // TODO - ...unfortunately, seems like I need to revert back to develop to see wtf is happening

            // Determine if we're searching a template or a metadata database...both of those
            //  are only supposed to have a single record
            if ( $datatype->getIsMasterType() || !is_null($datatype->getMetadataFor()) ) {
                // This is a metadata datatype, so this controller action is going to return the
                //  contents of the metadata record

                /** @var DataRecord $datarecord */
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array(
                        'dataType' => $datatype->getId()
                    )
                );
                if ($datarecord == null)
                    throw new ODRNotFoundException('Datarecord');

                // If the record exists and the user can view it...
                if ( $pm_service->canViewDatarecord($user, $datarecord) ) {
                    // ...return its JSON version
                    return $this->getDatarecordExportAction(
                        $version,
                        $datarecord->getUniqueId(),
                        $request,
                        $user
                    );
                }
                else {
                    throw new ODRNotFoundException('Datarecord');
                }
            }
            else {
                // ...otherwise, this controller action is going to return json of every record
                //  belonging to this datatype

                // Use a targeted query instead of potentially hydrating thousands of records...
                $query =
                   'SELECT dr.unique_id, drm.publicDate
                    FROM ODRAdminBundle:DataRecord dr
                    LEFT JOIN ODRAdminBundle:DataRecordMeta drm WITH drm.dataRecord = dr
                    WHERE dr.dataType = :datatype_id
                    AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL';
                $params = array('datatype_id' => $datatype->getId());

                // If a record id was specified, only retrieve that single record
                if ( !is_null($record_uuid) ) {
                    $query .= ' AND dr.unique_id = :record_uuid';
                    $params['record_uuid'] = $record_uuid;
                }

                $results = $em->createQuery($query)->setParameters($params)->getArrayResult();

                // Need to filter out any records the user can't view
                $output_records = array();
                foreach ($results as $result) {
                    $dr_uuid = $result['unique_id'];
                    $public_date = $result['publicDate']->format('Y-m-d H:i:s');

                    // Since the records aren't hydrated, need to do the permissions "manually"...
                    $can_view_record = false;
                    if ( $public_date !== '2200-01-01 00:00:00' )
                        $can_view_record = true;

                    if ( isset($datatype_permissions[ $datatype->getId() ])
                        && isset($datatype_permissions[ $datatype->getId() ]['dr_view'])
                    ) {
                        $can_view_record = true;
                    }

                    // If the user can view the record, store it for later
                    if ( $can_view_record )
                        $output_records[] = $dr_uuid;
                }


                // ----------------------------------------
                // Start building the output
                $output = '';

                // If the request was not for a template or a metadata datatype...
                if ( !$datatype->getIsMasterType() && is_null($datatype->getMetadataFor()) ) {
                    // ...then wrap the output with some additional info about how many records this
                    //  datatype has
                    $output .= '{';
                    $output .= '"count": '.count($output_records).',';
                    $output .= '"records": [';
                }

                for ($i = 0; $i < count($output_records); $i++) {
                    $dr_uuid = $output_records[$i];

                    // Get the JSON version of this datarecord
                    $output .= self::getRecordData(
                        $version,
                        $dr_uuid,
                        $request->getRequestFormat(),
                        $display_metadata,
                        $user
                    );

                    // Splice in commas when needed
                    if ( $i < (count($output_records) - 1) )
                        $output .= ',';
                }

                // Close the additional info if required
                if ( !$datatype->getIsMasterType() && is_null($datatype->getMetadataFor()) )
                    $output .= ']}';

                // Return the output back to the user
                $response = new Response();
                $response->setContent($output);
                return $response;
            }
        }
        catch (\Exception $e) {
            $source = 0x722347a6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns how many bytes of files have been uplaoded to the given datatype.
     *
     * @param string $version
     * @param string $dataset_uuid
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function datasetQuotaByUUIDAction($version, $dataset_uuid, Request $request)
    {
        try {
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dataset_uuid
                )
            );
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');

            // If called with a metadata datatype, then silently use the "actual" datatype instead
            if ( !is_null($datatype->getMetadataFor()) )
                $datatype = $datatype->getMetadataFor();

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Only check the files that have been uploaded to this datatype
            // TODO - should it include images too?
            // TODO - this only returns files uploaded to top-level records...shouldn't it do child records as well?
            $query = $em->createQuery(
               'SELECT SUM(f.filesize) FROM ODRAdminBundle:File AS f
                JOIN f.dataRecord AS dr
                JOIN dr.dataType AS dt
                WHERE dt.id = :datatype_id
                AND f.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId()) );
            $total = $query->getScalarResult();

            if ( is_null($total[0][1]) )
                $total[0][1] = 0;

            $result = array('total_bytes' => $total[0][1]);

            $response = new JsonResponse($result);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x19238491;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Deletes the requested Datatype.
     *
     * @param string $version
     * @param string $dataset_uuid
     * @param Request $request
     *
     * @return Response
     */
    public function deleteDatasetByUUIDAction($version, $dataset_uuid, Request $request)
    {
        try {
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityDeletionService $ed_service */
            $ed_service = $this->container->get('odr.entity_deletion_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dataset_uuid
                )
            );
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');

            // If called with a metadata datatype, then silently use the "actual" datatype instead
            if ( !is_null($datatype->getMetadataFor()) )
                $datatype = $datatype->getMetadataFor();

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // deleteDatatype() will throw an exception if the datafield shouldn't be deleted
            $ed_service->deleteDatatype($datatype, $user);

            // Don't need to clear any other cache entries

            // Delete datatype
            $response = new Response('Deleted', 200);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x1923491;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * Parse raw HTTP request data
     *
     * Pass in $a_data as an array. This is done by reference to avoid copying
     * the data around too much.
     *
     * Any files found in the request will be added by their field name to the
     * $data['files'] array.
     *
     * @param   array  Empty array to fill with data
     * @return  array  Associative array of request data
     */
    private function parse_raw_http_request($a_data = [])
    {
        // read incoming data
        $input = file_get_contents('php://input');

        if(strlen($input) < 1) {
            return [];
        }

        // grab multipart boundary from content type header
        preg_match('/boundary=(.*)$/', $_SERVER['CONTENT_TYPE'], $matches);

        // content type is probably regular form-encoded
        if (!count($matches))
        {
            // we expect regular puts to containt a query string containing data
            parse_str(urldecode($input), $a_data);
            return $a_data;
        }

        $boundary = $matches[1];

        // split content by boundary and get rid of last -- element
        $a_blocks = preg_split("/-+$boundary/", $input);
        array_pop($a_blocks);

        $keyValueStr = '';
        // loop data blocks
        foreach ($a_blocks as $id => $block)
        {
            if (empty($block))
                continue;

            // you'll have to var_dump $block to understand this and maybe replace \n or \r with a visibile char

            // parse uploaded files
            if (strpos($block, 'application/octet-stream') !== FALSE)
            {
                // match "name", then everything after "stream" (optional) except for prepending newlines
                preg_match("/name=\"([^\"]*)\".*stream[\n|\r]+([^\n\r].*)?$/s", $block, $matches);
                $a_data['files'][$matches[1]] = $matches[2];
            }
            // parse all other fields
            else
            {
                // match "name" and optional value in between newline sequences
                preg_match('/name=\"([^\"]*)\"[\n|\r]+([^\n\r].*)?\r$/s', $block, $matches);
                $keyValueStr .= $matches[1]."=".$matches[2]."&";
            }
        }
        $keyValueArr = [];
        parse_str($keyValueStr, $keyValueArr);
        return array_merge($a_data, $keyValueArr);
    }


    /**
     * Deletes a File or Image.
     *
     * @param string $version
     * @param string $file_uuid
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function fileDeleteByUUIDAction($version, $file_uuid, Request $request)
    {
        try {
            // TODO - why using this instead of $request->request->all()???
            $_POST = self::parse_raw_http_request();

            // Only used if SuperAdmin & Present
            $user_email = null;
            if ( isset($_POST['user_email']) )
                $user_email = $_POST['user_email'];


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityDeletionService $ed_service */
            $ed_service = $this->container->get('odr.entity_deletion_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // This API action works on both files and images...
            $obj = $em->getRepository('ODRAdminBundle:File')->findOneBy(
                array('unique_id' => $file_uuid)
            );
            if ($obj == null) {
                // ...if there's no file with the given UUID, look for an image instead
                $obj = $em->getRepository('ODRAdminBundle:Image')->findOneBy(
                    array('unique_id' => $file_uuid)
                );
            }
            if ($obj == null)
                throw new ODRNotFoundException('File');
            /** @var File|Image $obj */

            $datafield = $obj->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $typeclass = $datafield->getFieldType()->getTypeClass();

            $datarecord = $obj->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files that aren't done encrypting shouldn't be modified
            if ($obj->getEncryptKey() === '')
                throw new ODRNotFoundException('File');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            /** @var ODRUser $user */
            $user = null;

            if ( is_null($user_email) ) {
                // If a user email wasn't provided, then use the admin user for this action
                $user = $logged_in_user;
            }
            else if ( !is_null($user_email) && $logged_in_user->hasRole('ROLE_SUPER_ADMIN') ) {
                // If a user email was provided, and the user calling this action is a super-admin,
                //  then attempt to locate the user for the given email
                /** @var UserManager $user_manager */
                $user_manager = $this->container->get('fos_user.user_manager');
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if ($user == null)
                    throw new ODRNotFoundException('The User "'.$user_email.'" does not exist', true);
            }

            if ($user == null)
                throw new ODRNotFoundException('User');

            // Ensure this user can modify this datafield
            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // Determine if file or image
            switch ($typeclass) {
                case 'File':
                    /** @var File $file */
                    $file = $obj;

                    // Delete the file
                    $ed_service->deleteFile($file, $user);
                    break;

                case 'Image':
                    /** @var Image $image */
                    $image = $obj;

                    // Delete the image
                    $ed_service->deleteImage($image, $user);
                    break;
            }

            // Don't need to fire off any events


            // ----------------------------------------
            $response = new Response('Created', 201);    // TODO - shouldn't this be 200 OK?
            $url = $this->generateUrl(
                'odr_api_get_dataset_record',
                array(
                    'version' => $version,
                    'record_uuid' => $datarecord->getUniqueId()
                ),
                false
            );
            $response->headers->set('Location', $url);

            return $this->redirect($url);
        }
        catch (\Exception $e) {
            $source = 0x8a83ef89;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Changes the public date of a record
     *
     * @param string $version
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function publishRecordAction($version, Request $request)
    {
//        $content = $request->request->all();
//        if (!empty($content)) {
//            $logger = $this->get('logger');
//            $logger->info('DATA FROM PUBLISH: ' . var_export($content,true));
//        }

        try {
            // Get data from POST/Request
            $data = $request->request->all();
            if ( !isset($data['dataset_uuid']) || !isset($data['record_uuid']) )
                throw new ODRBadRequestException();

            // Public date is optional
            $public_date = new \DateTime();
            if ( isset($data['public_date']) )
                $public_date = new \DateTime($data['public_date']);

            // Only used if SuperAdmin & Present
            $user_email = null;
            if ( isset($data['user_email']) )
                $user_email = $data['user_email'];


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                array('unique_id' => $data['record_uuid'])
            );
            if ($datarecord === null)
                throw new ODRNotFoundException('Datarecord');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array('unique_id' => $data['dataset_uuid'])
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // TODO - getting rid of the requirement to use 'dataset_uuid' would be easier...
            if ( $datarecord->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException();


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            /** @var ODRUser $user */
            $user = null;

            if ( is_null($user_email) ) {
                // If a user email wasn't provided, then use the admin user for this action
                $user = $logged_in_user;
            }
            else if ( !is_null($user_email) && $logged_in_user->hasRole('ROLE_SUPER_ADMIN') ) {
                // If a user email was provided, and the user calling this action is a super-admin,
                //  then attempt to locate the user for the given email
                /** @var UserManager $user_manager */
                $user_manager = $this->container->get('fos_user.user_manager');
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if ($user == null)
                    throw new ODRNotFoundException('The User "'.$user_email.'" does not exist', true);
            }

            if ($user == null)
                throw new ODRNotFoundException('User');

            // Ensure user has permissions to be doing this
            if ( !$pm_service->canChangePublicStatus($user, $datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // Change the public date of the record to the requested value
            $properties = array('publicDate' => $public_date);
            $emm_service->updateDatarecordMeta($user, $datarecord, $properties);


            // ----------------------------------------
            // Fire off a DatarecordPublicStatusChanged event...this will also end up triggering
            //  the database changes and cache clearing that a DatarecordModified event would cause

            // NOTE: do NOT want to also fire off a DatarecordModified event...this would effectively
            //  double the work any event subscribers (such as RSS) would have to do
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordPublicStatusChangedEvent($datarecord, $user);
                $dispatcher->dispatch(DatarecordPublicStatusChangedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            $response = new Response('Created', 201);

            // Switching to get datarecord which uses user's permissions to build array
            // This is required because the user can turn databases non-public.
            $url = $this->generateUrl(
                'odr_api_get_dataset_record',
                array(
                    'version' => $version,
                    'record_uuid' => $datarecord->getUniqueId()
                ),
                false
            );
            $response->headers->set('Location', $url);

            return $this->redirect($url);
        }
        catch (\Exception $e) {
            $source = 0x82831003;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Changes the public date of a datatype, or a datarecord and all its children.
     * TODO
     *
     * @param string $version
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function publishAction($version, Request $request) {
/*
                $response = new Response('Updated', 200);
                $response->headers->set('Content-Type', 'application/json');
                $response->setContent(json_encode(array('true' => 'yes')));
                return $response;
*/
        $content = $request->request->all();
        if (!empty($content)) {
            $logger = $this->get('logger');
            $logger->info('DATA FROM PUBLISH: ' . var_export($content,true));
        }

        try {
            // Get data from POST/Request
            $data = $request->request->all();

            if ( !isset($data['dataset_uuid']) )
                throw new ODRBadRequestException();


            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $logged_in_user */  // Anon when nobody is logged in.
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($logged_in_user == 'anon.')
                throw new ODRForbiddenException();

            // User to act as during update
            $user = null;
            if (isset($data['user_email']) && $data['user_email'] !== null) {
                // Act As user
                $user_email = $data['user_email'];
                if (!$logged_in_user->hasRole('ROLE_SUPER_ADMIN'))
                    throw new ODRForbiddenException();

                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('User');
            } else {
                $user_email = $logged_in_user->getEmail();
                $user = $logged_in_user;
            }

            // Find datatype for Dataset UUID
            /** @var DataType $data_type */
            $data_type = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $data['dataset_uuid']
                )
            );

            if (is_null($data_type))
                throw new ODRNotFoundException('DataType');

            // Calculate the Public Date
            if(isset($data['public_date'])) {
                $public_date = new \DateTime($data['public_date']);
            }
            else {
                $public_date = new \DateTime();
            }

            /** @var DataRecord $datarecord */
            $datarecord = null;
            if(isset($data['record_uuid'])) {
                /** @var DataRecord $datarecord */
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array(
                        'unique_id' => $data['record_uuid']
                    )
                );
            }
            // Only works for Metadata Records
            else if (isset($data['dataset_uuid'])) {
                /** @var DataRecord $datarecord */
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array(
                        'dataType' => $data_type->getId()
                    )
                );
            }

            if ( $datarecord == null )
                throw new ODRNotFoundException('Datarecord');


            // Ensure Datatype is public
            /** @var DataTypeMeta $data_type_meta */
            $data_type_meta = $data_type->getDataTypeMeta();
            $data_type_meta->setPublicDate($public_date);
            $data_type_meta->setUpdatedBy($user);
            $em->persist($data_type_meta);

            // Ensure record is public
            /** @var DataRecordMeta $datarecord_meta */
            $datarecord_meta = $datarecord->getDataRecordMeta();
            $datarecord_meta->setPublicDate($public_date);
            $datarecord_meta->setUpdatedBy($user);

            // Change permissions for all related datarecords
            $json_data = self::getRecordData(
                $version,
                $datarecord->getUniqueId(),
                'json',
                1,
                $user
            );

            $json_record_data = json_decode($json_data, true);
            foreach($json_record_data['records'] as $json_record) {
                // Make record public
                // Check for children
                self::makeDatarecordPublic($json_record, $public_date, $user);
            }

            $em->persist($datarecord_meta);


            $actual_data_record = "";
            $actual_data_type = $data_type->getMetadataFor();
            if($actual_data_type) {
                /** @var DataTypeMeta $data_type_meta */
                $actual_data_type_meta = $actual_data_type->getDataTypeMeta();
                $actual_data_type_meta->setPublicDate($public_date);

                $actual_data_type_meta->setUpdatedBy($user);
                $em->persist($actual_data_type_meta);

                /** @var DataRecord $actual_data_record */
                $actual_data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array(
                        'dataType' => $actual_data_type->getId()
                    )
                );

                // Ensure record is public
                /** @var DataRecordMeta $datarecord_meta */
                $actual_data_record_meta = $actual_data_record->getDataRecordMeta();
                $actual_data_record_meta->setPublicDate($public_date);
                $actual_data_record_meta->setUpdatedBy($user);

                // Change permissions for all related datarecords
                $json_data = self::getRecordData(
                    $version,
                    $actual_data_record->getUniqueId(),
                    'json',
                    1,
                    $user
                );

                $json_record_data = json_decode($json_data, true);
                foreach($json_record_data['records'] as $json_record) {
                    // Make record public
                    // Check for children
                    self::makeDatarecordPublic($json_record, $public_date, $user);
                }

                $em->persist($actual_data_record_meta);
            }

            $em->flush();


            // ----------------------------------------
            // Fire off a DatarecordPublicStatusChanged event...this will also end up triggering
            //  the database changes and cache clearing that a DatarecordModified event would cause

            // NOTE: do NOT want to also fire off a DatarecordModified event...this would effectively
            //  double the work any event subscribers (such as RSS) would have to do
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordPublicStatusChangedEvent($datarecord, $user);
                $dispatcher->dispatch(DatarecordPublicStatusChangedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Also need to fire off a DatatypePublicStatusChangedEvent event...
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatatypePublicStatusChangedEvent($data_type, $user);
                $dispatcher->dispatch(DatatypePublicStatusChangedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            if ($actual_data_record != "") {
                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new DatarecordPublicStatusChangedEvent($actual_data_record, $user);
                    $dispatcher->dispatch(DatarecordPublicStatusChangedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
                }

                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new DatatypePublicStatusChangedEvent($actual_data_type, $user);
                    $dispatcher->dispatch(DatatypePublicStatusChangedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
                }
            }

            $response = new Response('Created', 201);

            // Switching to get datarecord which uses user's permissions to build array
            // This is required because the user can turn databases non-public.
            $url = $this->generateUrl('odr_api_get_dataset_single_no_format', array(
                'version' => $version,
                'dataset_uuid' => $datarecord->getDataType()->getUniqueId()
            ), false);

            $response->headers->set('Location', $url);

            return $this->redirect($url);

        }
        catch (\Exception $e) {
            $source = 0x75e74bd7;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param array $record_data
     * @param \DateTime $public_date
     * @param ODRUser $user
     */
    private function makeDatarecordPublic($record_data, $public_date, $user) {
        /** @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        // Probably should check if user owns record here?

        /** @var DataRecord $datarecord */
        $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
            array(
                'unique_id' => $record_data['record_uuid']
            )
        );

        if($datarecord) {
            // Set public using utility
//            $datarecord->setPublicDate($user, $public_date, $em);    // TODO
        }

        if(isset($record_data['records'])) {
            foreach($record_data['records'] as $record) {
                self::makeDatarecordPublic($record, $public_date, $user);
            }
        }

        // All flushes done at end

    }


    /**
     * Uploads a File or Image.
     *
     * @param string $version
     * @param Request $request
     *
     * @return Response
     */
    public function addfileAction($version, Request $request)
    {
        try {
            // Get data from POST/Request
            $data = $request->request->all();

            // dataset_uuid is not optional
            if ( !isset($data['dataset_uuid']) && $data['dataset_uuid'] !== '' )
                throw new ODRBadRequestException();
            $dataset_uuid = $data['dataset_uuid'];

            // user_email is technically optional...if it isn't provided, then the logged-in user
            //  is used
            $user_email = null;
            if ( isset($data['user_email']) && $data['user_email'] !== '' )
                $user_email = $data['user_email'];

            // record_uuid is also technically optional...if not provided, then it'll default to
            //  selecting the metadata/example record (assuming the provided datatype is a metadata
            //  or a template datatype...)
            $record_uuid = null;
            if ( isset($data['record_uuid']) && $data['record_uuid'] !== '' )
                $record_uuid = $data['record_uuid'];

            // fields can be specified by either field_uuid or template_field_uuid
            $field_uuid = null;
            if ( isset($data['field_uuid']) && $data['field_uuid'] !== '' )
                $field_uuid = $data['field_uuid'];
            $template_field_uuid = null;
            if ( isset($data['template_field_uuid']) && $data['template_field_uuid'] !== '' )
                $template_field_uuid = $data['template_field_uuid'];

            // created/public dates are optional
            $created = null;
            if ( isset($data['created']) && $data['created'] !== '' )
                $created = new \DateTime($data['created']);
            $public_date = null;
            if ( isset($data['public_date']) && $data['public_date'] !== '' )
                $public_date = new \DateTime($data['public_date']);

            // display order for new images is also optional
            $display_order = null;
            if ( isset($data['display_order']) && is_integer($data['display_order']) )
                $display_order = intval($data['display_order']);


            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityDeletionService $ed_service */
            $ed_service = $this->container->get('odr.entity_deletion_service');
            /** @var ODRUploadService $odr_upload_service */
            $odr_upload_service = $this->container->get('odr.upload_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var Logger $logger */
            $logger = $this->container->get('logger');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array('unique_id' => $dataset_uuid)
            );
            if ($datatype == null)
                throw new ODRNotFoundException('DataType');

            /** @var DataRecord $datarecord */
            $datarecord = null;
            if ( !is_null($record_uuid) ) {
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array('unique_id' => $record_uuid)
                );
            }
            else if ( $datatype->getIsMasterType() || !is_null($datatype->getMetadataFor()) ) {
                // The alternate datarecord load is only allowed when it's a master template or
                //  a metadata datatype...those are only supposed to have a single record
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array('dataType' => $datatype->getId())
                );
            }
            if ($datarecord == null)
                throw new ODRNotFoundException('DataRecord');
            if ( $datarecord->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException();

            /** @var DataFields $datafield */
            $datafield = null;
            if ( !is_null($template_field_uuid) ) {
                $datafield = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                    array(
                        'templateFieldUuid' => $template_field_uuid,
                        'dataType' => $datatype->getId()
                    )
                );
            }
            else if ( !is_null($field_uuid) ) {
                $datafield = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                    array(
                        'fieldUuid' => $field_uuid,
                        'dataType' => $datatype->getId()
                    )
                );
            }
            if ($datafield == null)
                throw new ODRNotFoundException('DataField');
            if ( $datafield->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException();

            // Only allow on file/image fields
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ( $typeclass !== 'File' && $typeclass !== 'Image' )
                throw new ODRBadRequestException();


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            /** @var ODRUser $user */
            $user = null;

            if ( is_null($user_email) ) {
                // If a user email wasn't provided, then use the admin user for this action
                $user = $logged_in_user;
            }
            else if ( !is_null($user_email) && $logged_in_user->hasRole('ROLE_SUPER_ADMIN') ) {
                // If a user email was provided, and the user calling this action is a super-admin,
                //  then attempt to locate the user for the given email
                /** @var UserManager $user_manager */
                $user_manager = $this->container->get('fos_user.user_manager');
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if ($user == null)
                    throw new ODRNotFoundException('The User "'.$user_email.'" does not exist', true);
            }

            if ($user == null)
                throw new ODRNotFoundException('User');

            // Ensure this user can modify this datafield
            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Quota Check
            // Only check the files that have been uploaded to this datatype
            // TODO - should it include images too?
            // TODO - this only returns files uploaded to top-level records...shouldn't it do child records as well?
            $query = $em->createQuery(
               'SELECT SUM(f.filesize) FROM ODRAdminBundle:File AS f
                JOIN f.dataRecord AS dr
                JOIN dr.dataType AS dt
                WHERE dt.id = :datatype_id
                AND f.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId()) );
            $total = $query->getScalarResult();

            if ($total[0][1] > 20000000000) {
                // 20 GB temporary limit
                throw new ODRForbiddenException("Quota Exceeded (20GB)");
            }

            // Check for local file on server (with name & path from data
            /*
             * $data['local_files']['0']['local_file_name'] = '92q0fa9klaj340jasfd90j13';
             * $data['local_files']['0']['original_file_name'] = 'some_file.txt';
             */
            $using_local_files = false;
            $file_array = array();
            if ( isset($data['local_files']) && count($data['local_files']) > 0 ) {
                $using_local_files = true;
                $file_array = $data['local_files'];
            }

            if (!$using_local_files) {
                $files_bag = $request->files->all();
                if ( count($files_bag) < 1 )
                    throw new ODRNotFoundException('File to upload');

                foreach ($files_bag as $file)
                    $file_array[] = $file;
            }


            // ----------------------------------------
            // Ensure the relevant drf entry exists
            $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield, $created);

            foreach ($file_array as $file) {
                // Going to need these...
                $local_filename = '';
                $original_filename = '';
                $current_folder = '';

                // Regardless of whether the file is "local" or not, it needs to get moved to this
                //  directory so that ODRUploadService can find it
                $destination_folder = $this->getParameter('odr_tmp_directory').'/user_'.$user->getId().'/chunks/completed';
                if ( !file_exists($destination_folder) )
                    mkdir($destination_folder, 0777, true);
//                $logger->debug('ensured "'.$destination_folder.'" exists', array('APIController::addfileAction()'));

                if ($using_local_files) {
                    // If the file is "local" then the POST request will have both its current name
                    //  on the disk, and its desired name after the upload
                    $local_filename = $file['local_file_name'];
                    $original_filename = $file['original_file_name'];

                    // Additionally, it won't be in the "usual" place
                    $current_folder = $this->getParameter('uploaded_files_path');
//                    $logger->debug('is local file...local_filename: "'.$local_filename.'", original_filename: "'.$original_filename.'", current_folder: "'.$current_folder.'"', array('APIController::addfileAction()'));
                }
                else {
                    // Otherwise, the file will have been "uploaded" as part of the POST request
                    /** @var \Symfony\Component\HttpFoundation\File\File $file */
                    $local_filename = $file->getFileName();
                    $original_filename = $file->getClientOriginalName();

                    // ...the "usual" place is same $destination given to FlowController::saveFile()
                    $current_folder = $this->getParameter('odr_tmp_directory').'/user_'.$user->getId().'/chunks/completed';
//                    $logger->debug('not local file...local_filename: "'.$local_filename.'", original_filename: "'.$original_filename.'"', array('APIController::addfileAction()'));

                    // ...so get Symfony to move the file from the POST request to that location
                    $file->move($current_folder);
//                    $logger->debug('file moved to current_folder: "'.$current_folder.'"', array('APIController::addfileAction()'));
                }

//                if ( file_exists($current_folder.'/'.$local_filename) )
//                    $logger->debug('file at "'.$current_folder.'/'.$local_filename.'" exists', array('APIController::addfileAction()'));
//                else
//                    $logger->debug('file at "'.$current_folder.'/'.$local_filename.'" does not exist', array('APIController::addfileAction()'));

                // Move the file from its current location to its expected location
                rename($current_folder.'/'.$local_filename, $destination_folder.'/'.$original_filename);

//                if ( file_exists($destination_folder.'/'.$original_filename) )
//                    $logger->debug('file successfully moved to "'.$destination_folder.'/'.$original_filename.'"', array('APIController::addfileAction()'));
//                else
//                    $logger->debug('unable to move file to "'.$destination_folder.'/'.$original_filename.'"???', array('APIController::addfileAction()'));

                // TODO - Need to also check file size here?

                // ----------------------------------------
                // Now that the file is in the correct place, get ODR to encrypt it properly
                switch ( $typeclass ) {
                    case 'File':
                        // If the field only allows a single file...
                        if ( !$datafield->getAllowMultipleUploads() ) {
                            // ...then delete the currently uploaded file if one exists
                            /** @var File $current_file */
                            $current_file = $em->getRepository('ODRAdminBundle:File')->findOneBy(
                                array('dataRecordFields' => $drf->getId())
                            );
                            if ($current_file != null)
                                $ed_service->deleteFile($current_file, $user);
                        }

                        // Upload the new file
                        $odr_upload_service->uploadNewFile(
                            $destination_folder.'/'.$original_filename,
                            $user,
                            $drf,
                            $created,
                            $public_date
                        );

                        break;

                    case 'Image':
                        // If the field only allows a single image...
                        if ( !$datafield->getAllowMultipleUploads() ) {
                            // ...then delete the currently uploaded image if one exists
                            /** @var Image $current_image */
                            $current_image = $em->getRepository('ODRAdminBundle:Image')->findOneBy(
                                array(
                                    'dataRecordFields' => $drf->getId(),
                                    'parent' => null
                                )
                            );
                            if ($current_image != null)
                                $ed_service->deleteImage($current_image, $user);
                        }

                        // Upload the new image
                        $odr_upload_service->uploadNewImage(
                            $destination_folder.'/'.$original_filename,
                            $user,
                            $drf,
                            $created,
                            $public_date,
                            $display_order
                        );
                        break;
                }
            }

            // TODO - need to build image and file arrays here and fix into JSON....

            // Don't need to fire off any more events...the services have already done so


            // ----------------------------------------
            $response = new Response('Created', 201);
            $url = $this->generateUrl(
                'odr_api_get_dataset_record',
                array(
                    'version' => $version,
                    'record_uuid' => $datarecord->getGrandparent()->getUniqueId()
                ),
                false
            );
            $response->headers->set('Location', $url);

            return $this->redirect($url);
        }
        catch (\Exception $e) {
            $source = 0x8a83ef88;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * @param string $version
     * @param string $record_uuid
     * @param Request $request
     *
     * @return Response
     */
    public function getRecordAction($version, $record_uuid, Request $request): Response
    {
        try {
            // Apparently this controller action demands a user...
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( is_null($user) )
                throw new ODRNotFoundException('User');

            // ...but is otherwise handled by this other controller action
            return $this->getDatarecordExportAction(
                $version,
                $record_uuid,
                $request,
                $user
            );
        }
        catch (\Exception $e) {
            $source = 0x9ea474c1;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Loads the requested datarecord from the cache, or rebuilds it if it doesn't exist.
     *
     * @param string $version
     * @param string $datarecord_uuid
     * @param string $format
     * @param bool $display_metadata
     * @param null $user
     * @param bool $bypass_cache
     *
     * @return array|bool|string
     */
    private function getRecordData(
        $version,
        $datarecord_uuid,
        $format,
        $display_metadata = false,
        $user = null,
        $bypass_cache = false
    ) {
        // ----------------------------------------
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var CacheService $cache_service */
        $cache_service = $this->container->get('odr.cache_service');
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
        if ( is_null($user) )
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

        if (!$pm_service->canViewDatarecord($user, $datarecord))
            throw new ODRForbiddenException();
        // ----------------------------------------


        // Attempt to get the record from its cache entry...
        $data = $cache_service->get('json_record_'.$datarecord_uuid);

        // If the record isn't cached, or the cache should be bypassed...
        if ( !$data || $bypass_cache ) {
            // ...then re-render the requested record
            $data = $dre_service->getData(
                $version,
                array($datarecord_id),
                $format,
                true,    // TODO - the rest of the API stuff demands that metadata exist...
                $user,
                $this->container->getParameter('site_baseurl'),
                0
            );

            // Store the record back in the cache
            // TODO work out how to expire this data...
            $cache_service->set('json_record_'.$datarecord_uuid, $data);
        }

        return $data;
    }


    /**
     * This controller action is forwarded to by APIController::getRecordAction(), as well as
     * FacadeController::getDatarecordExportAction()
     *
     * @param $version
     * @param $record_uuid
     * @param Request $request
     * @param null $user
     *
     * @return Response
     */
    public function getDatarecordExportAction($version, $record_uuid, Request $request, $user = null)
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


            // getRecordData() will deal with any permissions/verification checks
            $data = self::getRecordData(
                $version,
                $record_uuid,
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
                    'attachment; filename="Datarecord_' . $data['internal_id'] . '.' . $request->getRequestFormat() . '";'
                );
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x80e2674a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * TODO
     * @param $version
     * @param $template_uuid
     * @param $template_field_uuid
     * @param Request $request
     *
     * @return Response
     */
    public function getfieldstatsbydatasetAction(
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
            $array_label = 'radio_options';
            if ($typeclass === 'Tag') {
                $item_label = 'template_tag_uuid';
                $array_label = 'value';
            }

            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            // $token = $this->container->get('security.token_storage')->getToken();   // <-- will return 'anon.' when nobody is logged in
            // $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in


            // TODO this is currently used by public searches only.  Need to improve call to allow private.
            $user = 'anon.';
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


            /** @var DatatypeExportService $dte_service */
            $dte_service = $this->container->get('odr.datatype_export_service');

            // Render the requested datatype
            $template_data = $dte_service->getData(
                $version,
                $template_datatype->getId(),
                $request->getRequestFormat(),
                false,
                $user,
                $this->container->getParameter('site_baseurl')
            );

            $template = json_decode($template_data, true);

            // get the field in question
            $field = array();
            for($i=0;$i<count($template['fields']);$i++) {
                if($template['fields'][$i]['template_field_uuid'] == $template_field_uuid) {
                    $field = $template['fields'][$i];
                    break;
                }
            }

            // print var_export($records, true);exit();
            // Translate the two provided arrays into a a slightly different format
            $data = array();
            foreach ($records as $dt_id => $df_list) {
                foreach ($df_list as $df_id => $dr_list) {
                    foreach ($dr_list as $dr_id => $item_list) {
                        foreach ($item_list as $num => $item_uuid) {
                            $item_name = $labels[$item_uuid];
                            if (!isset($data[$item_name])) {
                                $data[$item_name] = array(
                                    $item_label => $item_uuid
                                );
                            }
                            $data[$item_name]['records'][] = $dr_id;
                        }
                    }
                }
            }

            for($i=0;$i<count($field[$array_label]);$i++) {
                // First level
                $level = $field[$array_label][$i];
                $level_array = [];
                foreach ($data as $name => $item_record) {
                    if ($level[$item_label] == $item_record[$item_label]) {
                        // add records to parent array
                        $level_array = array_merge($level_array, $item_record['records']);
                    }
                }

                // Get array of matching records
                // Merge with array of records matching child terms
                $sub_level_array = [];
                if(isset($level['children'])) {
                    $sub_level_array = self::check_children($level['children'], $data, $item_label);
                }
                $level_array = array_merge($level_array, $sub_level_array);
                $level['count'] = count(array_unique($level_array));
                $field[$array_label][$i] = $level;
            }

            // print(json_encode($field));exit();

            // Set up a response to send the datatype back
            $response = new Response();
            $response->setContent(json_encode($field));
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x883def33;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * @param $selection_array
     * @param $data
     * @param $item_label
     * @return array
     */
    function check_children(&$selection_array, $data, $item_label) {

        $my_level_array = [];
        for($i=0;$i<count($selection_array);$i++) {
            $level = $selection_array[$i];

            $sub_level_array = [];
            foreach ($data as $name => $item_record) {
                if ($level[$item_label] == $item_record[$item_label]) {
                    // add records to parent array
                    $sub_level_array = array_merge($sub_level_array, $item_record['records']);
                }
            }

            $children_array = [];
            if(isset($level['children'])) {
                $children_array = self::check_children($level['children'], $data, $item_label);
            }
            $sub_level_array = array_merge($sub_level_array, $children_array);
            $level['count'] = count(array_unique($sub_level_array));
            $selection_array[$i] = $level;

            $my_level_array = array_merge($my_level_array, $sub_level_array);
        }
        return $my_level_array;
    }

    /**
     * Returns a list of radio options in the given template field, and a count of how many
     * datarecords from datatypes derived from the given template have those options selected.
     * TODO
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
            // $token = $this->container->get('security.token_storage')->getToken();   // <-- will return 'anon.' when nobody is logged in
            // $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in


            // TODO this is currently used by public searches only.  Need to improve call to allow private.
            $user = 'anon.';
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
                            if (!isset($data[$item_name])) {
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
            uasort($data, function ($a, $b) {
                if ($a['count'] < $b['count'])
                    return 1;
                else if ($a['count'] == $b['count'])
                    return 0;
                else
                    return -1;
            });


            // ----------------------------------------
            // Render the data in the requested format
            $format = $request->getRequestFormat();
            $templating = $this->get('templating');
            $data = $templating->render(
                'ODRAdminBundle:API:field_stats.' . $format . '.twig',
                array(
                    'field_stats' => $data
                )
            );

            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="Datafield_' . $template_field_uuid . '.' . $request->getRequestFormat() . '";');
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x66869767;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * Creates a Symfony Response so API users can download a file or an image.
     *
     * @param string $version
     * @param string $file_uuid
     * @param Request $request
     *
     * @return Response|StreamedResponse
     */
    public function fileDownloadByUUIDAction($version, $file_uuid, Request $request)
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


            // This API action works on both files and images...
            $obj = $em->getRepository('ODRAdminBundle:File')->findOneBy(
                array('unique_id' => $file_uuid)
            );
            if ($obj == null) {
                // ...if there's no file with the given UUID, look for an image instead
                $obj = $em->getRepository('ODRAdminBundle:Image')->findOneBy(
                    array('unique_id' => $file_uuid)
                );
            }
            if ($obj == null)
                throw new ODRNotFoundException('File');
            /** @var File|Image $obj */

            $datafield = $obj->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $typeclass = $datafield->getFieldType()->getTypeClass();

            $datarecord = $obj->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files/Images that aren't done encrypting shouldn't be downloaded
            if ($obj->getEncryptKey() === '')
                throw new ODRNotFoundException('File');


            // ----------------------------------------
            // Determine user privileges
            // TODO - Determine how to make this work for "act-as" users
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if ($typeclass === 'File') {
                if ( !$pm_service->canViewFile($user, $obj) )
                    throw new ODRForbiddenException();
            }
            else if ($typeclass === 'Image') {
                if ( !$pm_service->canViewImage($user, $obj) )
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------

            if ($typeclass === 'File') {
                /** @var File $file */
                $file = $obj;

                // Only allow this action for files smaller than 5Mb?
                $filesize = $file->getFilesize() / 1024 / 1024;
                if ($filesize > 50)
                    throw new ODRNotImplementedException('Currently not allowed to download files larger than 5Mb');

                // Ensure file exists on the server before attempting to serve it...
                $filename = 'File_'.$file->getId().'.'.$file->getExt();
                if ( !$file->isPublic() )
                    $filename = md5($file->getOriginalChecksum().'_'.$file->getId().'_'.$user->getId()).'.'.$file->getExt();

                $local_filepath = realpath($this->getParameter('odr_web_directory').'/'.$file->getUploadDir().'/'.$filename);
                if ( !$local_filepath )
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
                $response->headers->set('Content-Length', filesize($local_filepath));        // TODO - apparently this isn't sent?
                $response->headers->set('Content-Disposition', 'attachment; filename="' . $display_filename . '";');
                $response->headers->set('Content-Type', mime_content_type($local_filepath));

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
                    unlink($local_filepath);

                return $response;
            }
            else if ($typeclass === 'Image') {
                /** @var Image $image */
                $image = $obj;

                // Ensure file exists before attempting to download it
                $filename = 'Image_'.$image->getId().'.'.$image->getExt();
                if ( !$image->isPublic() )
                    $filename = md5($image->getOriginalChecksum().'_'.$image->getId().'_'.$user->getId()).'.'.$image->getExt();

                // Ensure the image exists in decrypted format
                $image_path = realpath( $this->getParameter('odr_web_directory').'/'.$filename );     // realpath() returns false if file does not exist
                if ( !$image->isPublic() || !$image_path )
                    $image_path = $crypto_service->decryptImage($image->getId(), $filename);

                $handle = fopen($image_path, 'r');
                if ($handle === false)
                    throw new FileNotFoundException($image_path);

                // Have to send image headers first...
                $response = new Response();
                $response->setPrivate();

                switch ( strtolower($image->getExt()) ) {
                    case 'gif':
                        $response->headers->set('Content-Type', 'image/gif');
                        break;
                    case 'png':
                        $response->headers->set('Content-Type', 'image/png');
                        break;
                    case 'jpg':
                    case 'jpeg':
                        $response->headers->set('Content-Type', 'image/jpeg');
                        break;
                }

                // Attach the image's original name to the headers...
                $display_filename = $image->getOriginalFileName();
                if ($display_filename == null)
                    $display_filename = 'Image_'.$image->getId().'.'.$image->getExt();

                $response->headers->set('Content-Disposition', 'inline; filename="'.$display_filename.'";');
                $response->sendHeaders();

                // After headers are sent, send the image itself
                $im = null;
                switch ( strtolower($image->getExt()) ) {
                    case 'gif':
                        $im = imagecreatefromgif($image_path);
                        imagegif($im);
                        break;
                    case 'png':
                        $im = imagecreatefrompng($image_path);
                        imagepng($im);
                        break;
                    case 'jpg':
                    case 'jpeg':
                        $im = imagecreatefromjpeg($image_path);
                        imagejpeg($im);
                        break;
                }
                imagedestroy($im);

                fclose($handle);

                // If the image isn't public, delete the decrypted version so it can't be accessed without going through symfony
                if ( !$image->isPublic() )
                    unlink($image_path);

                return $response;
            }
        }
        catch (\Exception $e) {
            // Returning an error...do it in json
            $request->setRequestFormat('json');

            $source = 0xbbaafae5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Begins the download of a file by its id.
     *
     * @param string $version
     * @param integer $file_id
     * @param Request $request
     */
    public function filedownloadAction($version, $file_id, Request $request)
    {
        try {
            // Need to load the file to convert the id into a uuid...
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');
            $file_uuid = $file->getUniqueId();

            // ...but the download is otherwise handled by this other controller action
            return $this->fileDownloadByUUIDAction(
                $version,
                $file_uuid,
                $request
            );
        }
        catch (\Exception $e) {
            // Any errors should be returned in json format
            $request->setRequestFormat('json');

            $source = 0x91c5c5d9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Begins the download of an image by its id.
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
            // Need to load the file to convert the id into a uuid...
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var Image $image */
            $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');
            $image_uuid = $image->getUniqueId();

            // ...but the download is otherwise handled by this other controller action
            return $this->fileDownloadByUUIDAction(
                $version,
                $image_uuid,
                $request
            );
        }
        catch (\Exception $e) {
            // Any errors should be returned in json format
            $request->setRequestFormat('json');

            $source = 0x3c4842c5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * TODO
     * @param string $version
     * @param Request $request
     * @return Response
     */
    public function userPermissionsAction($version, Request $request) {

        try {

            $user_email = null;
            if(isset($_POST['user_email']))
                $user_email = $_POST['user_email'];

            if(!isset($_POST['dataset_uuid']))
                throw new ODRBadRequestException('Dataset UUID is required.');

            $dataset_uuid = $_POST['dataset_uuid'];

            if(!isset($_POST['permission']))
                throw new ODRBadRequestException('Permission type is required.');

            // one of "admin", "edit_all", "view_all", "view_only"
            $permission = $_POST['permission'];

            // We must check if the logged in user is acting as a user
            // When acting as a user, the logged in user must be a SuperAdmin
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if($user_email === '') {
                // User is setting up dataset for themselves - always allowed
                $user_email = $logged_in_user->getEmail();
            }
            else if(!$logged_in_user->hasRole('ROLE_SUPER_ADMIN')) {
                // We are acting as a user and do not have Super Permissions - Forbidden
                throw new ODRForbiddenException();
            }

            // Check if user exists & throw user not found error
            // Save which user started this creation process
            // Any user can create a dataset as long as they exist
            // No need to do further permissions checks.
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser $user */
            $user = $user_manager->findUserBy(array('email' => $user_email));
            if (is_null($user))
                throw new ODRNotFoundException('User');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dataset_uuid
                )
            );

            if(!$datatype)
                throw new ODRNotFoundException('Datatype');

            // Grant
            /** @var ODRUserGroupMangementService $user_group_service */
            $user_group_service = $this->container->get('odr.user_group_management_service');
            $user_group_service->addUserToDefaultGroup(
                $logged_in_user,
                $user,
                $datatype,
                $permission
            );

            /** @var Response $response */
            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');
            $output_array = [];
            $output_array['success'] = "true";
            $response->setContent(json_encode($output_array));

            return $response;
        }
        catch (\Exception $e) {
            // Any errors should be returned in json format
            $request->setRequestFormat('json');

            $source = 0xafaf3835;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * Retrieve user info and list of created databases (that have metadata).
     * Creates user if not exists.
     * TODO
     *
     * @param $version
     * @param Request $request
     */
    public function userAction($version, Request $request)
    {
        try {
            $user_email = null;
            if(isset($_POST['user_email']))
                $user_email = $_POST['user_email'];

            if(!isset($_POST['first_name']) || !isset($_POST['last_name']))
                throw new ODRBadRequestException("First and last name paramaters are required.");

            $first_name = $_POST['first_name'];
            $last_name = $_POST['last_name'];

            // We must check if the logged in user is acting as a user
            // When acting as a user, the logged in user must be a SuperAdmin
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if(strlen($user_email) < 5)
                throw new ODRNotFoundException('User Email Parameter');

            // The logged in user must be a SuperAdmin to create users
            if(!$logged_in_user->hasRole('ROLE_SUPER_ADMIN')) {
                // We are acting as a user and do not have Super Permissions - Forbidden
                throw new ODRForbiddenException();
            }

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Check if user exists & throw user not found error
            // Save which user started this creation process
            // Any user can create a dataset as long as they exist
            // No need to do further permissions checks.
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var FOSUser $user */
            $user = $user_manager->findUserBy(array('email' => $user_email));
            if (is_null($user)) {
                // Create a new user with this email & set a random password

                $user = $user_manager->createUser();
                $user->setUsername($user_email);
                $user->setEmail($user_email);
                $user->setPlainPassword(random_bytes(8));
                $user->setRoles(array('ROLE_USER'));
                $user->setEnabled(1);
                $user_manager->updateUser($user);

            }
            else {
                // Undelete User if needed
                $user->setEnabled(1);
                $user_manager->updateUser($user);


                $filter = $em->getFilters()->enable('softdeleteable');
                $filter->disableForEntity(UserGroup::class);

                $user_groups = $em->getRepository('ODRAdminBundle:UserGroup')
                    ->findBy(array('user' => $user->getId()));

                foreach($user_groups as $group) {
                    $group->setDeletedAt(null);
                    $group->setDeletedBy(null);
                    $em->persist($group);
                }

                $em->flush();
            }


            /** @var DataType $datatype */
            /*
            $datatypes = $em->getRepository('ODRAdminBundle:DataType')->findAll(
                array(
                    'createdBy' => $user,
                    'is_master_type' => 0,
                    'metadata_for_id'
                )
            );
            */

            $query = $em->createQuery(
                'SELECT
                       dt.id AS database_id,
                       dt.unique_id AS database_uuid,
                       dr.id AS record_id,
                       dr.unique_id AS record_uuid
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                    WHERE dt.setup_step IN (:setup_steps)
                    AND dt.createdBy = :user
                    AND dt.is_master_type = :is_master_type 
                    AND dt.metadata_for IS NOT NULL
                    AND dt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'user' => $user,
                    'setup_steps' => DataType::STATE_VIEWABLE,
                    'is_master_type' => 0
                )
            );
            $results = $query->getArrayResult();

            $metadata_records = array();
            foreach ($results as $record) {
                try {
                    $data = self::getRecordData(
                        $version,
                        $record['record_uuid'],
                        $request->getRequestFormat(),
                        1, // Need to figure out how this is set
                        $user
                    );

                    if ($data) {
                        array_push($metadata_records, json_decode($data));
                    }
                } catch (\Exception $e) {
                    // Ignoring errors building data
                    // TODO need to determine cause of data errors
                }

            }

            $output_array = array();
            $output_array['user_email'] = $user->getEmail();
            $output_array['datasets'] = $metadata_records;


            // Set up a response to send the datatype back
            /** @var Response $response */
            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');
            $response->setContent(json_encode($output_array));

            return $response;

        }
        catch (\Exception $e) {
            // Any errors should be returned in json format
            $request->setRequestFormat('json');

            $source = 0x8a8b2309;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * TODO
     * @param $template_uuid
     * @param $template_field_uuid
     * @param Request $request
     * @return Response
     */
    public function search_field_statsAction($template_uuid, $template_field_uuid, Request $request)
    {
        try {
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
            foreach ($datatype_array as $dt) {
                // Find record
                $results = $em->createQuery(
                    'SELECT distinct dr FROM ODRAdminBundle:DataRecord dr
                            JOIN ODRAdminBundle:DataRecordMeta drm 
                            WHERE drm.publicDate <= CURRENT_DATE()
                            AND dr.dataType = :data_type_id
                            AND drm.deletedAt IS NULL
                            AND dr.deletedAt IS NULL
                ')
                    ->setParameters(
                        array(
                            'data_type_id' => $dt->getId()
                        )
                    )
                    ->getArrayResult();
                // Add record object to array
                if (count($results) > 0) {
                    $records[] = $results[0];
                }
            }

            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Use get record to build array
            $output_records = array();
            /** @var DataRecord $record */
            foreach ($records as $record) {
                // Let the APIController do the rest of the error-checking
                $all = $request->query->all();
                $all['download'] = 'raw';
                $all['metadata'] = 'true';
                $result = self::getRecordData(
                    'v1',
                    $record['unique_id'],
                    'json',
                    true,
                    $user
                );
                $parsed_result = json_decode($result);
                if (
                    $parsed_result !== null
                    && !property_exists($parsed_result, 'error')
                    && property_exists($parsed_result, 'records')
                    && is_array($parsed_result->records)
                ) {
                    array_push($output_records, $parsed_result->records['0']);
                }
            }
            // Process to build options array matching field id
            $options_data = array();
            foreach ($output_records as $record) {
                self::optionStats($record, $template_field_uuid, $options_data);
            }
            // Return array of records
            $response = new Response(json_encode($options_data));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x54b42212;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param $record
     * @param string $field_uuid
     * @param array $options_data
     */
    function optionStats($record, $field_uuid, &$options_data)
    {
        self::checkOptions($record->fields, $field_uuid, $options_data);
        // Check child records (calls check record)
        if (property_exists($record, 'child_records')) {
            foreach ($record->child_records as $child_record) {
                foreach ($child_record->records as $child_data_record) {
                    self::checkOptions($child_data_record->fields, $field_uuid, $options_data);
                }
            }
        }
        // Check linked records (calls check record)
        if (property_exists($record, 'linked_records')) {
            foreach ($record->linked_records as $child_record) {
                foreach ($child_record->records as $child_data_record) {
                    self::checkOptions($child_data_record->fields, $field_uuid, $options_data);
                }
            }
        }
    }

    /**
     * @param array $record_fields
     * @param string $field_uuid
     * @param array $options_data
     */
    function checkOptions($record_fields, $field_uuid, &$options_data)
    {
        foreach ($record_fields as $field) {
            // We are only checking option fields
            if (
                $field->template_field_uuid == $field_uuid
                && property_exists($field, 'value')
                && is_array($field->value)
            ) {
                foreach ($field->value as $option_id => $option) {
                    foreach ($option as $key => $selected_option) {
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
                                    if (count($option_data) == 1) {
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
                                    if (count($option_data) == 2) {
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
                                    if (count($option_data) == 3) {
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
                                    if (count($option_data) == 4) {
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
                                    if (count($option_data) == 5) {
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
                                    if (count($option_data) == 6) {
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
                                    if (count($option_data) == 7) {
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
}
