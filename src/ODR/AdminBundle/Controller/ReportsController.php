<?php

/**
* Open Data Repository Data Publisher
* Reports Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The reports controller is meant to be used as a storage spot
* for handling various metadata requests, such as determining which
* datarecords have duplicated values in a specific datafield, or which
* datarecords have multiple files/images uploaded to a specific datafield.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportsController extends ODRCustomController
{

    /**
     * Given a Datafield, build a list of Datarecords (if any) that have identical values in that Datafield.
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return TODO
     */
    public function analyzedatafielduniqueAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $templating = $this->get('templating');

            $datafield = $repo_datafield->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // Only run queries if field can be set to unique
            $fieldtype = $datafield->getFieldType();
            if ($fieldtype->getCanBeUnique() == '0')
                throw new \Exception("This DataField can't be unique becase of its FieldType");

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Build a query to determine which datarecords have duplicate values
            $typeclass = $fieldtype->getTypeClass();
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id, grandparent.id AS grandparent_id, e.value AS value
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
                WHERE e.dataField = :datafield
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            $results = $query->getArrayResult();

            // Convert the query results into an array grouped by value
            $values = array();
            $grandparent_list = array();
            foreach ($results as $num => $result) {
                $dr_id = $result['dr_id'];
                $grandparent_id = $result['grandparent_id'];
                $value = $result['value'];

                if ( !isset($values[$value]) ) {
                    $values[$value] = array('count' => 1, 'dr_list' => array($dr_id));
                }
                else {
                    $values[$value]['count'] += 1;
                    $values[$value]['dr_list'][] = $dr_id;
                }

                $grandparent_list[$dr_id] = $grandparent_id;
            }

            // Don't care about values which aren't duplicated
            foreach ($values as $value => $data) {
                if ( $data['count'] == 1 )
                    unset($values[$value]);
            }

            // Ensure only grandparent ids are passed to twig
            foreach ($values as $value => $data) {
                $values[$value]['grandparent_list'] = array();

                foreach ($values[$value]['dr_list'] as $num => $dr_id) {
                    if ( !in_array($grandparent_list[$dr_id], $values[$value]['grandparent_list']) )
                        $values[$value]['grandparent_list'][] = $grandparent_list[$dr_id];
                }
            }

            // Render and return a page detailing which datarecords have duplicate values...
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Reports:datafield_uniqueness_report.html.twig',
                    array(
                        'datafield' => $datafield,
//                        'user_permissions' => $user_permissions,
                        'duplicate_values' => $values,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x892357656 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Given a Datafield, build a list of Datarecords (if any) that have multiple files/images uploaded in that Datafield.
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return TODO
     */
    public function analyzefileuploadsAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $templating = $this->get('templating');

            $datafield = $repo_datafield->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // Only run on file or image fields
            $typename = $datafield->getFieldType()->getTypeName();
            if ($typename !== 'File' && $typename !== 'Image')
                throw new \Exception('This is not a File or an Image datafield');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Locate any datarecords where this datafield has multiple uploaded files
            $query = null;
            if ($typename == 'File') {
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id, grandparent.id AS grandparent_id
                    FROM ODRAdminBundle:File AS e
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                    JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                    JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
                    WHERE e.dataField = :datafield
                    AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
                )->setParameters( array('datafield' => $datafield_id) );
            }
            else if ($typename == 'Image') {
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id, grandparent.id AS grandparent_id
                    FROM ODRAdminBundle:Image AS e
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                    JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                    JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
                    WHERE e.dataField = :datafield AND e.original = 1
                    AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
                )->setParameters( array('datafield' => $datafield_id) );
            }
            $results = $query->getArrayResult();

            // Count how many files/images are uploaded for this (datarecord,datafield) pair 
            $grandparent_list = array();
            $duplicate_list = array();
            foreach ($results as $num => $result) {
                $dr_id = $result['dr_id'];
                $grandparent_id = $result['grandparent_id'];

                if ( !isset($duplicate_list[$dr_id]) )
                    $duplicate_list[$dr_id] = 0;

                $duplicate_list[$dr_id]++;

                $grandparent_list[$dr_id] = $grandparent_id;
            }

            // Only want to send a list of the grandparent ids to the twig file
            $datarecord_list = array();
            foreach ($duplicate_list as $dr_id => $count) {
                $grandparent_id = $grandparent_list[$dr_id];

                if ( $count > 1 && !in_array($grandparent_id, $datarecord_list) )
                    $datarecord_list[] = $grandparent_id;
            }

            // Render and return a page detailing which datarecords have multiple uploads...
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Reports:multiple_file_uploads_report.html.twig',
                    array(
                        'datafield' => $datafield,
//                        'user_permissions' => $user_permissions,
                        'multiple_uploads' => $datarecord_list,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x835376856 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
