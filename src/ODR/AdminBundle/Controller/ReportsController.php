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

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\ODRUploadService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;


class ReportsController extends ODRCustomController
{

    /**
     * Returns a list of all datarecords that have identical values in the given datafield.
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function analyzedatafielduniqueAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Only run queries if field can be set to unique
            $fieldtype = $datafield->getFieldType();
            if ($fieldtype->getCanBeUnique() == '0')
                throw new ODRBadRequestException("This DataField can't be set to require unique values.");


            // Locate the top-level datatype
            $top_level_datatype = $datatype->getGrandparent();

            // Datafields in child datatypes have different rules for duplicated values...
            $is_child_datatype = false;
            if ( $datatype->getId() !== $top_level_datatype->getId() )
                $is_child_datatype = true;

            // Procedure for locating duplicate values in top-level datatypes differs just enough
            //  from the one for locating duplicate values in child datatypes...
            if (!$is_child_datatype) {
                // Determine which records have duplicated values in the given datafield
                $values = self::buildDatafieldUniquenessReport($em, $sort_service, $datafield);

                // Render the report
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Reports:datafield_uniqueness_report.html.twig',
                        array(
                            'datafield' => $datafield,
                            'duplicate_values' => $values,
                        )
                    )
                );
            }
            else {
                // Determine which records have duplicated values in the given datafield
                $values = self::buildChildDatafieldUniquenessReport($em, $sort_service, $datafield);

                // Render the report
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Reports:child_datafield_uniqueness_report.html.twig',
                        array(
                            'datafield' => $datafield,
                            'duplicate_values' => $values,
                        )
                    )
                );
            }

        }
        catch (\Exception $e) {
            $source = 0x2993cf40;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds an array of duplicated values for a datafield belonging to a top-level datatype.
     *
     * In this version, duplicate values are not allowed in this datafield.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param SortService $sort_service
     * @param Datafields $datafield
     *
     * @return array
     */
    private function buildDatafieldUniquenessReport($em, $sort_service, $datafield)
    {
        // Get the namefield_value for each datarecord of the given datafield's datatype
        $datarecord_names = $sort_service->getNamedDatarecordList($datafield->getDataType()->getId());

        // Build a query to determine which top-level datarecords have duplicate values
        // TODO - this doesn't find values that are empty because of a missing drf/storage entity
        // TODO - ...is that actually a problem?
        $query = $em->createQuery(
           'SELECT dr.id AS dr_id, e.value AS datafield_value
            FROM ODRAdminBundle:'.$datafield->getFieldType()->getTypeClass().' AS e
            JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
            WHERE e.dataField = :datafield
            AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
        )->setParameters( array('datafield' => $datafield->getId()) );
        $results = $query->getArrayResult();

        // Convert the query results into an array grouped by value
        $values = array();
        foreach ($results as $num => $result) {
            $dr_id = $result['dr_id'];
            $value = strval($result['datafield_value']);

            // Use the datarecord's name if it exists
            $dr_name = $dr_id;
            if ( isset($datarecord_names[$dr_id]) )
                $dr_name = $datarecord_names[$dr_id];

            if ( !isset($values[$value]) ) {
                $values[$value] = array(
                    'count' => 1,
                    'dr_list' => array(
                        $dr_id => $dr_name
                    )
                );
            }
            else {
                $values[$value]['count'] += 1;
                $values[$value]['dr_list'][$dr_id] = $dr_name;
            }
        }

        // Filter out all values that aren't duplicated
        foreach ($values as $value => $data) {
            if ( $data['count'] == 1 )
                unset( $values[$value] );
        }

        return $values;
    }


