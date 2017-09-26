<?php

/**
 * Open Data Repository Data Publisher
 * Edit Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller handles everything required to edit any kind of
 * data stored in a DataRecord.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Controllers/Classes
use ODR\OpenRepository\SearchBundle\Controller\DefaultController as SearchController;
// Entities
use ODR\AdminBundle\Entity\Boolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Forms
use ODR\AdminBundle\Form\BooleanForm;
use ODR\AdminBundle\Form\DatetimeValueForm;
use ODR\AdminBundle\Form\DecimalValueForm;
use ODR\AdminBundle\Form\IntegerValueForm;
use ODR\AdminBundle\Form\LongTextForm;
use ODR\AdminBundle\Form\LongVarcharForm;
use ODR\AdminBundle\Form\MediumVarcharForm;
use ODR\AdminBundle\Form\ShortVarcharForm;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\FormError;


class EditController extends ODRCustomController
{

    /**
     * Creates a new DataRecord.
     * 
     * @param integer $datatype_id The database id of the DataType this DataRecord will belong to.
     * @param Request $request
     * 
     * @return Response
     */
    public function adddatarecordAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_add_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_add']) )
                $can_add_datarecord = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !$can_add_datarecord )
                throw new ODRForbiddenException();
            // --------------------


            // Determine whether this is a request to add a datarecord for a top-level datatype or not
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ( !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('EditController::adddatarecordAction() called for child datatype');

            // Create a new datarecord
            $datarecord = parent::ODR_addDataRecord($em, $user, $datatype);

            // This is a top-level datarecord...must have grandparent and parent set to itself
            $datarecord->setParent($datarecord);
            $datarecord->setGrandparent($datarecord);

            // Datarecord is ready, remove provisioned flag
            $datarecord->setProvisioned(false);
            $em->flush();


            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'datarecord_id' => $datarecord->getId()
            );


            // ----------------------------------------
            // Delete the cached string containing the ordered list of datarecords for this datatype
            $cache_service->delete('data_type_'.$datatype->getId().'_record_order');

            // See if any cached search results need to be deleted...
            $cached_searches = $cache_service->get('cached_search_results');
            if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
                // Delete all cached search results for this datatype that were NOT run with datafield criteria
                foreach ($cached_searches[$datatype_id] as $search_checksum => $search_data) {
                    $searched_datafields = $search_data['searched_datafields'];
                    if ($searched_datafields == '')
                        unset( $cached_searches[$datatype_id][$search_checksum] );
                }

                // Save the collection of cached searches back to memcached
                $cache_service->set('cached_search_results', $cached_searches);
            }
        }
        catch (\Exception $e) {
            $source = 0x2d4d92e6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Creates a new DataRecord and sets it as a child of the given DataRecord.
     * 
     * @param integer $datatype_id    The database id of the child DataType this new child DataRecord will belong to.
     * @param integer $parent_id      The database id of the DataRecord...
     * @param Request $request
     * 
     * @return Response
     */
    public function addchildrecordAction($datatype_id, $parent_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');

            // Grab needed Entities from the repository
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');

            /** @var DataRecord $parent */
            $parent = $em->getRepository('ODRAdminBundle:DataRecord')->find($parent_id);
            if ( $parent == null )
                throw new ODRNotFoundException('DataRecord');

            $grandparent = $parent->getGrandparent();
            if ( $grandparent->getDeletedAt() != null )
                throw new ODRNotFoundException('Grandparent DataRecord');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_add_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_add']) )
                $can_add_datarecord = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !($grandparent->isPublic() || $can_view_datarecord) || !$can_add_datarecord )
                throw new ODRForbiddenException();
            // --------------------


            // Determine whether this is a request to add a datarecord for a top-level datatype or not
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ( in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('EditController::addchildrecordAction() called for top-level datatype');

            // Create new Data Record
            $datarecord = parent::ODR_addDataRecord($em, $user, $datatype);

            $datarecord->setGrandparent($grandparent);
            $datarecord->setParent($parent);

            // Datarecord is ready, remove provisioned flag
            $datarecord->setProvisioned(false);

            $em->persist($datarecord);
            $em->flush();

            // Get record_ajax.html.twig to re-render the datarecord
            $return['d'] = array(
                'new_datarecord_id' => $datarecord->getId(),
                'datatype_id' => $datatype_id,
                'parent_id' => $parent->getId(),
            );

            // Refresh the cache entries for this datarecord
            parent::tmp_updateDatarecordCache($em, $datarecord, $user);
        }
        catch (\Exception $e) {
            $source = 0x3d2835d5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a top-level DataRecord.
     * 
     * @param integer $datarecord_id The database id of the datarecord to delete.
     * @param string $search_key
     * @param Request $request
     * 
     * @return Response
     */
    public function deletedatarecordAction($datarecord_id, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');

            // Grab the necessary entities
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_delete_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_delete']) )
                $can_delete_datarecord = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !$can_delete_datarecord )
                throw new ODRForbiddenException();
            // --------------------


            if ($datarecord->getId() !== $datarecord->getGrandparent()->getId())
                throw new ODRBadRequestException('EditController::deletedatarecordAction() called on a Datarecord that is not top-level');


            // ----------------------------------------
            // Grab all children of this datarecord
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.grandparent = :datarecord_id
                AND dr.deletedAt IS NULL'
            )->setParameters( array('datarecord_id' => $datarecord->getId()) );
            $results = $query->getArrayResult();

            $affected_datarecords = array();
            foreach ($results as $result)
                $affected_datarecords[] = $result['dr_id'];

            // Grab all datarecords that link to any of the affected datarecords...don't really care about the other direction
            $query = $em->createQuery(
               'SELECT ancestor.id AS ancestor_id
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                WHERE ldt.descendant IN (:datarecord_ids)
                AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
            )->setParameters( array('datarecord_ids' => $affected_datarecords) );
            $results = $query->getArrayResult();

            $ancestor_datarecord_ids = array();
            foreach ($results as $result)
                $ancestor_datarecord_ids[] = $result['ancestor_id'];


            // ...linked_datatree entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:LinkedDataTree AS ldt
                SET ldt.deletedAt = :now, ldt.deletedBy = :deleted_by
                WHERE (ldt.ancestor IN (:datarecord_ids) OR ldt.descendant IN (:datarecord_ids))
                AND ldt.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datarecord_ids' => $affected_datarecords) );
            $rows = $query->execute();

            // ...delete each meta entry for the datarecord and its children
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecordMeta AS drm
                SET drm.deletedAt = :now
                WHERE drm.dataRecord IN (:datarecord_ids)
                AND drm.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'datarecord_ids' => $affected_datarecords) );
            $rows = $query->execute();

            // ...delete the datarecord and all its children
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecord AS dr
                SET dr.deletedAt = :now, dr.deletedBy = :deleted_by
                WHERE dr.id IN (:datarecord_ids)
                AND dr.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datarecord_ids' => $affected_datarecords) );
            $rows = $query->execute();


            // -----------------------------------
            // Delete the list of associated datarecords for the datarecords that linked to this now-deleted datarecord
            foreach ($ancestor_datarecord_ids as $num => $ancestor_id)
                $cache_service->delete('associated_datarecords_for_'.$ancestor_id);

            // Delete the cached entry for this now-deleted datarecord
            $cache_service->delete('cached_datarecord_'.$datarecord_id);
            $cache_service->delete('datarecord_table_data_'.$datarecord_id);

            // Delete the sorted list of datarecords for this datatype
            $cache_service->delete('data_type_'.$datatype->getId().'_record_order');


            // ----------------------------------------
            // See if any cached search results need to be deleted...
            $cached_searches = $cache_service->get('cached_search_results');
            if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
                // Delete all cached search results for this datatype that contained this now-deleted datarecord
                foreach ($cached_searches[$datatype_id] as $search_checksum => $search_data) {
                    $datarecord_list = explode(',', $search_data['datarecord_list']['all']);    // if found in the list of all grandparents matching a search, just delete the entire cached search
                    if ( in_array($datarecord_id, $datarecord_list) )
                        unset ( $cached_searches[$datatype_id][$search_checksum] );
                }

                // Save the collection of cached searches back to memcached
                $cache_service->set('cached_search_results', $cached_searches);
            }


            // ----------------------------------------
            // Determine how many datarecords of this datatype remain
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.deletedAt IS NULL AND dr.dataType = :datatype'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $remaining = $query->getArrayResult();

            // Determine where to redirect since the current datareccord is now deleted
            $url = '';
            if ($search_key == '')
                $search_key = 'dt_id='.$datatype->getId();

            if ( count($remaining) > 0 ) {
                // Return to the list of datarecords since at least one datarecord of this datatype still exists
                $url = $this->generateUrl('odr_search_render', array('search_key' => $search_key));
            }
            else {
                // ...otherwise, return to the list of datatypes
                $url = $this->generateUrl('odr_list_types', array('section' => 'records'));
            }

            $return['d'] = $url;
        }
        catch (\Exception $e) {
            $source = 0x2fb5590f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a child DataRecord, and re-renders the DataRecord so the child disappears.
     *
     * @param integer $datarecord_id The database id of the datarecord being deleted
     * @param Request $request
     * 
     * @return Response
     */
    public function deletechildrecordAction($datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');

            // Grab the necessary entities
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('DataRecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_delete_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_delete']) )
                $can_delete_datarecord = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !$can_delete_datarecord )
                throw new ODRForbiddenException();
            // --------------------


            if ($datarecord->getId() == $datarecord->getGrandparent()->getId())
                throw new ODRBadRequestException('EditController::deletechildrecordAction() called on a Datarecord that is top-level');

            $parent = $datarecord->getParent();
            $grandparent = $datarecord->getGrandparent();
            $grandparent_id = $grandparent->getId();
            $grandparent_datatype_id = $grandparent->getDataType()->getId();


            // ----------------------------------------
            // Grab all children of this datarecord
            $parent_ids = array();
            $parent_ids[] = $datarecord->getId();

            $affected_datarecords = array();
            $affected_datarecords[] = $datarecord->getId();


            while ( count($parent_ids) > 0 ) {
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS parent
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr.parent = parent
                    WHERE dr.id != parent.id AND parent.id IN (:parent_ids)
                    AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL'
                )->setParameters( array('parent_ids' => $parent_ids) );
                $results = $query->getArrayResult();

                $parent_ids = array();
                foreach ($results as $result) {
                    $dr_id = $result['dr_id'];

                    $parent_ids[] = $dr_id;
                    $affected_datarecords[] = $dr_id;
                }
            }

