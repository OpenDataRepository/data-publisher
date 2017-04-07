<?php

/**
 * Open Data Repository Data Publisher
 * API Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class APIController extends ODRCustomController
{


    /**
     * Utility function to cleanly return JSON error responses.
     *
     * @param $message
     *
     * @return JsonResponse
     */
    private function createJSONError($message)
    {
        return new JsonResponse(
            array(
                'error_description' => $message
            )
        );
    }


    /**
     * Used by JupyterHub to determine which user has logged in via ODR's OAuth
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function userdataAction(Request $request)
    {
        /** @var ODRUser $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();

        $logged_in = true;
        if ($user && $user === 'anon.')
            $logged_in = false;

        if ($logged_in && $user->hasRole('ROLE_JUPYTERHUB_USER')) {
            return new JsonResponse(
                array(
                    'username' => $user->getUserString(),
                    'email' => $user->getEmail(),
                    'jupyterhub_username' => 'jupyter_user_'.$user->getId(),
                    'baseurl' => $this->getParameter('site_baseurl'),
                )
            );
        }

        return self::createJSONError('Invalid User');
    }


    /**
     * Returns a JSON array of all top-level datatypes the user can view.  Optionally also returns the child datatypes.
     *
     * @param string $type      "" or "all"...corresponding to "top-level only" or "all datatypes including children"
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function datatypelistAction($type, Request $request)
    {
        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $top_level_datatype_ids = parent::getTopLevelDatatypes();
            $datatree_array = parent::getDatatreeArray($em);

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
            if ($type == 'all') {
                // Build/execute a query to get basic info on all datatypes
                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id, dtm.shortName AS datatype_name, dtm.description AS datatype_description, dtm.publicDate AS public_date
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                    WHERE dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                );
                $results = $query->getArrayResult();
            }
            else {
                // Build/execute a query to get basic info on all top-level datatypes
                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id, dtm.shortName AS datatype_name, dtm.description AS datatype_description, dtm.publicDate AS public_date
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                    WHERE dt.id IN (:datatype_ids)
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters( array('datatype_ids' => $top_level_datatype_ids) );
                $results = $query->getArrayResult();
            }


            // ----------------------------------------
            // Filter the query results by what the user is allowed to see
            $datatype_data = array();
            foreach ($results as $num => $dt) {
                // Store whether the user has permission to view this datatype
                $dt_id = $dt['dt_id'];
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

            if ($type == 'all') {
                // Need to recursively turn this array of datatypes into an inflated array
                foreach ($datatype_data as $dt_id => $dt) {
                    if ( in_array($dt_id, $top_level_datatype_ids) ) {
                        $tmp = self::inflateDatatypeArray($datatype_data, $datatree_array, $dt_id);
                        if ( count($tmp) > 0 )
                            $dt['child_datatypes'] = $tmp;

                        $final_datatype_data[$dt_id] = $dt;
                    }
                }
            }
            else {
                // Otherwise, this is just the top-level dataypes
                $final_datatype_data = $datatype_data;
            }

            // Return everything this user is allowed to see
            return new JsonResponse( array('datatypes' => $final_datatype_data) );
        }
        catch (\Exception $e) {
            return self::createJSONError($e->getMessage());
        }
    }


    /**
     * Utility function to recursively inflate the datatype array for self::datatypelistAction()
     *
     * @param $source_data  @see self::datatypelistAction()
     * @param $datatree_array  @see parent::getDatatreeArray()
     * @param $parent_datatype_id
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
                    $child_datatype_data[$child_dt_id]['child_datatypes'] = $tmp;
            }
        }

        return $child_datatype_data;
    }


    /**
     * Returns a list of top-level datarecords the user is allowed to see by datatype...
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function datarecordlistAction($datatype_id, Request $request)
    {
        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $top_level_datatype_ids = parent::getTopLevelDatatypes();
            if ( !in_array($datatype_id, $top_level_datatype_ids) )
                throw new \Exception('Invalid Datatype');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new \Exception('Invalid Datatype');

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
                throw new \Exception('Invalid Datatype');
            // ----------------------------------------

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

            // If this datatype has an external_id field, make sure the query selects it
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

            // If this datatype has an name field, make sure the query selects it
            if ($datatype->getNameField() !== null) {
                $name_field = $datatype->getNameField()->getId();
                $name_field_fieldtype = $datatype->getNameField()->getFieldType()->getTypeClass();

                $qb->addSelect('e_2.value AS name_field')
                    ->leftJoin('ODRAdminBundle:DataRecordFields', 'drf_2', 'WITH', 'drf_2.dataRecord = dr')
                    ->leftJoin('ODRAdminBundle:'.$name_field_fieldtype, 'e_2', 'WITH', 'e_2.dataRecordFields = drf_2')
                    ->andWhere('e_2.dataField = :name_field')
                    ->andWhere('drf_2.deletedAt IS NULL')->andWhere('e_2.deletedAt IS NULL')
                    ->setParameter('name_field', $name_field);
            }

            $query = $qb->getQuery();
            $results = $query->getArrayResult();

            // The db query results are already close to an ideal JSON format
            return new JsonResponse( array('datarecords' => $results) );
        }
        catch (\Exception $e) {
            return self::createJSONError($e->getMessage());
        }
    }


    /**
     * @param integer $datarecord_id
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function filelistAction($datarecord_id, Request $request)
    {
        try {

            throw new \Exception('not implemented');

        }
        catch (\Exception $e) {
            return self::createJSONError($e->getMessage());
        }
    }
}
