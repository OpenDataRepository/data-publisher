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

// Entities
// Forms
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
     * @return Response TODO
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
     * @return Response TODO
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


    /**
     * Given a Datatree, build a list of Datarecords that have children/are linked to multiple Datarecords through this Datatree.
     *
     * @param integer $datatree_id
     * @param Request $request
     *
     * @return Response TODO
     */
    public function analyzedatarecordnumberAction($datatree_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->find($datatree_id);
            $templating = $this->get('templating');

            if ($datatree == null)
                return parent::deletedEntityError('Datatree');

            $parent_datatype_id = $datatree->getAncestor()->getId();
            $child_datatype_id = $datatree->getDescendant()->getId();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $parent_datatype_id ]) && isset($user_permissions[ $parent_datatype_id ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            $results = array();
            if ($datatree->getIsLink() == 0) {
                // Determine whether a datarecord of this datatype has multiple child datarecords...if so, then require the "multiple allowed" property of the datatree to remain true
                $query = $em->createQuery(
                   'SELECT parent.id AS ancestor_id, child.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS parent
                    JOIN ODRAdminBundle:DataRecord AS child WITH child.parent = parent
                    WHERE parent.dataType = :parent_datatype AND child.dataType = :child_datatype AND parent.id != child.id
                    AND parent.deletedAt IS NULL AND child.deletedAt IS NULL'
                )->setParameters( array('parent_datatype' => $parent_datatype_id, 'child_datatype' => $child_datatype_id) );
                $results = $query->getArrayResult();
            }
            else {
                // Determine whether a datarecord of this datatype is linked to multiple datarecords...if so, then require the "multiple allowed" property of the datatree to remain true
                $query = $em->createQuery(
                   'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS ancestor
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor.dataType = :ancestor_datatype AND descendant.dataType = :descendant_datatype
                    AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters( array('ancestor_datatype' => $parent_datatype_id, 'descendant_datatype' => $child_datatype_id) );
                $results = $query->getArrayResult();
            }

            $tmp = array();
            foreach ($results as $num => $result) {
                $ancestor_id = $result['ancestor_id'];
                if ( !isset($tmp[$ancestor_id]) )
                    $tmp[$ancestor_id] = 0;

                $tmp[$ancestor_id]++;
            }

            $datarecords = array();
            foreach ($tmp as $dr_id => $count) {
                if ($count > 1)
                    $datarecords[] = $dr_id;
            }

            // Render and return a page detailing which datarecords have multiple child/linked datarecords...
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Reports:datarecord_number_report.html.twig',
                    array(
                        'datatree' => $datatree,
                        'datarecords' => $datarecords,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x531765856 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Given a datafield, build a list of all values stored in that datafield
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response TODO
     */
    public function analyzedatafieldcontentAction($datafield_id, Request $request)
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


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Only run this on valid fieldtypes...
            $typeclass = $datafield->getFieldType()->getTypeClass();
            switch ($typeclass) {
                case 'ShortVarchar':
                case 'MediumVarchar':
                case 'LongVarchar':
                case 'LongText':
                case 'IntegerValue':
                case 'DecimalValue':
                    // Allowed
                    break;

                default:
                    throw new \Exception('Invalid DataField');
                    break;
            }


            // Build a query to grab all values in this datafield
            $use_external_id_field = true;
            $results = array();
            if ($datatype->getExternalIdField() == null) {
                $use_external_id_field = false;
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id, e.value AS value, "" AS external_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                    JOIN ODRAdminBundle:'.$typeclass.' AS e WITH e.dataRecordFields = drf
                    WHERE dr.dataType = :datatype AND drf.dataField = :datafield
                    AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                    ORDER BY dr.id'
                )->setParameters( array('datatype' => $datatype->getId(), 'datafield' => $datafield_id) );
                $results = $query->getArrayResult();
            }
            else {
                $external_id_field_typeclass = $datatype->getExternalIdField()->getFieldType()->getTypeClass();
                $typeclass = $datafield->getFieldType()->getTypeClass();
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id, e_2.value AS value, e_1.value AS external_id
                    FROM ODRAdminBundle:'.$external_id_field_typeclass.' AS e_1
                    JOIN ODRAdminBundle:DataRecordFields AS drf_1 WITH e_1.dataRecordFields = drf_1
                    JOIN ODRAdminBundle:DataRecord AS dr WITH drf_1.dataRecord = dr
                    JOIN ODRAdminBundle:DataRecordFields AS drf_2 WITH drf_2.dataRecord = dr
                    JOIN ODRAdminBundle:'.$typeclass.' AS e_2 WITH e_2.dataRecordFields = drf_2
                    WHERE dr.dataType = :datatype AND drf_2.dataField = :datafield AND drf_1.dataField = :external_id_field
                    AND e_1.deletedAt IS NULL AND drf_1.deletedAt IS NULL AND dr.deletedAt IS NULL AND drf_2.deletedAt IS NULL AND e_2.deletedAt IS NULL
                    ORDER BY dr.id'
                )->setParameters( array('datatype' => $datatype->getId(), 'datafield' => $datafield_id, 'external_id_field' => $datatype->getExternalIdField()->getId()) );
                $results = $query->getArrayResult();
            }

            $content = array();
            foreach ($results as $num => $result) {
                $dr_id = $result['dr_id'];
                $value = $result['value'];
                $external_id = $result['external_id'];

                $content[$dr_id] = array('external_id' => $external_id, 'value' => $value);
            }


            // Render and return a page detailing which datarecords have multiple child/linked datarecords...
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Reports:datafield_content_report.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datatype' => $datatype,
                        'content' => $content,
                        'use_external_id_field' => $use_external_id_field,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x173658256 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Given a datafield, build a list of all values stored in that datafield
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response TODO
     */
    public function analyzeradioselectionsAction($datafield_id, Request $request)
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


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // ----------------------------------------
            // Only run this on valid fieldtypes...
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new \Exception('Invalid DataField');

            // Find all selected radio options for this datafield
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id, ro.optionName
                FROM ODRAdminBundle:DataRecord AS dr
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.dataRecordFields = drf
                JOIN ODRAdminBundle:RadioOptions AS ro WITH rs.radioOption = ro
                WHERE drf.dataField = :datafield AND rs.selected = 1
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            $results = $query->getArrayResult();

            // Determine which datarecords have multiple selected radio options
            $datarecords = array();
            foreach ($results as $num => $result) {
                $dr_id = $result['dr_id'];

                if ( !isset($datarecords[$dr_id]) )
                    $datarecords[$dr_id] = 0;

                $datarecords[$dr_id]++;
            }

            // ----------------------------------------
            // Render and return a page detailing which datarecords have multiple selected Radio Options...
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Reports:radio_selections_report.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datatype' => $datatype,
                        'datarecords' => $datarecords,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x365824256 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