//print '<pre>'.print_r($affected_datarecords, true).'</pre>';  exit();

            // Grab all datarecords that link to any of the affected datarecords...don't really care about the other direction
            $query = $em->createQuery(
               'SELECT ancestor.id AS ancestor_id
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                WHERE ldt.descendant IN (:datarecord_ids)
                AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
            )->setParameters( array('datarecord_ids' => $affected_datarecords) );
            $results = $query->getArrayResult();

            $ancestor_datarecord_ids = array();
            foreach ($results as $result)
                $ancestor_datarecord_ids[] = $result['ancestor_id'];

            // ...linked_datatree entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:LinkedDataTree AS ldt
                SET ldt.deletedAt = :now, ldt.deletedBy = :deleted_by
                WHERE (ldt.ancestor IN (:datarecord_ids) OR ldt.descendant IN (:datarecord_ids))
                AND ldt.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datarecord_ids' => $affected_datarecords) );
            $rows = $query->execute();

            // ...delete each meta entry for the datarecord and its children
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecordMeta AS drm
                SET drm.deletedAt = :now
                WHERE drm.dataRecord IN (:datarecord_ids)
                AND drm.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'datarecord_ids' => $affected_datarecords) );
            $rows = $query->execute();

            // ...delete the datarecord and all its children
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecord AS dr
                SET dr.deletedAt = :now, dr.deletedBy = :deleted_by
                WHERE dr.id IN (:datarecord_ids)
                AND dr.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datarecord_ids' => $affected_datarecords) );
            $rows = $query->execute();


            // -----------------------------------
            // Delete the list of associated datarecords for the datarecords that linked to this now-deleted datarecord
            foreach ($ancestor_datarecord_ids as $num => $ancestor_id)
                $cache_service->delete('associated_datarecords_for_'.$ancestor_id);

            // Delete the cached entries for this datarecord's grandparent
            $cache_service->delete('associated_datarecords_for_'.$grandparent_id);
            parent::tmp_updateDatarecordCache($em, $grandparent, $user);


            // ----------------------------------------
            // See if any cached search results need to be deleted...
            $cached_searches = $cache_service->get('cached_search_results');
            if ( $cached_searches !== false && isset($cached_searches[$grandparent_datatype_id]) ) {
                // Delete all cached search results for this datatype that contained this now-deleted datarecord
                foreach ($cached_searches[$grandparent_datatype_id] as $search_checksum => $search_data) {
                    $complete_datarecord_list = explode(',', $search_data['complete_datarecord_list']);    // if found in the list of all grandparents matching a search, just delete the entire cached search
                    if ( in_array($datarecord_id, $complete_datarecord_list) )
                        unset ( $cached_searches[$grandparent_datatype_id][$search_checksum] );
                }

                // Save the collection of cached searches back to memcached
                $cache_service->set('cached_search_results', $cached_searches);
            }


            // Get record_ajax.html.twig to re-render the datarecord
            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'parent_id' => $parent->getId(),
            );
        }
        catch (\Exception $e) {
            $source = 0x82bb1bb6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a user-uploaded file from the database.
     *
     * @param integer $file_id The database id of the File to delete.
     * @param Request $request
     * 
     * @return Response
     */
    public function deletefileAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Grab the necessary entities
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');

            $datafield = $file->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datafield_id = $datafield->getId();

            $datarecord = $file->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            // Files that aren't done encrypting shouldn't be modified
            if ($file->getProvisioned() == true)
                throw new ODRNotFoundException('File');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_edit_datafield = false;
            if ( isset($datafield_permissions[$datafield_id]) && isset($datafield_permissions[$datafield_id]['edit']) )
                $can_edit_datafield = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions for this datafield...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !$can_edit_datafield )
                throw new ODRForbiddenException();
            // --------------------


            // Delete the decrypted version of this file from the server, if it exists
            $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
            $filename = 'File_'.$file_id.'.'.$file->getExt();
            $absolute_path = realpath($file_upload_path).'/'.$filename;

            if ( file_exists($absolute_path) )
                unlink($absolute_path);

            // Save who deleted the file
            $file->setDeletedBy($user);
            $em->persist($file);
            $em->flush($file);

            // Delete the file and its current metadata entry
            $file_meta = $file->getFileMeta();
            $em->remove($file);
            $em->remove($file_meta);
            $em->flush();


            // Delete cached version of this datarecord
            // TODO - directly update the cached version of the datarecord?
            // TODO - execute graph plugin?
            parent::tmp_updateDatarecordCache($em, $datarecord, $user);

            // TODO - update cached search results?

            // If this datafield only allows a single upload, tell record_ajax.html.twig to refresh that datafield so the upload button shows up
            if ($datafield->getAllowMultipleUploads() == "0")
                $return['d'] = array('need_reload' => true);
        }
        catch (\Exception $e) {
            $source = 0x08e2fe10;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the public status of a file.
     * 
     * @param integer $file_id The database id of the File to modify.
     * @param Request $request
     * 
     * @return Response
     */
    public function publicfileAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');

            // Grab the necessary entities
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');

            $datafield = $file->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datafield_id = $datafield->getId();

            $datarecord = $file->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            // Files that aren't done encrypting shouldn't be modified
            if ($file->getProvisioned() == true)
                throw new ODRNotFoundException('File');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_edit_datafield = false;
            if ( isset($datafield_permissions[$datafield_id]) && isset($datafield_permissions[$datafield_id]['edit']) )
                $can_edit_datafield = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions for this datafield...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !$can_edit_datafield )
                throw new ODRForbiddenException();
            // --------------------


            // Toggle public status of specified file...
            $public_date = null;
            if ( $file->isPublic() ) {
                // Make the file non-public
                $public_date = new \DateTime('2200-01-01 00:00:00');

                $properties = array('publicDate' => $public_date);
                parent::ODR_copyFileMeta($em, $user, $file, $properties);

                // Delete the decrypted version of the file, if it exists
                $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
                $filename = 'File_'.$file_id.'.'.$file->getExt();
                $absolute_path = realpath($file_upload_path).'/'.$filename;

                if ( file_exists($absolute_path) )
                    unlink($absolute_path);
            }
            else {
                // Make the file public
                $public_date = new \DateTime();

                $properties = array('publicDate' => $public_date);
                parent::ODR_copyFileMeta($em, $user, $file, $properties);

                // ----------------------------------------
                // Generate the url for cURL to use
                $redis_prefix = $this->container->getParameter('memcached_key_prefix');    // debug purposes only

                $pheanstalk = $this->get('pheanstalk');
                $router = $this->container->get('router');
                $url = $this->container->getParameter('site_baseurl');
                $url .= $router->generate('odr_crypto_request');

                $api_key = $this->container->getParameter('beanstalk_api_key');
                $file_decryptions = $cache_service->get('file_decryptions');

                // Determine the filename after decryption
                $target_filename = 'File_'.$file_id.'.'.$file->getExt();
                if ( !isset($file_decryptions[$target_filename]) ) {
                    // File is not scheduled to get decrypted at the moment, store that it will be decrypted
                    $file_decryptions[$target_filename] = 1;
                    $cache_service->set('file_decryptions', $file_decryptions);

                    // Schedule a beanstalk job to start decrypting the file
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "object_type" => 'File',
                            "object_id" => $file_id,
                            "target_filename" => $target_filename,
                            "crypto_type" => 'decrypt',

                            "archive_filepath" => '',
                            "desired_filename" => '',

                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 0;
                    $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
                }

                /* otherwise, decryption already in progress, do nothing */
            }

            // Reload the file entity so its associated meta entry gets updated in the EntityManager
            $em->refresh($file);

            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'is_public' => $file->isPublic(),
                'public_date' => $public_date->format('Y-m-d'),
            );


            // Delete cached version of datarecord
            // TODO - replace this block with code to directly update the cached version of the datarecord
            // TODO - execute graph plugin?
            parent::tmp_updateDatarecordCache($em, $datarecord, $user);


            // ----------------------------------------
            // See if any cached search results need to be deleted...
            $grandparent_datatype_id = $dti_service->getGrandparentDatatypeId($datatype->getId());

            $cached_searches = $cache_service->get('cached_search_results');
            if ( $cached_searches != false && isset($cached_searches[$grandparent_datatype_id]) ) {
                // Delete all cached search results for this datatype that were run with criteria for this specific datafield
                foreach ($cached_searches[$grandparent_datatype_id] as $search_checksum => $search_data) {
                    $searched_datafields = $search_data['searched_datafields'];
                    $searched_datafields = explode(',', $searched_datafields);

                    if ( in_array($datafield_id, $searched_datafields) )
                        unset( $cached_searches[$grandparent_datatype_id][$search_checksum] );
                }

                // Save the collection of cached searches back to memcached
                $cache_service->set('cached_search_results', $cached_searches);
            }
        }
        catch (\Exception $e) {
            $source = 0x5201b0cd;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the public status of an image.
     * 
     * @param integer $image_id The database id of the Image to modify
     * @param Request $request
     * 
     * @return Response
     */
    public function publicimageAction($image_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the necessary entities
            /** @var Image $image */
            $image = $repo_image->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');

            $datafield = $image->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datafield_id = $datafield->getId();

            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            // Images that aren't done encrypting shouldn't be downloaded
            if ($image->getOriginalChecksum() == '')
                throw new ODRNotFoundException('Image');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_edit_datafield = false;
            if ( isset($datafield_permissions[$datafield_id]) && isset($datafield_permissions[$datafield_id]['edit']) )
                $can_edit_datafield = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions for this datafield...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !$can_edit_datafield )
                throw new ODRForbiddenException();
            // --------------------


            // Grab all children of the original image (resizes, i believe)
            /** @var Image[] $all_images */
            $all_images = $repo_image->findBy( array('parent' => $image->getId()) );
            $all_images[] = $image;

            // Toggle public status of specified image...
            $public_date = null;

            if ( $image->isPublic() ) {
                // Make the original image non-public
                $public_date = new \DateTime('2200-01-01 00:00:00');

                $properties = array('publicDate' => $public_date );
                parent::ODR_copyImageMeta($em, $user, $image, $properties);

                // Delete the decrypted version of the image and all of its children, if any of them exist
                foreach ($all_images as $img) {
                    $image_upload_path = dirname(__FILE__).'/../../../../web/uploads/images/';
                    $filename = 'Image_'.$img->getId().'.'.$img->getExt();
                    $absolute_path = realpath($image_upload_path).'/'.$filename;

                    if ( file_exists($absolute_path) )
                        unlink($absolute_path);
                }
            }
            else {
                // Make the original image public
                $public_date = new \DateTime();

                $properties = array('publicDate' => $public_date);
                parent::ODR_copyImageMeta($em, $user, $image, $properties);

                // Immediately decrypt the image and all of its children
                foreach ($all_images as $img)
                    parent::decryptObject($img->getId(), 'image');
            }


            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'is_public' => $image->isPublic(),
                'public_date' => $public_date->format('Y-m-d'),
            );


            // Delete cached version of datarecord
            // TODO - replace this block with code to directly update the cached version of the datarecord
            parent::tmp_updateDatarecordCache($em, $datarecord, $user);

            // TODO - update cached search results?
        }
        catch (\Exception $e) {
            $source = 0xf051d2f4;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a user-uploaded image from the repository.
     * 
     * @param integer $image_id The database id of the Image to delete.
     * @param Request $request
     * 
     * @return Response
     */
    public function deleteimageAction($image_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the necessary entities
            /** @var Image $image */
            $image = $repo_image->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');

            $datafield = $image->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datafield_id = $datafield->getId();

            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            // Images that aren't done encrypting shouldn't be modified
            if ($image->getOriginalChecksum() == '')
                throw new ODRNotFoundException('Image');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_edit_datafield = false;
            if ( isset($datafield_permissions[$datafield_id]) && isset($datafield_permissions[$datafield_id]['edit']) )
                $can_edit_datafield = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions for this datafield...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !$can_edit_datafield )
                throw new ODRForbiddenException();
            // --------------------


            // Grab all alternate sizes of the original image (thumbnail is only current one) and remove them
            /** @var Image[] $images */
            $images = $repo_image->findBy( array('parent' => $image->getId()) );
            foreach ($images as $img) {
                // Ensure no decrypted version of any of the thumbnails exist on the server
                $local_filepath = dirname(__FILE__).'/../../../../web/uploads/images/Image_'.$img->getId().'.'.$img->getExt();
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);

                // Delete the alternate sized image from the database
                $em->remove($img);
            }

            // Ensure no decrypted version of the original image exists on the server
            $local_filepath = dirname(__FILE__).'/../../../../web/uploads/images/Image_'.$image->getId().'.'.$image->getExt();
            if ( file_exists($local_filepath) )
                unlink($local_filepath);

            // Save who deleted the image
            $image->setDeletedBy($user);
            $em->persist($image);
            $em->flush($image);

            // Delete the original image and its associated meta entry as well
            $image_meta = $image->getImageMeta();
            $em->remove($image);
            $em->remove($image_meta);
            $em->flush();


            // Delete cached version of this datarecord
            // TODO - directly update the cached version of the datarecord?
            parent::tmp_updateDatarecordCache($em, $datarecord, $user);


            // If this datafield only allows a single upload, tell record_ajax.html.twig to refresh that datafield so the upload button shows up
            if ($datafield->getAllowMultipleUploads() == "0")
                $return['d'] = array('need_reload' => true);

            // TODO - update cached search results?
        }
        catch (\Exception $e) {
            $source = 0xee8e8649;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Rotates a given image by 90 degrees (counter)clockwise, and saves it
     *
     * @param integer $image_id The database id of the Image to delete.
     * @param integer $direction -1 for 90 degrees counter-clockwise rotation, 1 for 90 degrees clockwise rotation
     * @param Request $request
     *
     * @return Response
     */
    public function rotateimageAction($image_id, $direction, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the necessary entities
            /** @var Image $image */
            $image = $repo_image->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');

            $datafield = $image->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datafield_id = $datafield->getId();

            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            // Images that aren't done encrypting shouldn't be modified
            if ($image->getOriginalChecksum() == '')
                throw new ODRNotFoundException('Image');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_edit_datafield = false;
            if ( isset($datafield_permissions[$datafield_id]) && isset($datafield_permissions[$datafield_id]['edit']) )
                $can_edit_datafield = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions for this datafield...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !$can_edit_datafield )
                throw new ODRForbiddenException();
            // --------------------


            // Determine how long it's been since the creation of this image...
            $create_date = $image->getCreated();
            $current_date = new \DateTime();
            $interval = $create_date->diff($current_date);

            // TODO - duration in which image can be rotated without creating new entry?
            // TODO - change to use parent::createNewMetaEntry() ?
            // If the image has existed on the server for less than 30 minutes
            $replace_existing = false;
            if ($interval->days == 0 && $interval->h == 0 && $interval->i <= 30)
                $replace_existing = true;


            // ----------------------------------------
            // Image is going to be rotated, so clear the original checksum for the original image and its thumbnails
            $image_path = parent::decryptObject($image_id, 'image');
            if ($replace_existing) {
                $image->setOriginalChecksum('');    // checksum of the image will be replaced after rotation
                $em->persist($image);
            }

            /** @var Image[] $images */
            $images = $repo_image->findBy( array('parent' => $image->getId()) );
            foreach ($images as $img) {
                // Ensure no decrypted version of any of the thumbnails exist on the server
                $local_filepath = dirname(__FILE__).'/../../../../web/uploads/images/Image_'.$img->getId().'.'.$img->getExt();
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);

                if ($replace_existing) {
                    $img->setOriginalChecksum('');    // checksum of the thumbnail will be replaced after rotation
                    $em->persist($img);
                }
            }

            if ($replace_existing)
                $em->flush();


            // ----------------------------------------
            // If not replacing existing image, have image rotation write back to the same file
            $dest_path = $image_path;
            if (!$replace_existing) {
                // ...otherwise, determine the path to the user's upload folder
                // The image rotation function will save the rotated image there so it can be "uploaded again"...this is the easiest way to ensure everything neccessary exists
                $dest_path = dirname(__FILE__).'/../../../../web/uploads/files';
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );
                $dest_path .= '/chunks';
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );
                $dest_path .= '/user_'.$user->getId();
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );
                $dest_path .= '/completed';
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );

                $dest_path.= '/'.$image->getOriginalFileName();
            }

            // Rotate and save image back to server...apparently positive degrees mean counter-clockwise rotation with imagerotate()
            $degrees = 90;
            if ($direction == 1)
                $degrees = -90;

            $im = null;
            switch ( strtolower($image->getExt()) ) {
                case 'gif':
                    $im = imagecreatefromgif($image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagegif($im, $dest_path);
                    break;
                case 'png':
                    $im = imagecreatefrompng($image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagepng($im, $dest_path);
                    break;
                case 'jpg':
                case 'jpeg':
                    $im = imagecreatefromjpeg($image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagejpeg($im, $dest_path);
                    break;
            }
            imagedestroy($im);


            // ----------------------------------------
            if ($replace_existing) {
                // Update the image's height/width as stored in the database
                $sizes = getimagesize($image_path);
                $image->setImageWidth($sizes[0]);
                $image->setImageHeight($sizes[1]);
                // Create thumbnails and other sizes/versions of the uploaded image
                self::resizeImages($image, $user);

                // Encrypt parent image AFTER thumbnails are created
                self::encryptObject($image_id, 'image');

                // Set original checksum for original image
                $filepath = self::decryptObject($image_id, 'image');
                $original_checksum = md5_file($filepath);
                $image->setOriginalChecksum($original_checksum);

                // A decrypted version of the Image still exists on the server...delete it
                unlink($filepath);

                // Save changes again
                $em->persist($image);
                $em->flush();
            }
            else {
                // "Upload" the "new" rotated image
                $filepath = 'uploads/files/chunks/user_'.$user->getId().'/completed';
                $original_filename = $image->getOriginalFileName();

                $new_image = parent::finishUpload($em, $filepath, $original_filename, $user->getId(), $image->getDataRecordFields()->getId());

                // Copy any metadata from the old image over to the new image
                $old_image_meta = $image->getImageMeta();
                $properties = array(
                    'caption' => $old_image_meta->getCaption(),
                    'original_filename' => $old_image_meta->getOriginalFileName(),
                    'external_id' => $old_image_meta->getExternalId(),
                    'publicDate' => $old_image_meta->getPublicDate(),
                    'display_order' => $old_image_meta->getDisplayorder()
                );
                parent::ODR_copyImageMeta($em, $user, $new_image, $properties);


                // Ensure no decrypted version of the original image exists on the server
                $local_filepath = dirname(__FILE__).'/../../../../web/uploads/images/Image_'.$image->getId().'.'.$image->getExt();
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);

                // Delete the original image and its metadata entry
                $em->remove($image);
                $em->remove($old_image_meta);

                // Delete any thumbnails of the original image
                foreach ($images as $img)
                    $em->remove($img);

                $em->flush();
            }


            // Updated cached version of datarecord
            // TODO - technically, only thing that *needs* updating is datarecord updatedBy property?
            parent::tmp_updateDatarecordCache($em, $datarecord, $user);
        }
        catch (\Exception $e) {
            $source = 0x4093b173;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Modifies the display order of the images in an Image control.
     * 
     * @param Request $request 
     * 
     * @return Response
     */
    public function saveimageorderAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $post = $request->request->all();
//print_r($post);  exit();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Grab the first image just to check permissions
            $image = null;
            foreach ($post as $index => $image_id) {
                $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
                break;
            }
            /** @var Image $image */

            $datafield = $image->getDataField();
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');
            $datafield_id = $datafield->getId();

            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_edit_datafield = false;
            if ( isset($datafield_permissions[$datafield_id]) && isset($datafield_permissions[$datafield_id]['edit']) )
                $can_edit_datafield = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions for this datafield...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !$can_edit_datafield )
                throw new ODRForbiddenException();
            // --------------------


            // Ensure that the provided image ids are all from the same datarecordfield, and that all images from that datarecordfield are listed in the post
            $query = $em->createQuery(
               'SELECT e
                FROM ODRAdminBundle:Image AS e
                WHERE e.dataRecordFields = :drf AND e.original = 1
                AND e.deletedAt IS NULL'
            )->setParameters( array('drf' => $image->getDataRecordFields()->getId()) );
            $results = $query->getResult();

            $all_images = array();
            foreach ($results as $image)
                $all_images[ $image->getId() ] = $image;
            /** @var Image[] $all_images */

            // Throw exceptions if the post request doesn't match the expected image list
            if ( count($post) !== count($all_images) ) {
                throw new ODRBadRequestException('wrong number of images');
            }
            else {
                foreach ($post as $index => $image_id) {
                    if ( !isset($all_images[$image_id]) )
                        throw new ODRBadRequestException('Invalid Image Id');
                }
            }


            // Update the image order based on the post request if required
            foreach ($post as $index => $image_id) {
                $image = $all_images[$image_id];

                if ( $image->getDisplayorder() != $index ) {
//print 'set "'.$image->getOriginalFilename().'" to index '.$index."\n";
                    $properties = array('display_order' => $index);
                    parent::ODR_copyImageMeta($em, $user, $image, $properties);
                }
            }


            // Delete cached version of this datarecord
            // TODO - directly update the cached version of the datarecord?
            parent::tmp_updateDatarecordCache($em, $datarecord, $user);
        }
        catch (\Exception $e) {
            $source = 0x8b01c7e4;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the public status of a DataRecord.
     * 
     * @param integer $datarecord_id The database id of the DataRecord to modify.
     * @param Request $request 
     * 
     * @return Response
     */
    public function publicdatarecordAction($datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            $is_datatype_admin = false;
            if ( isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ]['dt_admin']) )     // TODO - probably shouldn't be this permission...
                $is_datatype_admin = true;

            // Ensure user has permissions to be doing this
            if ( !$is_datatype_admin )
                throw new ODRForbiddenException();
            // --------------------


            // Toggle the public status of the datarecord
            if ( $datarecord->isPublic() ) {
                // Make the datarecord non-public
                $public_date = new \DateTime('2200-01-01 00:00:00');

                $properties = array('publicDate' => $public_date);
                parent::ODR_copyDatarecordMeta($em, $user, $datarecord, $properties);
            }
            else {
                // Make the datarecord non-public
                $public_date = new \DateTime();

                $properties = array('publicDate' => $public_date);
                parent::ODR_copyDatarecordMeta($em, $user, $datarecord, $properties);
            }

            // Refresh the cache entries for this datarecord?
            parent::tmp_updateDatarecordCache($em, $datarecord, $user);


            // ----------------------------------------
            // See if any cached search results need to be deleted...
            $cached_searches = $cache_service->get('cached_search_results');
            if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
                // Delete all cached search results for this datatype that contained this now-deleted datarecord
                foreach ($cached_searches[$datatype_id] as $search_checksum => $search_data) {
                    $datarecord_list = explode(',', $search_data['datarecord_list']['all']);    // if found in the list of all grandparents matching a search, just delete the entire cached search
                    if ( in_array($datarecord_id, $datarecord_list) )
                        unset ( $cached_searches[$datatype_id][$search_checksum] );
                }

                // Save the collection of cached searches back to memcached
                $cache_service->set('cached_search_results', $cached_searches);
            }


            $return['d'] = array(
                'public' => $datarecord->isPublic(),
                'datarecord_id' => $datarecord_id,
            );
        }
        catch (\Exception $e) {
            $source = 0x3df683c4;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Handles selection changes made to SingleRadio, MultipleRadio, SingleSelect, and MultipleSelect DataFields
     *
     * @param integer $datarecord_id    The database id of the Datarecord being modified
     * @param integer $datafield_id     The database id of the Datafield being modified
     * @param integer $radio_option_id  The database id of the RadioOption entity being (de)selected.  If 0, then no RadioOption should be selected.
     * @param Request $request
     *
     * @return Response
     */
    public function radioselectionAction($datarecord_id, $datafield_id, $radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            /** @var RadioOptions $radio_option */
            $radio_option = null;
            if ($radio_option_id != 0) {
                $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($radio_option_id);
                if ($radio_option == null)
                    throw new ODRNotFoundException('RadioOption');
            }


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_edit_datafield = false;
            if ( isset($datafield_permissions[$datafield_id]) && isset($datafield_permissions[$datafield_id]['edit']) )
                $can_edit_datafield = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions for this datafield...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !$can_edit_datafield )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Locate the existing datarecordfield entry, or create one if it doesn't exist
            $drf = parent::ODR_addDataRecordField($em, $user, $datarecord, $datafield);

            // Course of action differs based on whether multiple selections are allowed
            $typename = $datafield->getFieldType()->getTypeName();

            // A RadioOption id of 0 has no effect on a Multiple Radio/Select datafield
            if ( $radio_option_id != 0 && ($typename == 'Multiple Radio' || $typename == 'Multiple Select') ) {
                // Don't care about selected status of other RadioSelection entities...
                $radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option, $drf);

                // Default to a value of 'selected' if an older RadioSelection entity does not exist
                $new_value = 1;
                if ($radio_selection !== null) {
                    // An older version does exist...determine what the new value should be
                    if ($radio_selection->getSelected() == 1)
                        $new_value = 0;
                }

                // Update the RadioSelection entity to match $new_value
                $properties = array('selected' => $new_value);
                parent::ODR_copyRadioSelection($em, $user, $radio_selection, $properties);
            }
            else if ($typename == 'Single Radio' || $typename == 'Single Select') {
                // Probably need to change selected status of at least one other RadioSelection entity...
                /** @var RadioSelection[] $radio_selections */
                $radio_selections = $repo_radio_selection->findBy( array('dataRecordFields' => $drf->getId()) );

                foreach ($radio_selections as $rs) {
                    if ( $radio_option_id != $rs->getRadioOption()->getId() ) {
                        if ($rs->getSelected() == 1) {
                            // Deselect all RadioOptions that are selected and are not the one the user wants to be selected
                            $properties = array('selected' => 0);
                            parent::ODR_copyRadioSelection($em, $user, $rs, $properties);
                        }
                    }
                }

                // If the user selected something other than "<no option selected>"...
                if ($radio_option_id != 0) {
                    // ...locate the RadioSelection entity the user wanted to set to selected
                    $radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option, $drf);

                    // ...ensure it's selected
                    $properties = array('selected' => 1);
                    parent::ODR_copyRadioSelection($em, $user, $radio_selection, $properties);
                }
            }
            else {
                // No point doing anything if not a radio fieldtype
                throw new ODRBadRequestException('RecordController::radioselectionAction() called on Datafield that is not a Radio FieldType');
            }


            // ----------------------------------------
            // TODO - replace this block with code to directly update the cached version of the datarecord?
            parent::tmp_updateDatarecordCache($em, $datarecord, $user);

            // See if any cached search results need to be deleted...
            $cached_searches = $cache_service->get('cached_search_results');
            if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
                // Delete all cached search results for this datatype that were run with criteria for this specific datafield
                foreach ($cached_searches[$datatype_id] as $search_checksum => $search_data) {
                    $searched_datafields = $search_data['searched_datafields'];
                    $searched_datafields = explode(',', $searched_datafields);

                    if ( in_array($datafield_id, $searched_datafields) )
                        unset($cached_searches[$datatype_id][$search_checksum]);
                }

                // Save the collection of cached searches back to memcached
                $cache_service->set('cached_search_results', $cached_searches);
            }
        }
        catch (\Exception $e) {
            $source = 0x01019cfb;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Parses a $_POST request to update the contents of a datafield.
     * File and Image uploads are handled by @see FlowController
     * Changes to RadioSelections are handled by RecordController::radioselectionAction()
     * 
     * @param integer $datarecord_id  The datarecord of the storage entity being modified
     * @param integer $datafield_id   The datafield of the storage entity being modified
     * @param Request $request
     * 
     * @return Response
     */
    public function updateAction($datarecord_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get the Entity Manager
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_edit_datafield = false;
            if ( isset($datafield_permissions[$datafield_id]) && isset($datafield_permissions[$datafield_id]['edit']) )
                $can_edit_datafield = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions for this datafield...don't undertake this action
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !$can_edit_datafield )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Determine class of form needed
            $typeclass = $datafield->getFieldType()->getTypeClass();
            $form_object = null;
            $form_class = null;
            switch ($typeclass) {
                case 'Boolean':
                    $form_class = BooleanForm::class;
                    $form_object = new Boolean();
                    break;
                case 'DatetimeValue':
                    $form_class = DatetimeValueForm::class;
                    $form_object = new DatetimeValue();
                    break;
                case 'DecimalValue':
                    $form_class = DecimalValueForm::class;
                    $form_object = new DecimalValue();
                    break;
                case 'IntegerValue':
                    $form_class = IntegerValueForm::class;
                    $form_object = new IntegerValue();
                    break;
                case 'LongText':    // paragraph text
                    $form_class = LongTextForm::class;
                    $form_object = new LongText();
                    break;
                case 'LongVarchar':
                    $form_class = LongVarcharForm::class;
                    $form_object = new LongVarchar();
                    break;
                case 'MediumVarchar':
                    $form_class = MediumVarcharForm::class;
                    $form_object = new MediumVarchar();
                    break;
                case 'ShortVarchar':
                    $form_class = ShortVarcharForm::class;
                    $form_object = new ShortVarchar();
                    break;

                default:
                    // Radio fieldtypes aren't supposed to be updated here ever
                    // Files/Images might be permissible in the future
                    throw new ODRBadRequestException('RecordController::updateAction() called for a Datafield using the '.$typeclass.' Radio FieldType');
                    break;
            }

            // Ensure the associated storage entity exists
            /** @var Boolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $storage_entity */
            $storage_entity = $em->getRepository('ODRAdminBundle:'.$typeclass)->findOneBy(array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId()));
            if ($storage_entity == null)
                $storage_entity = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);
            $old_value = $storage_entity->getValue();


            // ----------------------------------------
            // Create a new form for this storage entity and bind it to the request
            $form = $this->createForm($form_class, $form_object, array('datarecord_id' => $datarecord->getId(), 'datafield_id' => $datafield->getId()));
            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                $new_value = $form_object->getValue();

                if ($old_value !== $new_value) {

                    // If the datafield is marked as unique...
                    if ($datafield->getIsUnique() == true) {
                        // ...determine whether the new value is a duplicate of a value that already exists
                        $found_existing_value = self::findExistingValue($em, $datafield, $datarecord->getParent()->getId(), $new_value);
                        if ($found_existing_value)
                            $form->addError( new FormError('Another Datarecord already has the value "'.$new_value.'" stored in this Datafield...reverting back to old value.') );
                    }

//$form->addError(new FormError('do not save'));

                    if ($form->isValid()) {
                        // ----------------------------------------
                        // If saving to a datetime field, ensure it's a datetime object?
                        if ($typeclass == 'DatetimeValue') {
                            if ($new_value == '')
                                $new_value = new \DateTime('9999-12-31 00:00:00');
                            else
                                $new_value = new \DateTime($new_value);
                        }
                        else if ($typeclass == 'IntegerValue' || $typeclass == 'DecimalValue') {
                            // DecimalValue::setValue() already does its own thing, and parent::ODR_copyStorageEntity() will set $new_value back to NULL for an IntegerValue
                            $new_value = strval($new_value);
                        }
                        else if ($typeclass == 'ShortVarchar' || $typeclass == 'MediumVarchar' || $typeclass == 'LongVarchar' || $typeclass == 'LongText') {
                            // if array($key => NULL), then isset($property[$key]) returns false...change $new_value to the empty string instead
                            // The text fields should store the empty string instead of NULL anyways
                            if ( is_null($new_value) )
                                $new_value = '';
                        }


                        // Save the value
                        parent::ODR_copyStorageEntity($em, $user, $storage_entity, array('value' => $new_value));


                        // ----------------------------------------
                        // TODO - replace this block with code to directly update the cached version of the datarecord?
                        // TODO - if cached datarecord version isn't completely wiped, then need to have code to modify external id/name/sort datafield value...
/*
                        $external_id_datafield = $datatype->getExternalIdField();
                        $sort_datafield = $datatype->getSortField();
                        $name_datafield = $datatype->getNameField();
*/
                        parent::tmp_updateDatarecordCache($em, $datarecord, $user);


                        // ----------------------------------------
                        // If the datafield that got changed was the datatype's sort datafield, delete the cached datarecord order
                        if ( $datatype->getSortField() != null && $datatype->getSortField()->getId() == $datafield->getId() )
                            $cache_service->del('data_type_'.$datatype->getId().'_record_order');

                        // See if any cached search results need to be deleted...
                        $cached_searches = $cache_service->get('cached_search_results');
                        if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
                            // Delete all cached search results for this datatype that were run with criteria for this specific datafield
                            foreach ($cached_searches[$datatype_id] as $search_checksum => $search_data) {
                                $searched_datafields = $search_data['searched_datafields'];
                                $searched_datafields = explode(',', $searched_datafields);

                                if ( in_array($datafield_id, $searched_datafields) )
                                    unset($cached_searches[$datatype_id][$search_checksum]);
                            }

                            // Save the collection of cached searches back to memcached
                            $cache_service->set('cached_search_results', $cached_searches);
                        }
                    }
                    else {
                        // Form validation failed
                        $error_str = parent::ODR_getErrorMessages($form);
                        throw new ODRException($error_str);
                    }
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x294a59c5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns whether the provided value would violate uniqueness constraints for the given datafield.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param Datafields $datafield
     * @param integer $parent_datarecord_id
     * @param mixed $new_value
     *
     * @return boolean
     */
    private function findExistingValue($em, $datafield, $parent_datarecord_id, $new_value)
    {
        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');

        // Going to need these...
        $datatype_id = $datafield->getDataType()->getId();
        $typeclass = $datafield->getFieldType()->getTypeClass();

        // Determine if this datafield belongs to a top-level datatype or not
        $is_child_datatype = false;
        $datatree_array = $dti_service->getDatatreeArray();
        if ( isset($datatree_array['descendant_of'][$datatype_id]) && $datatree_array['descendant_of'][$datatype_id] !== '' )
            $is_child_datatype = true;

        // Mysql requires a different comparision if checking for duplicates of a null value...
        $comparision = $parameters = null;
        if ($new_value != null) {
            $comparision = 'e.value = :value';
            $parameters = array('datafield' => $datafield->getId(), 'value' => $new_value);
        }
        else {
            $comparision = '(e.value IS NULL OR e.value = :value)';
            $parameters = array('datafield' => $datafield->getId(), 'value' => '');
        }

        // Also search on parent datarecord id if it was passed in
        if ($is_child_datatype)
            $parameters['parent_datarecord_id'] = $parent_datarecord_id;

        if (!$is_child_datatype) {
            $query = $em->createQuery(
               'SELECT e.id
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE e.dataField = :datafield AND '.$comparision.'
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters($parameters);
            $results = $query->getArrayResult();

            // The given value already exists in this datafield
            if ( count($results) > 0 )
                return true;
        }
        else {
            $query = $em->createQuery(
               'SELECT e.id
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
                WHERE e.dataField = :datafield AND '.$comparision.' AND parent.id = :parent_datarecord_id
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL'
            )->setParameters($parameters);
            $results = $query->getArrayResult();

            // The given value already exists in this datafield
            if ( count($results) > 0 )
                return true;
        }

        // The given value does not exist in this datafield
        return false;
    }


    /**
     * Builds and returns a list of available 'descendant' datarecords to link to from this 'ancestor' datarecord.
     * If such a link exists, GetDisplayData() will render a read-only version of the 'remote' datarecord in a ThemeElement of the 'local' datarecord.
     * 
     * @param integer $ancestor_datatype_id   The database id of the DataType that is being linked from
     * @param integer $descendant_datatype_id The database id of the DataType that is being linked to
     * @param integer $local_datarecord_id    The database id of the DataRecord being modified.
     * @param string $search_key              The current search on this tab
     * @param Request $request
     * 
     * @return Response
     */
    public function getlinkabledatarecordsAction($ancestor_datatype_id, $descendant_datatype_id, $local_datarecord_id, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            // Grab the datatypes from the database
            /** @var DataRecord $local_datarecord */
            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ($local_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $local_datatype = $local_datarecord->getDataType();
            if ($local_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Local Datatype');
            $local_datatype_id = $local_datatype->getId();

            /** @var DataType $ancestor_datatype */
            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ($ancestor_datatype == null)
                throw new ODRNotFoundException('Ancestor Datatype');

            /** @var DataType $descendant_datatype */
            $descendant_datatype = $repo_datatype->find($descendant_datatype_id);
            if ($descendant_datatype == null)
                throw new ODRNotFoundException('Descendant Datatype');

            // Ensure a link exists from ancestor to descendant datatype
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy( array('ancestor' => $ancestor_datatype->getId(), 'descendant' => $descendant_datatype->getId()) );
            if ($datatree == null)
                throw new ODRNotFoundException('DataTree');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_ancestor_datatype = false;
            if ( isset($datatype_permissions[$ancestor_datatype_id]) && isset($datatype_permissions[$ancestor_datatype_id]['dt_view']) )
                $can_view_ancestor_datatype = true;

            $can_view_descendant_datatype = false;
            if ( isset($datatype_permissions[$descendant_datatype_id]) && isset($datatype_permissions[$descendant_datatype_id]['dt_view']) )
                $can_view_descendant_datatype = true;

            $can_view_local_datarecord = false;
            if ( isset($datatype_permissions[$local_datatype_id]) && isset($datatype_permissions[$local_datatype_id]['dr_view']) )
                $can_view_local_datarecord = true;

            $can_edit_ancestor_datarecord = false;
            if ( isset($datatype_permissions[$ancestor_datatype_id]) && isset($datatype_permissions[$ancestor_datatype_id]['dr_edit']) )
                $can_edit_ancestor_datarecord = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't undertake this action
            if ( !($ancestor_datatype->isPublic() || $can_view_ancestor_datatype) || !($descendant_datatype->isPublic() || $can_view_descendant_datatype) || !($local_datarecord->isPublic() || $can_view_local_datarecord) || !$can_edit_ancestor_datarecord )
                throw new ODRForbiddenException();
            // --------------------

$debug = true;
$debug = false;
if ($debug) {
    print "local datarecord: ".$local_datarecord_id."\n";
    print "ancestor datatype: ".$ancestor_datatype_id."\n";
    print "descendant datatype: ".$descendant_datatype_id."\n";
}

            // ----------------------------------------
            // Determine which datatype we're trying to create a link with
            $local_datarecord_is_ancestor = false;
            $local_datatype = $local_datarecord->getDataType();
            $remote_datatype = null;
            if ($local_datatype->getId() == $ancestor_datatype_id) {
                $remote_datatype = $repo_datatype->find($descendant_datatype_id);   // Linking to a remote datarecord from this datarecord
                $local_datarecord_is_ancestor = true;
            }
            else {
                $remote_datatype = $repo_datatype->find($ancestor_datatype_id);     // Getting a remote datarecord to link to this datarecord
                $local_datarecord_is_ancestor = false;
            }
            /** @var DataType $remote_datatype */

if ($debug)
    print "\nremote datatype: ".$remote_datatype->getId()."\n";

            // ----------------------------------------
            // Ensure the remote datatype has a table theme...
            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $remote_datatype->getId(), 'themeType' => 'table') );
            if ($theme == null)
                throw new ODRException('Remote Datatype does not have a Table Theme');


            // ----------------------------------------
            // Grab all datarecords currently linked to the local_datarecord
            $linked_datarecords = array();
            if ($local_datarecord_is_ancestor) {
                // local_datarecord is on the ancestor side of the link
                $query = $em->createQuery(
                   'SELECT descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS ancestor
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor = :local_datarecord AND descendant.dataType = :remote_datatype AND descendant.provisioned = false
                    AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
                )->setParameters( array('local_datarecord' => $local_datarecord->getId(), 'remote_datatype' => $remote_datatype->getId()) );
                $results = $query->getResult();
                foreach ($results as $num => $data) {
                    $descendant_id = $data['descendant_id'];
                    if ( $descendant_id == null || trim($descendant_id) == '' )
                        continue;

                    $linked_datarecords[ $descendant_id ] = 1;
                }
            }
            else {
                // local_datarecord is on the descendant side of the link
                $query = $em->createQuery(
                   'SELECT ancestor.id AS ancestor_id
                    FROM ODRAdminBundle:DataRecord AS descendant
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = descendant
                    JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                    WHERE descendant = :local_datarecord AND ancestor.dataType = :remote_datatype AND ancestor.provisioned = false
                    AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters( array('local_datarecord' => $local_datarecord->getId(), 'remote_datatype' => $remote_datatype->getId()) );
                $results = $query->getResult();
                foreach ($results as $num => $data) {
                    $ancestor_id = $data['ancestor_id'];
                    if ( $ancestor_id == null || trim($ancestor_id) == '' )
                        continue;

                    $linked_datarecords[ $ancestor_id ] = 1;
                }

            }

if ($debug) {
    print "\nlinked datarecords\n";
    foreach ($linked_datarecords as $id => $value)
        print '-- '.$id."\n";
}


            // ----------------------------------------
            // Determine whether the link allows multiples or not
            $allow_multiple_links = true;
            $query = $em->createQuery(
               'SELECT dtm.multiple_allowed AS multiple_allowed
                FROM ODRAdminBundle:DataTree AS dt
                JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                WHERE dt.ancestor = :ancestor AND dt.descendant = :descendant
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters( array('ancestor' => $ancestor_datatype->getId(), 'descendant' => $descendant_datatype->getId()) );
            $result = $query->getArrayResult();

            // Save whether only allowed to link to a single datarecord at a time
            if ( $result[0]['multiple_allowed'] == 0 )
                $allow_multiple_links = false;

if ($debug) {
    if ($allow_multiple_links)
        print "\nallow multiple links: true\n";
    else
        print "\nallow multiple links: false\n";
}

            // ----------------------------------------
            // Determine which, if any, datarecords can't be linked to because doing so would violate the "multiple_allowed" rule
            $illegal_datarecords = array();
            if ($local_datarecord_is_ancestor) {
                /* do nothing...the javascript will force compliance with the "multiple_allowed" rule */
            }
            else if (!$allow_multiple_links) {
                // If linking from descendant side, and link is setup to only allow to linking to a single descendant...
                // ...then determine which datarecords on the ancestor side already have links to datarecords on the descendant side
                $query = $em->createQuery(
                   'SELECT ancestor.id
                    FROM ODRAdminBundle:DataRecord AS descendant
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = descendant
                    JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                    WHERE descendant.dataType = :descendant_datatype AND ancestor.dataType = :ancestor_datatype
                    AND descendant.deletedAt IS NULL AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
                )->setParameters( array('descendant_datatype' => $descendant_datatype->getId(), 'ancestor_datatype' => $ancestor_datatype->getId()) );
                $results = $query->getArrayResult();
//print_r($results);
                foreach ($results as $num => $result) {
                    $dr_id = $result['id'];
                    $illegal_datarecords[$dr_id] = 1;
                }
            }

if ($debug) {
    print "\nillegal datarecords\n";
    foreach ($illegal_datarecords as $key => $id)
        print '-- datarecord '.$id."\n";
}


            // ----------------------------------------
            // Convert the list of linked datarecords into a slightly different format so renderTextResultsList() can build it
            $datarecord_list = array();
            foreach ($linked_datarecords as $dr_id => $value)
                $datarecord_list[] = $dr_id;

            $table_html = parent::renderTextResultsList($em, $datarecord_list, $theme, $request);
            $table_html = json_encode($table_html);
//print_r($table_html);

            // Grab the column names for the datatables plugin
            $column_data = parent::getDatatablesColumnNames($em, $theme, $datafield_permissions);
            $column_names = $column_data['column_names'];
            $num_columns = $column_data['num_columns'];
/*
print '<pre>';
print_r($column_data);
print '</pre>';
exit();
*/
            // Render the dialog box for this request
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Edit:link_datarecord_form.html.twig',
                    array(
                        'search_key' => $search_key,

                        'local_datarecord' => $local_datarecord,
                        'local_datarecord_is_ancestor' => $local_datarecord_is_ancestor,
                        'ancestor_datatype' => $ancestor_datatype,
                        'descendant_datatype' => $descendant_datatype,

                        'allow_multiple_links' => $allow_multiple_links,
                        'linked_datarecords' => $linked_datarecords,
                        'illegal_datarecords' => $illegal_datarecords,

                        'count' => count($linked_datarecords),
                        'table_html' => $table_html,
                        'column_names' => $column_names,
                        'num_columns' => $num_columns,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x30878efd;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Parses a $_POST request to modify whether a 'local' datarecord is linked to a 'remote' datarecord.
     * If such a link exists, GetDisplayData() will render a read-only version of the 'remote' datarecord in a ThemeElement of the 'local' datarecord.
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function linkdatarecordsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Symfony firewall won't permit GET requests to reach this point
            $post = $request->request->all();
//print_r($post);  exit();


            if ( !isset($post['local_datarecord_id']) || !isset($post['ancestor_datatype_id']) || !isset($post['descendant_datatype_id']))
                throw new ODRBadRequestException();

            $local_datarecord_id = $post['local_datarecord_id'];
            $ancestor_datatype_id = $post['ancestor_datatype_id'];
            $descendant_datatype_id = $post['descendant_datatype_id'];
//            $allow_multiple_links = $post['allow_multiple_links'];      // TODO - not used when it should be?
            $datarecords = array();
            if ( isset($post['datarecords']) )
                $datarecords = $post['datarecords'];


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');

            /** @var DataRecord $local_datarecord */
            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ($local_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $local_datatype = $local_datarecord->getDataType();
            if ($local_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Local Datatype');
            $local_datatype_id = $local_datatype->getId();


            /** @var DataType $ancestor_datatype */
            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ($ancestor_datatype == null)
                throw new ODRNotFoundException('Ancestor Datatype');

            /** @var DataType $descendant_datatype */
            $descendant_datatype = $repo_datatype->find($descendant_datatype_id);
            if ($descendant_datatype == null)
                throw new ODRNotFoundException('Descendant Datatype');

            // Ensure a link exists from ancestor to descendant datatype
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy( array('ancestor' => $ancestor_datatype->getId(), 'descendant' => $descendant_datatype->getId()) );
            if ($datatree == null)
                throw new ODRNotFoundException('DataTree');

            // Determine which datatype is the remote one
            $remote_datatype_id = $descendant_datatype_id;
            if ($local_datatype_id == $descendant_datatype_id)
                $remote_datatype_id = $ancestor_datatype_id;


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            $can_view_ancestor_datatype = false;
            if ( isset($datatype_permissions[$ancestor_datatype_id]) && isset($datatype_permissions[$ancestor_datatype_id]['dt_view']) )
                $can_view_ancestor_datatype = true;

            $can_view_descendant_datatype = false;
            if ( isset($datatype_permissions[$descendant_datatype_id]) && isset($datatype_permissions[$descendant_datatype_id]['dt_view']) )
                $can_view_descendant_datatype = true;

            $can_view_local_datarecord = false;
            if ( isset($datatype_permissions[$local_datatype_id]) && isset($datatype_permissions[$local_datatype_id]['dr_view']) )
                $can_view_local_datarecord = true;

            $can_edit_ancestor_datarecord = false;
            if ( isset($datatype_permissions[$ancestor_datatype_id]) && isset($datatype_permissions[$ancestor_datatype_id]['dr_edit']) )
                $can_edit_ancestor_datarecord = true;

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't undertake this action
            if ( !($ancestor_datatype->isPublic() || $can_view_ancestor_datatype) || !($descendant_datatype->isPublic() || $can_view_descendant_datatype) || !($local_datarecord->isPublic() || $can_view_local_datarecord) || !$can_edit_ancestor_datarecord )
                throw new ODRForbiddenException();


            // Need to also check whether user has view permissions for remote datatype...
            $can_view_remote_datarecords = false;
            if ( isset($datatype_permissions[$remote_datatype_id]) && isset($datatype_permissions[$remote_datatype_id]['dr_view']) )
                $can_view_remote_datarecords = true;

            if (!$can_view_remote_datarecords) {
                // User apparently doesn't have view permissions for the remote datatype...prevent them from touching a non-public datarecord in that datatype
                $remote_datarecord_ids = array();
                foreach ($datarecords as $id => $num)
                    $remote_datarecord_ids[] = $id;

                // Determine whether there are any non-public datarecords in the list that the user wants to link...
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                    WHERE dr.id IN (:datarecord_ids) AND drm.publicDate = "2200-01-01 00:00:00"
                    AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL'
                )->setParameters( array('datarecord_ids' => $remote_datarecord_ids) );
                $results = $query->getArrayResult();

                // ...if there are, then prevent the action since the user isn't allowed to see them
                if ( count($results) > 0 )
                    throw new ODRForbiddenException();
            }
            else {
                /* user can view remote datatype, no other checks needed */
            }
            // --------------------


            $linked_datatree = null;
            $local_datarecord_is_ancestor = true;
            if ($local_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
                $local_datarecord_is_ancestor = false;
            }

            // Grab records currently linked to the local_datarecord
            $remote = 'ancestor';
            if (!$local_datarecord_is_ancestor)
                $remote = 'descendant';

            $query = $em->createQuery(
               'SELECT ldt
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                WHERE ldt.'.$remote.' = :datarecord
                AND ldt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $local_datarecord->getId()) );
            $results = $query->getResult();

            $linked_datatree = array();
            foreach ($results as $num => $ldt)
                $linked_datatree[] = $ldt;
            /** @var LinkedDataTree[] $linked_datatree */

$debug = true;
$debug = false;
if ($debug) {
    print_r($datarecords);
    print "\nlocal datarecord: ".$local_datarecord_id."\n";
    print "ancestor datatype: ".$ancestor_datatype_id."\n";
    print "descendant datatype: ".$descendant_datatype_id."\n";
    if ($local_datarecord_is_ancestor)
        print "local datarecord is ancestor\n";
    else
        print "local datarecord is descendant\n";
}

if ($debug) {
    print "\nlinked datatree\n";
    foreach ($linked_datatree as $ldt)
        print "-- ldt ".$ldt->getId().' ancestor: '.$ldt->getAncestor()->getId().' descendant: '.$ldt->getDescendant()->getId()."\n";
}

            foreach ($linked_datatree as $ldt) {
                $remote_datarecord = null;
                if ($local_datarecord_is_ancestor)
                    $remote_datarecord = $ldt->getDescendant();
                else
                    $remote_datarecord = $ldt->getAncestor();

                // Ensure that this descendant datarecord is of the same datatype that's being modified...don't want to delete links to datarecords of another datatype
                if ($local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $descendant_datatype->getId()) {
if ($debug)
    print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match descendant datatype\n";
                    continue;
                }
                else if (!$local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
if ($debug)
    print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match ancestor datatype\n";
                    continue;
                }

                // If a descendant datarecord isn't listed in $datarecords, it got unlinked
                if ( !isset($datarecords[$remote_datarecord->getId()]) ) {
if ($debug)
    print 'removing link between ancestor datarecord '.$ldt->getAncestor()->getId().' and descendant datarecord '.$ldt->getDescendant()->getId()."\n";

                    // Delete the cached list of child/linked datarecords for the ancestor datarecord
                    $cache_service->delete('associated_datarecords_for_'.$ldt->getAncestor()->getId());

                    // Remove the linked_data_tree entry
                    $ldt->setDeletedBy($user);
                    $em->persist($ldt);
                    $em->flush($ldt);

                    $em->remove($ldt);
                }
                else {
                    // Otherwise, a datarecord was linked and still is linked...
                    unset( $datarecords[$remote_datarecord->getId()] );
if ($debug)
    print 'link between local datarecord '.$local_datarecord->getId().' and remote datarecord '.$remote_datarecord->getId()." already exists\n";
                }
            }

            // Anything remaining in $datarecords is a newly linked datarecord
            foreach ($datarecords as $id => $num) {
                $remote_datarecord = $repo_datarecord->find($id);

                // Attempt to find a link between these two datarecords that was deleted at some point in the past
                $ancestor_datarecord = null;
                $descendant_datarecord = null;
                if ($local_datarecord_is_ancestor) {
                    $ancestor_datarecord = $local_datarecord;
                    $descendant_datarecord = $remote_datarecord;
if ($debug)
    print 'ensuring link from local datarecord '.$local_datarecord->getId().' to remote datarecord '.$remote_datarecord->getId()."\n";
                }
                else {
                    $ancestor_datarecord = $remote_datarecord;
                    $descendant_datarecord = $local_datarecord;
if ($debug)
    print 'ensuring link from remote datarecord '.$remote_datarecord->getId().' to local datarecord '.$local_datarecord->getId()."\n";
                }

                // Ensure there is a link between the two datarecords
                parent::ODR_linkDataRecords($em, $user, $ancestor_datarecord, $descendant_datarecord);

                // Delete the cached list of child/linked datarecords for the ancestor datarecord
                $cache_service->delete('associated_datarecords_for_'.$ancestor_datarecord->getId());
            }

            $em->flush();

            $return['d'] = array(
                'datatype_id' => $descendant_datatype->getId(),
                'datarecord_id' => $local_datarecord->getId()
            );
        }
        catch (\Exception $e) {
            $source = 0xdd047dcd;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Given a child datatype id and a datarecord, re-render and return the html for that child datatype.
     *
     * @param integer $child_datatype_id     The database id of the child DataType to re-render
     * @param integer $parent_datarecord_id  The database id of the parent DataRecord
     * @param Request $request
     *
     * @return Response
     */
    public function reloadchildAction($child_datatype_id, $parent_datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Don't actually need a search_key for a child reload, but parameter is expected
            $search_key = '';

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $child_datatype */
            $child_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($child_datatype_id);
            if ($child_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataRecord $parent_datarecord */
            $parent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($parent_datarecord_id);
            if ($parent_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $parent_datatype = $parent_datarecord->getDataType();
            if ($parent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Parent Datatype');
            $parent_datatype_id = $parent_datatype->getId();

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $child_datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            $can_view_child_datatype = false;
            if ( isset($datatype_permissions[$child_datatype_id]) && isset($datatype_permissions[$child_datatype_id]['dt_view']) )
                $can_view_child_datatype = true;

            $can_view_parent_datatype = false;
            if ( isset($datatype_permissions[$parent_datatype_id]) && isset($datatype_permissions[$parent_datatype_id]['dt_view']) )
                $can_view_parent_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$parent_datatype_id]) && isset($datatype_permissions[$parent_datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_edit_datarecord = false;
            if ( isset($datatype_permissions[$child_datatype_id]) && isset($datatype_permissions[$child_datatype_id]['dr_edit']) )
                $can_edit_datarecord = true;


            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't reload the child datatype's HTML
            if ( !($parent_datatype->isPublic() || $can_view_parent_datatype) )
                throw new ODRForbiddenException();
            if ( !($child_datatype->isPublic() || $can_view_child_datatype) )
                throw new ODRForbiddenException();
            if ( !($parent_datarecord->isPublic() || $can_view_datarecord) )
                throw new ODRForbiddenException();
            if ( !$can_edit_datarecord )
                throw new ODRForbiddenException();
            // --------------------


            $return['d'] = array(
                'html' => self::GetDisplayData($search_key, $parent_datarecord_id, 'child', $child_datatype_id, $request),
            );
        }
        catch (\Exception $e) {
            $source = 0xb61ecefa;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Given a datarecord and datafield, re-render and return the html for that datafield.
     *
     * @param integer $datafield_id  The database id of the DataField inside the DataRecord to re-render.
     * @param integer $datarecord_id The database id of the DataRecord to re-render
     * @param Request $request
     *
     * @return Response
     */
    public function reloaddatafieldAction($datafield_id, $datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Don't actually need a search_key for a datafield reload, but the parameter is expected
            $search_key = '';

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_edit_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_edit']) )
                $can_edit_datarecord = true;

            $can_view_datafield = false;
            if ( isset($datafield_permissions[$datafield_id]) && isset($datafield_permissions[$datafield_id]['view']) )
                $can_view_datafield = true;

            // If the datatype/datarecord/datafield is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't reload the datafield HTML
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !($datafield->isPublic() || $can_view_datafield) || !$can_edit_datarecord )
                throw new ODRForbiddenException();
            // --------------------


            $return['d'] = array(
                'html' => self::GetDisplayData($search_key, $datarecord_id, 'datafield', $datafield_id, $request),
            );
        }
        catch (\Exception $e) {
            $source = 0xc28be446;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders the HTML required to edit datafield values for a given record.
     *
     * @param string $search_key
     * @param integer $initial_datarecord_id  The datarecord that originally requested this Edit mode render
     * @param string $template_name           One of 'default', 'child_datarecord', or 'datafield'
     * @param integer $target_id              If 'default', then $target_id should be a...TODO
     *                                        If 'child', then $target_id should be a child/linked datatype id
     *                                        if 'datafield', then $target_id should be a datafield id
     * @param Request $request
     *
     * @throws ODRException
     *
     * @return string
     */
    private function GetDisplayData($search_key, $initial_datarecord_id, $template_name, $target_id, Request $request)
    {
        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');
        /** @var DatarecordInfoService $dri_service */
        $dri_service = $this->container->get('odr.datarecord_info_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');

        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_theme = $em->getRepository('ODRAdminBundle:Theme');


        // Load all permissions for this user
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
        $datatype_permissions = $user_permissions['datatypes'];
        $datafield_permissions = $user_permissions['datafields'];

        // Going to need this a lot...
        $datatree_array = $dti_service->getDatatreeArray();


        // ----------------------------------------
        // Load required objects based on parameters
        $is_top_level = 1;

        /** @var DataRecord $datarecord */
        $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($initial_datarecord_id);
        $grandparent_datarecord = $datarecord->getGrandparent();

        /** @var DataType $datatype */
        $datatype = null;
        /** @var Theme $theme */
        $theme = null;

        /** @var DataType|null $child_datatype */
        $child_datatype = null;
        /** @var DataFields|null $datafield */
        $datafield = null;
        $datafield_id = null;


        // Don't allow a child reload request for a top-level datatype
        if ($template_name == 'child' && $datarecord->getDataType()->getId() == $target_id)
            $template_name = 'default';


        if ($template_name == 'default') {
            $datatype = $datarecord->getDataType();

            $grandparent_datarecord = $datarecord->getGrandparent();
            if ( $grandparent_datarecord->getId() !== $datarecord->getId() )
                $is_top_level = 0;

            $theme = $repo_theme->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
        }
        else if ($template_name == 'child') {
            $is_top_level = 0;

            $child_datatype = $repo_datatype->find($target_id);
            $theme = $repo_theme->findOneBy( array('dataType' => $child_datatype->getId(), 'themeType' => 'master') );

            // Need to determine the top-level datatype to be able to load all necessary data for rendering this child datatype
            if ( isset($datatree_array['descendant_of'][ $child_datatype->getId() ]) && $datatree_array['descendant_of'][ $child_datatype->getId() ] !== '' ) {
                $grandparent_datatype_id = $dti_service->getGrandparentDatatypeId($child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
            }
            else if ( isset($datatree_array['linked_from'][ $child_datatype->getId() ]) && in_array($datarecord->getDataType()->getId(), $datatree_array['linked_from'][ $child_datatype->getId() ]) ) {
                $grandparent_datatype_id = $dti_service->getGrandparentDatatypeId($datarecord->getDataType()->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
            }
            else {
                throw new ODRException('Unable to locate grandparent datatype for datatype '.$child_datatype->getId());
            }
        }
        else if ($template_name == 'datafield') {
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($target_id);
            $datafield_id = $target_id;

            $child_datatype = $datafield->getDataType();
            $theme = $repo_theme->findOneBy( array('dataType' => $child_datatype->getId(), 'themeType' => 'master') );

            $grandparent_datatype_id = $dti_service->getGrandparentDatatypeId($datarecord->getDataType()->getId());
            $datatype = $repo_datatype->find($grandparent_datatype_id);
        }


        // ----------------------------------------
        // Grab all datarecords "associated" with the desired datarecord...
        $datarecord_array = $dri_service->getDatarecordArray($grandparent_datarecord->getId());

        // Grab all datatypes associated with the desired datarecord
        // NOTE - specifically doing it this way because $dti_service->getDatatypeArrayByDatarecords() won't load datatype entries if datarecord doesn't have child/linked datatypes
        $include_links = true;
        $associated_datatypes = $dti_service->getAssociatedDatatypes(array($datatype->getId()), $include_links);
        $datatype_array = $dti_service->getDatatypeArray($associated_datatypes);

        // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
        $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


        // ----------------------------------------
        // "Inflate" the currently flattened $datarecord_array and $datatype_array...needed so that render plugins for a datatype can also correctly render that datatype's child/linked datatypes
        $stacked_datarecord_array = array();
        $stacked_datatype_array = array();
        if ($template_name == 'default') {
            $stacked_datarecord_array[ $datarecord->getId() ] = $dri_service->stackDatarecordArray($datarecord_array, $datarecord->getId());
            $stacked_datatype_array[ $datatype->getId() ] = $dti_service->stackDatatypeArray($datatype_array, $datatype->getId(), $theme->getId());
        }
        else if ($template_name == 'child') {
            $stacked_datarecord_array[ $initial_datarecord_id ] = $dri_service->stackDatarecordArray($datarecord_array, $initial_datarecord_id);
            $stacked_datatype_array[ $child_datatype->getId() ] = $dti_service->stackDatatypeArray($datatype_array, $child_datatype->getId(), $theme->getId());
        }

        // ----------------------------------------
        // Render the requested version of this page
        $templating = $this->get('templating');

        $html = '';
        if ($template_name == 'default') {
            // ----------------------------------------
            // Need to determine ids and names of datatypes this datarecord can link to
            $query = $em->createQuery(
               'SELECT ancestor.id AS ancestor_id, ancestor_meta.shortName AS ancestor_name, descendant.id AS descendant_id, descendant_meta.shortName AS descendant_name
                FROM ODRAdminBundle:DataTypeMeta AS ancestor_meta
                JOIN ODRAdminBundle:DataType AS ancestor WITH ancestor_meta.dataType = ancestor
                JOIN ODRAdminBundle:DataTree AS dt WITH dt.ancestor = ancestor
                JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
                JOIN ODRAdminBundle:DataTypeMeta AS descendant_meta WITH descendant_meta.dataType = descendant
                WHERE dtm.is_link = 1 AND (ancestor.id = :datatype_id OR descendant.id = :datatype_id)
                AND ancestor_meta.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND descendant.deletedAt IS NULL AND descendant_meta.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId()) );
            $results = $query->getArrayResult();

            $linked_datatype_ancestors = array();
            $linked_datatype_descendants = array();
            foreach ($results as $result) {
                if ( $result['ancestor_id'] == $datatype->getId() )
                    $linked_datatype_descendants[ $result['descendant_id'] ] = $result['descendant_name'];
                else if ( $result['descendant_id'] == $datatype->getId() )
                    $linked_datatype_ancestors[ $result['ancestor_id'] ] = $result['ancestor_name'];
            }

            // ----------------------------------------
            // Remove ids/names of datatypes this datarecord can link to if the datatype doesn't have a table theme
            $disabled_datatype_links = array();
            foreach ($linked_datatype_descendants as $dt_id => $dt_name) {
                //
                $has_table_theme = false;
                if ( isset($datatype_array[$dt_id]) ) {
                    foreach ($datatype_array[$dt_id]['themes'] as $num => $t) {
                        if ($t['themeType'] == 'table')
                            $has_table_theme = true;
                    }
                }

                if (!$has_table_theme) {
                    $disabled_datatype_links[$dt_id] = $dt_name;
                    unset( $linked_datatype_descendants[$dt_id] );
                }
            }
            foreach ($linked_datatype_ancestors as $dt_id => $dt_name) {
                // $datatype_array won't have data on an "ancestor" datatype, so have to load data for each of them from the cache...
                $anc_dt_data = $dti_service->getDatatypeArray(array($dt_id));

                $has_table_theme = false;
                foreach ($anc_dt_data[$dt_id]['themes'] as $num => $t) {
                    if ( $t['themeType'] == 'table' )
                        $has_table_theme = true;
                }

                if (!$has_table_theme) {
                    $disabled_datatype_links[$dt_id] = $dt_name;
                    unset( $linked_datatype_ancestors[$dt_id] );
                }
            }


            // ----------------------------------------
            // Generate a csrf token for each of the datarecord/datafield pairs
            $token_list = self::generateCSRFTokens($datatype_array, $datarecord_array);

            $html = $templating->render(
                'ODRAdminBundle:Edit:edit_ajax.html.twig',
                array(
                    'search_key' => $search_key,

                    'datatype_array' => $stacked_datatype_array,
                    'datarecord_array' => $stacked_datarecord_array,
                    'theme_id' => $theme->getId(),

                    'initial_datatype_id' => $datatype->getId(),
                    'initial_datarecord_id' => $datarecord->getId(),

                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'linked_datatype_ancestors' => $linked_datatype_ancestors,
                    'linked_datatype_descendants' => $linked_datatype_descendants,
                    'disabled_datatype_links' => $disabled_datatype_links,

                    'is_top_level' => $is_top_level,
                    'token_list' => $token_list,
                )
            );
        }
        else if ($template_name == 'child') {

            // Need to find the ThemeDatatype entry for this child datatype
            $theme_datatype = null;
            $parent_datatype = $datarecord->getDataType();
            $parent_theme = $repo_theme->findOneBy( array('dataType' => $parent_datatype->getId(), 'themeType' => 'master') );

//print 'parent_datatype: '.$parent_datatype->getId();
//print '<pre>'.print_r($datatype_array, true).'</pre>';  exit();

            foreach ($datatype_array[ $parent_datatype->getId() ]['themes'][ $parent_theme->getId() ]['themeElements'] as $te_num => $te) {
                if ( isset($te['themeDataType']) ) {
                    foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                        if ( $tdt['dataType']['id'] == $child_datatype->getId() ) {
                            $theme_datatype = $tdt;
                            break;
                        }
                    }
                }

                if ($theme_datatype !== null)
                    break;
            }

            if ($theme_datatype == null)
                throw new ODRException('Unable to locate theme_datatype entry for child datatype '.$child_datatype->getId());

            $is_link = $theme_datatype['is_link'];
            $display_type = $theme_datatype['display_type'];
            $multiple_allowed = $theme_datatype['multiple_allowed'];

            // Generate a csrf token for each of the datarecord/datafield pairs
            $token_list = self::generateCSRFTokens($datatype_array, $datarecord_array);

            $html = $templating->render(
                'ODRAdminBundle:Edit:edit_childtype_reload.html.twig',
                array(
                    'datatype_array' => $stacked_datatype_array,
                    'datarecord_array' => $stacked_datarecord_array,
                    'theme_id' => $theme->getId(),

                    'target_datatype_id' => $child_datatype->getId(),
                    'parent_datarecord_id' => $datarecord->getId(),

                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'is_top_level' => 0,
                    'is_link' => $is_link,
                    'display_type' => $display_type,
                    'multiple_allowed' => $multiple_allowed,

                    'token_list' => $token_list,
                )
            );
        }
        else if ($template_name == 'datafield') {

            // Generate a csrf token for each of the datarecord/datafield pairs
            $token_list = self::generateCSRFTokens($datatype_array, $datarecord_array);

            // Extract all needed arrays from $datatype_array and $datarecord_array
            $datatype = $datatype_array[ $child_datatype->getId() ];
            $datarecord = $datarecord_array[ $initial_datarecord_id ];

            $datafield = null;
            foreach ($datatype_array[ $child_datatype->getId() ]['themes'][ $theme->getId() ]['themeElements'] as $te_num => $te) {
                if ( isset($te['themeDataFields']) ) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {

                        if ( isset($tdf['dataField']) && $tdf['dataField']['id'] == $datafield_id ) {
                            $datafield = $tdf['dataField'];
                            break;
                        }
                    }
                    if ($datafield !== null)
                        break;
                }
            }

            if ( $datafield == null )
                throw new ODRException('Unable to locate array entry for datafield '.$datafield_id);


            $html = $templating->render(
                'ODRAdminBundle:Edit:edit_datafield.html.twig',
                array(
                    'datatype' => $datatype,
                    'datarecord' => $datarecord,
                    'datafield' => $datafield,

                    'force_image_reload' => true,

                    'token_list' => $token_list,
                )
            );
        }

        return $html;
    }


    /**
     * Generates a CSRF token for every datarecord/datafield pair in the provided arrays.
     *
     * @param array $datatype_array    @see parent::getDatatypeData()
     * @param array $datarecord_array  @see parent::getDatarecordData()
     *
     * @return array
     */
    private function generateCSRFTokens($datatype_array, $datarecord_array)
    {
        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManager $token_generator */
        $token_generator = $this->get('security.csrf.token_manager');

        $token_list = array();

        foreach ($datarecord_array as $dr_id => $dr) {
            if ( !isset($token_list[$dr_id]) )
                $token_list[$dr_id] = array();

            $dt_id = $dr['dataType']['id'];

            if ( !isset($datatype_array[$dt_id]) )
                continue;

            foreach ($datatype_array[$dt_id]['themes'] as $theme_id => $theme) {
                if ( $theme['themeType'] !== 'master' )
                    continue;

                foreach ($theme['themeElements'] as $te_num => $te) {
                    if ( isset($te['themeDataFields']) ) {
                        foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                            if ( !isset($tdf['dataField']) ) {
                                // Don't throw an exception if the datafield entry in the array doesn't exist...it just means that the user can't see that datafield, so therefore no need for a csrf token
                            }
                            else {
                                $df_id = $tdf['dataField']['id'];
                                $typeclass = $tdf['dataField']['dataFieldMeta']['fieldType']['typeClass'];

                                $token_id = $typeclass.'Form_'.$dr_id.'_'.$df_id;

                                $token_list[$dr_id][$df_id] = $token_generator->getToken($token_id)->getValue();
                            }
                        }
                    }
                }
            }
        }

        return $token_list;
    }


    /**
     * Given a datarecord and datafield, re-render and return the html for files uploaded to that datafield.
     *
     * @param integer $datafield_id  The database id of the DataField inside the DataRecord to re-render.
     * @param integer $datarecord_id The database id of the DataRecord to re-render
     * @param Request $request
     *
     * @return Response
     */
    public function reloadfiledatafieldAction($datafield_id, $datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $can_view_datatype = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_view']) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
                $can_view_datarecord = true;

            $can_edit_datarecord = false;
            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_edit']) )
                $can_edit_datarecord = true;

            $can_view_datafield = false;
            if ( isset($datafield_permissions[$datafield_id]) && isset($datafield_permissions[$datafield_id]['view']) )
                $can_view_datafield = true;

            // If the datatype/datarecord/datafield is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't reload the datafield HTML
            if ( !($datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord) || !($datafield->isPublic() || $can_view_datafield) || !$can_edit_datarecord )
                throw new ODRForbiddenException();
            // --------------------

            // Don't run if the datafield isn't a file datafield
            if ( $datafield->getFieldType()->getTypeClass() !== 'File' )
                throw new ODRBadRequestException('Datafield is not of a File Typeclass');


            // Load all files uploaded to this datafield
            $query = $em->createQuery(
               'SELECT f, fm, f_cb
                FROM ODRAdminBundle:File AS f
                JOIN f.fileMeta AS fm
                JOIN f.createdBy AS f_cb
                WHERE f.dataRecord = :datarecord_id AND f.dataField = :datafield_id
                AND f.deletedAt IS NULL AND fm.deletedAt IS NULL'
            )->setParameters( array('datarecord_id' => $datarecord->getId(), 'datafield_id' => $datafield->getId()) );
            $results = $query->getArrayResult();

            $file_list = array();
            foreach ($results as $num => $result) {
                $file = $result;
                $file['fileMeta'] = $result['fileMeta'][0];
                $file['createdBy'] = $pm_service->cleanUserData($result['createdBy']);

                $file_list[$num] = $file;
            }

            // Render and return the HTML for the list of files
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Edit:edit_file_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'files' => $file_list,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xe33cd134;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders the edit form for a DataRecord if the user has the requisite permissions.
     * 
     * @param integer $datarecord_id The database id of the DataRecord the user wants to edit
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     * @param Request $request
     * 
     * @return Response
     */
    public function editAction($datarecord_id, $search_key, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $session = $request->getSession();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            $pm_service = $this->container->get('odr.permissions_management_service');



            // Get Record In Question
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ( $datarecord == null )
                throw new ODRNotFoundException('Datarecord');

            // TODO - not accurate, technically...
            if ($datarecord->getProvisioned() == true)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();


            // Grab the theme to use to display this
            // TODO - alternate themes?
            // Currently only Master themes are used for Edit.
            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);
            $datafield_permissions = $pm_service->getDatafieldPermissions($user);
            $can_edit_datarecord = $pm_service->checkDatatypePermission($user, $datatype_id, 'dr_edit');

            // Ensure user has permissions to be doing this
            // TODO Confirm that can_edit_record supersedes all others.
            /*
            if (
                !($datatype->isPublic() || $can_view_datatype)
                || !($datarecord->isPublic() || $can_view_datarecord)
                || !$can_edit_datarecord
            )
                throw new ODRForbiddenException();
            */

            // Ensure user has permissions to be doing this
            if (!$can_edit_datarecord)
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // If this datarecord is being viewed from a search result list...
            $datarecord_list = '';
            $encoded_search_key = '';
            if ($search_key !== '') {
                // ...attempt to grab the list of datarecords from that search result
                $data = parent::getSavedSearch($em, $user, $datatype_permissions, $datafield_permissions, $datatype->getId(), $search_key, $request);
                $encoded_search_key = $data['encoded_search_key'];
                $datarecord_list = $data['datarecord_list'];

                if (!$data['redirect'] && $encoded_search_key !== '' && $datarecord_list === '') {
                    // Some sort of error encounted...bad search query, invalid permissions, or empty datarecord list
                    /** @var SearchController $search_controller */
                    $search_controller = $this->get('odr_search_controller', $request);
                    return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
                }
                else if ($data['redirect']) {
                    $url = $this->generateUrl('odr_record_edit', array('datarecord_id' => $datarecord_id, 'search_key' => $encoded_search_key, 'offset' => 1));
                    return parent::searchPageRedirect($user, $url);
                }
            }


            // ----------------------------------------
            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];

            // Locate a sorted list of datarecords for search_header.html.twig if possible
            if ( $session->has('stored_tab_data') && $odr_tab_id !== '' ) {
                // Prefer the use of the sorted lists created during usage of the datatables plugin over the default list created during searching
                $stored_tab_data = $session->get('stored_tab_data');

                if ( isset($stored_tab_data[$odr_tab_id]) && isset($stored_tab_data[$odr_tab_id]['datarecord_list']) ) {
                    $dr_list = explode(',', $stored_tab_data[$odr_tab_id]['datarecord_list']);
                    if ( !in_array($datarecord->getId(), $dr_list) ) {
                        // There's some sort of mismatch between the URL the user wants and the data stored by the tab id...wipe the tab data and just use the search results
                        unset( $stored_tab_data[$odr_tab_id] );
                    }
                    else {
                        // Otherwise, use the sorted list stored in the user's session
                        $datarecord_list = $stored_tab_data[$odr_tab_id]['datarecord_list'];

                        // Grab start/length from the datatables state object if it exists
                        if ( isset($stored_tab_data[$odr_tab_id]['state']) ) {
                            $start = intval($stored_tab_data[$odr_tab_id]['state']['start']);
                            $length = intval($stored_tab_data[$odr_tab_id]['state']['length']);

                            // Calculate which page datatables says it's on
                            $datatables_page = 0;
                            if ($start > 0)
                                $datatables_page = $start / $length;
                            $datatables_page++;

                            // If the offset doesn't match the page, update it
                            if ( $offset !== '' && intval($offset) !== intval($datatables_page) ) {
                                $new_start = strval( (intval($offset) - 1) * $length );

                                $stored_tab_data[$odr_tab_id]['state']['start'] = $new_start;
                                $session->set('stored_tab_data', $stored_tab_data);
                            }
                        }
                    }
                }
            }


            // ----------------------------------------
            // Determine whether this is a top-level datatype...if not, then the "Add new Datarecord" button in edit_header.html.twig needs to be disabled
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            $is_top_level = 1;
            if ( !in_array($datatype_id, $top_level_datatypes) )
                $is_top_level = 0;


            // Build an array of values to use for navigating the search result list, if it exists
            $search_header = parent::getSearchHeaderValues(
                $datarecord_list,
                $datarecord->getId(),
                $request
            );

            $router = $this->get('router');
            $templating = $this->get('templating');

            $redirect_path = $router->generate('odr_record_edit', array('datarecord_id' => 0));
            $record_header_html = $templating->render(
                'ODRAdminBundle:Edit:edit_header.html.twig',
                array(
                    'datatype_permissions' => $datatype_permissions,
                    'datarecord' => $datarecord,
                    'datatype' => $datatype,

                    'is_top_level' => $is_top_level,

                    // values used by search_header.html.twig 
                    'search_key' => $encoded_search_key,
                    'offset' => $offset,
                    'page_length' => $search_header['page_length'],
                    'next_datarecord' => $search_header['next_datarecord'],
                    'prev_datarecord' => $search_header['prev_datarecord'],
                    'search_result_current' => $search_header['search_result_current'],
                    'search_result_count' => $search_header['search_result_count'],
                    'redirect_path' => $redirect_path,
                )
            );

            $record_page_html = self::GetDisplayData($encoded_search_key, $datarecord->getId(), 'default', $datarecord->getId(), $request);      // TODO - replace the second $datarecord->getId() with $original_datarecord->getId()?

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $record_header_html.$record_page_html,
            );

            // Store which datarecord to scroll to if returning to the search results list
            $session->set('scroll_target', $datarecord->getId());
        }
        catch (\Exception $e) {
            $source = 0x409f64ee;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds an array of all prior values of the given datafield, to serve as a both display of field history and a reversion dialog.
     *
     * @param integer $datarecord_id
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function getfieldhistoryAction($datarecord_id, $datafield_id, Request $request)
    {
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new ODRNotImplementedException();

            // ----------------------------------------
            // Get Entity Manager and setup repositories
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                return parent::deletedEntityError('Datarecord');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                return parent::deletedEntityError('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() !== null)
                return parent::deletedEntityError('Datatype');


            // ----------------------------------------
            // Ensure user has permissions to be doing this
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                return parent::permissionDeniedError('You need to be a super-admin to view datafield history, for now');    // TODO - less restrictive requirements?

            // Ensure user has permissions to be doing this
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dr_edit' ])) )
                return parent::permissionDeniedError("edit");
            // ----------------------------------------


            // ----------------------------------------
            // Don't check field history of certain fieldtypes
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass == 'File' || $typeclass == 'Image' || $typeclass == 'Markdown' || $typeclass == 'Radio')
                throw new \Exception('Unable to view history of a '.$typeclass.' datafield, for now');


            // Grab all fieldtypes that the datafield has been
            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows
            $query = $em->createQuery(
               'SELECT DISTINCT(ft.typeClass) AS type_class
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
                WHERE df = :df_id'
            )->setParameters( array('df_id' => $datafield->getId()) );
            $results = $query->getArrayResult();

            $all_typeclasses = array();
            foreach ($results as $result) {
                $typeclass = $result['type_class'];

                if ( $typeclass !== 'File' && $typeclass !== 'Image' && $typeclass !== 'Markdown' && $typeclass !== 'Radio' )
                    $all_typeclasses[] = $typeclass;
            }


            // Grab all values that the datafield has had across all fieldtypes
            $historical_values = array();
            foreach ($all_typeclasses as $num => $typeclass) {
                $query = $em->createQuery(
                   'SELECT e.value AS value, ft.typeName AS typename, e.created AS created, created_by.firstName, created_by.lastName, created_by.username
                    FROM ODRAdminBundle:'.$typeclass.' AS e
                    JOIN ODRAdminBundle:FieldType AS ft WITH e.fieldType = ft
                    JOIN ODROpenRepositoryUserBundle:User AS created_by WITH e.createdBy = created_by
                    WHERE e.dataRecord = :datarecord_id AND e.dataField = :datafield_id'
                )->setParameters( array('datarecord_id' => $datarecord->getId(), 'datafield_id' => $datafield->getId()) );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $value = $result['value'];
                    $created = $result['created'];
                    $typename = $result['typename'];

                    $user_string = $result['username'];
                    if ( $result['firstName'] !== '' && $result['lastName'] !== '' )
                        $user_string = $result['firstName'].' '.$result['lastName'];

                    $historical_values[] = array('value' => $value, 'user' => $user_string, 'created' => $created, 'typeclass' => $typeclass, 'typename' => $typename);
                }
            }

            $em->getFilters()->enable('softdeleteable');    // Re-enable the softdeleteable filter


            // ----------------------------------------
            // Sort array from earliest date to latest date
            usort($historical_values, function ($a, $b) {
                // Sort by display order first if possible
                $interval = date_diff($a['created'], $b['created']);
                if ( $interval->invert == 0 )
                    return -1;
                else
                    return 1;
            });


            // ----------------------------------------
            // Use the resulting keys of the array after the sort as version numbers
            foreach ($historical_values as $num => $data)
                $historical_values[$num]['version'] = ($num+1);

//print_r($historical_values);
//exit();

            // Generate a csrf token to use if the user wants to revert back to an earlier value
            $current_typeclass = $datafield->getFieldType()->getTypeClass();

            /** @var \Symfony\Component\Security\Csrf\CsrfTokenManager $token_generator */
            $token_generator = $this->get('security.csrf.token_manager');

            $token_id = $current_typeclass.'Form_'.$datarecord->getId().'_'.$datafield->getId();
            $csrf_token = $token_generator->getToken($token_id)->getValue();


            // Render the dialog box for this request
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Edit:field_history_dialog_form.html.twig',
                    array(
                        'historical_values' => $historical_values,

                        'datarecord' => $datarecord,
                        'datafield' => $datafield,
                        'current_typeclass' => $current_typeclass,

                        'csrf_token' => $csrf_token,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x29534288935 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
