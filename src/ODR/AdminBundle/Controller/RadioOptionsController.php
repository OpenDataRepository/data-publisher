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
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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
//                throw new ODRBadRequestException('Not allowed to load radio options for a derived field');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Locate cached array entries
            $datatype_array = $dti_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't need links
            $df_array = $datatype_array[$datatype->getId()]['dataFields'][$datafield->getId()];

            // Render the template
            $templating = $this->get('templating');
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
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
                throw new ODRBadRequestException('Not allowed to add a radio option to a derived field');


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


            // Update the cached version of the datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

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
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SearchService $search_service */
            $search_service = $this->container->get('odr.search_service');
            /** @var TrackedJobService $tj_service */
            $tj_service = $this->container->get('odr.tracked_job_service');


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
                throw new ODRBadRequestException('Not allowed to delete a radio option from a derived datafield');

            // Also prevent a radio option from being deleted if certain jobs are in progress by
            //  throwing an error
            $restricted_jobs = array('mass_edit', /*'migrate',*/ 'csv_export', 'csv_import_validate', 'csv_import');
            $tj_service->checkActiveJobs($datafield, $restricted_jobs, "Unable to delete this radio option");

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
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Wipe cached data for all the datatype's datarecords
            $dr_list = $search_service->getCachedSearchDatarecordList($grandparent_datatype->getId());
            foreach ($dr_list as $dr_id => $parent_dr_id) {
                $cache_service->delete('cached_datarecord_'.$dr_id);
                $cache_service->delete('cached_table_data_'.$dr_id);
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

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var TrackedJobService $tj_service */
            $tj_service = $this->container->get('odr.tracked_job_service');


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
                throw new ODRBadRequestException('Not allowed to change the name of a radio option for a derived field');

            // Also prevent a radio option from being renamed if certain jobs are in progress
            $restricted_jobs = array(/*'mass_edit',*/ /*'migrate',*/ 'csv_export', 'csv_import_validate', 'csv_import');
            $tj_service->checkActiveJobs($datafield, $restricted_jobs, "Unable to rename this radio option");


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


            // Update the cached version of the datatype...
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Determine whether cached entries for table themes need to get deleted...
            $delete_table_data = false;
            $typename = $datafield->getFieldType()->getTypeName();
            if ($typename == 'Single Radio' || $typename == 'Single Select')
                $delete_table_data = true;

            // Locate all datarecords that could display this radio option...
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :datatype_id AND dr.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId()) );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                // Always need to delete the cached datarecord...it has the optionName property
                $cache_service->delete('cached_datarecord_'.$result['dr_id']);

                // Only need to delete the cached table data if the datafield was a single radio or
                //  a single select
                if ($delete_table_data)
                    $cache_service->delete('cached_table_data_'.$result['dr_id']);
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
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
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
                throw new ODRBadRequestException('Not allowed to set a default radio option on a derived field');


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

            // Force an update of this datatype's cached entries
            $dti_service->updateDatatypeCacheEntry($datatype, $user);
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
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
//                throw new ODRBadRequestException('Not allowed to modify order of radio options for a derived field');


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


            // Update cached version of datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
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
                throw new ODRBadRequestException('Not allowed to import radio options to a derived field');


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


            // Update the cached version of the datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

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
}
