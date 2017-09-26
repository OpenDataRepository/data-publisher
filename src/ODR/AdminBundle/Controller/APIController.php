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

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\DatarecordExportService;
use ODR\AdminBundle\Component\Service\DatatypeExportService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns an array of all top-level datatypes the user can view.
     *
     * @param string $version
     * @param Request $request
     *
     * @return Response
     */
    public function getDatatypeListAction($version, Request $request)
    {
        try {
            // Default to only showing top-level datatypes...
            $show_child_datatypes = false;
            if ($request->query->has('display') && $request->query->get('display') == 'all')
                // ...but show child datatypes upon request
                $show_child_datatypes = true;


            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');

            $top_level_datatype_ids = $dti_service->getTopLevelDatatypes();
            $datatree_array = $dti_service->getDatatreeArray();

            // Get the user's permissions if applicable
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = array();
            if ($user !== 'anon.') {
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];
            }


            // ----------------------------------------
            $results = array();
            if ($show_child_datatypes) {
                // Build/execute a query to get basic info on all datatypes
                $query = $em->createQuery(
                   'SELECT dt.id AS database_id, dtm.shortName AS database_name, dtm.description AS database_description, dtm.publicDate AS public_date
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                    WHERE dt.setup_step IN (:setup_steps)
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters( array('setup_steps' => DataType::STATE_VIEWABLE) );
                $results = $query->getArrayResult();
            }
            else {
                // Build/execute a query to get basic info on all top-level datatypes
                $query = $em->createQuery(
                   'SELECT dt.id AS database_id, dtm.shortName AS database_name, dtm.description AS database_description, dtm.publicDate AS public_date
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                    WHERE dt.id IN (:datatype_ids) AND dt.setup_step IN (:setup_steps)
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters( array('datatype_ids' => $top_level_datatype_ids, 'setup_steps' => DataType::STATE_VIEWABLE) );
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

            // Symfony should automatically set the response format based on the request format
            return new Response($data);
        }
        catch (\Exception $e) {
            $source = 0x5dc89429;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
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
     * Renders and returns the json/XML version of the given Datatype when accessed via the OAuth firewall
     *
     * @param string $version
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function getDatatypeExportAction($version, $datatype_id, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to only showing the most useful identification info...
            $display_metadata = false;
            if ($request->query->has('metadata') && $request->query->get('metadata') == 'true')
                // ...but show even more data upon request
                $display_metadata = true;

            // Default to returning the data as a file download...
            $download_response = true;
            if ($request->query->has('download') && $request->query->get('download') == 'stream')
                // ...but return the data as a simple response on request
                $download_response = false;


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeExportService $dte_service */
            $dte_service = $this->container->get('odr.datatype_export_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ( !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('Only permitted on top-level datatypes');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = array();
            if ( $user === 'anon.' ) {
                if ( !$datatype->isPublic() ) {
                    // non-public datatype and anonymous user, can't view
                    throw new ODRForbiddenException();
                }
                else {
                    // public datatype, anybody can view
                }
            }
            else {
                // Grab user's permissions
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];

                $can_view_datatype = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_view' ]) )
                    $can_view_datatype = true;

                if (!$datatype->isPublic() && !$can_view_datatype)
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------


            // ----------------------------------------
            // Render the requested datatype
            $baseurl = $this->container->getParameter('site_baseurl');
            $data = $dte_service->getData($version, $datatype_id, $request->getRequestFormat(), $display_metadata, $user_permissions, $baseurl);

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns a list of top-level datarecords the user is allowed to see by datatype...
     * TODO - allow user to specify search key...but probably fix immediate issues with search key first...
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function getDatarecordListAction($datatype_id, Request $request)
    {
        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            $top_level_datatype_ids = $dti_service->getTopLevelDatatypes();
            if ( !in_array($datatype_id, $top_level_datatype_ids) )
                throw new ODRBadRequestException('Datatype must be top-level');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = array();
            if ($user !== 'anon.') {
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];
            }

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            if (!$datatype->isPublic() && !$can_view_datatype)
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Allow users to specify positive integer values less than a billion for these variables
            $offset = 0;
            if ($request->query->has('offset') && strlen($request->query->get('offset')) < 10 && preg_match('/[^0-9]+/', $request->query->get('offset')) === 0)
                $offset = intval( $request->query->get('offset') );

            $limit = 999999999;
            if ($request->query->has('limit') && strlen($request->query->get('limit')) < 10 && preg_match('/[^0-9]+/', $request->query->get('limit')) === 0) {
                $limit = intval( $request->query->get('limit') );

                if ($limit == 0)
                    $limit = 999999999;
            }

            // $offset is the index of the "first" datarecord the user wants...turn $limit into the index of the "last" datarecord the user wants
            $limit = $offset + $limit;


            // ----------------------------------------
            // Load all top-level datarecords of this datatype that the user can see

            // The contents of this database query depend greatly on whether the datatype has external_id or name datafields set...
            // ...therefore, building the query is considerably quicker/easier when using the Doctrine querybuilder
            $qb = $em->createQueryBuilder();
            $qb->select('dr.id AS internal_id')
                ->from('ODRAdminBundle:DataRecord', 'dr')
                ->join('ODRAdminBundle:DataRecordMeta', 'drm', 'WITH', 'drm.dataRecord = dr')
                ->where('dr.dataType = :datatype_id')->andWhere('dr.deletedAt IS NULL')->andWhere('drm.deletedAt IS NULL')
                ->setParameter('datatype_id', $datatype_id);

            // TODO - add sql limit?

            // If the user isn't allowed to view non-public datarecords, add that requirement in
            if (!$can_view_datarecord)
                $qb->andWhere('drm.publicDate != :public_date')->setParameter('public_date', '2200-01-01 00:00:00');

            // If this datatype has an external_id field, make sure the query selects it for the JSON response
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

            // If this datatype has an name field, make sure the query selects it for the JSON response
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
            $sorted_datarecord_list = $dti_service->getSortedDatarecordList($datatype_id);


            // $sorted_datarecord_list and $dr_list both contain all datarecords of this datatype
            $count = 0;
            $final_datarecord_list = array();
            foreach ($sorted_datarecord_list as $dr_id => $sort_value) {
                // Only save ones that the user specified
                if ( isset($dr_list[$dr_id]) && $count >= $offset && $count < $limit ) {
                    $final_datarecord_list[] = $dr_list[$dr_id];
                    $count++;
                }
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

            // Symfony should automatically set the response format based on the request format
            return new Response($data);
        }
        catch (\Exception $e) {
            $source = 0xd12ec6ee;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Renders and returns the json/XML version of the given DataRecord when accessed via the OAuth firewall
     *
     * @param string $version
     * @param integer $datarecord_id
     * @param Request $request
     *
     * @return Response
     */
    public function getDatarecordExportAction($version, $datarecord_id, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to only showing the most useful identification info...
            $display_metadata = false;
            if ($request->query->has('metadata') && $request->query->get('metadata') == 'true')
                // ...but show even more data upon request
                $display_metadata = true;

            // Default to returning the data as a file download...
            $download_response = true;
            if ($request->query->has('download') && $request->query->get('download') == 'stream')
                // ...but return the data as a simple response on request
                $download_response = false;


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordExportService $dre_service */
            $dre_service = $this->container->get('odr.datarecord_export_service');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            if ($datarecord->getId() != $datarecord->getGrandparent()->getId())
                throw new ODRBadRequestException('Only permitted on top-level datarecords');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = array();
            if ( $user === 'anon.' ) {
                if ( !$datatype->isPublic() || !$datarecord->isPublic() ) {
                    // non-public datatype and anonymous user, can't view
                    throw new ODRForbiddenException();
                }
                else {
                    // public datatype, anybody can view
                }
            }
            else {
                // Grab user's permissions
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];

                $can_view_datatype = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_view' ]) )
                    $can_view_datatype = true;

                $can_view_datarecord = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dr_view' ]) )
                    $can_view_datarecord = true;

                // If either the datatype or the datarecord is not public, and the user doesn't have the correct permissions...then don't allow them to view the datarecord
                if (!$datatype->isPublic() && !$can_view_datatype)
                    throw new ODRForbiddenException();
                if (!$datarecord->isPublic() && !$can_view_datarecord)
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------


            // ----------------------------------------
            // Render the requested datarecord
            $baseurl = $this->container->getParameter('site_baseurl');
            $data = $dre_service->getData($version, $datarecord_id, $request->getRequestFormat(), $display_metadata, $user_permissions, $baseurl);

            // Set up a response to send the datarecord back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="Datarecord_'.$datarecord_id.'.'.$request->getRequestFormat().'";');
            }

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x722347a6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Assuming the user has permissions to do so, creates a Symfony StreamedResponse for a file download
     *
     * @param integer $file_id
     * @param Request $request
     *
     * @return JsonResponse|StreamedResponse
     */
    public function filedownloadAction($file_id, Request $request)
    {
        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

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
            if ($user === 'anon.') {
                if ( $datatype->isPublic() && $datarecord->isPublic() && $datafield->isPublic() && $file->isPublic() ) {
                    // user is allowed to download this file
                }
                else {
                    // something is non-public, therefore an anonymous user isn't allowed to download this file
                    throw new ODRForbiddenException();
                }
            }
            else {
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];
                $datafield_permissions = $user_permissions['datafields'];

                $can_view_datatype = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ]['dt_view']) )
                    $can_view_datatype = true;

                $can_view_datarecord = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ]['dr_view']) )
                    $can_view_datarecord = true;

                $can_view_datafield = false;
                if ( isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ]['view']) )
                    $can_view_datafield = true;

                if (!$datatype->isPublic() && !$can_view_datatype)
                    throw new ODRForbiddenException();
                if (!$datarecord->isPublic() && !$can_view_datarecord)
                    throw new ODRForbiddenException();
                if (!$datafield->isPublic() && !$can_view_datafield)
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------

            // Only allow this action for files smaller than 5Mb?
            $filesize = $file->getFilesize() / 1024 / 1024;
            if ($filesize > 5)
                throw new ODRNotImplementedException('Currently not allowed to download files larger than 5Mb');


            // Ensure the file exists in decrypted format
            $file_path = realpath( dirname(__FILE__).'/../../../../web/'.$file->getLocalFileName() );     // realpath() returns false if file does not exist
            if ( !$file->isPublic() || !$file_path )
                $file_path = parent::decryptObject($file->getId(), 'file');     // TODO - decrypts non-public files to guessable names...though 5Mb size restriction should prevent this from being easily exploitable

            $handle = fopen($file_path, 'r');
            if ($handle === false)
                throw new FileNotFoundException($file_path);


            // Attach the original filename to the download
            $display_filename = $file->getOriginalFileName();
            if ($display_filename == null)
                $display_filename = 'File_'.$file->getId().'.'.$file->getExt();

            // Set up a response to send the file back
            $response = new StreamedResponse();
            $response->setPrivate();
            $response->headers->set('Content-Type', mime_content_type($file_path));
            $response->headers->set('Content-Length', filesize($file_path));        // TODO - apparently this isn't sent?
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
                unlink( $file_path );

            return $response;
        }
        catch (\Exception $e) {
            // Returning an error...do it in json
            $request->setRequestFormat('json');

            $source = 0xbbaafae5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Assuming the user has permissions to do so, creates a Symfony StreamedResponse for an image download
     *
     * @param integer $image_id
     * @param Request $request
     *
     * @return JsonResponse|StreamedResponse
     */
    public function imagedownloadAction($image_id, Request $request)
    {
        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

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
            if ($user === 'anon.') {
                if ( $datatype->isPublic() && $datarecord->isPublic() && $datafield->isPublic() && $image->isPublic() ) {
                    // user is allowed to download this image
                }
                else {
                    // something is non-public, therefore an anonymous user isn't allowed to download this image
                    throw new ODRForbiddenException();
                }
            }
            else {
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];
                $datafield_permissions = $user_permissions['datafields'];

                $can_view_datatype = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ]['dt_view']) )
                    $can_view_datatype = true;

                $can_view_datarecord = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ]['dr_view']) )
                    $can_view_datarecord = true;

                $can_view_datafield = false;
                if ( isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ]['view']) )
                    $can_view_datafield = true;

                if (!$datatype->isPublic() && !$can_view_datatype)
                    throw new ODRForbiddenException();
                if (!$datarecord->isPublic() && !$can_view_datarecord)
                    throw new ODRForbiddenException();
                if (!$datafield->isPublic() && !$can_view_datafield)
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------

            // TODO - Only allow this action for images smaller than 5Mb?  filesize isn't being stored in the database though...