    /**
     * Builds an array of duplicated values for a datafield belonging to a child datatype.
     *
     * In this version, duplicate values are allowed in this datafield...provided they don't occur
     * within the same parent datarecord.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param SortService $sort_service
     * @param Datafields $datafield
     *
     * @return array
     */
    private function buildChildDatafieldUniquenessReport($em, $sort_service, $datafield)
    {
        // Get the namefield_value for each datarecord of the given datafield's grandparent datatype
        $grandparent_datarecord_names = $sort_service->getNamedDatarecordList($datafield->getDataType()->getGrandparent()->getId());

        // Build a query to determine which child datarecords have duplicate values
        $query = $em->createQuery(
           'SELECT
                dr.id AS dr_id, parent.id AS parent_id, grandparent.id AS grandparent_id,
                e.value AS datafield_value
            FROM ODRAdminBundle:'.$datafield->getFieldType()->getTypeClass().' AS e
            JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
            JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
            JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
            WHERE e.dataField = :datafield
            AND e.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( array('datafield' => $datafield->getId()) );
        $results = $query->getArrayResult();

        // Convert the query results into a more useful array...
        $values = array();
        foreach ($results as $num => $result) {
            $dr_id = $result['dr_id'];
            $parent_id = $result['parent_id'];
            $grandparent_id = $result['grandparent_id'];
            $value = strval($result['datafield_value']);

            // Use the grandparent datarecord's name if it exists
            $dr_name = $dr_id;
            if ( isset($grandparent_datarecord_names[$grandparent_id]) )
                $dr_name = $grandparent_datarecord_names[$grandparent_id];

            // The results are first grouped by grandparent datarecord id...
            if ( !isset($values[$grandparent_id]) ) {
                $values[$grandparent_id] = array(
                    'dr_name' => $dr_name,
                    'parent_ids' => array(),
                );
            }

            // ...then grouped by parent datarecord id...
            if ( !isset($values[$grandparent_id]['parent_ids'][$parent_id]) )
                $values[$grandparent_id]['parent_ids'][$parent_id] = array();

            // ...then finally by the value in the datarecord
            if ( !isset($values[$grandparent_id]['parent_ids'][$parent_id][$value]) ) {
                // Haven't seen this value before...
                $values[$grandparent_id]['parent_ids'][$parent_id][$value] = 1;
            }
            else {
                // Have seen this value before...
                $values[$grandparent_id]['parent_ids'][$parent_id][$value] += 1;
            }
        }

        // Filter out all values that aren't duplicated
        foreach ($values as $grandparent_id => $data) {
            foreach ($data['parent_ids'] as $parent_id => $dr_values) {
                foreach ($dr_values as $dr_value => $count) {
                    if ( $count == 1 )
                        unset( $values[$grandparent_id]['parent_ids'][$parent_id][$dr_value] );
                }

                // Don't preserve a parent datarecord id if none of its children have duplicates
                if ( empty($values[$grandparent_id]['parent_ids'][$parent_id]) )
                    unset( $values[$grandparent_id]['parent_ids'][$parent_id] );
            }

            // Don't preserve a grandparent datarecord if no duplicates are listed
            if ( empty($values[$grandparent_id]['parent_ids']) )
                unset( $values[$grandparent_id] );
        }

        return $values;
    }


    /**
     * Returns a list of all datarecords that have multiple files/images uploaded to the given
     * datafield.
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function analyzefileuploadsAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Only run on file or image fields
            $typename = $datafield->getFieldType()->getTypeName();
            if ($typename !== 'File' && $typename !== 'Image')
                throw new ODRBadRequestException("This Datafield's Fieldtype is not File or Image");


            // Get the namefield_value for each datarecord of the given datafield's grandparent datatype
            $datarecord_names = $sort_service->getNamedDatarecordList($datafield->getDataType()->getGrandparent()->getId());

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

                // Increment the number of files/images this datarecord has
                if ( !isset($duplicate_list[$dr_id]) )
                    $duplicate_list[$dr_id] = 0;
                $duplicate_list[$dr_id]++;

                // Also store the grandparent id for later use
                $grandparent_list[$dr_id] = $grandparent_id;
            }

            // Only want the twig file to display grandparents
            $datarecord_list = array();
            foreach ($duplicate_list as $dr_id => $count) {
                if ($count > 1) {
                    // Want to use the grandparent datarecord's name value, if possible
                    $grandparent_id = $grandparent_list[$dr_id];

                    if ( isset($datarecord_names[$grandparent_id]) ) {
                        $grandparent_name = $datarecord_names[$grandparent_id];
                        $datarecord_list[$grandparent_id] = $grandparent_name;
                    }
                    else {
                        $datarecord_list[$grandparent_id] = $grandparent_id;
                    }
                }
            }

            // Render and return a page detailing which datarecords have multiple uploads...
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Reports:multiple_file_uploads_report.html.twig',
                    array(
                        'datafield' => $datafield,
                        'multiple_uploads' => $datarecord_list,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0x93aaae47;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * For a specific datatree relationship (e.g. parent datatype -> child datatype, OR ancestor
     * datatype -> remote datatype), returns a list of parent/ancestor datarecords that have
     * multiple children/remote datarecords.
     *
     * @param integer $datatree_id
     * @param Request $request
     *
     * @return Response
     */
    public function analyzedatarecordnumberAction($datatree_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->find($datatree_id);
            if ($datatree == null)
                throw new ODRNotFoundException('Datatree');

            $parent_datatype = $datatree->getAncestor();
            if ($parent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Parent Datatype');
            $child_datatype = $datatree->getDescendant();
            if ($child_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Child Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $parent_datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Get the namefield_value for each of the ancestor side's datarecords
            $datarecord_names = $sort_service->getNamedDatarecordList($datatree->getAncestor()->getId());

            $results = array();
            if ($datatree->getIsLink() == 0) {
                // Determine whether a datarecord of this datatype has multiple child datarecords
                $query = $em->createQuery(
                   'SELECT parent.id AS ancestor_id, child.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS parent
                    JOIN ODRAdminBundle:DataRecord AS child WITH child.parent = parent
                    WHERE parent.dataType = :parent_datatype AND child.dataType = :child_datatype AND parent.id != child.id
                    AND parent.deletedAt IS NULL AND child.deletedAt IS NULL'
                )->setParameters( array('parent_datatype' => $parent_datatype->getId(), 'child_datatype' => $child_datatype->getId()) );
                $results = $query->getArrayResult();
            }
            else {
                // Determine whether a datarecord of this datatype is linked to multiple datarecords
                $query = $em->createQuery(
                   'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS ancestor
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor.dataType = :ancestor_datatype AND descendant.dataType = :descendant_datatype
                    AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters( array('ancestor_datatype' => $parent_datatype->getId(), 'descendant_datatype' => $child_datatype->getId()) );
                $results = $query->getArrayResult();
            }

            $tmp = array();
            foreach ($results as $num => $result) {
                $ancestor_id = $result['ancestor_id'];

                // Increment the number of child/linked datarecords for this ancestor datarecord
                if ( !isset($tmp[$ancestor_id]) )
                    $tmp[$ancestor_id] = 0;
                $tmp[$ancestor_id]++;
            }

            $datarecord_list = array();
            foreach ($tmp as $dr_id => $count) {
                if ($count > 1) {
                    // Want to use the ancestor datarecord's name value, if possible
                    if ( isset($datarecord_names[$dr_id]) )
                        $datarecord_list[$dr_id] = $datarecord_names[$dr_id];
                    else
                        $datarecord_list[$dr_id] = $dr_id;
                }
            }

            // Render and return a page detailing which datarecords have multiple child/linked datarecords...
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Reports:datarecord_number_report.html.twig',
                    array(
                        'datatree' => $datatree,
                        'datarecords' => $datarecord_list,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0x62dda448;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns a list of local datarecords that are linked to the datarecords of the remote datatype,
     * as well as which remote records are linked to.
     *
     * @param integer $local_datatype_id
     * @param integer $remote_datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function analyzedatarecordlinksAction($local_datatype_id, $remote_datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $local_datatype */
            $local_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($local_datatype_id);
            if ($local_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataType $remote_datatype */
            $remote_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($remote_datatype_id);
            if ($remote_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array(
                    'ancestor' => $local_datatype->getId(),
                    'descendant' => $remote_datatype->getId(),
                )
            );
            if ($datatree == null)
                throw new ODRNotFoundException('Datatree');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $local_datatype) )
                throw new ODRForbiddenException();

            // TODO - if this report becomes available to non-admins, then datarecord restrictions will become an issue...
            $can_edit_local = $permissions_service->canEditDatatype($user, $local_datatype);
            $can_edit_remote = $permissions_service->canEditDatatype($user, $remote_datatype);
            // --------------------


            // Attempt to get the name values for both the local and the remote datarecord
            $local_datatype_names = $sort_service->getNamedDatarecordList($datatree->getAncestor()->getId());
            $remote_datatype_names = $sort_service->getNamedDatarecordList($datatree->getDescendant()->getId());

            // Locate any datarecords of the local datatype that link to datarecords of the remote datatype
            $query = $em->createQuery(
               'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
                FROM ODRAdminBundle:DataRecord AS ancestor
                JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                WHERE ancestor.dataType = :local_datatype_id AND descendant.dataType = :remote_datatype_id
                AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters(
                array(
                    'local_datatype_id' => $local_datatype->getId(),
                    'remote_datatype_id' => $remote_datatype->getId()
                )
            );
            $results = $query->getArrayResult();

            $linked_datarecords = array();
            foreach ($results as $result) {
                $ancestor_id = $result['ancestor_id'];
                $descendant_id = $result['descendant_id'];

                if ( !isset($linked_datarecords[$ancestor_id]) )
                    $linked_datarecords[$ancestor_id] = array();

                $linked_datarecords[$ancestor_id][] = $descendant_id;
            }

            // Render and return a page detailing which datarecords have multiple child/linked datarecords...
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Reports:datarecord_links_report.html.twig',
                    array(
                        'local_datatype' => $local_datatype,
                        'remote_datatype' => $remote_datatype,
                        'linked_datarecords' => $linked_datarecords,

                        'can_edit_local' => $can_edit_local,
                        'can_edit_remote' => $can_edit_remote,

                        'local_datatype_names' => $local_datatype_names,
                        'remote_datatype_names' => $remote_datatype_names,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0x6abcb0cc;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns a list of all values stored in the given datafield.
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function analyzedatafieldcontentAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
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
                    throw new ODRBadRequestException('Invalid DataField');
                    break;
            }


            // Get the namefield_value for each datarecord of the given datafield's datatype
            $datarecord_names = $sort_service->getNamedDatarecordList($datafield->getDataType()->getId());

            // Build a query to grab all values in this datafield
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id, e.value AS value
                FROM ODRAdminBundle:DataRecord AS dr
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:'.$typeclass.' AS e WITH e.dataRecordFields = drf
                WHERE dr.dataType = :datatype AND drf.dataField = :datafield
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                ORDER BY dr.id'
            )->setParameters( array('datatype' => $datatype->getId(), 'datafield' => $datafield_id) );
            $results = $query->getArrayResult();

            $content = array();
            foreach ($results as $num => $result) {
                $dr_id = $result['dr_id'];
                $value = $result['value'];

                $dr_name = $dr_id;
                if ( isset($datarecord_names[$dr_id]) )
                    $dr_name = $datarecord_names[$dr_id];

                $content[$dr_id] = array('dr_name' => $dr_name, 'value' => $value);
            }


            // Render and return a page detailing which datarecords have multiple child/linked datarecords...
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Reports:datafield_content_report.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datatype' => $datatype,

                        'content' => $content,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0xb552e494;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns a list of all records that have selected radio options in the given datafield.
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function analyzeradioselectionsAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Only run this on valid fieldtypes...
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Invalid DataField');


            // Get the namefield_value for each datarecord of the given datafield's datatype
            $datarecord_names = $sort_service->getNamedDatarecordList($datafield->getDataType()->getId());

            // Find all selected radio options for this datafield
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id, rom.optionName
                FROM ODRAdminBundle:DataRecord AS dr
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.dataRecordFields = drf
                JOIN ODRAdminBundle:RadioOptions AS ro WITH rs.radioOption = ro
                JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOption = ro
                WHERE ro.dataField = :datafield AND rs.selected = 1
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            $results = $query->getArrayResult();

            // Determine which datarecords have multiple selected radio options
            $datarecords = array();
            foreach ($results as $num => $result) {
                $dr_id = $result['dr_id'];

                // Increment the number of radio options this datarecord has selected
                if ( !isset($datarecords[$dr_id]) )
                    $datarecords[$dr_id] = 0;
                $datarecords[$dr_id]++;
            }

            foreach ($datarecords as $dr_id => $count) {
                if ($count > 1) {
                    // Want to use the datarecord's name value, if possible
                    if ( isset($datarecord_names[$dr_id]) )
                        $datarecords[$dr_id] = $datarecord_names[$dr_id];
                    else
                        $datarecords[$dr_id] = $dr_id;
                }
                else {
                    // Don't need to save datarecords that don't have more than one selection
                    unset( $datarecords[$dr_id] );
                }
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
            $source = 0x16cb020a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Sets up the minimum required to start a report of what would happen if a datafield got migrated
     * to a different fieldtype
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function analyzedatafieldmigrationstartAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');

            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');

            /** @var DataFields $datafield */
            $datafield = $repo_datafields->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');
            $current_typeclass = $datafield->getFieldType()->getTypeClass();
            $current_typename = $datafield->getFieldType()->getTypeName();

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            /** @var FieldType[] $fieldtypes */
            $fieldtypes = $em->getRepository('ODRAdminBundle:FieldType')->findAll();

            /** @var string[] $fieldtype_list */
            $fieldtype_list = array();
            foreach ($fieldtypes as $fieldtype)
                $fieldtype_list[$fieldtype->getId()] = $fieldtype->getTypeName();


            // There's a couple different versions of output, depending on what the datafield is
            $master_datatype_id = '';
            $strings = array();

            // If this is a derived field...
            if ( !is_null($datafield->getMasterDataField()) ) {
                // ...then it makes more sense to send the user to the master design page for the
                //  master datafield, since you can't directly change fieldtypes for derived fields
                $master_datatype_id = $datafield->getMasterDataField()->getDataType()->getGrandparent()->getId();

                // Intentionally redirecting even if the fieldtype isn't migrateable
            }
            else {
                // ...otherwise, certain fieldtypes should never have a fieldtype selector
                $need_count = false;
                switch ($current_typename) {
                    case 'Boolean':
                    case 'File':
                    case 'Image':
//                    case 'DateTime':      // can convert from datetime to text
//                    case 'Markdown':  // no values to lose, but easier to work with elsewhere
                    case 'Tags':
                    case 'XYZ Data':
                        $need_count = true;
                        break;
                }

                // There are more situations where the field will lose all data, but they depend on
                //  what the field is migrated to...the ones listed above will always lose all data

                if ( $need_count ) {
                    // ...as such, the user needs to be informed how many values will be lost
                    $counts = self::DatafieldMigrations_CountItems($em, $datafield);

                    foreach ($counts as $df_id => $count) {
                        /** @var DataFields $df */
                        $df = $repo_datafields->find($df_id);
                        $strings[$df_id] = array('df' => $df);

                        if ( $count > 0 ) {
                            switch ($current_typeclass) {
                                case 'File':
                                    $strings[$df_id]['str'] = 'All '.$count.' files currently uploaded in this field will be lost upon migration.';
                                    break;
                                case 'Image':
                                    $strings[$df_id]['str'] = 'All '.$count.' images currently uploaded in this field will be lost upon migration.';
                                    break;
                                case 'Radio':
                                    $strings[$df_id]['str'] = $count.' records will lose their selected radio options in this field upon migration.';
                                    break;
                                case 'Tag':
                                    $strings[$df_id]['str'] = $count.' records will lose their selected tags in this field upon migration.';
                                    break;
                                default:
                                    $strings[$df_id]['str'] = 'All '.$count.' values currently in this field will be lost upon migration.';
                                    break;
                            }
                        }
                        else {
                            $strings[$df_id]['str'] = 'This migration would usually cause the loss of all data in this field...but there are no values in the field.';
                        }
                    }
                }
            }


            // ----------------------------------------
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Reports:analyze_datafield_migration_start.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datatype' => $datatype,
                        'master_datatype_id' => $master_datatype_id,

                        'fieldtype_list' => $fieldtype_list,
                        'strings' => $strings,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xbc0b273b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Checks what values would get changed if the given datafield got migrated to a given fieldtype.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function analyzedatafieldmigrationAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $request->request->all();
            if ( !isset($post['datafield_id']) || !isset($post['fieldtype_id']) )
                throw new ODRBadRequestException('Invalid Form');

            $datafield_id = intval($post['datafield_id']);
            $fieldtype_id = intval($post['fieldtype_id']);

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');

            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');

            /** @var DataFields $datafield */
            $datafield = $repo_datafields->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var FieldType $new_fieldtype */
            $new_fieldtype = $em->getRepository('ODRAdminBundle:FieldType')->find($fieldtype_id);
            if ($new_fieldtype == null)
                throw new ODRNotFoundException('Fieldtype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            $is_master_datafield = $datafield->getIsMasterField();
            $current_typeclass = $datafield->getFieldType()->getTypeClass();
            $current_typename = $datafield->getFieldType()->getTypeName();
            $new_typeclass = $new_fieldtype->getTypeClass();
            $new_typename = $new_fieldtype->getTypeName();

            // Having these properties of text fields on hand is useful...
            $old_length = 0;
            $old_is_text = false;
            if ( $current_typename === 'Short Text' ) {
                $old_length = 32;
                $old_is_text = true;
            }
            else if ( $current_typename === 'Medium Text' ) {
                $old_length = 64;
                $old_is_text = true;
            }
            else if ( $current_typename === 'Long Text' ) {
                $old_length = 255;
                $old_is_text = true;
            }
            else if ( $current_typename === 'Paragraph Text' ) {
                $old_length = 9999;
                $old_is_text = true;
            }

            $new_length = 0;
            $new_is_text = false;
            if ( $new_typename === 'Short Text' ) {
                $new_length = 32;
                $new_is_text = true;
            }
            else if ( $new_typename === 'Medium Text' ) {
                $new_length = 64;
                $new_is_text = true;
            }
            else if ( $new_typename === 'Long Text' ) {
                $new_length = 255;
                $new_is_text = true;
            }
            else if ( $new_typename === 'Paragraph Text' ) {
                $new_length = 9999;
                $new_is_text = true;
            }

            // A couple migrations make no sense
            $strings = array();
            $html = '';
            if ( $current_typename === $new_typename )
                $html = 'This field is already a '.$new_typename.'.';
            if ( $current_typename === 'Markdown' )
                $html = 'This field has no values to migrate.';

            if ( $html === '' ) {
                // Most of the typeclasses can't be converted into each other...
                $need_count = false;
                switch ($current_typename) {  // NOTE: identical to self::analyzedatafieldmigrationstartAction(), just in case
                    case 'Boolean':
                    case 'File':
                    case 'Image':
//                    case 'DateTime':      // can convert from datetime to text
//                    case 'Markdown':  // no values to lose, but easier to work with elsewhere
                    case 'Tags':
                    case 'XYZ Data':
                        $need_count = true;
                        break;
                }
                switch ($new_typename) {
                    case 'Boolean':
                    case 'File':
                    case 'Image':
                    case 'DateTime':    // can't convert anything to datetime
                    case 'Markdown':
                    case 'Tags':
                    case 'XYZ Data':
                        $need_count = true;
                        break;
                }

                // Conversions from Datetime to a fieldtype that isn't text will lose all data
                if ( $current_typename === 'DateTime' && !$new_is_text )
                    $need_count = true;

                // Conversions to/from any Radio fieldtype will lose all data, unless the other part
                //  is also a Radio fieldtype
                if ( $current_typeclass === 'Radio' && $new_typeclass !== 'Radio' )
                    $need_count = true;
                if ( $current_typeclass !== 'Radio' && $new_typeclass === 'Radio' )
                    $need_count = true;

                if ($need_count) {
                    // Want to run a query to figure out how many items/values will be lost
                    $counts = self::DatafieldMigrations_CountItems($em, $datafield);

                    foreach ($counts as $df_id => $count) {
                        /** @var DataFields $df */
                        $df = $repo_datafields->find($df_id);
                        $strings[$df_id] = array('df' => $df);

                        if ( $count > 0 ) {
                            switch ($current_typeclass) {
                                case 'File':
                                    $strings[$df_id]['str'] = 'All '.$count.' files currently uploaded in this field will be lost upon migration.';
                                    break;
                                case 'Image':
                                    $strings[$df_id]['str'] = 'All '.$count.' images currently uploaded in this field will be lost upon migration.';
                                    break;
                                case 'Radio':
                                    $strings[$df_id]['str'] = $count.' records will lose their selected radio options in this field upon migration.';
                                    break;
                                case 'Tag':
                                    $strings[$df_id]['str'] = $count.' records will lose their selected tags in this field upon migration.';
                                    break;
                                default:
                                    $strings[$df_id]['str'] = 'All '.$count.' values currently in this field will be lost upon migration.';
                                    break;
                            }
                        }
                        else {
                            $strings[$df_id]['str'] = 'This migration would usually cause the loss of all data in this field...but there are no values in the field.';
                        }
                    }

                    if ( !$is_master_datafield ) {
                        // Counts of values from regular fields should be converted back into a
                        //  single string
                        foreach ($strings as $df_id => $data)
                            $html = $data['str'];
                        $strings = array();
                    }
                }
            }

            // Several conversions will never lose data
            if ( $html === '' || empty($strings) ) {
                if ( ($old_is_text && $new_is_text && $old_length < $new_length)
                    || ($current_typename === 'Integer' && $new_is_text)
                    || ($current_typename === 'Decimal' && $new_is_text)
                    || ($current_typename === 'Integer' && $new_typename === 'Decimal')
                    || ($current_typename === 'DateTime' && $new_is_text)
                ) {
                    $html = 'A '.$current_typename.' field can always be migrated to a '.$new_typename.' field without loss of data.';
                }

                if ( ($current_typename === 'Single Select' && $new_typename === 'Single Radio')
                    || ($current_typename === 'Single Radio' && $new_typename === 'Single Select')
                    || ($current_typename === 'Multiple Select' && $new_typename === 'Multiple Radio')
                    || ($current_typename === 'Multiple Radio' && $new_typename === 'Multiple Select')
                ) {
                    $html = 'A '.$current_typename.' field can always be migrated to a '.$new_typename.' field without loss of data.';
                }

                if ( ($current_typename === 'Single Select' || $current_typename === 'Single Radio')
                    && ($new_typename === 'Multiple Select' || $new_typename === 'Multiple Radio')
                ) {
                    $html = 'A '.$current_typename.' field can always be migrated to a '.$new_typename.' field without loss of data.';
                }
            }

            if ( $html !== '' ) {
                // In most cases, there will be a single string...wrap it inside a div here
                $html = '<div class="ODRDatarecordListHeader">'.$html.'</div>';
            }
            else if ( !empty($strings) ) {
                // If there's an array of strings, then render them
                $html = $templating->render(
                    'ODRAdminBundle:Reports:analyze_datafield_migration_template_fields.html.twig',
                    array(
                        'strings' => $strings,
                    )
                );
            }
            else {
                // ...otherwise, need to do some mysql queries to determine what will change
                if ( $old_is_text && $new_is_text && $old_length > $new_length ) {
                    // Longer text to shorter text has to shorten strings
                    $html = self::DatafieldMigrations_ConvertToShorterText($em, $templating, $datafield, $new_fieldtype);
                }
                else if ( ($old_is_text && $new_typename === 'Integer') || ($current_typename === 'Decimal' && $new_typename === 'Integer') ) {
                    // Text to Integer runs into casting issues, while Decimal to Integer has
                    //  precision issues
                    $html = self::DatafieldMigrations_ConvertToInteger($em, $templating, $datafield, $new_fieldtype);
                }
                else if ( $old_is_text && $new_typename === 'Decimal' ) {
                    // Text to Decimal runs into casting issues
                    $html = self::DatafieldMigrations_ConvertToDecimal($em, $templating, $datafield, $new_fieldtype);
                }
                else if ( ($current_typename === 'Multiple Select' || $current_typename === 'Multiple Radio')
                    && ($new_typename === 'Single Select' || $new_typename === 'Single Radio')
                ) {
                    // Multiple radio/select to Single radio/select will have to deselect options
                    $html = self::DatafieldMigration_ConvertToSingleRadio($em, $templating, $datafield);
                }
            }


            // ----------------------------------------
            $return['d'] = array(
                'html' => $html
            );
        }
        catch (\Exception $e) {
            $source = 0x569c1387;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Need a function to count how many items this datafield has...
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataFields $datafield
     * @return int[]
     */
    private function DatafieldMigrations_CountItems($em, $datafield)
    {
        $datafield_id = $datafield->getId();
        $typeclass = $datafield->getFieldType()->getTypeClass();
        $is_master_field = $datafield->getIsMasterField();

        $query = '';
        if ( $typeclass === 'File' ) {
            $query =
               'SELECT df.id AS df_id, COUNT(e.id) AS num_values
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN odr_file AS e ON e.data_record_fields_id = drf.id
                WHERE ';
            if ( !$is_master_field )
                $query .= 'df.id = '.$datafield_id;
            else
                $query .= 'df.master_datafield_id = '.$datafield_id;
            $query .= '
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL GROUP BY df.id';
        }
        else if ( $typeclass === 'Image' ) {
            $query =
               'SELECT df.id AS df_id, COUNT(e.id) AS num_values
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN odr_image AS e ON e.data_record_fields_id = drf.id
                WHERE e.original = 1 AND ';
            if ( !$is_master_field )
                $query .= 'df.id = '.$datafield_id;
            else
                $query .= 'df.master_datafield_id = '.$datafield_id;
            $query .= '
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL GROUP BY df.id';
        }
        else if ( $typeclass === 'Radio' ) {
            $query =
               'SELECT df.id AS df_id, dr.id AS dr_id
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN odr_radio_selection AS e ON e.data_record_fields_id = drf.id
                WHERE ';
            if ( !$is_master_field )
                $query .= 'df.id = '.$datafield_id;
            else
                $query .= 'df.master_datafield_id = '.$datafield_id;
            $query .= '
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL';
        }
        else if ( $typeclass === 'Tag' ) {
            $query =
               'SELECT df.id AS df_id, dr.id AS dr_id
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN odr_tag_selection AS e ON e.data_record_fields_id = drf.id
                WHERE ';
            if ( !$is_master_field )
                $query .= 'df.id = '.$datafield_id;
            else
                $query .= 'df.master_datafield_id = '.$datafield_id;
            $query .= '
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL';
        }
        else if ( $typeclass === 'XYZData' ) {
            $query =
               'SELECT df.id AS df_id, COUNT(DISTINCT(e.data_record_id)) AS num_values
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN odr_xyz_data AS e ON e.data_record_fields_id = drf.id
                WHERE ';
            if ( !$is_master_field )
                $query .= 'df.id = '.$datafield_id;
            else
                $query .= 'df.master_datafield_id = '.$datafield_id;
            $query .= '
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL GROUP BY df.id';
        }
        else {
            $mapping = array(
                'Boolean' => 'odr_boolean',
                'IntegerValue' => 'odr_integer_value',
                'DecimalValue' => 'odr_decimal_value',
                'DatetimeValue' => 'odr_datetime_value',
                'ShortVarchar' => 'odr_short_varchar',
                'MediumVarchar' => 'odr_medium_varchar',
                'LongVarchar' => 'odr_long_varchar',
                'LongText' => 'odr_long_text',
            );

            $query =
               'SELECT df.id AS df_id, COUNT(e.value) AS num_values
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN '.$mapping[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE ';
            if ( !$is_master_field )
                $query .= 'df.id = '.$datafield_id;
            else
                $query .= 'df.master_datafield_id = '.$datafield_id;
            $query .= '
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL GROUP BY df.id';
        }

        $conn = $em->getConnection();
        $results = $conn->executeQuery($query);

        $counts = array();
        if ( $typeclass === 'Radio' || $typeclass === 'Tag' ) {
            // Radio/Tag fields need to "manually" determine how many records are going to get
            //  changed
            foreach ($results as $result) {
                $df_id = $result['df_id'];
                $dr_id = $result['dr_id'];

                if ( !isset($counts[$df_id]) )
                    $counts[$df_id] = array();
                $counts[$df_id][$dr_id] = 1;
            }

            foreach ($counts as $df_id => $dr_list)
                $counts[$df_id] = count($dr_list);
        }
        else {
            // All other typeclasses can have their counts calculated by mysql
            foreach ($results as $result) {
                $df_id = intval($result['df_id']);
                $num_values = intval($result['num_values']);

                $counts[$df_id] = $num_values;
            }
        }

        return $counts;
    }


    /**
     * Determines what the values in a field would be if it got migrated to the requested text
     * fieldtype, and returns a list of records that would have a different value afterwards.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EngineInterface $templating
     * @param DataFields $datafield
     * @param FieldType $new_fieldtype
     */
    private function DatafieldMigrations_ConvertToShorterText($em, $templating, $datafield, $new_fieldtype)
    {
        $is_master_field = $datafield->getIsMasterField();
        $conn = $em->getConnection();

        // This only gets called when coming from text fields
        $mapping = array(
            'ShortVarchar' => 'odr_short_varchar',
            'MediumVarchar' => 'odr_medium_varchar',
            'LongVarchar' => 'odr_long_varchar',
            'LongText' => 'odr_long_text',
        );

        $datafield_id = $datafield->getId();
        $old_typeclass = $datafield->getFieldType()->getTypeClass();

        $new_length = 0;
        $new_typeclass = $new_fieldtype->getTypeClass();
        if ( $new_typeclass == 'ShortVarchar' )
            $new_length = 32;
        else if ( $new_typeclass == 'MediumVarchar' )
            $new_length = 64;
        else if ( $new_typeclass == 'LongVarchar' )
            $new_length = 255;


        // ----------------------------------------
        // This report exists to help users (mostly me) figure out what's going to happen if a field
        //  gets migrated.  To do that, need an array of the current string data...
        $query =
           'SELECT df.id AS df_id, gdr.id AS dr_id, e.value AS old_value, SUBSTR(e.value, 1, '.$new_length.') AS new_value
            FROM odr_data_record AS gdr
            JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
            JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
            JOIN odr_data_fields df ON drf.data_field_id = df.id
            JOIN '.$mapping[$old_typeclass].' AS e ON e.data_record_fields_id = drf.id
            WHERE ';
        if ( !$is_master_field )
            $query .= 'df.id = '.$datafield_id;
        else
            $query .= 'df.master_datafield_id = '.$datafield_id;
        $query .= '
            AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
            AND df.deletedAt IS NULL';
        $results = $conn->executeQuery($query);

        $data = array();
        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];
            $old_value = $result['old_value'];
            $new_value = $result['new_value'];

            if ( !isset($data[$df_id]) )
                $data[$df_id] = array();
            $data[$df_id][$dr_id] = array('old_value' => $old_value, 'new_value' => $new_value);
        }


        // ----------------------------------------
        // No sense displaying results that haven't visually changed
        $original_lengths = array();
        foreach ($data as $df_id => $df_data) {
            $original_lengths[$df_id] = count($df_data);
            foreach ($df_data as $dr_id => $dr_data) {
                if ( trim($dr_data['old_value']) == trim($dr_data['new_value']) )
                    unset( $data[$df_id][$dr_id] );
            }
        }

        /** @var DataFields[] $df_mapping */
        $df_mapping = array();
        foreach ($data as $df_id => $df_data) {
            $df = $em->getRepository('ODRAdminBundle:DataFields')->find($df_id);
            $df_mapping[$df_id] = $df;
        }

        // Render and return a list of the records that would be changed
        $baseurl = 'https:'.$this->getParameter('site_baseurl').'/admin#/view/';

        $html = $templating->render(
            'ODRAdminBundle:Reports:analyze_datafield_migration_to_text.html.twig',
            array(
                'baseurl' => $baseurl,

                'df_mapping' => $df_mapping,
                'data' => $data,
                'original_lengths' => $original_lengths,
            )
        );

        return $html;
    }


    /**
     * Determines what the values in a field would be if it got migrated to an IntegerValue, and
     * returns a list of records that would have a different value afterwards.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EngineInterface $templating
     * @param DataFields $datafield
     * @param FieldType $new_fieldtype
     */
    private function DatafieldMigrations_ConvertToInteger($em, $templating, $datafield, $new_fieldtype)
    {
        $is_master_field = $datafield->getIsMasterField();
        $conn = $em->getConnection();

        // This only gets called when coming from text or decimal fields
        $mapping = array(
            'DecimalValue' => 'odr_decimal_value',
            'ShortVarchar' => 'odr_short_varchar',
            'MediumVarchar' => 'odr_medium_varchar',
            'LongVarchar' => 'odr_long_varchar',
            'LongText' => 'odr_long_text',
        );

        $datafield_id = $datafield->getId();
        $old_typeclass = $datafield->getFieldType()->getTypeClass();

        // ----------------------------------------
        // This report exists to help users (mostly me) figure out what's going to happen if a field
        //  gets migrated.  To do that, need an array of the current string data...
        $query =
           'SELECT df.id AS df_id, gdr.id AS dr_id, e.value AS value
            FROM odr_data_record AS gdr
            JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
            JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
            JOIN odr_data_fields df ON drf.data_field_id = df.id
            JOIN '.$mapping[$old_typeclass].' AS e ON  e.data_record_fields_id = drf.id
            WHERE ';
        if ( !$is_master_field )
            $query .= 'df.id = '.$datafield_id;
        else
            $query .= 'df.master_datafield_id = '.$datafield_id;
        $query .= '
            AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
            AND df.deletedAt IS NULL';
        $results = $conn->executeQuery($query);

        $data = array();
        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];
            $old_value = $result['value'];

            if ( !isset($data[$df_id]) )
                $data[$df_id] = array();
            $data[$df_id][$dr_id] = array('old_value' => $old_value, 'new_value' => '', 'pass' => '');
        }

        // Going to use CAST() repeatedly to get other data...
        // IMPORTANT: changes made here must also be transferred to WorkerController::migrateAction()
        $query =
           'SELECT df.id AS df_id, gdr.id AS dr_id, e.value AS old_value, CAST(e.value AS SIGNED) AS new_value
            FROM odr_data_record AS gdr
            JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
            JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
            JOIN odr_data_fields df ON drf.data_field_id = df.id
            JOIN '.$mapping[$old_typeclass].' AS e ON e.data_record_fields_id = drf.id
            WHERE ';
        if ( !$is_master_field )
            $query .= 'df.id = '.$datafield_id;
        else
            $query .= 'df.master_datafield_id = '.$datafield_id;
        $query .= '
            AND REGEXP_LIKE(e.value, "'.ValidUtility::INTEGER_MIGRATE_REGEX.'")
            AND CAST(e.value AS DOUBLE) BETWEEN -2147483648 AND 2147483647
            AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
            AND df.deletedAt IS NULL';
        // Need to double-escape the backslashes for mysql
        $query = str_replace("\\", "\\\\", $query);
        $results = $conn->executeQuery($query);

        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];
            $old_value = $result['old_value'];
            $new_value = $result['new_value'];

            $data[$df_id][$dr_id]['new_value'] = $new_value;
            $data[$df_id][$dr_id]['pass'] = 1;
        }


        // ----------------------------------------
        // No sense displaying results that haven't visually changed
        $original_lengths = array();
        foreach ($data as $df_id => $df_data) {
            $original_lengths[$df_id] = count($df_data);
            foreach ($df_data as $dr_id => $dr_data) {
                if ( trim($dr_data['old_value']) == trim($dr_data['new_value']) )
                    unset( $data[$df_id][$dr_id] );
            }
        }

        /** @var DataFields[] $df_mapping */
        $df_mapping = array();
        foreach ($data as $df_id => $df_data) {
            $df = $em->getRepository('ODRAdminBundle:DataFields')->find($df_id);
            $df_mapping[$df_id] = $df;
        }

        // Render and return a list of the records that would be changed
        $baseurl = 'https:'.$this->getParameter('site_baseurl').'/admin#/view/';

        $html = $templating->render(
            'ODRAdminBundle:Reports:analyze_datafield_migration_to_integer.html.twig',
            array(
                'baseurl' => $baseurl,

                'df_mapping' => $df_mapping,
                'data' => $data,
                'original_lengths' => $original_lengths,
            )
        );

        return $html;
    }


    /**
     * Determines what the values in a field would be if it got migrated to a DecimalValue, and
     * returns a list of records that would have a different value afterwards.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EngineInterface $templating
     * @param DataFields $datafield
     * @param FieldType $new_fieldtype
     */
    private function DatafieldMigrations_ConvertToDecimal($em, $templating, $datafield, $new_fieldtype)
    {
        $is_master_field = $datafield->getIsMasterField();
        $conn = $em->getConnection();

        // This only gets called when coming from text fields
        $mapping = array(
            'ShortVarchar' => 'odr_short_varchar',
            'MediumVarchar' => 'odr_medium_varchar',
            'LongVarchar' => 'odr_long_varchar',
            'LongText' => 'odr_long_text',
        );

        $datafield_id = $datafield->getId();
        $old_typeclass = $datafield->getFieldType()->getTypeClass();

        // ----------------------------------------
        // Back when ODR was first designed, it used a beanstalkd queue to end up processing each
        //  individual value...in March 2022 (04b13dd), the migration got changed to attempt to
        //  use INSERT INTO...SELECT statements to greatly speed things up.

        // This worked until the need to convert values with tolerances...such as "5.260(2)"...
        //  into decimals.  The INSERT INTO...SELECT statements use mysql's CAST() function, which
        //  throws warnings on values which aren't numeric...and the warnings get automatically
        //  "upgraded" into errors, which kills the entire migration immediately.

        // So, this report exists to help users (mostly me) figure out what's going to happen
        //  if a field gets migrated.  To do that, need an array of the current string data...
        $query =
           'SELECT df.id AS df_id, gdr.id AS dr_id, e.value AS value
            FROM odr_data_record AS gdr
            JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
            JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
            JOIN odr_data_fields df ON drf.data_field_id = df.id
            JOIN '.$mapping[$old_typeclass].' AS e ON  e.data_record_fields_id = drf.id
            WHERE ';
        if ( !$is_master_field )
            $query .= 'df.id = '.$datafield_id;
        else
            $query .= 'df.master_datafield_id = '.$datafield_id;
        $query .= '
            AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
            AND df.deletedAt IS NULL';
        $results = $conn->executeQuery($query);

        $data = array();
        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];
            $old_value = $result['value'];

            if ( !isset($data[$df_id]) )
                $data[$df_id] = array();
            if ( !isset($data[$df_id][$dr_id]) )
                $data[$df_id][$dr_id] = array('old_value' => $old_value, 'new_value' => '', 'pass' => '');
        }

        // Going to use CAST() repeatedly to get other data...
        // IMPORTANT: changes made here must also be transferred to WorkerController::migrateAction()
        $query =
           'SELECT df.id AS df_id, gdr.id AS dr_id, e.value AS old_value, CAST(SUBSTR(e.value, 1, 255) AS DOUBLE) AS new_value
            FROM odr_data_record AS gdr
            JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
            JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
            JOIN odr_data_fields df ON drf.data_field_id = df.id
            JOIN '.$mapping[$old_typeclass].' AS e ON e.data_record_fields_id = drf.id
            WHERE ';
        if ( !$is_master_field )
            $query .= 'df.id = '.$datafield_id;
        else
            $query .= 'df.master_datafield_id = '.$datafield_id;
        $query .= '
            AND REGEXP_LIKE(e.value, "'.ValidUtility::DECIMAL_MIGRATE_REGEX_A.'")
            AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
            AND df.deletedAt IS NULL';
        // Need to double-escape the backslashes for mysql
        $query = str_replace("\\", "\\\\", $query);
        $results = $conn->executeQuery($query);

        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];
            $old_value = $result['old_value'];
            $new_value = $result['new_value'];

