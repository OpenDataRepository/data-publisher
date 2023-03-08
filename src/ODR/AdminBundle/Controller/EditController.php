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

// Entities
use ODR\AdminBundle\Entity\Boolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
use ODR\AdminBundle\Component\Event\DatarecordDeletedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordPublicStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatarecordLinkStatusChangedEvent;
use ODR\AdminBundle\Component\Event\FileDeletedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
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
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\ODRUploadService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
use ODR\AdminBundle\Component\Utility\UserUtility;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldReloadOverrideInterface;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
// Symfony
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\FormError;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Router;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Templating\EngineInterface;


class EditController extends ODRCustomController
{

    /**
     * Creates a new top-level DataRecord in the database.
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

        // NOTE - this seems to only be used for directly creating a new "test record" from the template list

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canAddDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Determine whether this is a request to add a datarecord for a top-level datatype or not
            // Adding a top-level datarecord is different than adding a child datarecord, and the
            //  database could get messed up if the wrong controller action is used
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ( !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('EditController::adddatarecordAction() called for child datatype');


            // If this datatype is a "master template" or a "metadata datatype"...
            if ( $datatype->getIsMasterType() || !is_null($datatype->getMetadataFor()) ) {
                // ...then don't create another datarecord if the datatype already has one
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :datatype_id
                    AND dr.deletedAt IS NULL'
                )->setParameters( array('datatype_id' => $datatype->getId()) );
                $results = $query->getArrayResult();

                if ( count($results) !== 0 ) {
                    if ( !is_null($datatype->getMetadataFor()) )
                        throw new ODRBadRequestException('This Metadata Datatype already has a sample datarecord');
                    else
                        throw new ODRBadRequestException('This Master Template already has a sample datarecord');
                }
            }

            // Create a new top-level datarecord
            $datarecord = $entity_create_service->createDatarecord($user, $datatype);

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
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event.  In this case, a datarecord gets created, but the rest of the values aren't
                //  saved and the provisioned flag never gets changed to "false"...leaving the
                //  datarecord in a state that the user can't view/edit
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Datarecord is ready, remove provisioned flag
            $datarecord->setProvisioned(false);
            $em->persist($datarecord);
            $em->flush();

            // ----------------------------------------
            // Don't need to fire off a DatarecordModified event, since this new top-level record
            //  with no values in it

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'datarecord_id' => $datarecord->getId()
            );
        }
        catch (\Exception $e) {
            $source = 0x2d4d92e6;
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

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Grab needed Entities from the repository
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');

            /** @var DataRecord $parent_datarecord */
            $parent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($parent_id);
            if ($parent_datarecord == null)
                throw new ODRNotFoundException('DataRecord');