/*
            $filesize = $image->->getFilesize() / 1024 / 1024;
            if ($filesize > 5)
                throw new ODRNotImplementedException('Currently not allowed to download files larger than 5Mb');
*/

            // Ensure the image exists in decrypted format
            $file_path = realpath( dirname(__FILE__).'/../../../../web/'.$image->getLocalFileName() );     // realpath() returns false if file does not exist
            if ( !$image->isPublic() || !$file_path )
                $file_path = parent::decryptObject($image->getId(), 'image');     // TODO - decrypts non-public files to guessable names...though 5Mb size restriction should prevent this from being easily exploitable

            $handle = fopen($file_path, 'r');
            if ($handle === false)
                throw new FileNotFoundException($file_path);


            // Attach the original filename to the download
            $display_filename = $image->getOriginalFileName();
            if ($display_filename == null)
                $display_filename = 'Image_'.$image->getId().'.'.$image->getExt();

            // Set up a response to send the image back
            $response = new StreamedResponse();
            $response->setPrivate();
            $response->headers->set('Content-Type', mime_content_type($file_path));
            $response->headers->set('Content-Length', filesize($file_path));        // TODO - apparently this isn't sent?
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
                unlink( $file_path );

            return $response;
        }
        catch (\Exception $e) {
            // Returning an error...do it in json
            $request->setRequestFormat('json');

            $source = 0x8a8b2309;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