            $data[$df_id][$dr_id]['new_value'] = $new_value;
            $data[$df_id][$dr_id]['pass'] = 1;
        }

        // IMPORTANT: changes made here must also be transferred to WorkerController::migrateAction()
        $query =
           'SELECT df.id AS df_id, gdr.id AS dr_id, e.value AS old_value, CAST(SUBSTR(e.value, 1, LOCATE("(",e.value)-1) AS DOUBLE) AS new_value
            FROM odr_data_record AS gdr
            JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
            JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
            JOIN odr_data_fields df ON drf.data_field_id = df.id
            JOIN '.$mapping[$old_typeclass].' AS e ON  e.data_record_fields_id = drf.id
            WHERE ';
        if ( !$is_master_field )
            $query .= 'df.id = '.$datafield_id;
        else
            $query .= 'df.master_datafield_id = '.$datafield_id;
        $query .= ' 
            AND REGEXP_LIKE(e.value, "'.ValidUtility::DECIMAL_MIGRATE_REGEX_B.'")
            AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND e.deletedAt IS NULL';
        // Need to double-escape the backslashes for mysql
        $query = str_replace("\\", "\\\\", $query);
        $results = $conn->executeQuery($query);

        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];
            $old_value = $result['old_value'];
            $new_value = $result['new_value'];

            if ( $data[$dr_id]['new_value'] !== '' )
                continue;

            $data[$df_id][$dr_id]['new_value'] = $new_value;
            $data[$df_id][$dr_id]['pass'] = 2;
        }


        // ----------------------------------------
        // No sense displaying results that haven't visually changed
        $original_lengths = array();
        foreach ($data as $df_id => $df_data) {
            $original_lengths[$df_id] = count($df_data);
            foreach ($df_data as $dr_id => $dr_data) {
                if ( trim($dr_data['old_value']) == trim($dr_data['new_value']) )
                    unset( $data[$df_id][$dr_id] );
            }
        }

        /** @var DataFields[] $df_mapping */
        $df_mapping = array();
        foreach ($data as $df_id => $df_data) {
            $df = $em->getRepository('ODRAdminBundle:DataFields')->find($df_id);
            $df_mapping[$df_id] = $df;
        }

        // Render and return a list of the records that would be changed
        $baseurl = 'https:'.$this->getParameter('site_baseurl').'/admin#/view/';

        $html = $templating->render(
            'ODRAdminBundle:Reports:analyze_datafield_migration_to_decimal.html.twig',
            array(
                'baseurl' => $baseurl,

                'df_mapping' => $df_mapping,
                'data' => $data,
                'original_lengths' => $original_lengths,
            )
        );

        return $html;
    }


    /**
     * Determines which records would need to deselect some of their radio selections to "fit" in
     * a single radio/select field.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EngineInterface $templating
     * @param DataFields $datafield
     */
    private function DatafieldMigration_ConvertToSingleRadio($em, $templating, $datafield)
    {
        $is_master_field = $datafield->getIsMasterField();
        $conn = $em->getConnection();

        $datafield_id = $datafield->getId();
        $old_typeclass = $datafield->getFieldType()->getTypeClass();


        // ----------------------------------------
        // This report exists to help users (mostly me) figure out what's going to happen if a field
        //  gets migrated.  To do that, need an array of the current string data...
        $query =
           'SELECT df.id AS df_id, gdr.id AS dr_id, ro.id AS ro_id, rs.selected
            FROM odr_data_record AS gdr
            JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
            JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
            JOIN odr_data_fields df ON drf.data_field_id = df.id
            JOIN odr_radio_selection AS rs ON rs.data_record_fields_id = drf.id
            JOIN odr_radio_options AS ro ON rs.radio_option_id = ro.id
            WHERE ';
        if ( !$is_master_field )
            $query .= 'df.id = '.$datafield_id;
        else
            $query .= 'df.master_datafield_id = '.$datafield_id;
        $query .= '
            AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL
            AND df.deletedAt IS NULL';
        $results = $conn->executeQuery($query);

        $data = array();
        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];
            $ro_id = $result['ro_id'];
            $selected = $result['selected'];

            if ( !isset($data[$df_id]) )
                $data[$df_id] = array();
            if ( !isset($data[$df_id][$dr_id]) )    // NOTE: need this to determine original counts
                $data[$df_id][$dr_id] = array();

            if ( $selected == 1 )
                $data[$df_id][$dr_id][$ro_id] = 1;
        }


        // ----------------------------------------
        // No sense displaying results that haven't visually changed
        $original_lengths = array();
        foreach ($data as $df_id => $df_data) {
            $original_lengths[$df_id] = count($df_data);
            foreach ($df_data as $dr_id => $ro_list) {
                if ( count($ro_list) < 2 )
                    unset( $data[$df_id][$dr_id] );
            }
        }

        /** @var DataFields[] $df_mapping */
        $df_mapping = array();
        foreach ($data as $df_id => $df_data) {
            $df = $em->getRepository('ODRAdminBundle:DataFields')->find($df_id);
            $df_mapping[$df_id] = $df;
        }

        // Render and return a list of the records that would be changed
        $baseurl = 'https:'.$this->getParameter('site_baseurl').'/admin#/view/';

        $html = $templating->render(
            'ODRAdminBundle:Reports:analyze_datafield_migration_to_single_radio.html.twig',
            array(
                'baseurl' => $baseurl,

                'df_mapping' => $df_mapping,
                'data' => $data,
                'original_lengths' => $original_lengths,
            )
        );

        return $html;
    }


    /**
     * Returns a simple JSON array of progress made towards decrypting a given file.
     * TODO - allow for images as well?
     *
     * @param integer $file_id
     * @param Request $request
     *
     * @return Response
     */
    public function getfiledecryptprogressAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


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
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files that aren't done encrypting shouldn't be checked for decryption progress
            if ($file->getEncryptKey() === '')
                throw new ODRNotFoundException('File');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if ( $user === 'anon.' ) {
                if ( $datatype->isPublic() && $datarecord->isPublic() && $datafield->isPublic() && $file->isPublic() ) {
                    // user is allowed to download this file
                }
                else {
                    // something is non-public, therefore an anonymous user isn't allowed to download this file
                    throw new ODRForbiddenException();
                }
            }
            else {
                // Grab user's permissions
                $datatype_permissions = $permissions_service->getDatatypePermissions($user);

                // If user has view permissions, show non-public sections of the datarecord
                $can_view_datarecord = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dr_view' ]) )
                    $can_view_datarecord = true;


                // If datatype is not public and user doesn't have permissions to view anything other than public sections of the datarecord, then don't allow them to view
                if ( !$permissions_service->canViewDatatype($user, $datatype) )
                    throw new ODRForbiddenException();

                if ( !$permissions_service->canViewDatafield($user, $datafield) )
                    throw new ODRForbiddenException();

                if ( !$file->isPublic() && !$can_view_datarecord )
                    throw new ODRForbiddenException();
            }
            // --------------------


            // ----------------------------------------
            $progress = array('current_value' => 0, 'max_value' => 100, 'filename' => $file->getOriginalFileName());

            // Shouldn't really be necessary if the file is public, but including anyways for completeness/later use
            if ( $file->isPublic() ) {
                $absolute_path = realpath( $this->getParameter('odr_web_directory').'/'.$file->getLocalFileName() );

                if (!$absolute_path) {
                    // File doesn't exist, so no progress yet
                }
                else {
                    // Grab current filesize of file
                    clearstatcache(true, $absolute_path);
                    $current_filesize = filesize($absolute_path);

                    $progress['current_value'] = intval( (floatval($current_filesize) / floatval($file->getFilesize()) ) * 100);
                }
            }
            else {
                // Determine temporary filename
                $temp_filename = md5($file->getOriginalChecksum().'_'.$file_id.'_'.$user->getId());
                $temp_filename .= '.'.$file->getExt();
                $absolute_path = realpath( $this->getParameter('odr_web_directory').'/uploads/files/'.$temp_filename );

                if (!$absolute_path) {
                    // File doesn't exist, so no progress yet
                }
                else {
                    // Grab current filesize of file
                    clearstatcache(true, $absolute_path);
                    $current_filesize = filesize($absolute_path);

                    $progress['current_value'] = intval( (floatval($current_filesize) / floatval($file->getFilesize()) ) * 100);
                }
            }

            $return['d'] = $progress;
        }
        catch (\Exception $e) {
            $source = 0x10d09095;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns a simple JSON array of progress made towards encrypting a given file.
     * TODO - allow for images as well?
     *
     * @param integer $file_id
     * @param Request $request
     *
     * @return Response
     */
    public function getfileencryptprogressAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


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
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            if ($file->getFilesize() == '')
                throw new ODRException('Filesize not set');     // pretty sure this indicates an error

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = $permissions_service->getDatatypePermissions($user);

            // If user has view permissions, show non-public sections of the datarecord
            $can_view_datarecord = false;
            if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dr_view' ]) )
                $can_view_datarecord = true;


            // If datatype is not public and user doesn't have permissions to view anything other
            //  than public sections of the datarecord, then don't allow them to view
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            if ( !$permissions_service->canViewDatafield($user, $datafield) )
                throw new ODRForbiddenException();

            if ( !$file->isPublic() && !$can_view_datarecord )
                throw new ODRForbiddenException();
            // --------------------

            $progress = array('current_value' => 100, 'max_value' => 100, 'filename' => $file->getOriginalFileName());

            if ($file->getEncryptKey() !== '') {
                // Figure out whether the cached version of this datarecord lists this file as fully
                //  decrypted or not...
                $grandparent_datarecord_id = $datarecord->getGrandparent()->getId();

                // Not using datarecord_info_service because we don't really care what's associated
                //  with this datarecord, or want to rebuild the cache entry if it doesn't exist
                $datarecord_data = $cache_service->get('cached_datarecord_'.$grandparent_datarecord_id);

                if ($datarecord_data != false) {
                    // Attempt to locate this file in the cached datarecord array
                    $delete_cache_entries = true;
                    if ( isset($datarecord_data[$datarecord->getId()]) ) {
                        $drf_data = $datarecord_data[ $datarecord->getId() ]['dataRecordFields'];
                        if ( isset($drf_data[ $datafield->getId() ]) ) {
                            foreach ($drf_data[ $datafield->getId() ]['file'] as $num => $tmp_file) {
                                if ( $tmp_file['id'] == $file->getId() ) {
                                    if ( $tmp_file['original_checksum'] == '' )
                                        $delete_cache_entries = true;
                                    else
                                        $delete_cache_entries = false;
                                }
                            }
                        }
                    }

                    if ($delete_cache_entries) {
                        // The file is fully encrypted, but the cached array entry doesn't properly
                        //  reflect that...wipe the cache entry so they eventually get rebuilt
                        $cache_service->delete('cached_datarecord_'.$grandparent_datarecord_id);
                        $cache_service->delete('cached_table_data_'.$grandparent_datarecord_id);
                    }
                }
            }
            else {
                $crypto_dir = $this->getParameter('dterranova_crypto.temp_folder').'/File_'.$file_id;
                $chunk_size = 2 * 1024 * 1024;  // 2Mb in bytes

                $num_chunks = intval(floatval($file->getFilesize()) / floatval($chunk_size)) + 1;

                // Going to have to use scandir() to count how many chunks have already been encrypted
                if (file_exists($crypto_dir)) {
                    $files = scandir($crypto_dir);
                    // TODO - assumes linux machine
                    $progress['current_value'] = intval((floatval(count($files) - 2) / floatval($num_chunks)) * 100);
                }
                else {
                    // Encrypted directory doesn't exist yet, so no progress has been made
                    $progress['current_value'] = 0;
                }
            }

            $return['d'] = $progress;
        }
        catch (\Exception $e) {
            $source = 0xccdb4bcb;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Restarts a file encryption attempt.
     *
     * @param integer $file_id
     * @param Request $request
     *
     * @return Response
     */
    public function fileencryptretryAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUploadService $odr_upload_service */
            $odr_upload_service = $this->container->get('odr.upload_service');


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
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            if ($file->getEncryptKey() !== '') {
                // File is already encrypted, silently return
                $response = new Response(json_encode($return));
                $response->headers->set('Content-Type', 'application/json');
                return $response;
            }

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            // Files that haven't encrypted should still be non-public, so no sense allowing users
            //  that aren't logged in to run this
            if ( $user === 'anon.' )
                throw new ODRForbiddenException();

            // TODO - other restrictions on who can trigger a retry
            // --------------------

            // This particular retry attempt only works when the uploaded file still exists in
            // the user's tmp directory in ODR
            $em->refresh($file);
            $file_meta = $file->getFileMeta();
            $em->refresh($file_meta);
            $filepath = $file->getLocalFileName().'/'.$file->getOriginalFileName();

            // If the filepath is already pointing to the fully-uploaded path for some reason, then
            //  throw an error
            $odr_files_directory = $this->getParameter('odr_files_directory');
            if ( strpos($filepath, $odr_files_directory) !== false )
                throw new ODRException('File '.$file->getId().' in invalid state to retry encryption');
            // NOTE: I don't think this can actually happen, but be safe

            // If the file pointed to by the filepath doesn't exist, then throw another error
            if ( !file_exists($filepath) )
                throw new ODRException('Unable to retry upload...the actual file for File '.$file->getID().' no longer exists on server');
            // TODO - this would be the ideal state to trigger a deletion of the database entries...


            // ----------------------------------------
            // If no obvious issues, then attempt to "replace" the file with what was already uploaded
            // Keep the original user, and use beanstalk so the rest of the javascript works
            $odr_upload_service->replaceExistingFile($file, $filepath, $file->getCreatedBy(), true);

        }
        catch (\Exception $e) {
            $source = 0x8aa8c87d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Reports on the progress of building a zip archive...
     *
     * @param string $archive_filename
     * @param Request $request
     *
     * @return Response
     */
    public function getziparchiveprogressAction($archive_filename, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            // Don't need to check user's permissions

            // Need a user id for the temp directory to work...
            $user_id = null;
            if ($user == null || $user === 'anon.')
                $user_id = 0;
            else
                $user_id = $user->getId();
            // ----------------------------------------

            // Symfony firewall requires $archive_filename to match "0|[0-9a-zA-Z\-\_]{12}.zip"
            if ($archive_filename == '0')
                throw new ODRBadRequestException();

            $archive_filepath = $this->getParameter('odr_tmp_directory').'/user_'.$user_id.'/'.$archive_filename;
            if ( file_exists($archive_filepath) ) {
                // Load the number of files currently in the archive
                $zip_archive = new \ZipArchive();
                $zip_archive->open($archive_filepath, \ZipArchive::CREATE);

                $archive_filecount = $zip_archive->numFiles;
                $return['d'] = array('archive_filecount' => $archive_filecount);
            }
            else {
                // If the file doesn't exist yet, maybe the background is just slow...don't throw
                //  an error immediately
                $return['d'] = array('archive_filecount' => 0);
            }
        }
        catch (\Exception $e) {
            $source = 0xd16f3328;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