            $grandparent_datarecord = $parent_datarecord->getGrandparent();
            if ($grandparent_datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent DataRecord');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canAddDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canEditDatarecord($user, $parent_datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Determine whether this is a request to add a datarecord for a top-level datatype or not
            // Adding a child datarecord is different than adding a top-level datarecord, and the
            //  database could get messed up if the wrong controller action is used
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ( in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('EditController::addchildrecordAction() called for top-level datatype');

            // Create a new datarecord...
            $datarecord = $entity_create_service->createDatarecord($user, $datatype, true);    // don't flush until parent/grandparent is set

            // Set parent/grandparent properties so this becomes a child datarecord
            $datarecord->setGrandparent($grandparent_datarecord);
            $datarecord->setParent($parent_datarecord);
            $em->persist($datarecord);
            $em->flush();

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
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event.  In this case, a datarecord gets created, but the rest of the values aren't
                //  saved and the provisioned flag never gets changed to "false"...leaving the
                //  datarecord in a state that the user can't view/edit
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Datarecord is ready, remove provisioned flag
            $datarecord->setProvisioned(false);
            $em->persist($datarecord);
            $em->flush();


            // Need to fire off a DatarecordModified event for the parent datarecord
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordModifiedEvent($parent_datarecord, $user);
                $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            // ----------------------------------------
            // Get edit_ajax.html.twig to re-render the datarecord
            $return['d'] = array(
                'new_datarecord_id' => $datarecord->getId(),
                'datatype_id' => $datatype_id,
                'parent_id' => $parent_datarecord->getId(),
            );
        }
        catch (\Exception $e) {
            $source = 0x3d2835d5;
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
     * Deletes a Datarecord and all the related entities that also need to be deleted.
     *
     * If top-level, a URL to the search results list for the datatype is built and returned.
     *
     * If a child datarecord, or a linked datarecord being viewed from a linked ancestor, then the
     * information to reload the theme_element is returned instead.
     *
     * @param integer $datarecord_id The database id of the datarecord being deleted
     * @param bool $is_link
     * @param string $search_key
     * @param Request $request
     *
     * @return Response
     */
    public function deletedatarecordAction($datarecord_id, $is_link, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $conn = null;

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');


            // Grab the necessary entities
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('DataRecord');
            $datarecord_uuid = $datarecord->getUniqueId();

            $parent_datarecord = $datarecord->getParent();
            if ($parent_datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Parent Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatarecord($user, $parent_datarecord) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canDeleteDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // ----------------------------------------
            // Check whether any jobs that are currently running would interfere with the deletion
            //  of this datarecord
            $new_job_data = array(
                'job_type' => 'delete_datarecord',
                'target_entity' => $datarecord,
            );

            $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to delete this Datarecord, as it would interfere with an already running '.$conflicting_job.' job');


            // ----------------------------------------
            // NOTE - changes here should also be made in MassEditController::massDeleteAction()
            // ----------------------------------------


            // Store whether this was a deletion for a top-level datarecord or not
            $is_top_level = true;
            if ( $datatype->getId() !== $parent_datarecord->getDataType()->getId() )
                $is_top_level = false;

            // Also store whether this was for a linked datarecord or not
            if ( $is_link === '0' )
                $is_link = false;
            else
                $is_link = true;


            // ----------------------------------------
            // Recursively locate all children of this datarecord
            $parent_ids = array();
            $parent_ids[] = $datarecord->getId();

            $datarecords_to_delete = array();
            $datarecords_to_delete[] = $datarecord->getId();

            while ( count($parent_ids) > 0 ) {
                // Can't use the grandparent datarecord property, because this deletion request
                //  could be for a datarecord that isn't top-level
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
                    $datarecords_to_delete[] = $dr_id;
                }
            }
//print '<pre>'.print_r($datarecords_to_delete, true).'</pre>';  exit();

            // Locate all datarecords that link to any of the datarecords that will be deleted...
            //  they will need to have their cache entries rebuilt
            $query = $em->createQuery(
               'SELECT DISTINCT(gp.id) AS ancestor_id
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                JOIN ODRAdminBundle:DataRecord AS gp WITH ancestor.grandparent = gp
                WHERE ldt.descendant IN (:datarecord_ids)
                AND ldt.deletedAt IS NULL
                AND ancestor.deletedAt IS NULL AND gp.deletedAt IS NULL'
            )->setParameters( array('datarecord_ids' => $datarecords_to_delete) );
            $results = $query->getArrayResult();

            $ancestor_datarecord_ids = array();
            foreach ($results as $result)
                $ancestor_datarecord_ids[] = $result['ancestor_id'];
//print '<pre>'.print_r($ancestor_datarecord_ids, true).'</pre>';  exit();


            // If the datarecord contains any datafields that are being used as a sortfield for
            //  other datatypes, then need to clear the default sort order for those datatypes
            $query = $em->createQuery(
               'SELECT DISTINCT(l_dt.id) AS dt_id
                FROM ODRAdminBundle:DataRecord AS dr
                LEFT JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
                LEFT JOIN ODRAdminBundle:DataFields AS df WITH df.dataType = dt
                LEFT JOIN ODRAdminBundle:DataTypeSpecialFields AS dtsf WITH dtsf.dataField = df
                LEFT JOIN ODRAdminBundle:DataType AS l_dt WITH dtsf.dataType = l_dt
                WHERE dr.id IN (:datarecords_to_delete) AND dtsf.field_purpose = :field_purpose
                AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
                AND dtsf.deletedAt IS NULL AND l_dt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datarecords_to_delete' => $datarecords_to_delete,
                    'field_purpose' => DataTypeSpecialFields::SORT_FIELD
                )
            );
            $results = $query->getArrayResult();

            $datatypes_to_reset_order = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $datatypes_to_reset_order[] = $dt_id;
            }


            // ----------------------------------------
            // Since this needs to make updates to multiple tables, use a transaction
            $conn = $em->getConnection();
            $conn->beginTransaction();

            // TODO - delete datarecordfield entries as well?
            // TODO - delete radio/tagSelection entries as well?

            // ...delete all linked_datatree entries that reference these datarecords
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:LinkedDataTree AS ldt
                SET ldt.deletedAt = :now, ldt.deletedBy = :deleted_by
                WHERE (ldt.ancestor IN (:datarecord_ids) OR ldt.descendant IN (:datarecord_ids))
                AND ldt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'deleted_by' => $user->getId(),
                    'datarecord_ids' => $datarecords_to_delete
                )
            );
            $rows = $query->execute();

            // ...delete each meta entry for the datarecords to be deleted
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecordMeta AS drm
                SET drm.deletedAt = :now
                WHERE drm.dataRecord IN (:datarecord_ids)
                AND drm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datarecord_ids' => $datarecords_to_delete
                )
            );
            $rows = $query->execute();

            // ...delete all of the datarecords
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecord AS dr
                SET dr.deletedAt = :now, dr.deletedBy = :deleted_by
                WHERE dr.id IN (:datarecord_ids)
                AND dr.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'deleted_by' => $user->getId(),
                    'datarecord_ids' => $datarecords_to_delete
                )
            );
            $rows = $query->execute();

            // No error encountered, commit changes
