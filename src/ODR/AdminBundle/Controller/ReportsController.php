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
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\File;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Symfony
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
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
            $templating = $this->get('templating');
            if (!$is_child_datatype) {
                // Determine which records have duplicated values in the given datafield
                $values = self::buildDatafieldUniquenessReport($em, $datafield);

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
                $values = self::buildChildDatafieldUniquenessReport($em, $datafield);

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
     * Returns an array of the namefield values for all datarecords of the given datatype.
     * TODO - move to DatarecordInfoService?  This is the only controller that needs this data though...
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataType $datatype
     *
     * @return array
     */
    private function getDatarecordNames($em, $datatype)
    {
        // If the datatype has a namefield set, load all the values of the namefield
        $namefield = $datatype->getNameField();

        $datarecord_names = array();
        if ( !is_null($namefield) ) {
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id, e.value AS namefield_value
                FROM ODRAdminBundle:'.$namefield->getFieldType()->getTypeClass().' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE e.dataField = :datafield
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters( array('datafield' => $namefield->getId()) );
            $results = $query->getArrayResult();

            foreach ($results as $num => $result) {
                $dr_id = $result['dr_id'];
                $namefield_value = trim($result['namefield_value']);

                // Name field values are useless if they're blank...
                if ( $namefield_value !== '' )
                    $datarecord_names[$dr_id] = $namefield_value;
                else
                    $datarecord_names[$dr_id] = $dr_id;
            }
        }

        return $datarecord_names;
    }


    /**
     * Builds an array of duplicated values for a datafield belonging to a top-level datatype.
     *
     * In this version, duplicate values are not allowed in this datafield.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param Datafields $datafield
     *
     * @return array
     */
    private function buildDatafieldUniquenessReport($em, $datafield)
    {
        // Load the namefield_value for each datarecord of the given datafield's datatype
        $datarecord_names = self::getDatarecordNames($em, $datafield->getDataType());

        // Build a query to determine which top-level datarecords have duplicate values
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
            $value = $result['datafield_value'];

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
     * @param Datafields $datafield
     *
     * @return array
     */
    private function buildChildDatafieldUniquenessReport($em, $datafield)
    {
        // Load the namefield_value for each datarecord of the given datafield's grandparent datatype
        $grandparent_datarecord_names = self::getDatarecordNames($em, $datafield->getDataType()->getGrandparent());

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
            $value = $result['datafield_value'];

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
            $templating = $this->get('templating');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Only run on file or image fields
            $typename = $datafield->getFieldType()->getTypeName();
            if ($typename !== 'File' && $typename !== 'Image')
                throw new ODRBadRequestException("This Datafield's Fieldtype is not File or Image");


            // Load the namefield_value for each datarecord of the given datafield's grandparent datatype
            $datarecord_names = self::getDatarecordNames($em, $datafield->getDataType()->getGrandparent());

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
            $templating = $this->get('templating');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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
            if ( !$pm_service->isDatatypeAdmin($user, $parent_datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Load the namefield_value for each of the ancestor side's datarecords
            $datarecord_names = self::getDatarecordNames($em, $datatree->getAncestor());

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
            $templating = $this->get('templating');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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
            if ( !$pm_service->isDatatypeAdmin($user, $local_datatype) )
                throw new ODRForbiddenException();

            // TODO - if this report becomes available to non-admins, then datarecord restrictions will become an issue...
            $can_edit_local = $pm_service->canEditDatatype($user, $local_datatype);
            $can_edit_remote = $pm_service->canEditDatatype($user, $remote_datatype);
            // --------------------


            // Attempt to load both the local and the remote datarecord's name values
            $local_datatype_names = self::getDatarecordNames($em, $datatree->getAncestor());
            $remote_datatype_names = self::getDatarecordNames($em, $datatree->getDescendant());

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
            $templating = $this->get('templating');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
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


            // Load the namefield_value for each datarecord of the given datafield's datatype
            $datarecord_names = self::getDatarecordNames($em, $datafield->getDataType());

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
            $templating = $this->get('templating');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Only run this on valid fieldtypes...
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Invalid DataField');


            // Load the namefield_value for each datarecord of the given datafield's datatype
            $datarecord_names = self::getDatarecordNames($em, $datafield->getDataType());

            // Find all selected radio options for this datafield
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id, rom.optionName
                FROM ODRAdminBundle:DataRecord AS dr
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.dataRecordFields = drf
                JOIN ODRAdminBundle:RadioOptions AS ro WITH rs.radioOption = ro
                JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOption = ro
                WHERE drf.dataField = :datafield AND rs.selected = 1
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
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files that aren't done encrypting shouldn't be checked for decryption progress
            if ($file->getProvisioned() == true)
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
                $datatype_permissions = $pm_service->getDatatypePermissions($user);

                // If user has view permissions, show non-public sections of the datarecord
                $can_view_datarecord = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dr_view' ]) )
                    $can_view_datarecord = true;


                // If datatype is not public and user doesn't have permissions to view anything other than public sections of the datarecord, then don't allow them to view
                if ( !$pm_service->canViewDatatype($user, $datatype) )
                    throw new ODRForbiddenException();

                if ( !$pm_service->canViewDatafield($user, $datafield) )
                    throw new ODRForbiddenException();

                if ( !$file->isPublic() && !$can_view_datarecord )
                    throw new ODRForbiddenException();
            }
            // --------------------


            // ----------------------------------------
            $progress = array('current_value' => 0, 'max_value' => 100);

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
            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            if ($file->getFilesize() == '')
                throw new ODRException('Filesize not set');     // pretty sure this indicates an error

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // If user has view permissions, show non-public sections of the datarecord
            $can_view_datarecord = false;
            if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dr_view' ]) )
                $can_view_datarecord = true;


            // If datatype is not public and user doesn't have permissions to view anything other than public sections of the datarecord, then don't allow them to view
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            if ( !$pm_service->canViewDatafield($user, $datafield) )
                throw new ODRForbiddenException();

            if ( !$file->isPublic() && !$can_view_datarecord )
                throw new ODRForbiddenException();
            // --------------------

            $progress = array('current_value' => 100, 'max_value' => 100);

            if ($file->getProvisioned() == false) {
                // Figure out whether the cached version of this datarecord lists this file as fully decrypted or not...
                $grandparent_datarecord_id = $datarecord->getGrandparent()->getId();

                // Not using datarecord_info_service because we don't really care what's associated with this
                // datarecord, or want the cache entry to exist if it doesn't for some reason
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
                        // Somehow, the file is fully encrypted, but the cached array doesn't properly reflect that...wipe them so they get rebuilt
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
            // TODO - some level of permissions checking?  maybe store archive filename in user's session?

            // Symfony firewall requires $archive_filename to match "0|[0-9a-zA-Z\-\_]{12}.zip"
            if ($archive_filename == '0')
                throw new ODRBadRequestException();

            $archive_filepath = $this->getParameter('odr_web_directory').'/uploads/files/'.$archive_filename;
            if ( !file_exists($archive_filepath) )
                throw new FileNotFoundException($archive_filename);

            // Load the number of files currently in the archive
            $zip_archive = new \ZipArchive();
            $zip_archive->open($archive_filepath, \ZipArchive::CREATE);

            $archive_filecount = $zip_archive->numFiles;

            $return['d'] = array('archive_filecount' => $archive_filecount);
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
