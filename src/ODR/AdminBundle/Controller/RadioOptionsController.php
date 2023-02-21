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
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
// Symfony
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;


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

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
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

            // Since re-ordering radio options is permissible, this controller action needs to be
            //  permitted as well
//            if ( !is_null($datafield->getMasterDataField()) )
//                throw new ODRBadRequestException('Not allowed to load radio options for a datafield derived from a template');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Locate cached array entries
            $datatype_array = $dbi_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't need links
            $df_array = $datatype_array[$datatype->getId()]['dataFields'][$datafield->getId()];

            // Render the template
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:radio_option_dialog_form.html.twig',
                    array(
                        'datafield' => $df_array,
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

            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');


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
            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to add a radio option to a datafield derived from a template');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Create a new RadioOption
            $force_create = true;
            $option_name = "Option";
            $radio_option = $ec_service->createRadioOption($user, $datafield, $force_create, $option_name);

            // Creating a new RadioOption requires an update of the "master_revision" property of
            //  the datafield it got added to
            if ( $datafield->getIsMasterField() )
                $emm_service->incrementDatafieldMasterRevision($user, $datafield, true);    // don't flush immediately...

            // createRadioOption() does not automatically flush when $force_create == true
            $em->flush();
            $em->refresh($radio_option);

            // If the datafield is sorting its radio options by name, then force a re-sort of all
            //  of this datafield's radio options
            if ($datafield->getRadioOptionNameSort())
                $sort_service->sortRadioOptionsByName($user, $datafield);


            // ----------------------------------------
            // Mark the datatype as updated
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
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

            // Do need to clear some search cache entries however
            $search_cache_service->onDatafieldModify($datafield);


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
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');


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


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to delete a radio option from a '.$typeclass.' field');
            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to delete a radio option from a datafield derived from a template');


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
            // Wrap this in a transaction
            $conn = $em->getConnection();
            $conn->beginTransaction();

            // Delete all radio selection entities attached to the radio option
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:RadioSelection AS rs
                SET rs.deletedAt = :now
                WHERE rs.radioOption = :radio_option_id AND rs.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'radio_option_id' => $radio_option_id
                )
            );
            $updated = $query->execute();


            // Delete the radio option and its meta entry
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:RadioOptionsMeta AS rom
                SET rom.deletedAt = :now
                WHERE rom.radioOption = :radio_option_id AND rom.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'radio_option_id' => $radio_option_id
                )
            );
            $updated = $query->execute();

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:RadioOptions AS ro
                SET ro.deletedBy = :user, ro.deletedAt = :now
                WHERE ro = :radio_option_id AND ro.deletedAt IS NULL'
            )->setParameters(
                array(
                    'user' => $user->getId(),
                    'now' => new \DateTime(),
                    'radio_option_id' => $radio_option_id
                )
            );
            $updated = $query->execute();

            // No errors, commit transaction
            $conn->commit();


            // ----------------------------------------
            // Deleting a new RadioOption requires an update of the "master_revision" property of
            //  the datafield it got deleted from
            if ( $datafield->getIsMasterField() )
                $emm_service->incrementDatafieldMasterRevision($user, $datafield, true);    // don't flush immediately

            // Faster to just delete the cached list of default radio options, rather than try to
            //  figure out specifics
            $cache_service->delete('default_radio_options');

            // Mark this datatype as updated
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatatypeModifiedEvent($datatype, $user, true);    // need to clear the cached datarecord entries since deletion could have unselected a radio option
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Delete any cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);


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
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');


            // Grab necessary objects
            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['option_name']) )
                throw new ODRBadRequestException();
            $option_name = trim( $post['option_name'] );
            if ($option_name === '')
                throw new ODRBadRequestException("Radio Option Names can't be blank");


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


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to change the name of a radio option for a '.$typeclass.' field');
            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to change the name of a radio option for a datafield derived from a template');


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
            // Update the radio option's name
            $properties = array(
                'optionName' => trim($post['option_name'])
            );
            $emm_service->updateRadioOptionsMeta($user, $radio_option, $properties);

            // If the datafield is being sorted by name, then also update the displayOrder
            $changes_made = false;
            if ( $datafield->getRadioOptionNameSort() )
                $changes_made = $sort_service->sortRadioOptionsByName($user, $datafield);


            // ----------------------------------------
            // Update the cached version of the datatype...
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatatypeModifiedEvent($datatype, $user, true);    // need to clear the cached datarecord entries since they have the radio option name
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Delete any cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);


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
     * Toggles whether a given RadioOption entity is automatically selected upon creation of a new datarecord.
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
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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
                throw new ODRBadRequestException('Unable to set a default radio option on a '.$typeclass.' field');
            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to set a default radio option on a datafield derived from a template');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Save whether the given radio option is currently "default" or not
            $originally_was_default = $radio_option->getIsDefault();

            $field_typename = $datafield->getFieldType()->getTypeName();
            if ( $field_typename == 'Single Radio' || $field_typename == 'Single Select' ) {
                // Only one option allowed to be default for Single Radio/Select DataFields, find the other option(s) where isDefault == true
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
                    $properties = array(
                        'isDefault' => false
                    );
                    $emm_service->updateRadioOptionsMeta($user, $ro, $properties, true);    // don't flush immediately...
                }

                if ( $originally_was_default ) {
                    // If the radio option was originally marked as default, then this request was to
                    //  change it to not default...the previous for loop has already accomplished that
                }
                else {
                    // Set this radio option as selected by default
                    $properties = array(
                        'isDefault' => true
                    );
                    $emm_service->updateRadioOptionsMeta($user, $radio_option, $properties, true);    // don't flush here...
                }
            }
            else {
                // Multiple options allowed as defaults, toggle default status of current radio option
                $properties = array(
                    'isDefault' => !$originally_was_default
                );
                $emm_service->updateRadioOptionsMeta($user, $radio_option, $properties, true);    // don't flush here...
            }


            // Now that changes are made, flush the database
            $em->flush();

            // Faster to just delete the cached list of default radio options, rather than try to
            //  figure out specifics
            $cache_service->delete('default_radio_options');


            // ----------------------------------------
            // Mark this datatype as updated
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
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
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            $post = $request->request->all();
//print_r($post);  exit();

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


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Ensure the datatype has a master theme...
            $theme_service->getDatatypeMasterTheme($datatype->getId());

            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to modify order of radio options for a '.$typeclass.' field');

            // Re-ordering radio options in a derived datafield is permissible...doing so doesn't
            //  fundamentally change content