//$conn->rollBack();
            $conn->commit();


            // -----------------------------------
            // Fire off an event notifying that this datarecord got deleted
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordDeletedEvent($datarecord_id, $datarecord_uuid, $datatype, $user);
                $dispatcher->dispatch(DatarecordDeletedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // If this was a top-level datarecord that just got deleted...
            if ( $is_top_level ) {
                // ...then ensure no other datarecords think they're still linked to it
                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new DatarecordLinkStatusChangedEvent($ancestor_datarecord_ids, $datatype, $user);
                    $dispatcher->dispatch(DatarecordLinkStatusChangedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }
            else {
                // ...if not, then mark this now-deleted datarecord's parent (and all its parents)
                //  as updated
                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new DatarecordModifiedEvent($parent_datarecord, $user);
                    $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }


            // ----------------------------------------
            // Reset sort order for the datatypes found earlier
            foreach ($datatypes_to_reset_order as $num => $dt_id)
                $cache_service->delete('datatype_'.$dt_id.'_record_order');

            // NOTE: don't actually need to delete cached graphs for the datatype...the relevant
            //  plugins will end up requesting new graphs without the files for the deleted records


            // ----------------------------------------
            // The proper return value depends on whether this was a top-level datarecord or not
            if ($is_top_level && !$is_link) {
                // Determine whether any datarecords of this datatype remain
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.deletedAt IS NULL AND dr.dataType = :datatype'
                )->setParameters(array('datatype' => $datatype->getId()));
                $remaining = $query->getArrayResult();

                // Determine where to redirect since the current datarecord is now deleted
                $url = '';
                if ($search_key == '') {
                    $search_key = $search_key_service->encodeSearchKey(
                        array(
                            'dt_id' => $datatype->getId()
                        )
                    );
                }

                if ( count($remaining) > 0 ) {
                    // Return to the list of datarecords since at least one datarecord of this datatype still exists
                    $preferred_theme_id = $theme_info_service->getPreferredTheme($user, $datatype->getId(), 'search_results');
                    $url = $this->generateUrl(
                        'odr_search_render',
                        array(
                            'search_theme_id' => $preferred_theme_id,
                            'search_key' => $search_key
                        )
                    );
                }
                else {
                    // ...otherwise, return to the list of datatypes
                    $url = $this->generateUrl('odr_list_types', array('section' => 'databases'));
                }

                $return['d'] = $url;
            }
            else {
                // This is either a child datarecord, or a request to delete a datarecord from a
                //  parent datarecord that links to it

                // Get edit_ajax.html.twig to re-render the datarecord
                $return['d'] = array(
                    'datatype_id' => $datatype->getId(),
                    'parent_id' => $parent_datarecord->getId(),
                );
            }
        }
        catch (\Exception $e) {
            // Don't commit changes if any error was encountered...
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x82bb1bb6;
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Grab the necessary entities
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

            // Files that aren't done encrypting shouldn't be modified
            if ($file->getEncryptKey() === '')
                throw new ODRNotFoundException('File');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------

            // Delete the decrypted version of this file from the server, if it exists
            $file_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/';
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
            $file_meta->setDeletedAt(new \DateTime());
            $em->persist($file_meta);

            $file->setDeletedBy($user);
            $file->setDeletedAt(new \DateTime());
            $em->persist($file);

            $em->flush();


            // -----------------------------------
            // Fire off an event notifying that the modification of the datafield is done
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatafieldModifiedEvent($datafield, $user);
                $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Need to fire off a DatarecordModified event because a file got deleted
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordModifiedEvent($datarecord, $user);
                $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            // ----------------------------------------
            // This is wrapped in a try/catch block because any uncaught exceptions thrown by the
            //  event subscribers will prevent file encryption otherwise...
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new FileDeletedEvent($file_id, $datafield, $datarecord, $user);
                $dispatcher->dispatch(FileDeletedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't particularly want to rethrow the error since it'll interrupt
                //  everything downstream of the event (such as file encryption...), but
                //  having the error disappear is less ideal on the dev environment...
                if ( $this->container->getParameter('kernel.environment') === 'dev' )
                    throw $e;
            }


            // -----------------------------------
            // If this datafield only allows a single upload, tell edit_ajax.html.twig to show
            //  the upload button again since this datafield's only file just got deleted
            if ( !$datafield->getAllowMultipleUploads() )
                $return['d'] = array('need_reload' => true);

        }
        catch (\Exception $e) {
            $source = 0x08e2fe10;
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

            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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

            // Files that aren't done encrypting shouldn't be modified
            if ($file->getEncryptKey() === '')
                throw new ODRNotFoundException('File');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // TODO - should there be a permission to be able to change public status of files/images?  (would technically work for radio options/tags too...)
            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Toggle public status of specified file...
            $public_date = null;
            if ( $file->isPublic() ) {
                // Make the file non-public
                $public_date = new \DateTime('2200-01-01 00:00:00');

                $properties = array('publicDate' => $public_date);
                $emm_service->updateFileMeta($user, $file, $properties);

                // Delete the decrypted version of the file, if it exists
                $file_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/';
                $filename = 'File_'.$file_id.'.'.$file->getExt();
                $absolute_path = realpath($file_upload_path).'/'.$filename;

                if ( file_exists($absolute_path) )
                    unlink($absolute_path);
            }
            else {
                // Make the file public
                $public_date = new \DateTime();

                $properties = array('publicDate' => $public_date);
                $emm_service->updateFileMeta($user, $file, $properties);


                // ----------------------------------------
                // Need to decrypt the file...generate the url for cURL to use
                $url = $this->generateUrl('odr_crypto_request', array(), UrlGeneratorInterface::ABSOLUTE_URL);

                $redis_prefix = $this->container->getParameter('memcached_key_prefix');    // debug purposes only
                $pheanstalk = $this->get('pheanstalk');
                $api_key = $this->container->getParameter('beanstalk_api_key');

                // Determine the filename after decryption
                $target_filename = 'File_'.$file_id.'.'.$file->getExt();

                // Schedule a beanstalk job to start decrypting the file
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        "object_type" => 'File',
                        "object_id" => $file_id,
                        "crypto_type" => 'decrypt',

                        "local_filename" => $target_filename,
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

            // Reload the file entity so its associated meta entry gets updated in the EntityManager
            $em->refresh($file);

            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'is_public' => $file->isPublic(),
                'public_date' => $public_date->format('Y-m-d'),
            );


            // ----------------------------------------
            // Fire off an event notifying that the modification of the datafield is done
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatafieldModifiedEvent($datafield, $user);
                $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Need to fire off a DatarecordModified event because a file's public status was changed
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordModifiedEvent($datarecord, $user);
                $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // TODO - implement searching based on public status of file/image?
        }
        catch (\Exception $e) {
            $source = 0x5201b0cd;
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

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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

            // Images that aren't done encrypting shouldn't be downloaded
            if ($image->getEncryptKey() == '')
                throw new ODRNotFoundException('Image');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // TODO - should there be a permission to be able to change public status of files/images?  (would technically work for radio options/tags too...)
            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
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
                $emm_service->updateImageMeta($user, $image, $properties);

                // Delete the decrypted version of the image and all of its children, if any of them exist
                foreach ($all_images as $img) {
                    $image_upload_path = $this->getParameter('odr_web_directory').'/uploads/images/';
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
                $emm_service->updateImageMeta($user, $image, $properties);

                // Immediately decrypt the image and all of its children...don't need to specify
                //  a filename because the images are guaranteed to be public
                foreach ($all_images as $img)
                    $crypto_service->decryptImage($img->getId());
            }


            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'is_public' => $image->isPublic(),
                'public_date' => $public_date->format('Y-m-d'),
            );


            // ----------------------------------------
            // Fire off an event notifying that the modification of the datafield is done
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatafieldModifiedEvent($datafield, $user);
                $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Need to fire off a DatarecordModified event because an image's public status got changed
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordModifiedEvent($datarecord, $user);
                $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // TODO - implement searching based on public status of file/image?
        }
        catch (\Exception $e) {
            $source = 0xf051d2f4;
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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

            // Images that aren't done encrypting shouldn't be modified
            if ($image->getEncryptKey() == '')
                throw new ODRNotFoundException('Image');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Grab all alternate sizes of the original image (thumbnail is only current one) and remove them
            /** @var Image[] $images */
            $images = $repo_image->findBy( array('parent' => $image->getId()) );
            foreach ($images as $img) {
                // Ensure no decrypted version of any of the thumbnails exist on the server
                $local_filepath = $this->getParameter('odr_web_directory').'/uploads/images/Image_'.$img->getId().'.'.$img->getExt();
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);

                // Delete the alternate sized image from the database
                $img->setDeletedBy($user);
                $img->setDeletedAt(new \DateTime());
                $em->persist($img);
            }

            // Ensure no decrypted version of the original image exists on the server
            $local_filepath = $this->getParameter('odr_web_directory').'/uploads/images/Image_'.$image->getId().'.'.$image->getExt();
            if ( file_exists($local_filepath) )
                unlink($local_filepath);

            // Delete the image's meta entry
            $image_meta = $image->getImageMeta();
            $image_meta->setDeletedAt(new \DateTime());
            $em->persist($image_meta);

            // Delete the image
            $image->setDeletedBy($user);
            $image->setDeletedAt(new \DateTime());
            $em->persist($image);

            $em->flush();


            // If this datafield only allows a single upload, tell edit_ajax.html.twig to show the
            //  the upload button again since this datafield's only image got deleted
            if ($datafield->getAllowMultipleUploads() == "0")
                $return['d'] = array('need_reload' => true);


            // ----------------------------------------
            // Fire off an event notifying that the modification of the datafield is done
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatafieldModifiedEvent($datafield, $user);
                $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Need to fire off a DatarecordModified event because an image got deleted
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordModifiedEvent($datarecord, $user);
                $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

        }
        catch (\Exception $e) {
            $source = 0xee8e8649;
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

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ODRUploadService $upload_service */
            $upload_service = $this->container->get('odr.upload_service');


            // Grab the necessary entities
            /** @var Image $image */
            $image = $repo_image->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');

            $datafield = $image->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');

            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Images that aren't done encrypting shouldn't be modified
            if ($image->getEncryptKey() == '')
                throw new ODRNotFoundException('Image');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Determine how long it's been since the creation of this image...
            $create_date = $image->getCreated();
            $current_date = new \DateTime();
            $interval = $create_date->diff($current_date);

            // TODO - duration in which image can be rotated without creating new entry?
            // Replace existing image if it has existed on the server for less than 30 minutes
            $overwrite_existing = false;
            if ($interval->days == 0 && $interval->h == 0 && $interval->i <= 30)
                $overwrite_existing = true;


            // ----------------------------------------
            // Going to need an array of the original image and each of its resized children...
            /** @var Image[] $relevant_images */
            $relevant_images = $em->getRepository('ODRAdminBundle:Image')->findBy(
                array(
                    'parent' => $image->getId()
                )
            );
            $relevant_images[] = $image;

            // Ensure all resized versions of the original image are deleted off the server...the
            //  original image will get moved into ODR's tmp directory, so it doesn't need to be
            //  deleted here
            foreach ($relevant_images as $i) {
                if ( !$i->getOriginal() ) {
                    $path = $this->getParameter('odr_web_directory').'/'.$i->getLocalFileName();
                    if ( file_exists($path) )
                        unlink($path);
                }
            }


            // ----------------------------------------
            // Ensure the original image is decrypted
            // TODO - decrypts non-public images to web-accessible directory, but it'll get moved almost immediately?
            $image_path = $crypto_service->decryptImage($image_id);
            $original_filename = $image->getOriginalFileName();

            // Move the decrypted version into ODR's temporary directory, using the filename it was
            //  originally uploaded with
            $dirname = $this->getParameter('odr_tmp_directory').'/user_'.$user->getId();
            if ( !file_exists($dirname) )
                mkdir($dirname);
            rename($image_path, $dirname.'/'.$original_filename);
            // Store the path to the image in the tmp directory
            $new_image_path = $dirname.'/'.$original_filename;


            // Rotate the image on the server...apparently a positive number means counter-clockwise
            //  rotation with imagerotate()
            $degrees = 90;
            if ($direction == 1)
                $degrees = -90;

            $im = null;
            switch ( strtolower($image->getExt()) ) {
                case 'gif':
                    $im = imagecreatefromgif($new_image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagegif($im, $new_image_path);
                    break;
                case 'png':
                    $im = imagecreatefrompng($new_image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagepng($im, $new_image_path);
                    break;
                case 'jpg':
                case 'jpeg':
                    $im = imagecreatefromjpeg($new_image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagejpeg($im, $new_image_path);
                    break;
            }
            imagedestroy($im);


            // ----------------------------------------
            if ( $overwrite_existing ) {
                // This image is being overwritten
                $upload_service->replaceExistingImage($image, $new_image_path, $user);
            }
            else {
                // This image is not being overwritten, so create a new one
                $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);
                $new_image = $upload_service->uploadNewImage($new_image_path, $user, $drf);

                // Still need to copy several properties from the previous image to the new one
                $props = array(
                    'displayOrder' => $image->getDisplayorder(),
                    'publicDate' => $image->getPublicDate(),
                    'caption' => $image->getCaption(),
                    'externalId' => $image->getExternalId(),
                );
                $emm_service->updateImageMeta($user, $new_image, $props);

                // Mark the original image and its resizes as deleted
                foreach ($relevant_images as $i) {
                    $i->setDeletedBy($user);
                    $i->setDeletedAt(new \DateTime());
                    $em->persist($i);

                    if ( $i->getOriginal() ) {
                        $im = $i->getImageMeta();
                        $im->setDeletedAt(new \DateTime());
                        $em->persist($im);
                    }
                }
                $em->flush();
            }

            // Don't need to fire off events here...ODRUploadService handles it
        }
        catch (\Exception $e) {
            $source = 0x4093b173;
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

            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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

            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Ensure that the provided image ids are all from the same datarecordfield, and that
            //  all images from that datarecordfield are listed in the post
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
            $changes_made = false;
            foreach ($post as $index => $image_id) {
                $image = $all_images[$image_id];

                if ( $image->getDisplayorder() != $index ) {
                    $properties = array('display_order' => $index);
                    $emm_service->updateImageMeta($user, $image, $properties, true);    // don't flush immediately...
                    $changes_made = true;
                }
            }

            if ($changes_made)
                $em->flush();


            // ----------------------------------------
            // While this is a pretty weak reason to require a DatarecordModified event, it does
            //  technically make a change to the cached entries, and any remote copies maintained
            //  over RSS are technically out of sync until they redownload
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordModifiedEvent($datarecord, $user);
                $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }
        }
        catch (\Exception $e) {
            $source = 0x8b01c7e4;
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

            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->canChangePublicStatus($user, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Toggle the public status of the datarecord
            if ( $datarecord->isPublic() ) {
                // Make the datarecord non-public
                $public_date = new \DateTime('2200-01-01 00:00:00');

                $properties = array('publicDate' => $public_date);
                $emm_service->updateDatarecordMeta($user, $datarecord, $properties);
            }
            else {
                // Make the datarecord non-public
                $public_date = new \DateTime();

                $properties = array('publicDate' => $public_date);
                $emm_service->updateDatarecordMeta($user, $datarecord, $properties);
            }


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


            // ----------------------------------------
            $return['d'] = array(
                'public' => $datarecord->isPublic(),
                'datarecord_id' => $datarecord_id,
            );
        }
        catch (\Exception $e) {
            $source = 0x3df683c4;
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
     * Parses a $_POST request to update the contents of a datafield.
     * File and Image uploads are handled by @see FlowController
     * Changes to RadioSelections are handled by EditController::radioselectionAction(), and changes
     * to Tags are handled by TagsController::tagselectionAction()
     *
     * @param integer $datarecord_id  The datarecord of the storage entity being modified
     * @param integer $datafield_id   The datafield of the storage entity being modified
     * @param Request $request
     *
     * @return Response
     */
    public function updateAction($datarecord_id, $datafield_id, Request $request)
    {
        // TODO - This should be changed to a transaction....

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get the Entity Manager
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();

            // If the datafield is set to prevent user edits, then prevent this controller action
            //  from making a change to it
            if ( $datafield->getPreventUserEdits() )
                throw new ODRForbiddenException("The Datatype's administrator has blocked changes to this Datafield.");
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
                    // The Markdown fieldtype can't have its value changed
                    // Radio and Tag fieldtypes aren't supposed to be updated here ever
                    // Files/Images might be permissible in the future
                    throw new ODRBadRequestException('EditController::updateAction() called for a Datafield using the '.$typeclass.' FieldType');
                    break;
            }

            // Load the existing storage entity if it exists, or create a new one if it doesn't
            $storage_entity = $ec_service->createStorageEntity($user, $datarecord, $datafield);
            $old_value = $storage_entity->getValue();


            // ----------------------------------------
            // Create a new form for this storage entity and bind it to the request
            $form = $this->createForm($form_class,
                $form_object,
                array(
                    'datarecord_id' => $datarecord->getId(),
                    'datafield_id' => $datafield->getId()
                )
            );
            $form->handleRequest($request);

            if ($form->isSubmitted()) {

                if ($form->isValid()) {
                    $new_value = $form_object->getValue();

                    // TODO - this doesn't allow users to "update" a derived field unless they change the contents of the source field...
                    if ($old_value !== $new_value) {

                        // If the datafield is marked as unique...
                        if ( $datafield->getIsUnique() ) {
                            // ...determine whether the new value is a duplicate of a value that
                            //  already exists, ignoring the current datarecord
                            if ( $sort_service->valueAlreadyExists($datafield, $new_value, $datarecord) )
                                throw new ODRConflictException('Another Datarecord already has the value "'.$new_value.'" stored in this Datafield.');
                        }

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

                        // Save the value...this will also fire a PostUpdate event, which will cause
                        //  any datafields derived from this particular field to update if needed
                        $emm_service->updateStorageEntity($user, $storage_entity, array('value' => $new_value));


                        // TODO Create mirror function for datatypes that have metadata


                        // Update related records/datatypes depending on internal reference name
                        $flush_required = false;
                        switch($storage_entity->getDataField()->getDataFieldMeta()->getInternalReferenceName()) {
                            // Update parent datatype name automatically
                            case 'datatype_name':
                                // Check if this is a metadata_for datatype
                                $ancestor_datatype = $storage_entity->getDataRecord()->getDataType()->getGrandparent();

                                if($related_datatype = $ancestor_datatype->getMetadataFor()) {
                                    // TODO - coerce value to string.  Possibly needed
                                    // clone datatypemeta
                                    $datatype_meta = $related_datatype->getDataTypeMeta();

                                    $new_meta = clone $datatype_meta;
                                    $new_meta->setLongName($storage_entity->getValue());
                                    $new_meta->setShortName($storage_entity->getValue());
                                    $new_meta->setCreatedBy($user);
                                    $new_meta->setUpdatedBy($user);

                                    $em->persist($new_meta);
                                    $em->remove($datatype_meta);

                                    $flush_required = true;
                                }


                                break;

                            // Update parent datatype description
                            case 'datatype_description':
                                $ancestor_datatype = $storage_entity->getDataRecord()->getDataType()->getGrandparent();

                                if($related_datatype = $ancestor_datatype->getMetadataFor()) {
                                    // TODO - coerce value to string.  Possibly needed
                                    // clone datatypemeta
                                    $datatype_meta = $related_datatype->getDataTypeMeta();

                                    $new_meta = clone $datatype_meta;
                                    $new_meta->setDescription($storage_entity->getValue());
                                    $new_meta->setCreatedBy($user);
                                    $new_meta->setUpdatedBy($user);

                                    $em->persist($new_meta);
                                    $em->remove($datatype_meta);

                                    $flush_required = true;
                                }
                                break;

                            default:
                                break;
                        }

                        if($flush_required && isset($related_datatype)) {
                            $em->flush();
                            // Need to flush datatype cache
                            $cache_service->delete('cached_datatype_'.$related_datatype->getId());
                        }

                        // ----------------------------------------
                        // Fire off an event notifying that the modification of the datafield is done
                        try {
                            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                            /** @var EventDispatcherInterface $event_dispatcher */
                            $dispatcher = $this->get('event_dispatcher');
                            $event = new DatafieldModifiedEvent($datafield, $user);
                            $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't want to rethrow the error since it'll interrupt everything after this
                            //  event
//                            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                                throw $e;
                        }

                        // Mark this datarecord as updated
                        try {
                            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                            /** @var EventDispatcherInterface $event_dispatcher */
                            $dispatcher = $this->get('event_dispatcher');
                            $event = new DatarecordModifiedEvent($datarecord, $user);
                            $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
                        }
                        catch (\Exception $e) {
                            // ...don't want to rethrow the error since it'll interrupt everything after this
                            //  event
//                            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                                throw $e;
                        }
                    }
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($form);
                    throw new ODRException($error_str);
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x294a59c5;
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
     * Given a child datatype id and a datarecord, re-render and return the html for that child datatype.
     *
     * @param int $theme_element_id        The theme element this child/linked datatype is in
     * @param int $parent_datarecord_id    The parent datarecord of the child/linked datarecord
     *                                       that is getting reloaded
     * @param int $top_level_datarecord_id The datarecord currently being viewed in edit mode,
     *                                       required incase the user tries to reload B or C in the
     *                                       structure A => B => C => ...
     * @param Request $request
     *
     * @return Response
     */
    public function reloadchildAction($theme_element_id, $parent_datarecord_id, $top_level_datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            // This is only valid if the theme element has a child/linked datatype
            if ( $theme_element->getThemeDataType()->isEmpty() )
                throw new ODRBadRequestException();

            $theme = $theme_element->getTheme();
            $parent_datatype = $theme->getDataType();
            $top_level_datatype = $theme->getParentTheme()->getDataType();


            /** @var DataRecord $parent_datarecord */
            $parent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($parent_datarecord_id);
            if ($parent_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            if ($parent_datarecord->getDataType()->getId() !== $parent_datatype->getId())
                throw new ODRBadRequestException();


            /** @var DataRecord $top_level_datarecord */
            $top_level_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($top_level_datarecord_id);
            if ($top_level_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            if ($top_level_datarecord->getDataType()->getId() !== $top_level_datatype->getId())
                throw new ODRBadRequestException();


            /** @var ThemeDataType $theme_datatype */
            $theme_datatype = $theme_element->getThemeDataType()->first();
            $child_datatype = $theme_datatype->getDataType();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatarecord($user, $parent_datarecord) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $child_datatype) )
                throw new ODRForbiddenException();
            // --------------------

            $return['d'] = array(
                'html' => $odr_render_service->reloadEditChildtype(
                    $user,
                    $theme_element,
                    $parent_datarecord,
                    $top_level_datarecord
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xb61ecefa;
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
     * Given a datarecord and datafield, re-render and return the html for that datafield.
     *
     * @param integer $source_datarecord_id The id of the top-level Datarecord the edit page is currently displaying
     * @param integer $datarecord_id The id of the Datarecord being re-rendered
     * @param integer $datafield_id  The id of the Datafield inside $datarecord_id to re-render
     * @param Request $request
     *
     * @return Response
     */
    public function reloaddatafieldAction($source_datarecord_id, $datarecord_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $ti_service */
            $ti_service = $this->container->get('odr.theme_info_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            if ($datarecord->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException('The given datarecord does not belong to the given datatype');


            /** @var Datarecord $source_datarecord */
            $source_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($source_datarecord_id);
            if ($source_datarecord == null)
                throw new ODRNotFoundException('Source Datarecord');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Need to locate the theme element being reloaded...
            // TODO - ODRRenderService can technically render a non-master theme for Edit mode...
            // TODO - ...though self::editAction() doesn't let it happen, yet
            $master_theme = $ti_service->getDatatypeMasterTheme($datatype->getId());
            $query = $em->createQuery(
               'SELECT te
                FROM ODRAdminBundle:Theme AS t
                JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
                JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
                WHERE t.id = :theme_id AND tdf.dataField = :datafield_id
                AND t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdf.deletedAt IS NULL'
            )->setParameters(
                array(
                    'theme_id' => $master_theme->getId(),
                    'datafield_id' => $datafield->getId()
                )
            );
            $result = $query->getResult();
            /** @var ThemeElement $theme_element */
            $theme_element = $result[0];

            $output = '';

            // Check whether the datatype is using a render plugin that wants to override the
            //  regular edit datafield reloading
            // NOTE: don't need to perform the same check for a datafield plugin, since rendering
            //  the default template will automatically trigger execution of said datafield plugin
            $query = $em->createQuery(
               'SELECT rpi
                FROM ODRAdminBundle:RenderPluginFields rpf
                JOIN ODRAdminBundle:RenderPluginMap rpm WITH rpm.renderPluginFields = rpf
                JOIN ODRAdminBundle:RenderPluginInstance rpi WITH rpm.renderPluginInstance = rpi
                JOIN ODRAdminBundle:RenderPlugin rp WITH rpi.renderPlugin = rp
                WHERE rpi.dataType = :datatype_id AND rpm.dataField = :datafield_id
                AND rp.overrideFieldReload = :override_field_reload
                AND rp.deletedAt IS NULL AND rpi.deletedAt IS NULL
                AND rpm.deletedAt IS NULL AND rpf.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datatype_id' => $datatype->getId(),
                    'datafield_id' => $datafield->getId(),
                    'override_field_reload' => true
                )
            );
            $results = $query->getResult();

            if ( !empty($results) ) {
                /** @var RenderPluginInstance $render_plugin_instance */
                $render_plugin_instance = $results[0];

                // Load the render plugin as a service
                $render_plugin_classname = $render_plugin_instance->getRenderPlugin()->getPluginClassName();
                /** @var DatafieldReloadOverrideInterface $render_plugin */
                $render_plugin = $this->container->get($render_plugin_classname);

                // Request a set of parameters from the render plugin for ODRRenderService to use
                $extra_parameters = $render_plugin->getOverrideParameters('edit', $render_plugin_instance, $datafield, $datarecord, $master_theme, $user);

                // If the render plugin is going to do something...
                if ( isset($extra_parameters['template_name']) ) {
                    // ...extract the template name and remove it from the list of parameters
                    $template_name = $extra_parameters['template_name'];
                    unset( $extra_parameters['template_name'] );

                    // Attempt to render the datafield using the given template and parameters
                    $output = $odr_render_service->reloadPluginDatafield(
                        $user,
                        $datatype,
                        $theme_element,
                        $datafield,
                        $datarecord,
                        $template_name,
                        $extra_parameters
                    );
                }
            }

            // If the datafield isn't using a render plugin, or the plugin didn't return anything...
            if ( $output === '' ) {
                // ...then use the standard edit datafield reload instead
                $output = $odr_render_service->reloadEditDatafield(
                    $user,
                    $source_datarecord->getDataType(),
                    $theme_element,
                    $datafield,
                    $datarecord
                );
            }

            $return['d'] = array(
                'html' => $output
            );
        }
        catch (\Exception $e) {
            $source = 0xc28be446;
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
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
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
                $file['createdBy'] = UserUtility::cleanUserData($result['createdBy']);

                $file_list[$num] = $file;
            }


            // Render and return the HTML for the list of files
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Edit:edit_file_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,
                        'files' => $file_list,

                        'datarecord_is_fake' => false,    // "Fake" records can't reach this, because they don't have a datarecord_id
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xe33cd134;
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
     * Renders the edit form for a DataRecord if the user has the requisite permissions.
     *
     * @param integer $datarecord_id The database id of the DataRecord the user wants to edit
     * @param integer $search_theme_id
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     * @param Request $request
     *
     * @return Response
     */
    public function editAction($datarecord_id, $search_theme_id, $search_key, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $session = $request->getSession();

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');
            /** @var ThemeInfoService $ti_service */
            $ti_service = $this->container->get('odr.theme_info_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');
            /** @var Router $router */
            $router = $this->get('router');


            // ----------------------------------------
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


            // Ensure the datatype has a "master" theme...ODRRenderService will use it by default
            // TODO - alternate themes?
            $ti_service->getDatatypeMasterTheme($datatype->getId());

            // If $search_theme_id is set...
            if ($search_theme_id != 0) {
                // ...require a search key to also be set
                if ($search_key == '')
                    throw new ODRBadRequestException();

                // ...require the referenced theme to exist
                /** @var Theme $search_theme */
                $search_theme = $em->getRepository('ODRAdminBundle:Theme')->find($search_theme_id);
                if ($search_theme == null)
                    throw new ODRNotFoundException('Search Theme');

                // ...require it to match the datatype being rendered
                if ($search_theme->getDataType()->getId() !== $datatype->getId())
                    throw new ODRBadRequestException('The given search theme does not belong to the given datatype');
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canEditDatarecord($user, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];
            else
                $odr_tab_id = $odr_tab_service->createTabId();

            // Determine whether the user has a restriction on which datarecords they can edit
            $restricted_datarecord_list = $pm_service->getDatarecordRestrictionList($user, $datatype);
            $has_search_restriction = false;
            if ( !is_null($restricted_datarecord_list) )
                $has_search_restriction = true;

            // Determine which list of datarecords to pull from the user's session
            $cookies = $request->cookies;
            $only_display_editable_datarecords = true;
            if ( $cookies->has('datatype_'.$datatype->getId().'_editable_only') )
                $only_display_editable_datarecords = $cookies->get('datatype_'.$datatype->getId().'_editable_only');


            // If this datarecord is being viewed from a search result list...
            $datarecord_list = '';
            if ($search_key !== '') {
                // Ensure the search key is valid first
                $search_key_service->validateSearchKey($search_key);
                // Determine whether the user is allowed to view this search key
                $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);
                if ($filtered_search_key !== $search_key) {
                    // User can't view the results of this search key, redirect to the one they can view
                    return $search_redirect_service->redirectToEditPage($datarecord_id, $search_theme_id, $filtered_search_key, $offset);
                }
                $search_params = $search_key_service->decodeSearchKey($search_key);

                // Ensure the tab refers to the given search key
                $expected_search_key = $odr_tab_service->getSearchKey($odr_tab_id);
                if ( $expected_search_key !== $search_key )
                    $odr_tab_service->setSearchKey($odr_tab_id, $search_key);

                // Need to ensure a sort criteria is set for this tab, otherwise the table plugin
                //  will display stuff in a different order
                $sort_datafields = array();
                $sort_directions = array();

                $sort_criteria = $odr_tab_service->getSortCriteria($odr_tab_id);
                if ( !is_null($sort_criteria) ) {
                    // Prefer the criteria from the user's session whenever possible
                    $sort_datafields = $sort_criteria['datafield_ids'];
                    $sort_directions = $sort_criteria['sort_directions'];
                }
                else if ( isset($search_params['sort_by']) ) {
                    // If the user's session doesn't have anything but the search key does, then
                    //  use that
                    foreach ($search_params['sort_by'] as $display_order => $data) {
                        $sort_datafields[$display_order] = intval($data['sort_df_id']);
                        $sort_directions[$display_order] = $data['sort_dir'];
                    }

                    // Store this in the user's session
                    $odr_tab_service->setSortCriteria($odr_tab_id, $sort_datafields, $sort_directions);
                }
                else {
                    // No criteria set...get this datatype's current list of sort fields, and convert
                    //  into a list of datafield ids for storing this tab's criteria
                    foreach ($datatype->getSortFields() as $display_order => $df) {
                        $sort_datafields[$display_order] = $df->getId();
                        $sort_directions[$display_order] = 'asc';
                    }
                    $odr_tab_service->setSortCriteria($odr_tab_id, $sort_datafields, $sort_directions);
                }

                // No problems, so get the datarecords that match the search
                $cached_search_results = $odr_tab_service->getSearchResults($odr_tab_id);
                if ( is_null($cached_search_results) ) {
                    $cached_search_results = $search_api_service->performSearch($datatype, $search_key, $user_permissions, $sort_datafields, $sort_directions);
                    $odr_tab_service->setSearchResults($odr_tab_id, $cached_search_results);
                }
                $original_datarecord_list = $cached_search_results['grandparent_datarecord_list'];


                // ----------------------------------------
                // Determine the correct list of datarecords to use for rendering...
                $datarecord_list = array();
                if ($has_search_restriction) {
                    // ...user has a restriction list, so the search header should only cycle through
                    //  the datarecords they're allowed to edit...even if there's technically more
                    //  in the search results

                    // array_flip() + isset() is orders of magnitude faster than repeated calls to in_array()
                    $datarecord_list = $original_datarecord_list;
                    $editable_datarecord_list = array_flip($restricted_datarecord_list);
                    foreach ($datarecord_list as $num => $dr_id) {
                        if ( !isset($editable_datarecord_list[$dr_id]) )
                            unset( $datarecord_list[$num] );
                    }

                    // Search header will use keys of $original_datarecord_list to determine
                    //  offset...so the array needs to be start from 0 again
                    $datarecord_list = array_values($datarecord_list);
                }
                else {
                    // ...user doesn't have a restriction list, so they can view all datarecords
                    //  in the search result
                    $datarecord_list = $original_datarecord_list;
                }


                $key = 0;
                if (!$only_display_editable_datarecords) {
                    // NOTE - intentionally using $original_datarecord_list instead of $datarecord_list
                    // The current page of editable datarecords may be completely different than the
                    //  current page of viewable datarecords
                    $key = array_search($datarecord->getId(), $original_datarecord_list);
                }
                else {
                    $key = array_search($datarecord->getId(), $datarecord_list);
                }

                // Compute which page of the search results this datarecord is on
                $page_length = $odr_tab_service->getPageLength($odr_tab_id);
                $offset = floor($key / $page_length) + 1;

                // Ensure the session has the correct offset stored
                $odr_tab_service->updateDatatablesOffset($odr_tab_id, $offset);
            }


            // ----------------------------------------
            // Determine whether this is a top-level datatype...if not, then the "Add new Datarecord" button in edit_header.html.twig needs to be disabled
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            $is_top_level = 1;
            if ( !in_array($datatype_id, $top_level_datatypes) )
                $is_top_level = 0;


            // Build an array of values to use for navigating the search result list, if it exists
            $search_header = null;
            if ($search_key !== '')
                $search_header = $odr_tab_service->getSearchHeaderValues($odr_tab_id, $datarecord->getId(), $datarecord_list);

            // Need this array to exist right now so the part that's not the search header will display
            if ( is_null($search_header) ) {
                $search_header = array(
                    'page_length' => 0,
                    'next_datarecord_id' => 0,
                    'prev_datarecord_id' => 0,
                    'search_result_current' => 0,
                    'search_result_count' => 0
                );
            }

            $redirect_path = $router->generate('odr_record_edit', array('datarecord_id' => 0));
            $record_header_html = $templating->render(
                'ODRAdminBundle:Edit:edit_header.html.twig',
                array(
                    'datatype_permissions' => $datatype_permissions,
                    'datarecord' => $datarecord,
                    'datatype' => $datatype,

                    'is_top_level' => $is_top_level,
                    'odr_tab_id' => $odr_tab_id,

                    // values used by search_header.html.twig
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $search_key,
                    'offset' => $offset,

                    'page_length' => $search_header['page_length'],
                    'next_datarecord' => $search_header['next_datarecord_id'],
                    'prev_datarecord' => $search_header['prev_datarecord_id'],
                    'search_result_current' => $search_header['search_result_current'],
                    'search_result_count' => $search_header['search_result_count'],
                    'redirect_path' => $redirect_path,
                )
            );


            // ----------------------------------------
            // Render the edit page for this datarecord
            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            $page_html = $odr_render_service->getEditHTML($user, $datarecord, $search_key, $search_theme_id);

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $record_header_html.$page_html,
            );

            // Store which datarecord to scroll to if returning to the search results list
            $session->set('scroll_target', $datarecord->getId());
        }
        catch (\Exception $e) {
            $source = 0x409f64ee;
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
     * Builds an array of all prior values of the given datafield, to serve as a both display of
     * field history and a reversion dialog.
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
            // ----------------------------------------
            // Get Entity Manager and setup repositories
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() !== null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Don't check field history of certain fieldtypes
            $invalid_typeclasses = array(
                'File' => 0,
                'Image' => 0,
                'Markdown' => 0,
                'Radio' => 0,
                'Tag' => 0
            );

            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ( isset($invalid_typeclasses[$typeclass]) )
                throw new ODRException('Unable to view history of a '.$typeclass.' datafield');


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

                if ( !isset($invalid_typeclasses[$typeclass]) )
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
                )->setParameters(
                    array(
                        'datarecord_id' => $datarecord->getId(),
                        'datafield_id' => $datafield->getId()
                    )
                );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $value = $result['value'];
                    $created = $result['created'];
                    $typename = $result['typename'];

                    $user_string = $result['username'];
                    if ( $result['firstName'] !== '' && $result['lastName'] !== '' )
                        $user_string = $result['firstName'].' '.$result['lastName'];

                    $historical_values[] = array(
                        'value' => $value,
                        'user' => $user_string,
                        'created' => $created,
                        'typeclass' => $typeclass,
                        'typename' => $typename
                    );
                }
            }

            $em->getFilters()->enable('softdeleteable');    // Don't need to load deleted rows anymore


            // ----------------------------------------
            // Sort array from earliest date to latest date
            usort($historical_values, function ($a, $b) {
                $interval = date_diff($a['created'], $b['created']);
                if ( $interval->invert == 0 )
                    return -1;
                else
                    return 1;
            });

//exit( '<pre>'.print_r($historical_values, true).'</pre>' );

            // Filter the array so it doesn't list the same value multiple times in a row
            $previous_value = null;
            foreach ($historical_values as $num => $data) {
                $current_value = $data['value'];

                if ( $previous_value !== $current_value )
                    $previous_value = $current_value;
                else
                    unset( $historical_values[$num] );
            }
            // Make the array indices contiguous again
            $historical_values = array_values($historical_values);


            // ----------------------------------------
            // Use the resulting keys of the array after the sort as version numbers
            foreach ($historical_values as $num => $data)
                $historical_values[$num]['version'] = ($num+1);

//exit( '<pre>'.print_r($historical_values, true).'</pre>' );

            // Generate a csrf token to use if the user wants to revert back to an earlier value
            $current_typeclass = $datafield->getFieldType()->getTypeClass();

            /** @var CsrfTokenManager $token_generator */
            $token_generator = $this->get('security.csrf.token_manager');

            $token_id = $current_typeclass.'Form_'.$datarecord->getId().'_'.$datafield->getId();
            $csrf_token = $token_generator->getToken($token_id)->getValue();


            // Render the dialog box for this request
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
            $source = 0xb2073584;
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
