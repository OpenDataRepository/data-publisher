<?php

/**
 * Open Data Repository Data Publisher
 * Radio Options Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * There are enough controller actions specific to radio options that it makse sense to put them in
 * their own controller.
 */

namespace ODR\AdminBundle\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\DatatypeModifiedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneTemplateService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
// Symfony
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;
use Doctrine\DBAL\Connection as DBALConnection;


class RadioOptionsController extends ODRCustomController
{

    /**
     * Gets all RadioOptions associated with a DataField, for display in the datafield properties
     * area.
     *
     * @param integer $datafield_id The database if of the DataField to grab RadioOptions from.
     * @param Request $request
     *
     * @return Response
     */
    public function getradiooptionlistAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CloneTemplateService $clone_template_service */
            $clone_template_service = $this->container->get('odr.clone_template_service');
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( is_null($datafield) )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ( !is_null($grandparent_datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Grandparent Datatype');


            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to load radio options for a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();

            // If this is a derived field...
            $can_modify_template = false;
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( $permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                    $can_modify_template = true;

                // Not throwing exceptions here, because want to open the dialog in read-only mode
                //  if the user doesn't have permissions to modify the template
            }
            else if ( $datafield->getIsMasterField() ) {
                // Ensure this variable remains accurate if the user is attempting to modify a master
                //  datafield
                $can_modify_template = true;
            }
            // --------------------

            // If this is getting called on a derived field...
            $out_of_sync = false;
            if ( $is_derived_field ) {
                // ...then the modal should not allow users to edit if the relevant datafields are
                //  out of sync
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    $out_of_sync = true;
            }


            // ----------------------------------------
            // Locate cached array entries
            $datatype_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't need links
            $df_array = $datatype_array[$datatype->getId()]['dataFields'][$datafield->getId()];

            // Render the template
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:radio_option_dialog_form.html.twig',
                    array(
                        'datafield' => $df_array,

                        'is_derived_field' => $is_derived_field,
                        'can_modify_template' => $can_modify_template,
                        'out_of_sync' => $out_of_sync,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x71d2cc47;
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
     * Adds a new RadioOption entity to a SingleSelect, MultipleSelect, SingleRadio, or MultipleRadio DataField.
     *
     * @param integer $datafield_id The database id of the DataField to add a RadioOption to.
     * @param Request $request
     *
     * @return Response
     */
    public function addradiooptionAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CloneTemplateService $clone_template_service */
            $clone_template_service = $this->container->get('odr.clone_template_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( is_null($datafield) )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ( !is_null($grandparent_datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Grandparent Datatype');


            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to add a radio option to a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();

            // If this is a derived field...
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // If this is getting called on a derived field...
            if ( $is_derived_field ) {
                // ...then the relevant datafields need to be in sync before continuing
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    throw new ODRBadRequestException('Not allowed to create a new radio option when the derived field is out of sync with its master field');
            }


            // The request to create this radio option can come from one of three places...
            $master_radio_option = null;
            $radio_option = null;

            if ( $is_derived_field ) {
                // ...this is a request to create a radio option for a derived field, which means
                //  two of them need to get created

                // Create the master radio option first...
                $master_radio_option = $entity_create_service->createRadioOption(
                    $user,
                    $datafield->getMasterDataField(),
                    true,    // always create a new radio option
                    "New Option"
                );

                // ...then create the derived radio option
                $radio_option = $entity_create_service->createRadioOption(
                    $user,
                    $datafield,
                    true,    // always create a new radio option
                    "New Option",
                    true    // don't randomly generate a uuid for the derived radio option
                );

                // The derived radio option needs the UUID of its new master radio option
                $radio_option->setRadioOptionUuid( $master_radio_option->getRadioOptionUuid() );
                $em->persist($radio_option);
            }
            else {
                // Otherwise, this is a request to create a radio option for a field which is not
                //  derived, or a request to create a radio option directly from a template
                $radio_option = $entity_create_service->createRadioOption(
                    $user,
                    $datafield,
                    true,    // always create a new radio option
                    "New Option"
                );
            }

            // createRadioOption() does not automatically flush when $force_create == true
            $em->flush();

            // If the radio options are supposed to be sorted by name, then force a re-sort
            if ( $is_derived_field && $datafield->getMasterDataField()->getRadioOptionNameSort() === true )
                $sort_service->sortRadioOptionsByName($user, $datafield->getMasterDataField());
            if ($datafield->getRadioOptionNameSort() === true)
                $sort_service->sortRadioOptionsByName($user, $datafield);


            // ----------------------------------------
            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield);
            else if ( $is_derived_field )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield->getMasterDataField());

            // Fire off an event notifying that the modification of the datafield is done
            try {
                if ( $is_derived_field ) {
                    $event = new DatafieldModifiedEvent($datafield->getMasterDataField(), $user);
                    $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                }

                $event = new DatafieldModifiedEvent($datafield, $user);
                $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Mark the datatype as updated
            try {
                if ( $is_derived_field ) {
                    $event = new DatatypeModifiedEvent($datatype->getMasterDataType(), $user);
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }

                $event = new DatatypeModifiedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Don't need to update cached versions of datarecords or themes


            // ----------------------------------------
            // Instruct the page to reload to get the updated HTML
            $return['d'] = array(
                'datafield_id' => $datafield->getId(),
                'reload_datafield' => true,
                'radio_option_id' => $radio_option->getId(),
            );
        }
        catch (\Exception $e) {
            $source = 0x33ef7d94;
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
     * Deletes a RadioOption entity from a Datafield.
     *
     * @param integer $radio_option_id The database id of the RadioOption to delete.
     * @param Request $request
     *
     * @return Response
     */
    public function deleteradiooptionAction($radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $conn = null;

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var CloneTemplateService $clone_template_service */
            $clone_template_service = $this->container->get('odr.clone_template_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find( $radio_option_id );
            if ( is_null($radio_option) )
                throw new ODRNotFoundException('Radio Option');

            $datafield = $radio_option->getDataField();
            if ( !is_null($datafield->getDeletedAt()) )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ( !is_null($grandparent_datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Grandparent Datatype');

            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to delete a radio option from a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();

            // If this is a derived field...
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // If this is getting called on a derived field...
            if ( $is_derived_field ) {
                // ...then the relevant datafields need to be in sync before continuing
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    throw new ODRBadRequestException('Not allowed to delete a radio option when the derived field is out of sync with its master field');
            }

            // Check whether any jobs that are currently running would interfere with the deletion
            //  of this radio option
            $new_job_data = array(
                'job_type' => 'delete_radio_option',
                'target_entity' => $datafield,
            );

            $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to delete this RadioOption, as it would interfere with an already running '.$conflicting_job.' job');

            // As nice as it would be to delete any/all radio options derived from a template option
            //  here, the template synchronization needs to tell the user what will be changed, or
            //  changes get made without the user's knowledge/consent...which is bad.

            // ----------------------------------------
            $radio_options_to_delete = array($radio_option_id);
            if ( $is_derived_field ) {
                // ...if this is a request to delete a radio option from a derived field, then its
                //  master radio option also needs to be deleted
                /** @var RadioOptions $master_radio_option */
                $master_radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                    array(
                        'dataField' => $datafield->getMasterDataField(),
                        'radioOptionUuid' => $radio_option->getRadioOptionUuid(),
                    )
                );

                $radio_options_to_delete[] = $master_radio_option->getId();
            }


            // Run a query to get all of the radio selection entries that need deletion
            $query = $em->createQuery(
               'SELECT rs.id
                FROM ODRAdminBundle:RadioSelection AS rs
                WHERE rs.radioOption IN (:radio_option_list) AND rs.deletedAt IS NULL'
            )->setParameters( array('radio_option_list' => $radio_options_to_delete) );
            $results = $query->getArrayResult();

            $radio_selections_to_delete = array();
            foreach ($results as $num => $rs)
                $radio_selections_to_delete[] = $rs['id'];


            // ----------------------------------------
            // Wrap this in a transaction
            $conn = $em->getConnection();
            $conn->beginTransaction();

            // Delete all RadioOption and RadioOptionMeta entries
            $query_str =
               'UPDATE odr_radio_options AS ro, odr_radio_options_meta AS rom
                SET ro.deletedAt = NOW(), rom.deletedAt = NOW(),
                    ro.deletedBy = '.$user->getId().'
                WHERE rom.radio_option_id = ro.id AND ro.id IN (?)
                AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL';
            $parameters = array(1 => $radio_options_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);

            // Delete the RadioSelection entries
            $query_str =
               'UPDATE odr_radio_selection AS rs
                SET rs.deletedAt = NOW()
                WHERE rs.id IN (?)';
            $parameters = array(1 => $radio_selections_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);

            // No errors, commit transaction
            $conn->commit();


            // ----------------------------------------
            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield);
            else if ( $is_derived_field )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield->getMasterDataField());

            // Faster to just delete the cached list of default radio options, rather than try to
            //  figure out specifics
            $cache_service->delete('default_radio_options');

            // Fire off an event notifying that the modification of the datafield is done
            try {
                if ( $is_derived_field ) {
                    $event = new DatafieldModifiedEvent($datafield->getMasterDataField(), $user);
                    $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                }

                $event = new DatafieldModifiedEvent($datafield, $user);
                $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Mark this datatype as updated
            try {
                if ( $is_derived_field ) {
                    $event = new DatatypeModifiedEvent($datatype->getMasterDataType(), $user, true);    // need to clear the cached datarecord entries since deletion could have unselected a radio option
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }

                $event = new DatatypeModifiedEvent($datatype, $user, true);    // need to clear the cached datarecord entries since deletion could have unselected a radio option
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);

                // TODO - modify the modal so that it blocks further changes until the event finishes?
                // TODO - ...or modify the event to clear a subset of records?  neither is appealing...
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            // ----------------------------------------
            // Need to let the browser know which datafield to reload
            $return['d'] = array(
                'datafield_id' => $datafield->getId()
            );
        }
        catch (\Exception $e) {
            // Rollback if error encountered
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x00b86c51;
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
     * Renames a given RadioOption.
     *
     * @param integer $radio_option_id The database id of the RadioOption to rename.
     * @param Request $request
     *
     * @return Response
     */
    public function saveradiooptionnameAction($radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $request->request->all();
            if ( !isset($post['option_name']) )
                throw new ODRBadRequestException();
            $option_name = trim( $post['option_name'] );
            if ($option_name === '')
                throw new ODRBadRequestException("Radio Option Names can't be blank");

            // Need to unescape this value if it's coming from a wordpress install...
            $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
            if ( $is_wordpress_integrated )
                $option_name = stripslashes($option_name);


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CloneTemplateService $clone_template_service */
            $clone_template_service = $this->container->get('odr.clone_template_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($radio_option_id);
            if ( is_null($radio_option) )
                throw new ODRNotFoundException('RadioOption');

            $datafield = $radio_option->getDataField();
            if ( !is_null($datafield->getDeletedAt()) )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ( !is_null($grandparent_datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Grandparent Datatype');

            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to change the name of a radio option for a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();

            // If this is a derived field...
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // If this is getting called on a derived field...
            if ( $is_derived_field ) {
                // ...then the relevant datafields need to be in sync before continuing
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    throw new ODRBadRequestException('Not allowed to rename a radio option when the derived field is out of sync with its master field');
            }

            // Check whether any jobs that are currently running would interfere with the deletion
            //  of this datarecord
            $new_job_data = array(
                'job_type' => 'rename_radio_option',
                'target_entity' => $datafield,
            );

            $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to rename this RadioOption, as it would interfere with an already running '.$conflicting_job.' job');


            // ----------------------------------------
            // Could have to rename more than one radio option...
            $master_radio_option = null;
            $properties = array(
                'optionName' => $option_name
            );

            // The request to rename this radio option can come from one of three places...
            if ( $is_derived_field ) {
                // ...if this is a request to rename a radio option from a derived field, then its
                //  master radio option also needs to be renamed
                /** @var RadioOptions $master_radio_option */
                $master_radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                    array(
                        'dataField' => $datafield->getMasterDataField(),
                        'radioOptionUuid' => $radio_option->getRadioOptionUuid(),
                    )
                );
                $entity_modify_service->updateRadioOptionsMeta($user, $master_radio_option, $properties, true);    // don't flush immediately
            }

            // The radio option this controller action was called with should always be updated
            $entity_modify_service->updateRadioOptionsMeta($user, $radio_option, $properties);
            // Flushing here is intentional

            // If the datafield is being sorted by name, then also update the displayOrder
            $changes_made = false;
            if ( $datafield->getRadioOptionNameSort() )
                $changes_made = $sort_service->sortRadioOptionsByName($user, $datafield);
            if ( $is_derived_field && $datafield->getMasterDataField()->getRadioOptionNameSort() === true )
                $sort_service->sortRadioOptionsByName($user, $datafield->getMasterDataField());


            // ----------------------------------------
            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield);
            else if ( $is_derived_field )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield->getMasterDataField());

            // Fire off an event notifying that the modification of the datafield is done
            try {
                if ( $is_derived_field ) {
                    $event = new DatafieldModifiedEvent($datafield->getMasterDataField(), $user);
                    $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                }

                $event = new DatafieldModifiedEvent($datafield, $user);
                $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Update the cached version of the datatype...
            try {
                if ( $is_derived_field ) {
                    $event = new DatatypeModifiedEvent($datatype->getMasterDataType(), $user, true);    // need to wipe cached datarecord entries since they have radio option names
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }

                $event = new DatatypeModifiedEvent($datatype, $user, true);    // need to wipe cached datarecord entries since they have radio option names
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);

                // TODO - modify the modal so that it blocks further changes until the event finishes?
                // TODO - ...or modify the event to clear a subset of records?  neither is appealing...
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            // ----------------------------------------
            // Get the javascript to reload the datafield
            $return['d'] = array(
                'reload_modal' => $changes_made,
                'datafield_id' => $datafield->getId(),
                'radio_option_id' => $radio_option->getId(),
            );
        }
        catch (\Exception $e) {
            $source = 0xdf4e2574;
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
     * Toggles whether a given RadioOption entity is automatically selected upon creation of a
     * new datarecord.
     *
     * @param integer $radio_option_id The database id of the RadioOption to modify.
     * @param Request $request
     *
     * @return Response
     */
    public function defaultradiooptionAction($radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var CloneTemplateService $clone_template_service */
            $clone_template_service = $this->container->get('odr.clone_template_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find( $radio_option_id );
            if ( is_null($radio_option) )
                throw new ODRNotFoundException('Radio Option');

            $datafield = $radio_option->getDataField();
            if ( !is_null($datafield->getDeletedAt()) )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ( !is_null($grandparent_datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Grandparent Datatype');

            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to add a radio option to a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();

            // If this is a derived field...
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // If this is getting called on a derived field...
            if ( $is_derived_field ) {
                // ...then the relevant datafields need to be in sync before continuing
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    throw new ODRBadRequestException('Not allowed to change default status of a radio option when the derived field is out of sync with its master field');
            }

            // The request to change this propery can come from one of three places...
            if ( $is_derived_field ) {
                // ...if this request came from a derived field, then the relevant master radio option
                //  also needs to be modified
                /** @var RadioOptions $master_radio_option */
                $master_radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(
                    array(
                        'dataField' => $datafield->getMasterDataField(),
                        'radioOptionUuid' => $radio_option->getRadioOptionUuid(),
                    )
                );
                self::updateDefaultStatus($em, $entity_modify_service, $user, $master_radio_option);
            }

            // The radio option this controller action was called with should always be updated
            self::updateDefaultStatus($em, $entity_modify_service, $user, $radio_option);

            // Now that changes are made, flush the database
            $em->flush();

            // Faster to just delete the cached list of default radio options, rather than try to
            //  figure out specifics
            $cache_service->delete('default_radio_options');


            // ----------------------------------------
            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield);
            else if ( $is_derived_field )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield->getMasterDataField());

            // Don't need to fire off an event for the datafields here

            // Mark this datatype as updated
            try {
                if ( $is_derived_field ) {
                    $event = new DatatypeModifiedEvent($datatype->getMasterDataType(), $user);
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }

                $event = new DatatypeModifiedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Don't need to clear cached datarecord or theme entries
        }
        catch (\Exception $e) {
            $source = 0x5567b2f9;
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
     * Because updating a "default" radio option is a bit of a pain, it's easier to have the logic
     * off in its own function so updating a derived datafield isn't as messy
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EntityMetaModifyService $entity_modify_service
     * @param ODRUser $user
     * @param RadioOptions $radio_option
     */
    private function updateDefaultStatus($em, $entity_modify_service, $user, $radio_option)
    {
        // Save whether the given radio option is currently "default" or not
        $originally_was_default = $radio_option->getIsDefault();

        $datafield = $radio_option->getDataField();
        $field_typename = $datafield->getFieldType()->getTypeName();
        if ( $field_typename == 'Single Radio' || $field_typename == 'Single Select' ) {
            // Only one option allowed to be default for Single Radio/Select DataFields, so find
            //  the other option(s) where isDefault == true...
            $query = $em->createQuery(
               'SELECT ro
                FROM ODRAdminBundle:RadioOptions AS ro
                JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOption = ro
                WHERE rom.isDefault = 1 AND ro.dataField = :datafield
                AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield->getId()) );
            $results = $query->getResult();

            /** @var RadioOptions[] $results */
            foreach ($results as $num => $ro) {
                // ...and set them all to false
                $properties = array(
                    'isDefault' => false
                );
                $entity_modify_service->updateRadioOptionsMeta($user, $ro, $properties, true);    // don't flush immediately...
            }

            if ( $originally_was_default ) {
                // If the radio option was originally marked as default, then this request was to
                //  change it to not default...the previous foreach loop has already accomplished that
            }
            else {
                // Set this radio option as selected by default
                $properties = array(
                    'isDefault' => true
                );
                $entity_modify_service->updateRadioOptionsMeta($user, $radio_option, $properties, true);    // don't flush here...
            }
        }
        else {
            // Multiple radio options are allowed to be "default" for Multiple Radio/Select fields,
            //  so only need to toggle the "default" status for the current radio option
            $properties = array(
                'isDefault' => !$originally_was_default
            );
            $entity_modify_service->updateRadioOptionsMeta($user, $radio_option, $properties, true);    // don't flush here...
        }
    }


    /**
     * Updates the display order of the DataField's associated RadioOption entities.
     *
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function saveradiooptionorderAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $request->request->all();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( is_null($datafield) )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ( !is_null($grandparent_datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Grandparent Datatype');

            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to modify order of radio options for a '.$typeclass.' field');

            // Unlike most of the other radio option controller actions, this one doesn't need to
            //  simultaneously update the master datafield if it has one
//            $is_derived_field = false;
//            if ( !is_null($datafield->getMasterDataField()) )
//                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();

            // Not updating the master datafield also means not having to check permissions for the
            //  master datafield
            // --------------------


            // ----------------------------------------
            // If the datafield is being sorted by name...
            if ( $datafield->getRadioOptionNameSort() ) {
                // ...then do that
                $sort_service->sortRadioOptionsByName($user, $datafield);
            }
            else {
                // ...if not, then the $_POST will have the new order

                // Need to potentially look up radio options if their displayOrder gets changed
                $repo_radio_options = $em->getRepository('ODRAdminBundle:RadioOptions');

                $query = $em->createQuery(
                   'SELECT ro.id AS ro_id, rom.displayOrder
                    FROM ODRAdminBundle:RadioOptions AS ro
                    JOIN ro.radioOptionMeta AS rom
                    WHERE ro.dataField = :datafield
                    AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL'
                )->setParameters( array('datafield' => $datafield_id) );
                $results = $query->getArrayResult();

                // Organize by the id of the radio option
                $radio_option_list = array();
                foreach ($results as $result) {
                    $ro_id = $result['ro_id'];
                    $display_order = $result['displayOrder'];

                    $radio_option_list[$ro_id] = $display_order;
                }

                $changes_made = false;
                foreach ($post as $index => $radio_option_id) {
                    $ro_id = intval($radio_option_id);
                    if ( !isset($radio_option_list[$ro_id]) )
                        throw new ODRBadRequestException('Invalid radio option specified');

                    $display_order = $radio_option_list[$ro_id];
                    if ( $display_order !== $index ) {
                        // ...if a radio option is not in the correct order, then hydrate it...
                        /** @var RadioOptions $ro */
                        $ro = $repo_radio_options->find($ro_id);

                        // ...and update its displayOrder
                        $properties = array(
                            'displayOrder' => $index,
                        );
                        $entity_modify_service->updateRadioOptionsMeta($user, $ro, $properties, true);    // don't flush immediately...
                        $changes_made = true;
                    }
                }

                // Flush now that all changes have been made
                if ($changes_made)
                    $em->flush();
            }


            // ----------------------------------------
            // Mark the datatype as updated
            try {
                // Not updating the master datafield also means not having to fire events for it

                $event = new DatatypeModifiedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Don't need to update cached versions of datarecords, datafields, or themes

            // A change in order doesn't affect cached search results either
        }
        catch (\Exception $e) {
            $source = 0x89f8d46f;
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
     * Saves a series of radio options that were entered from the list interface.
     *
     * @param integer $datafield_id The database id of the DataField to add a RadioOption to.
     * @param Request $request
     *
     * @return Response
     */
    public function saveradiooptionlistAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure required options exist
            $post = $request->request->all();
            if ( !isset($post['radio_option_list']) )
                throw new ODRBadRequestException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CloneTemplateService $clone_template_service */
            $clone_template_service = $this->container->get('odr.clone_template_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( is_null($datafield) )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ( !is_null($grandparent_datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Grandparent Datatype');

            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to import radio options to a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();

            // If this is a derived field...
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // If this is getting called on a derived field...
            if ( $is_derived_field ) {
                // ...then the relevant datafields need to be in sync before continuing
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    throw new ODRBadRequestException('Not allowed to import new radio options when the derived field is out of sync with its master field');
            }

            // Want to prevent duplicate radio options from being created
            $query = $em->createQuery(
               'SELECT ro.id AS ro_id, rom.optionName
                FROM ODRAdminBundle:RadioOptions ro
                JOIN ODRAdminBundle:RadioOptionsMeta rom WITH rom.radioOption = ro
                WHERE ro.dataField = :datafield_id
                AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL'
            )->setParameters( array('datafield_id' => $datafield->getId()) );
            $results = $query->getArrayResult();

            $existing_radio_options = array();
            foreach ($results as $result) {
                $ro_id = $result['ro_id'];
                $ro_name = $result['optionName'];

                $existing_radio_options[$ro_name] = $ro_id;
            }


            // ----------------------------------------
            $radio_option_list = $post['radio_option_list'];
            if ( strlen($radio_option_list) > 0 ) {
                // Need to unescape this value if it's coming from a wordpress install...
                $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
                if ( $is_wordpress_integrated )
                    $radio_option_list = stripslashes($radio_option_list);

                $radio_option_list = explode("\n", $radio_option_list);
            }

            // Parse and process radio options
            foreach ($radio_option_list as $option_name) {
                // Remove whitespace
                $option_name = trim($option_name);
                if ( strlen($option_name) < 1 )
                    continue;

                // Add option to datafield
                if ( !isset($existing_radio_options[$option_name]) ) {

                    if ( $is_derived_field ) {
                        // ...this is a request to create a radio option for a derived field, which means
                        //  two of them need to get created

                        // Create the master radio option first...
                        $master_radio_option = $entity_create_service->createRadioOption(
                            $user,
                            $datafield->getMasterDataField(),
                            true,    // always create a new radio option
                            $option_name
                        );

                        // ...then create the derived radio option
                        $radio_option = $entity_create_service->createRadioOption(
                            $user,
                            $datafield,
                            true,    // always create a new radio option
                            $option_name,
                            true    // don't randomly generate a uuid for the derived radio option
                        );

                        // The derived radio option needs the UUID of its new master radio option
                        $radio_option->setRadioOptionUuid( $master_radio_option->getRadioOptionUuid() );
                        $em->persist($radio_option);
                    }
                    else {
                        // Otherwise, this is a request to create a radio option for a field which is not
                        //  derived, or a request to create a radio option directly from a template
                        $entity_create_service->createRadioOption(
                            $user,
                            $datafield,
                            true,    // always create a new radio option
                            $option_name
                        );
                    }

                    // Ensure that duplicate options do not get created
                    $existing_radio_options[$option_name] = 1;
                }
            }

            // createRadioOption() does not automatically flush when $force_create == true
            $em->flush();

            // If the radio options are supposed to be sorted by name, then force a re-sort
            if ( $is_derived_field && $datafield->getMasterDataField()->getRadioOptionNameSort() === true )
                $sort_service->sortRadioOptionsByName($user, $datafield->getMasterDataField());
            if ($datafield->getRadioOptionNameSort() === true)
                $sort_service->sortRadioOptionsByName($user, $datafield);


            // ----------------------------------------
            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield);
            else if ( $is_derived_field )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield->getMasterDataField());

            // Fire off an event notifying that the modification of the datafield is done
            try {
                if ( $is_derived_field ) {
                    $event = new DatafieldModifiedEvent($datafield->getMasterDataField(), $user);
                    $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                }

                $event = new DatafieldModifiedEvent($datafield, $user);
                $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Mark the datatype as updated
            try {
                if ( $is_derived_field ) {
                    $event = new DatatypeModifiedEvent($datatype->getMasterDataType(), $user);
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }

                $event = new DatatypeModifiedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            // ----------------------------------------
            // Always going to need to reload the datafield after this
            $return['d'] = array(
                'datafield_id' => $datafield->getId(),
            );
        }
        catch (\Exception $e) {
            $source = 0xfcc760f4;
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

            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


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

            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to select/deselect a radio option for a '.$typeclass.' field');

            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to make selections on a Master Template');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$permissions_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Locate the existing datarecordfield entry, or create one if it doesn't exist
            $drf = $entity_create_service->createDatarecordField($user, $datarecord, $datafield);

            // Course of action differs based on whether multiple selections are allowed
            $typename = $datafield->getFieldType()->getTypeName();

            // A RadioOption id of 0 has no effect on a Multiple Radio/Select datafield
            if ( $radio_option_id != 0 && ($typename == 'Multiple Radio' || $typename == 'Multiple Select') ) {
                // Don't care about selected status of other RadioSelection entities...
                $radio_selection = $entity_create_service->createRadioSelection($user, $radio_option, $drf);

                // Default to a value of 'selected' if an older RadioSelection entity does not exist
                $new_value = 1;
                if ($radio_selection !== null) {
                    // An older version does exist...toggle the existing value for the new value
                    if ($radio_selection->getSelected() == 1)
                        $new_value = 0;
                }

                // Update the RadioSelection entity to match $new_value
                $properties = array('selected' => $new_value);
                $entity_modify_service->updateRadioSelection($user, $radio_selection, $properties);
            }
            else if ($typename == 'Single Radio' || $typename == 'Single Select') {
                // Probably need to change selected status of at least one other RadioSelection entity...
                /** @var RadioSelection[] $radio_selections */
                $radio_selections = $repo_radio_selection->findBy(
                    array(
                        'dataRecordFields' => $drf->getId()
                    )
                );

                foreach ($radio_selections as $rs) {
                    if ( $radio_option_id != $rs->getRadioOption()->getId() ) {
                        if ($rs->getSelected() == 1) {
                            // Deselect all RadioOptions that are selected, and are not the one the
                            //  user wants to be selected
                            $properties = array('selected' => 0);
                            $entity_modify_service->updateRadioSelection($user, $rs, $properties, true);    // should only be one, technically...
                        }
                    }
                }

                // If the user selected something other than "<no option selected>"...
                if ($radio_option_id != 0) {
                    // ...locate the RadioSelection entity the user wanted to set to selected
                    $radio_selection = $entity_create_service->createRadioSelection($user, $radio_option, $drf);

                    // ...ensure it's selected
                    $properties = array('selected' => 1);
                    $entity_modify_service->updateRadioSelection($user, $radio_selection, $properties, true);    // flushing doesn't help...
                }

                // Flush now that all the changes have been made
                $em->flush();
            }
            else {
                // No point doing anything if not a radio fieldtype
                throw new ODRBadRequestException('EditController::radioselectionAction() called on Datafield that is not a Radio FieldType');
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
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
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
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

        }
        catch (\Exception $e) {
            $source = 0x01019cfb;
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