//            if ( !is_null($datafield->getMasterDataField()) )
//                throw new ODRBadRequestException('Not allowed to modify order of radio options for a datafield derived from a template');


            // ----------------------------------------
            // Store whether the datafield is sorting by name or not
            $sort_by_name = $datafield->getRadioOptionNameSort();

            // Load all RadioOption and RadioOptionMeta entities for this datafield
            $query = $em->createQuery(
               'SELECT ro, rom
                FROM ODRAdminBundle:RadioOptions AS ro
                JOIN ro.radioOptionMeta AS rom
                WHERE ro.dataField = :datafield
                AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            /** @var RadioOptions[] $results */
            $results = $query->getResult();

            // Organize by the id of the radio option
            /** @var RadioOptions[] $radio_option_list */
            $radio_option_list = array();
            foreach ($results as $result) {
                $ro_id = $result->getId();
                $radio_option_list[$ro_id] = $result;
            }


            if ($sort_by_name) {
                // Sort the radio options by name
                $sort_service->sortRadioOptionsByName($user, $datafield);
            }
            else {
                // Look to the $_POST for the new order
                $changes_made = false;
                foreach ($post as $index => $radio_option_id) {
                    $ro_id = intval($radio_option_id);
                    if ( !isset($radio_option_list[$ro_id]) )
                        throw new ODRBadRequestException('Invalid radio option specified');

                    $ro = $radio_option_list[$ro_id];
                    if ( $ro->getDisplayOrder() !== $index ) {
                        // This radio option should be in a different spot
                        $properties = array(
                            'displayOrder' => $index,
                        );
                        $emm_service->updateRadioOptionsMeta($user, $ro, $properties, true);    // don't flush immediately...
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
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
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

            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');


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
            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to import radio options to a datafield derived from a template');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            $radio_option_list = array();
            if ( strlen($post['radio_option_list']) > 0 )
                $radio_option_list = preg_split("/\n/", $post['radio_option_list']);

            // Parse and process radio options
            $processed_options = array();
            foreach ($radio_option_list as $option_name) {
                // Remove whitespace
                $option_name = trim($option_name);

                // ensure length > 0
                if (strlen($option_name) < 1)
                    continue;

                // Add option to datafield
                if ( !in_array($option_name, $processed_options) ) {
                    // Create a new RadioOption
                    $force_create = true;
                    $ec_service->createRadioOption(
                        $user,
                        $datafield,
                        $force_create,
                        $option_name
                    );

                    array_push($processed_options, $option_name);
                }
            }


            // ----------------------------------------
            // Creating a new RadioOption requires an update of the "master_revision" property of
            //  the datafield it got added to
            if ( $datafield->getIsMasterField() )
                $emm_service->incrementDatafieldMasterRevision($user, $datafield, true);    // don't flush immediately...

            // createRadioOption() does not automatically flush when $force_create == true
            $em->flush();


            // If the datafield is sorting its radio options by name, then re-sort all of this
            //  datafield's radio options again
            if ($datafield->getRadioOptionNameSort())
                $sort_service->sortRadioOptionsByName($user, $datafield);


            // ----------------------------------------
            // Mark the datatype as updated
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatatypeModifiedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Also need to clear a few search cache entries
            $search_cache_service->onDatafieldModify($datafield);


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

            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


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

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Locate the existing datarecordfield entry, or create one if it doesn't exist
            $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

            // Course of action differs based on whether multiple selections are allowed
            $typename = $datafield->getFieldType()->getTypeName();

            // A RadioOption id of 0 has no effect on a Multiple Radio/Select datafield
            if ( $radio_option_id != 0 && ($typename == 'Multiple Radio' || $typename == 'Multiple Select') ) {
                // Don't care about selected status of other RadioSelection entities...
                $radio_selection = $ec_service->createRadioSelection($user, $radio_option, $drf);

                // Default to a value of 'selected' if an older RadioSelection entity does not exist
                $new_value = 1;
                if ($radio_selection !== null) {
                    // An older version does exist...toggle the existing value for the new value
                    if ($radio_selection->getSelected() == 1)
                        $new_value = 0;
                }

                // Update the RadioSelection entity to match $new_value
                $properties = array('selected' => $new_value);
                $emm_service->updateRadioSelection($user, $radio_selection, $properties);
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
                            $emm_service->updateRadioSelection($user, $rs, $properties, true);    // should only be one, technically...
                        }
                    }
                }

                // If the user selected something other than "<no option selected>"...
                if ($radio_option_id != 0) {
                    // ...locate the RadioSelection entity the user wanted to set to selected
                    $radio_selection = $ec_service->createRadioSelection($user, $radio_option, $drf);

                    // ...ensure it's selected
                    $properties = array('selected' => 1);
                    $emm_service->updateRadioSelection($user, $radio_selection, $properties, true);    // flushing doesn't help...
                }

                // Flush now that all the changes have been made
                $em->flush();
            }
            else {
                // No point doing anything if not a radio fieldtype
                throw new ODRBadRequestException('EditController::radioselectionAction() called on Datafield that is not a Radio FieldType');
            }


            // ----------------------------------------
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

            // Delete any cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);

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
