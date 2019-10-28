<?php

/**
 * Open Data Repository Data Publisher
 * DisplayTemplate Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The displaytemplate controller handles everything required to
 * design the long-form view and edit layout of a database record.
 * This includes but is not limited to addition, deletion, and setting
 * of position and other related properties of DataFields, ThemeElement
 * containers, and child DataTypes.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Forms
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\UpdateDataTreeForm;
use ODR\AdminBundle\Form\UpdateThemeDatatypeForm;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneTemplateService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityDeletionService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class DisplaytemplateController extends ODRCustomController
{

    /**
     * Deletes a DataField from the DataType.
     *
     * @param integer $datafield_id The database id of the Datafield to delete.
     * @param Request $request
     *
     * @return Response
     */
    public function deletedatafieldAction($datafield_id, Request $request)
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
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datatype');
            $grandparent_datatype_id = $grandparent_datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to delete a derived datafield');


            // --------------------
            // TODO - better way of handling this?
            // Prevent deletion of datafields if a csv import is in progress, as this could screw the importing over
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'csv_import', 'target_entity' => 'datatype_'.$grandparent_datatype_id, 'completed' => null) );   // TODO - not datatype_id, right?
            if ($tracked_job !== null)
                throw new ODRException('Preventing deletion of any DataField for this DataType, because a CSV Import for this DataType is in progress...');
            // Prevent deletion of datafields if it's getting migrated...
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'target_entity' => 'datafield_'.$datafield_id, 'completed' => null) );
            if ($tracked_job !== null)
                throw new ODRException('Preventing deletion of this DataField, because it is currently being migrated to another Fieldtype...');

            // Check that the datafield isn't being used for something else before deleting it
            $reason = self::canDeleteDatafield($em, $datafield);
            if ( $reason['prevent_deletion'] )
                throw new ODRBadRequestException( $reason['prevent_deletion_message'] );



            // ----------------------------------------
            /** @var EntityDeletionService $ed_service */
            $ed_service = $this->container->get('odr.entity_deletion_service');
            $ed_service->deleteDatafield($datafield, $user);

        }
        catch (\Exception $e) {
            $source = 0x4fc66d72;
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
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SearchService $search_service */
            $search_service = $this->container->get('odr.search_service');


            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find( $radio_option_id );
            if ($radio_option == null)
                throw new ODRNotFoundException('Radio Option');

            $datafield = $radio_option->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datafield_id = $datafield->getId();

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datatype');
            $grandparent_datatype_id = $grandparent_datatype->getId();


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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find( $radio_option_id );
            if ($radio_option == null)
                throw new ODRNotFoundException('Radio Option');

            $datafield = $radio_option->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

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

                // TODO - currently not allowed to remove a default option from a single radio/select once a default has been set...
                // Set this radio option as selected by default
                $properties = array(
                    'isDefault' => true
                );
                $emm_service->updateRadioOptionsMeta($user, $radio_option, $properties);    // ...flush here instead
            }
            else {
                // Multiple options allowed as defaults, toggle default status of current radio option
                $properties = array(
                    'isDefault' => true
                );
                if ($radio_option->getIsDefault() == true)
                    $properties['isDefault'] = false;

                $emm_service->updateRadioOptionsMeta($user, $radio_option, $properties);
            }


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
     * Deletes an entire DataType and all of the entities directly related to rendering it.  Unlike
     * creating a datatype, this function works for both top-level and child datatypes.
     *
     * @param integer $datatype_id The database id of the DataType to be deleted.
     * @param Request $request
     *
     * @return Response
     */
    public function deletedatatypeAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // TODO - prevent datatype deletion when called from a linked dataype?  not sure if this is possible...
            // TODO - prevent datatype deletion when jobs are in progress?

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            /** @var EntityDeletionService $ed_service */
            $ed_service = $this->container->get('odr.entity_deletion_service');
            $ed_service->deleteDatatype($datatype, $user);

        }
        catch (\Exception $e) {
            $source = 0xa6304ef8;
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
     * TODO
     *
     * @param $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function check_statusAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Check if this is a master template based datatype that is still in the creation process...
            $templating = $this->get('templating');
            $return['t'] = "html";
            if ($datatype->getSetupStep() == DataType::STATE_INITIAL && $datatype->getMasterDataType() != null) {
                // The database is still in the process of being created...return the HTML for the page that'll periodically check for progress
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Datatype:create_status_checker.html.twig',
                        array(
                            "datatype" => $datatype
                        )
                    )
                );
            }
            else {
                // Determine where to send this redirect
                if ($datatype->getMetadataFor() !== null)  {
                    // Properties datatype - redirect to properties page
                    $url =  $this->generateUrl(
                            'odr_datatype_properties',
                            array(
                                'datatype_id' => $datatype->getMetadataFor()->getId(),
                                'wizard' => 1
                            ),
                            false
                        );
                }
                else {
                    // Redirect to design
                    $url = $this->generateUrl(
                            'odr_design_master_theme',
                            array(
                                'datatype_id' => $datatype->getId(),
                            ),
                            false
                        );
                }

                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Datatype:create_status_checker_redirect.html.twig',
                        array(
                            "url" => $url
                        )
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x20fab867;
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
     * Loads and returns the DesignTemplate HTML for this DataType.
     *
     * @param integer $datatype_id The database id of the DataType to be rendered.
     * @param Request $request
     *
     * @return Response
     */
    public function designAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            if ($datatype->getId() !== $datatype->getGrandparent()->getId())
                throw new ODRBadRequestException('Directly modifying the layout of child databases is not permitted.');


            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();


            // ----------------------------------------
            // Check if this is a master template based datatype that is still in the creation process...
            if ($datatype->getSetupStep() == DataType::STATE_INITIAL && $datatype->getMasterDataType() != null) {
                // The database is still in the process of being created...return the HTML for the page that'll periodically check for progress
                $templating = $this->get('templating');
                $return['t'] = "html";
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Datatype:create_status_checker.html.twig',
                        array(
                            "datatype" => $datatype
                        )
                    )
                );
            }
            else {
                // Ensure user has permissions to be doing this
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();

                /** @var ODRRenderService $odr_render_service */
                $odr_render_service = $this->container->get('odr.render_service');
                $page_html = $odr_render_service->getMasterDesignHTML($user, $datatype);

                $return['d'] = array(
                    'datatype_id' => $datatype->getId(),
                    'html' => $page_html,
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x8ae875b2;
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
     * Saves changes made to a Datatree entity.
     *
     * @param integer $datatree_id  The id of the Datatree entity being changed
     * @param Request $request
     *
     * @return Response
     */
    public function savedatatreeAction($datatree_id, Request $request)
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


            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->find($datatree_id);
            if ($datatree == null)
                throw new ODRNotFoundException('Datatree');

            $ancestor_datatype = $datatree->getAncestor();
            if ($ancestor_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $ancestor_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure that the datatree isn't set to only allow single child/linked datarecords when it already is supporting multiple child/linked datarecords
            $parent_datatype_id = $datatree->getAncestor()->getId();
            $child_datatype_id = $datatree->getDescendant()->getId();

            $force_multiple = false;
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
                if ( isset($tmp[$ancestor_id]) ) {
                    $force_multiple = true;
                    break;
                }
                else {
                    $tmp[$ancestor_id] = 1;
                }
            }

            // Determine whether the ancestor datatype is using a sortfield from the
            //  descendant datatype
            $ancestor_datatype = $datatree->getAncestor();
            $descendant_datatype = $datatree->getDescendant();

            $affects_sortfield = false;
            if ( !is_null($ancestor_datatype->getSortField()) ) {
                $sortfield = $ancestor_datatype->getSortField();
                if ( $sortfield->getDataType()->getId() === $descendant_datatype->getId() )
                    $affects_sortfield = true;
            }


            // ----------------------------------------
            // Populate new Datatree form
            $submitted_data = new DataTreeMeta();
            $datatree_form = $this->createForm(UpdateDataTreeForm::class, $submitted_data);

            $datatree_form->handleRequest($request);
            if ( $datatree_form->isSubmitted() ) {

                // Ensure that "multiple allowed" is true if required
                if ($force_multiple)
                    $submitted_data->setMultipleAllowed(true);

                if ( $datatree_form->isValid() ) {
                    // If a value in the form changed, create a new DataTree entity to store the change
                    $properties = array(
                        'multiple_allowed' => $submitted_data->getMultipleAllowed(),
                        'is_link' => $submitted_data->getIsLink(),
                    );
                    $emm_service->updateDatatreeMeta($user, $datatree, $properties);

                    // Need to delete the cached version of the datatree array
                    $cache_service->delete('cached_datatree_array');


                    // If the ancestor datatype's sortfield belongs to the descendant datatype, and
                    //  the user is now permitting multiple links/children between ancestor and
                    //  descendant...
                    if ( $affects_sortfield && $submitted_data->getMultipleAllowed() == true ) {
                        // ...then clear the ancestor datatype's sortfield
                        $props = array('sortField' => null);
                        $emm_service->updateDatatypeMeta($user, $ancestor_datatype, $props);
                    }

                    // Then delete the cached version of the affected datatype
                    $dti_service->updateDatatypeCacheEntry($ancestor_datatype, $user);

                    // The 'is_link' or 'multiple_allowed' properties are also stored in the
                    //  cached theme entries, so they need to get rebuilt as well
                    $query = $em->createQuery(
                       'SELECT t.id AS theme_id
                        FROM ODRAdminBundle:Theme AS t
                        WHERE t.dataType = :datatype_id
                        AND t.deletedAt IS NULL'
                    )->setParameters( array('datatype_id' => $ancestor_datatype->getGrandparent()->getId()) );
                    $results = $query->getArrayResult();

                    foreach ($results as $result)
                        $cache_service->delete('cached_theme_'.$result['theme_id']);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($datatree_form);
                    throw new ODRException($error_str);
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x43a5ff6f;
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
     * Adds a new DataField to the given DataType and ThemeElement.
     *
     * @param integer $theme_element_id The database id of the ThemeElement to attach this new Datafield to
     * @param Request $request
     *
     * @return Response
     */
    public function adddatafieldAction($theme_element_id, Request $request)
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
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
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
            // Ensure this is only called on a 'master' theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Datafields can only be added to a "master" theme');

            // Ensure there's not a child or linked datatype in this theme_element before going and creating a new datafield
            /** @var ThemeDataType[] $theme_datatypes */
            $theme_datatypes = $em->getRepository('ODRAdminBundle:ThemeDataType')->findBy( array('themeElement' => $theme_element_id) );
            if ( count($theme_datatypes) > 0 )
                throw new ODRBadRequestException('Unable to add a Datafield into a ThemeElement that already has a child/linked Datatype');


            // TODO - this is currently blocked...otherwise the new datafield would get attached
            // TODO -  to a "copy" of the theme for the linked datatype...it wouldn't get located
            // TODO -  and synchronized to any other theme
            // Ensure this isn't being called on a linked datatype
            $parent_theme_datatype_id = $theme->getParentTheme()->getDataType()->getGrandparent()->getId();
            $grandparent_datatype_id = $datatype->getGrandparent()->getId();
            if ($grandparent_datatype_id !== $parent_theme_datatype_id)
                throw new ODRBadRequestException('Unable to create a new Datafield inside a Linked Datatype');


            // ----------------------------------------
            // Grab objects required to create a datafield entity
            /** @var FieldType $fieldtype */
            $fieldtype = $em->getRepository('ODRAdminBundle:FieldType')->findOneBy( array('typeName' => 'Short Text') );
            /** @var RenderPlugin $render_plugin */
            $render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->find('1');

            // Create the datafield
            $datafield = $ec_service->createDatafield($user, $datatype, $fieldtype, $render_plugin, true);    // Don't flush immediately...

            // Tie the datafield to the theme element
            $ec_service->createThemeDatafield($user, $theme_element, $datafield, true);    // Don't flush immediately...

            // A datafield was added, so any themes that use this master theme as their source
            //  need to get updated themselves
            $properties = array(
                'sourceSyncVersion' => $theme->getSourceSyncVersion() + 1
            );
            $emm_service->updateThemeMeta($user, $theme, $properties);    // Flush here


            // design_ajax.html.twig calls ReloadThemeElement()

            // Update the cached version of the datatype and the master theme
            $dti_service->updateDatatypeCacheEntry($datatype, $user);
            $theme_service->updateThemeCacheEntry($theme, $user);

            // A couple search cache entries need cleared when a datafield is created...
            $search_cache_service->onDatafieldCreate($datafield);

            // Don't need to worry about datafield permissions here, those are taken care of inside ODR_addDataField()
        }
        catch (\Exception $e) {
            $source = 0x6f6cfd5d;
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
     * Clones the properties of an existing Datafield entity into a new one.
     *
     * @param integer $theme_element_id The database id of the ThemeElement containing the Datafield
     * @param integer $datafield_id     The database id of the DataField to clone
     * @param Request $request
     *
     * @return Response
     */
    public function copydatafieldAction($theme_element_id, $datafield_id, Request $request)
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
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataFields $old_datafield */
            $old_datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($old_datafield == null)
                throw new ODRNotFoundException('Datafield');

            /** @var ThemeDataField $old_theme_datafield */
            $old_theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy(
                array(
                    'dataField' => $old_datafield->getId(),
                    'themeElement' => $theme_element->getId()
                )
            );
            if ($old_theme_datafield == null)
                throw new ODRNotFoundException('ThemeDatafield');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Don't allow cloning of a datafield outside the master theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to clone a datafield outside of a "master" theme');

            // TODO - this is currently blocked...otherwise the copied datafield would get attached
            // TODO -  to a "copy" of the theme for the linked datatype...it wouldn't get located
            // TODO -  and synchronized to any other theme
            // Ensure this isn't being called on a linked datatype
            $parent_theme_datatype_id = $theme->getParentTheme()->getDataType()->getGrandparent()->getId();
            $grandparent_datatype_id = $datatype->getGrandparent()->getId();
            if ($grandparent_datatype_id !== $parent_theme_datatype_id)
                throw new ODRBadRequestException('Unable to copy a Datafield inside a Linked Datatype');


            // TODO - allow cloning of radio and tag datafields
            if ($old_datafield->getFieldType()->getTypeClass() == 'Radio')
                throw new ODRBadRequestException('Unable to clone a Radio Datafield.');
            if ($old_datafield->getFieldType()->getTypeClass() == 'Tag')
                throw new ODRBadRequestException('Unable to clone a Tag Datafield.');

            // TODO - allow cloning of datafields using render plugins
            // Datafields being used by render plugins shouldn't be cloned...
            /** @var RenderPluginMap $rpm */
            $rpm = $em->getRepository('ODRAdminBundle:RenderPluginMap')->findOneBy( array('dataField' => $old_datafield->getId()) );
            if ($rpm != null)
                throw new ODRBadRequestException('Unable to clone a Datafield that is using, or being used by, a Render Plugin.');

            // This should not work on a datafield that is derived from a master template
            if ( !is_null($old_datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to clone a derived field');


            // ----------------------------------------
            // Clone the old datafield...
            /** @var DataFields $new_df */
            $new_df = clone $old_datafield;

            // TODO - clear other tracking/revision history properties?
            $new_df->setMasterDataField(null);

            // Ensure the "in-memory" version of $datatype knows about the new datafield
            $datatype->addDataField($new_df);
            self::persistObject($em, $new_df, $user);


            // Clone the old datafield's meta entry...
            /** @var DataFieldsMeta $new_df_meta */
            $new_df_meta = clone $old_datafield->getDataFieldMeta();
            $new_df_meta->setDataField($new_df);
            $new_df_meta->setFieldName('Copy of '.$old_datafield->getFieldName());

            // Ensure the "in-memory" version of $new_df knows about the new meta entry
            $new_df->addDataFieldMetum($new_df_meta);
            self::persistObject($em, $new_df_meta, $user);

            // Need to create the groups for the new datafield...
            $ec_service->createGroupsForDatafield($user, $new_df);


            // Clone the old datafield's theme_datafield entry...
            /** @var ThemeDataField $new_tdf */
            $new_tdf = clone $old_theme_datafield;
            $new_tdf->setDataField($new_df);
            // Intentionally not changing displayOrder...new field should appear just after the
            //  old datafield, in theory

            // Ensure the "in-memory" theme_element knows about the new theme_datafield entry
            $theme_element->addThemeDataField($new_tdf);
            self::persistObject($em, $new_tdf, $user);

            // design_ajax.html.twig calls ReloadThemeElement()

            // Updated the cached version of the datatype and the master theme
            $dti_service->updateDatatypeCacheEntry($datatype, $user);
            $theme_service->updateThemeCacheEntry($theme, $user);

            // Since a new datafield got created as part of this copy, a few search cache entries
            //  need to be cleared...
            $search_cache_service->onDatafieldCreate($new_df);

        }
        catch (\Exception $e) {
            $source = 0x3db4c5ca;
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
     * Saves and reloads the provided object from the database.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param mixed $obj
     * @param ODRUser $user
     */
    private function persistObject($em, $obj, $user)
    {
        //
        if (method_exists($obj, "setCreated"))
            $obj->setCreated(new \DateTime());
        if (method_exists($obj, "setCreatedBy"))
            $obj->setCreatedBy($user);
        if (method_exists($obj, "setUpdated"))
            $obj->setUpdated(new \DateTime());
        if (method_exists($obj, "setUpdatedBy"))
            $obj->setUpdatedBy($user);

        $em->persist($obj);
        $em->flush();
        $em->refresh($obj);
    }


    /**
     * Gets all RadioOptions associated with a DataField, for display in the datafield properties
     * area.
     *
     * @param integer $datafield_id The database if of the DataField to grab RadioOptions from.
     * @param Request $request
     *
     * @return Response
     */
    public function getradiooptionsAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
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


            // Grab radio options
            $radio_options = $datafield->getRadioOptions();

            // Render the template
            $return['t'] = 'html';
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:radio_option_list.html.twig',
                    array(
                        'datafield' => $datafield,
                        'radio_options' => $radio_options,
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
     * Renames a given RadioOption.
     *
     * @param integer $radio_option_id The database id of the RadioOption to rename.
     * @param Request $request
     *
     * @return Response
     */
    public function radiooptionnameAction($radio_option_id, Request $request)
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
            if ($radio_option == null)
                throw new ODRNotFoundException('RadioOption');

            $datafield = $radio_option->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // This should only work on a Radio field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio')
                throw new ODRBadRequestException('Unable to change the name of a radio option for a '.$typeclass.' field');
            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to change the name of a radio option for a derived field');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Update the radio option's name
            $properties = array(
                'optionName' => trim($post['option_name'])
            );
            $emm_service->updateRadioOptionsMeta($user, $radio_option, $properties);


            // Update the cached version of the datatype...
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Determine whether cached entries for table themes need to get deleted...
            $typename = $datafield->getFieldType()->getTypeName();
            if ($typename == 'Single Radio' || $typename == 'Single Select') {
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :datatype_id AND dr.deletedAt IS NULL'
                )->setParameters( array('datatype_id' => $datatype->getId()) );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    // Both of these cache entries need to get deleted so they can get rebuilt with the new radio option name
                    $cache_service->delete('cached_datarecord_'.$result['dr_id']);
                    $cache_service->delete('cached_table_data_'.$result['dr_id']);
                }
            }

            // Delete any cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);

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
     * Updates the display order of the DataField's associated RadioOption entities.
     *
     * @param integer $datafield_id      The database id of the DataField that is having its RadioOption entities sorted.
     * @param boolean $alphabetical_sort Whether to order the RadioOptions alphabetically or in some user-specified order.
     * @param Request $request
     *
     * @return Response
     */
    public function radiooptionorderAction($datafield_id, $alphabetical_sort, Request $request)
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
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

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


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // ----------------------------------------
            // Update whether the datafield is sorting by name or not
            $properties = array(
                'radio_option_name_sort' => $alphabetical_sort
            );
            $emm_service->updateDatafieldMeta($user, $datafield, $properties);


            // ----------------------------------------
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


            if ($alphabetical_sort == 1 ) {
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
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

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
            $force_create = false;
            $option_name = "Option";
            $radio_option = $ec_service->createRadioOption($user, $datafield, $force_create, $option_name);

            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() ) {
                $dfm_properties['master_revision'] = $datafield->getMasterRevision() + 1;
                $emm_service->updateDatafieldMeta($user, $datafield, $dfm_properties, true);
            }

            // createRadioOption() does not automatically flush when $force_create == true
            $em->flush();
            $em->refresh($radio_option);

            // If the datafield is sorting its radio options by name, then force a re-sort of all
            //  of this datafield's radio options
            if ($datafield->getRadioOptionNameSort() == true)
                $sort_service->sortRadioOptionsByName($user, $datafield);


            // Update the cached version of the datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Get the latest radio option as HTML
            $typename = $radio_option->getDataField()->getFieldType()->getTypeName();

            $templating = $this->get('templating');
            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:radio_option_list_row.html.twig',
                array(
                    'datafield' => $radio_option->getDataField(),
                    'radio_option' => $radio_option,
                    'typename' => $typename,
                )
            );

            // Convert to option row...
            $return['d'] = array(
                'radio_option_id' => $radio_option->getId(),
                'datafield_id' => $radio_option->getDataField()->getId(),
                'typename' => $typename,
                'html' => $html
            );

            // Don't need to update cached versions of datarecords or themes

            // Do need to clear some search cache entries however
            $search_cache_service->onDatafieldModify($datafield);
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
     * Returns a form for adding multiple radio options via a list.
     *
     * @param integer $datafield_id The database id of the DataField to add a RadioOption to.
     * @param Request $request
     *
     * @return Response
     */
    public function addradiooptionfromlistAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
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


            // TODO - convert to use ODRAdminBundle:Form:RadioOptionListForm for csrf protection?
            $templating = $this->get('templating');
            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:radio_option_list_import.html.twig',
                array(
                    'datafield' => $datafield,
                )
            );

            // Convert to option row...
            $return['d'] = array(
                'datafield_id' => $datafield->getId(),
                'html' => $html
            );

        }
        catch (\Exception $e) {
            $source = 0x8df28adf;
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
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

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

            // TODO - should the server be reporting on the time taken?
            $start_time = microtime(true);

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
            // Now that all the radio options are created...
            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() ) {
                $dfm_properties['master_revision'] = $datafield->getMasterRevision() + 1;
                $emm_service->updateDatafieldMeta($user, $datafield, $dfm_properties, true);
            }

            // createRadioOption() does not automatically flush when $force_create == true
            $em->flush();


            // If the datafield is sorting its radio options by name, then re-sort all of this
            //  datafield's radio options again
            if ($datafield->getRadioOptionNameSort() == true)
                $sort_service->sortRadioOptionsByName($user, $datafield);


            // Update the cached version of the datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Also need to clear a few search cache entries
            $search_cache_service->onDatafieldModify($datafield);


            $end_time = microtime(true);
            // Convert to option row...
            $return['d'] = array(
                'html' => 'options created: '.($end_time - $start_time) //  var_export($radio_option_list)
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
     * Adds a new child DataType to this DataType.
     *
     * @param integer $theme_element_id The database id of the ThemeElement that the new DataType will be rendered in.
     * @param Request $request
     *
     * @return Response
     */
    public function addchilddatatypeAction($theme_element_id, Request $request)
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
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $parent_datatype = $theme->getDataType();
            if ($parent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $parent_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure that this action isn't being called on a derivative theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to create a new child Datatype outside of the master Theme');

            // Ensure there are no datafields in this theme_element before going and creating a child datatype
            /** @var ThemeDataField[] $theme_datafields */
            $theme_datafields = $em->getRepository('ODRAdminBundle:ThemeDataField')->findBy( array('themeElement' => $theme_element_id) );
            if ( count($theme_datafields) > 0 )
                throw new ODRBadRequestException('Unable to add a child Datatype into a ThemeElement that already has Datafields');


            // TODO - this is currently blocked...otherwise the new child datatype would get attached
            // TODO -  to a "copy" of the theme for the linked datatype...it wouldn't get located
            // TODO -  and synchronized to any other theme
            // Ensure this isn't being called on a linked datatype
            $parent_theme_datatype_id = $theme->getParentTheme()->getDataType()->getGrandparent()->getId();
            $grandparent_datatype_id = $parent_datatype->getGrandparent()->getId();
            if ($grandparent_datatype_id !== $parent_theme_datatype_id)
                throw new ODRBadRequestException('Unable to create a new child Datatype inside a Linked Datatype');


            // ----------------------------------------
            // Create the new child datatype...
            $child_datatype = $ec_service->createDatatype($user, 'New Child', true);    // Don't flush immediately...

            // Several of the child datatype's properties are inherited from its parent...
            $child_datatype->setParent($parent_datatype);
            $child_datatype->setGrandparent($parent_datatype->getGrandparent());
            $child_datatype->setTemplateGroup($parent_datatype->getTemplateGroup());
            if ($parent_datatype->getIsMasterType())
                $child_datatype->setIsMasterType(true);

            $em->persist($child_datatype);

            // ...same for its meta entry
            $child_datatype_meta = $child_datatype->getDataTypeMeta();
            $child_datatype_meta->setSearchSlug(null);    // child datatypes don't have search slugs
            if ($child_datatype->getIsMasterType())
                $child_datatype_meta->setMasterRevision(1);

            $em->persist($child_datatype_meta);


            // Create a new DataTree entry to link the new child datatype with its parent...
            $is_link = false;
            $multiple_allowed = true;
            $ec_service->createDatatree($user, $parent_datatype, $child_datatype, $is_link, $multiple_allowed, true);    // don't flush immediately...


            // Create a new master Theme for this child datatype...
            $child_theme = $ec_service->createTheme($user, $child_datatype, true);    // don't flush immediately...
            $child_theme->setParentTheme($theme->getParentTheme());
            $child_theme->setSourceTheme($child_theme);

            $em->persist($child_theme);

            // The new theme inherits a few properties from its parent as well...
            $child_theme_meta = $child_theme->getThemeMeta();
            $child_theme_meta->setIsDefault($theme->isDefault());
            $child_theme_meta->setShared($theme->isShared());


            // Create a new ThemeDatatype entry to let the renderer know it has to render a child
            //  datatype in this ThemeElement
            $ec_service->createThemeDatatype($user, $theme_element, $child_datatype, $child_theme, true);    // don't flush immediately...


            // Since a child datatype was added, any themes that use this master theme as their
            //  source need to get updated themselves
            $properties = array(
                'sourceSyncVersion' => $theme->getSourceSyncVersion() + 1
            );
            $emm_service->updateThemeMeta($user, $theme, $properties, true);


            // ----------------------------------------
            // Now that most of the required entities have been created, flush and reload the child
            //  datatype so that native SQL queries can copy groups for this child datatype
            $em->flush();
            $em->refresh($child_datatype);

            // Create the default groups for this child datatype
            $ec_service->createGroupsForDatatype($user, $child_datatype);

            // Child datatype should be fully operational now
            $child_datatype->setSetupStep(DataType::STATE_OPERATIONAL);
            $em->persist($child_datatype);
            $em->flush();


            // ----------------------------------------
            // Delete the cached version of the datatree array because a child datatype was created
            $cache_service->delete('cached_datatree_array');

            // Don't need to delete the "associated_datatypes_for_<dt_id>" cache entry...that only
            // stores top-level datatypes, and this was a new child datatype

            // Update the cached version of this datatype
            $dti_service->updateDatatypeCacheEntry($parent_datatype, $user);
            // Do the same for the cached version of this theme
            $theme_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0xe1cadbac;
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
     * Triggers a re-render and reload of a ThemeElement in the design.
     *
     * @param integer $source_datatype_id Which Datatype the design page is currently focused on...
     *                                    can't infer this because of the user could need to reload
     *                                    a ThemeElement in a linked Datatype
     * @param integer $theme_element_id Which ThemeElement to reload
     * @param Request $request
     *
     * @return Response
     */
    public function reloadthemeelementAction($source_datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                throw new ODRNotFoundException('Source Datatype');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                throw new ODRNotFoundException('Theme');
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException("Not allowed to re-render something that doesn't belong to the master Theme");

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $source_datatype) )
                throw new ODRForbiddenException();
            // --------------------

            $datatype_id = null;
            $return['d'] = array(
                'theme_element_id' => $theme_element_id,
                'html' => $odr_render_service->reloadMasterDesignThemeElement($user, $theme_element)
            );
        }
        catch (\Exception $e) {
            $source = 0xf0be4790;
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
     * Triggers a re-render and reload of a DataField in the design.
     *
     * @param integer $source_datatype_id Which Datatype the design page is currently focused on...
     *                                    can't infer this because of the user could need to reload
     *                                    a datafield in a linked Datatype
     * @param integer $theme_element_id Which ThemeElement the Datafield to reload is within...can't
     *                                  infer this because of the possibility of the same Datatype
     *                                  being linked to multiple times
     * @param integer $datafield_id Which Datafield to reload
     * @param Request $request
     *
     * @return Response
     */
    public function reloaddatafieldAction($source_datatype_id, $theme_element_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                throw new ODRNotFoundException('Source Datatype');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Ensure the datatype has a master theme...
            $theme_service->getDatatypeMasterTheme($datatype->getId());


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $source_datatype) || !$pm_service->canViewDatafield($user, $datafield) )
                throw new ODRForbiddenException();
            // --------------------

            $datatype_id = null;
            $return['d'] = array(
                'datafield_id' => $datafield_id,
                'html' => $odr_render_service->reloadMasterDesignDatafield($user, $source_datatype, $theme_element, $datafield)
            );
        }
        catch (\Exception $e) {
            $source = 0xe45c0214;
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
     * Loads/saves a Symfony DataType properties Form, and chain-loads Datatree and ThemeDataType properties forms as well.
     *
     * @param integer $datatype_id       The database id of the Datatype that is being modified
     * @param mixed $parent_datatype_id  Either the id of the Datatype of the parent of $datatype_id, or the empty string
     * @param Request $request
     *
     * @return Response
     */
    public function datatypepropertiesAction($datatype_id, $parent_datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $site_baseurl = $this->container->getParameter('site_baseurl');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');



            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // If $parent_datatype_id is set, locate the datatree and theme_datatype entities linking $datatype_id and $parent_datatype_id
            /** @var DataTree|null $datatree */
            $datatree = null;
            /** @var DataTreeMeta|null $datatree_meta */
            $datatree_meta = null;
            /** @var ThemeDataType|null $theme_datatype */
            $theme_datatype = null;

            if ($parent_datatype_id !== '') {
                $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                    array(
                        'ancestor' => $parent_datatype_id,
                        'descendant' => $datatype_id
                    )
                );
                if ($datatree == null)
                    throw new ODRNotFoundException('Datatree');

                $datatree_meta = $datatree->getDataTreeMeta();
                if ($datatree_meta->getDeletedAt() != null)
                    throw new ODRNotFoundException('DatatreeMeta');

                $parent_theme = $theme_service->getDatatypeMasterTheme($parent_datatype_id);

                $query = $em->createQuery(
                   'SELECT tdt
                    FROM ODRAdminBundle:Theme AS t
                    JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
                    JOIN ODRAdminBundle:ThemeDataType AS tdt WITH tdt.themeElement = te
                    WHERE t = :parent_master_theme AND tdt.dataType = :child_datatype
                    AND t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdt.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'parent_master_theme' => $parent_theme->getId(),
                        'child_datatype' => $datatype_id
                    )
                );
                $results = $query->getResult();

                if ( !isset($results[0]) )
                    throw new ODRNotFoundException('ThemeDatatype');
                $theme_datatype = $results[0];
            }

            // Store the current external id/name/sort datafield ids
            $old_external_id_field = $datatype->getExternalIdField();
            if ( !is_null($old_external_id_field) )
                $old_external_id_field = $old_external_id_field->getId();
            $old_namefield = $datatype->getNameField();
            if ( !is_null($old_namefield) )
                $old_namefield = $old_namefield->getId();
            $old_sortfield = $datatype->getSortField();
            if ( !is_null($old_sortfield) )
                $old_sortfield = $old_sortfield->getId();

            // Create the form for the Datatype
            $submitted_data = new DataTypeMeta();

            $is_top_level = true;
            if ( $parent_datatype_id !== '' && $parent_datatype_id !== $datatype_id )
                $is_top_level = false;

            $is_link = false;
            if ($datatree != null && $datatree->getIsLink() == true)
                $is_link = true;

            $sortfield_datatypes = self::getSortfieldDatatypes($datatype);

            $datatype_form = $this->createForm(
                UpdateDataTypeForm::class,
                $submitted_data,
                array(
                    'datatype_id' => $datatype->getId(),
                    'is_top_level' => $is_top_level,
                    'is_link' => $is_link,

                    'sortfield_datatypes' => $sortfield_datatypes,
                )
            );
            $datatype_form->handleRequest($request);

            if ($datatype_form->isSubmitted()) {
                // Child datatypes aren't allowed to have search slugs
                // Can't use $is_top_level for this, because that'll be false when accessing the
                //  properties of a linked datatype
                if ($datatype->getGrandparent()->getId() !== $datatype->getId())
                    $submitted_data->setSearchSlug(null);

                if ( !is_null($submitted_data->getSearchSlug()) && $submitted_data->getSearchSlug() !== $datatype->getSearchSlug() ) {
                    // ...check that the new search slug is restricted to alphanumeric characters and a few symbols
                    $pattern = '/^[0-9a-zA-Z][0-9a-zA-Z\_\-]+$/';
                    if ( !preg_match($pattern, $submitted_data->getSearchSlug()) )
                        $datatype_form->addError( new FormError('The abbreviation must start with an alphanumeric character; followed by any number of alphanumeric characters, hyphens, or underscores') );

                    // ...check that the new search slug isn't going to collide with other parts of the site
                    // TODO - make this automatic based on contents of routing files?
                    $search_slug_blacklist = $this->getParameter('odr.search_slug_blacklist');
                    $invalid_slugs = explode('|', $search_slug_blacklist);
                    if ( in_array(strtolower($submitted_data->getSearchSlug()), $invalid_slugs) )
                        $datatype_form->addError( new FormError('This abbreviation is reserved for use by ODR') );

                    // ...check that the new search slug doesn't collide with an existing search slug
                    $query = $em->createQuery(
                       'SELECT dtym.id
                        FROM ODRAdminBundle:DataTypeMeta AS dtym
                        WHERE dtym.searchSlug = :search_slug
                        AND dtym.deletedAt IS NULL'
                    )->setParameters(array('search_slug' => $submitted_data->getSearchSlug()));
                    $results = $query->getArrayResult();

                    if ( count($results) > 0 )
                        $datatype_form->addError( new FormError('A different Datatype is already using this abbreviation') );
                }

                if ($submitted_data->getShortName() == '')
                    $datatype_form->addError( new FormError('Short Name can not be empty') );
                if ($submitted_data->getLongName() == '')
                    $datatype_form->addError( new FormError('Long Name can not be empty') );

//$datatype_form->addError( new FormError('do not save') );

                // TODO - verify that the datafield provided as a (new) externalIdField can be unique
                // TODO - verify that the datafields provided as a (new) nameField and sortField are allowed...according to UpdateDataTypeForm.php, they don't have to be unique...

                if ($datatype_form->isValid()) {

                    // If any of the external/name/sort datafields got changed, clear the relevant cache fields for datarecords of this datatype
                    $new_external_id_field = $submitted_data->getExternalIdField();
                    if ( !is_null($new_external_id_field) )
                        $new_external_id_field = $new_external_id_field->getId();
                    $new_namefield = $submitted_data->getNameField();
                    if ( !is_null($new_namefield) )
                        $new_namefield = $new_namefield->getId();
                    $new_sortfield = $submitted_data->getSortField();
                    if ( !is_null($new_sortfield) )
                        $new_sortfield = $new_sortfield->getId();


                    $update_sort_order = false;
                    if ($old_sortfield !== $new_sortfield)  // These are either null or datafield ids at this point
                        $update_sort_order = true;

                    // Since the values of the external_id/name/sort datafields are cached with the
                    //  datarecord data, changing any of these fields requires them to be rebuilt
                    if ( $old_external_id_field !== $new_external_id_field
                        || $old_namefield !== $new_namefield
                        || $old_sortfield !== $new_sortfield
                    ) {
                        // Locate all datarecords of this datatype's grandparent
                        $grandparent_datatype_id = $datatype->getGrandparent()->getId();

                        $query = $em->createQuery(
                           'SELECT dr.id AS dr_id
                            FROM ODRAdminBundle:DataRecord AS dr
                            WHERE dr.dataType = :datatype_id
                            AND dr.deletedAt IS NULL'
                        )->setParameters( array('datatype_id' => $grandparent_datatype_id) );
                        $results = $query->getArrayResult();

                        // Wipe all cached entries for these datarecords
                        foreach ($results as $result) {
                            $dr_id = $result['dr_id'];
                            $cache_service->delete('cached_datarecord_'.$dr_id);
                            $cache_service->delete('cached_table_data_'.$dr_id);
                        }
                    }

                    // Convert the submitted Form entity into an array of relevant properties
                    // This should only have properties listed in the UpdateDataTypeForm
                    $properties = array(
                        'renderPlugin' => $datatype->getRenderPlugin()->getId(),

                        'externalIdField' => null,    // TODO - changing a field so it's no longer the external id field doesn't update the frontend to permit deletion
                        'nameField' => null,
                        'sortField' => null,
                        'backgroundImageField' => null,

                        'searchSlug' => $submitted_data->getSearchSlug(),
                        'shortName' => $submitted_data->getShortName(),
                        'longName' => $submitted_data->getLongName(),
                        'description' => $submitted_data->getDescription(),

                        'newRecordsArePublic' => $submitted_data->getNewRecordsArePublic(),
                    );

                    // These datafields are permitted to be null
                    if ( $submitted_data->getExternalIdField() !== null )
                        $properties['externalIdField'] = $submitted_data->getExternalIdField()->getId();
                    if ( $submitted_data->getNameField() !== null )
                        $properties['nameField'] = $submitted_data->getNameField()->getId();
                    if ( $submitted_data->getSortField() !== null )
                        $properties['sortField'] = $submitted_data->getSortField()->getId();
                    if ( $submitted_data->getBackgroundImageField() !== null )
                        $properties['backgroundImageField'] = $submitted_data->getBackgroundImageField()->getId();

                    // Master Template Data Types must increment Master Revision on all change requests.
                    if ($datatype->getIsMasterType() > 0)
                        $properties['master_revision'] = $datatype->getMasterRevision() + 1;


                    // Master Template Data Types must increment parent master template
                    // revision when changed.
                    if (!$is_link && $datatype->getIsMasterType() > 0) {
                        // TODO Need to update datatype revision for grandparent
                    }

                    $emm_service->updateDatatypeMeta($user, $datatype, $properties);

                    // Update cached version of datatype
                    $dti_service->updateDatatypeCacheEntry($datatype, $user);

                    // Don't need to update cached versions of datarecords or themes

                    // ----------------------------------------
                    // If the sort datafield changed, then several cache entries need to be rebuilt
                    if ($update_sort_order)
                        $dti_service->resetDatatypeSortOrder($datatype->getId());

                    // Cached search results don't need to be cleared here...none of them care about
                    // any of the properties being changed here
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($datatype_form);
                    throw new ODRException($error_str);
                }
            }
            else {
                $sortfield_datatypes = self::getSortfieldDatatypes($datatype);

                // This is a GET request...need to create the required form objects
                $datatype_meta = $datatype->getDataTypeMeta();
                $datatype_form = $this->createForm(
                    UpdateDataTypeForm::class,
                    $datatype_meta,
                    array(
                        'datatype_id' => $datatype->getId(),
                        'is_top_level' => $is_top_level,
                        'is_link' => $is_link,

                        'sortfield_datatypes' => $sortfield_datatypes
                    )
                );

                // Create the form for the Datatree entity (stores whether the parent datatype is allowed to have multiple datarecords of the child datatype)
                $force_multiple = false;
                $affects_sortfield = false;

                $datatree_form = null;
                if ($datatree_meta !== null) {
                    // Determine whether the ancestor datatype is using a sortfield from the
                    //  descendant datatype
                    $ancestor_datatype = $datatree->getAncestor();
                    $descendant_datatype = $datatree->getDescendant();

                    if ( !is_null($ancestor_datatype->getSortField()) ) {
                        $sortfield = $ancestor_datatype->getSortField();
                        if ( $sortfield->getDataType()->getId() === $descendant_datatype->getId() )
                            $affects_sortfield = true;
                    }

                    // Create the form itself
                    $datatree_form = $this->createForm(
                        UpdateDataTreeForm::class,
                        $datatree_meta
                    )->createView();

                    $results = array();
                    if ($datatree_meta->getIsLink() == 0) {
                        // Determine whether a datarecord of this datatype has multiple child datarecords...if so, then require the "multiple allowed" property of the datatree to remain true
                        $query = $em->createQuery(
                           'SELECT parent.id AS ancestor_id, child.id AS descendant_id
                            FROM ODRAdminBundle:DataRecord AS parent
                            JOIN ODRAdminBundle:DataRecord AS child WITH child.parent = parent
                            WHERE parent.dataType = :parent_datatype AND child.dataType = :child_datatype AND parent.id != child.id
                            AND parent.deletedAt IS NULL AND child.deletedAt IS NULL'
                        )->setParameters( array('parent_datatype' => $parent_datatype_id, 'child_datatype' => $datatype_id) );
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
                        )->setParameters( array('ancestor_datatype' => $parent_datatype_id, 'descendant_datatype' => $datatype_id) );
                        $results = $query->getArrayResult();
                    }

                    $tmp = array();
                    foreach ($results as $num => $result) {
                        $ancestor_id = $result['ancestor_id'];
                        if ( isset($tmp[$ancestor_id]) ) {
                            $force_multiple = true;
                            break;
                        }
                        else {
                            $tmp[$ancestor_id] = 1;
                        }
                    }
                }


                // Create the form for the ThemeDatatype entry (stores whether the child/linked datatype should use 'accordion', 'tabbed', 'dropdown', or 'list' rendering style)
                $theme_datatype_form = null;
                if ($theme_datatype !== null) {

                    $theme_datatype_form = $this->createForm(
                        UpdateThemeDatatypeForm::class,
                        $theme_datatype,
                        array(
                            'is_master_theme' => true,      // this is only called from a master theme
                            'multiple_allowed' => $datatree->getMultipleAllowed(),
                        )
                    )->createView();
                }

                // Determine whether user can view permissions of other users
                $can_view_permissions = false;
                if ( $user->hasRole('ROLE_SUPER_ADMIN') || $pm_service->isDatatypeAdmin($user, $datatype) )
                    $can_view_permissions = true;



                // Hide name and description for datatypes that have associated metadata
                $show_name = true;
                $show_description = true;
                if($datatype->getMetadataDatatype() && $datatype->getMetadataDatatype()->getId()) {
                    // TODO and metadata has field with internal_reference_name = datatype_name
                    /** @var DataFields[] $fields */
                    $fields = $datatype->getMetadataDatatype()->getDataFields();
                    foreach($fields as $field) {
                        if($field->getInternalReferenceName() == 'datatype_name') {
                            $show_name = false;
                        }
                        else if($field->getInternalReferenceName() == 'datatype_description') {
                            $show_description = false;
                        }
                    }
                }

                // Return the slideout html
                $templating = $this->get('templating');
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Displaytemplate:datatype_properties_form.html.twig',
                    array(
                        'show_name' => $show_name,
                        'show_description' => $show_description,
                        'datatype' => $datatype,
                        'datatype_form' => $datatype_form->createView(),
                        'site_baseurl' => $site_baseurl,
                        'is_top_level' => $is_top_level,
                        'can_view_permissions' => $can_view_permissions,

                        'datatree' => $datatree,
                        'datatree_form' => $datatree_form,              // not creating view here because form could be legitimately null
                        'force_multiple' => $force_multiple,
                        'affects_sortfield' => $affects_sortfield,

                        'theme_datatype' => $theme_datatype,
                        'theme_datatype_form' => $theme_datatype_form,  // not creating view here because form could be legitimately null
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x52de9520;
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
     * Datatypes are also allowed to pull datafields for sorting from linked datatypes, provided
     * they only allow a single linked datarecord.
     *
     * @param DataType $datatype
     *
     * @return int[]
     */
    private function getSortfieldDatatypes($datatype)
    {
        /** @var DatatreeInfoService $$dti_service */
        $dti_service = $this->container->get('odr.datatree_info_service');

        // Locate the ids of all datatypes that the given parent datatype links to
        $datatree_array = $dti_service->getDatatreeArray();
        $linked_descendents = $dti_service->getLinkedDescendants( array($datatype->getId()), $datatree_array );

        // The parent datatype should always be in here, otherwise no fields will get listed as
        //  candidates for a sortfield
        $sortfield_datatypes = array();
        $sortfield_datatypes[] = $datatype->getId();

        foreach ($linked_descendents as $num => $ldt_id) {
            if ( !isset($datatree_array['multiple_allowed'][$ldt_id]) ) {
                // If the linked datatype isn't in the 'multiple allowed' section, then everything
                //  that links to it only permits a single linked record
                $sortfield_datatypes[] = $ldt_id;
            }
            else {
                $parents = $datatree_array['multiple_allowed'][$ldt_id];
                if ( !in_array($datatype->getId(), $parents) ) {
                    // The parent datatype only allows at most one linked record
                    $sortfield_datatypes[] = $ldt_id;
                }
            }
        }

        return $sortfield_datatypes;
    }


    /**
     * Loads/saves an ODR DataFields properties Form.
     *
     * @param integer $datafield_id The database id of the DataField being modified.
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function datafieldpropertiesAction($datafield_id, Request $request)
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
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');



            // TODO - what should you be allowed to modify on a derived datafield?
//            // This should not work on a datafield that is derived from a master template
//            if ( !is_null($datafield->getMasterDataField()) )
//                throw new ODRBadRequestException();


            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // TODO Add Check if TED Exists and if datafield is related to it

            // Ensure the datatype has a master theme...
            if($datatype)
                $theme_service->getDatatypeMasterTheme($datatype->getId());


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Need to immediately force a reload of the right design slideout if certain fieldtypes change
            $force_slideout_reload = false;

            // Keep track of conditions where parts of the datafield shouldn't be changed...
            $ret = self::canChangeFieldtype($em, $datafield);
            $prevent_fieldtype_change = $ret['prevent_change'];


            // Check whether this datafield is being used by a table theme
            $query = $em->createQuery(
               'SELECT tdf.id
                FROM ODRAdminBundle:Theme AS t
                JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
                JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
                WHERE t.themeType = :theme_type AND tdf.dataField = :datafield
                AND t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdf.deletedAt IS NULL'
            )->setParameters( array('theme_type' => 'table', 'datafield' => $datafield->getId()) );
            $results = $query->getArrayResult();

            $used_by_table_theme = false;
            if ( count($results) > 0 )
                $used_by_table_theme = true;


            // Check to see whether the "allow multiple uploads" checkbox for file/image control needs to be disabled
            $has_multiple_uploads = 0;
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass === 'File' || $typeclass === 'Image')
                $has_multiple_uploads = self::hasMultipleUploads($em, $user, $datafield);

            // Check to see whether the "allow multiple levels" checkbox for tag control needs to be disabled
            $has_multiple_levels = 0;
            if ($typeclass === 'Tag')
                $has_multiple_levels = self::hasMultipleLevels($em, $user, $datafield);

            // Determine which fieldtypes the datafield is allowed to have
            $allowed_fieldtypes = self::getAllowedFieldtypes($em, $datafield);


            // ----------------------------------------
            // Populate new DataFields form
            $submitted_data = new DataFieldsMeta();
            $datafield_form = $this->createForm(
                UpdateDataFieldsForm::class,
                $submitted_data,
                array(
                    'allowed_fieldtypes' => $allowed_fieldtypes,
                    'current_typename' => $datafield->getFieldType()->getTypeName(),
                )
            );

            $datafield_form->handleRequest($request);

            if ($datafield_form->isSubmitted()) {
                // ----------------------------------------
                // Refresh just in case
                $em->refresh($datafield);
                $em->refresh($datafield->getDataFieldMeta());

                $current_datafield_meta = $datafield->getDataFieldMeta();

                // Deal with possible change of fieldtype
                $old_fieldtype = $datafield->getFieldType();
                $old_fieldtype_id = $old_fieldtype->getId();
                $new_fieldtype = $submitted_data->getFieldType();

                $new_fieldtype_id = $old_fieldtype_id;
                if ($new_fieldtype !== null)
                    $new_fieldtype_id = $new_fieldtype->getId();

                $migrate_data = false;
                $check_image_sizes = false;

                if ( $old_fieldtype_id !== $new_fieldtype_id ) {
                    // If not allowed to change fieldtype or not allowed to change to this fieldtype...
                    if ( $prevent_fieldtype_change || !in_array($new_fieldtype_id, $allowed_fieldtypes) ) {
                        // ...revert back to old fieldtype
                        $prevent_fieldtype_change = true;
                    }
                    else {
                        // Determine if we need to migrate the data over to the new fieldtype
                        /** @var FieldType $old_fieldtype */
                        $old_fieldtype = $repo_fieldtype->find($old_fieldtype_id);
                        /** @var FieldType $new_fieldtype */
                        $new_fieldtype = $repo_fieldtype->find($new_fieldtype_id);

                        // Check whether the fieldtype got changed from something that could be migrated...
                        $migrate_data = true;
                        switch ($old_fieldtype->getTypeClass()) {
                            case 'IntegerValue':
                            case 'LongText':
                            case 'LongVarchar':
                            case 'MediumVarchar':
                            case 'ShortVarchar':
                            case 'DecimalValue':
                            case 'DatetimeValue':
                                break;

                            default:
                                $migrate_data = false;
                                break;
                        }
                        // ...to something that needs the migration proccess
                        switch ($new_fieldtype->getTypeClass()) {
                            case 'IntegerValue':
                            case 'LongText':
                            case 'LongVarchar':
                            case 'MediumVarchar':
                            case 'ShortVarchar':
                            case 'DecimalValue':
                            case 'DatetimeValue':
                                break;

                            case 'Image':
                                $check_image_sizes = true;  // need to ensure that ImageSizes entities exist for this datafield...
                                $migrate_data = false;
                                break;

                            default:
                                $migrate_data = false;
                                break;
                        }

                        // If going from Multiple radio/select to Single radio/select...then need
                        //  to ensure that at most one RadioSelection is selected for each drf entry
                        $old_typename = $old_fieldtype->getTypeName();
                        $new_typename = $new_fieldtype->getTypeName();
                        if ( ($old_typename == 'Multiple Select' || $old_typename == 'Multiple Radio')
                            && ($new_typename == 'Single Select' || $new_typename == 'Single Radio')
                        ) {
                            $migrate_data = true;
                        }

                        // If fieldtype got changed to/from Markdown, File, Image, Radio, or Tags...
                        //  force a reload of the right slideout, because options on that slideout
                        //  are different for these fieldtypes
                        switch ($old_fieldtype->getTypeClass()) {
                            case 'Radio':
                            case 'File':
                            case 'Image':
                            case 'Markdown':
                            case 'Tag':
                                $force_slideout_reload = true;
                                break;
                        }
                        switch ($new_fieldtype->getTypeClass()) {
                            case 'Radio':
                            case 'File':
                            case 'Image':
                            case 'Markdown':
                            case 'Tag':
                                $force_slideout_reload = true;
                                break;
                        }
                    }
                }

                // If not allowed to change fieldtype, ensure the datafield always has the old fieldtype
                if ($prevent_fieldtype_change) {
                    $submitted_data->setFieldType( $old_fieldtype );
                    $migrate_data = false;
                    $force_slideout_reload = false;
                }

                // If the file/image field has multiple uploads, ensure that option remains checked
                if ( $has_multiple_uploads )
                    $submitted_data->setAllowMultipleUploads(true);

                // If the tag field has multiple levels, ensure that option remains checked
                if ( $has_multiple_levels )
                    $submitted_data->setTagsAllowMultipleLevels(true);


                // ----------------------------------------
                // If datafield is being used as the datatype's external ID field, ensure it's marked as unique
                if ( $datatype->getExternalIdField() !== null && $datatype->getExternalIdField()->getId() == $datafield->getId() )
                    $submitted_data->setIsUnique(true);

                // If the datafield got set to unique...
                if ( !$current_datafield_meta->getIsUnique() && $submitted_data->getIsUnique() ) {
                    // ...if it has duplicate values, manually add an error to the Symfony form...this will conveniently cause the subsequent isValid() call to fail
                    if ( !self::datafieldCanBeUnique($em, $datafield) )
                        $datafield_form->addError( new FormError("This Datafield can't be set to 'unique' because some Datarecords have duplicate values stored in this Datafield...click the gear icon to list which ones.") );
                }

                // If the unique status of the datafield got changed at all, force a slideout reload so the fieldtype will have the correct state
                if ( $current_datafield_meta->getIsUnique() != $submitted_data->getIsUnique() )
                    $force_slideout_reload = true;

                if ($datafield_form->isValid()) {
                    // No errors in form

                    // ----------------------------------------
                    // Easier to deal with change of fieldtype and how it relates to searchable here
                    switch ($submitted_data->getFieldType()->getTypeClass()) {
                        case 'DecimalValue':
                        case 'IntegerValue':
                        case 'LongText':
                        case 'LongVarchar':
                        case 'MediumVarchar':
                        case 'ShortVarchar':
                        case 'Radio':
                        case 'Tag':
                            // All of the above fields can have any value for searchable
                            break;

                        case 'Image':
                        case 'File':
                        case 'Boolean':
                        case 'DatetimeValue':
                            // It only makes sense for these four fieldtypes to be searchable from advanced search
                            if ($submitted_data->getSearchable() == 1 || $submitted_data->getSearchable() == 2)
                                $submitted_data->setSearchable(3);
                            break;

                        default:
                            // All other fieldtypes can't be searched
                            $submitted_data->setSearchable(0);
                            break;
                    }


                    // ----------------------------------------
                    // If the fieldtype changed, then check several of the properties to see if they need changed too...
                    if ( $old_fieldtype_id !== $new_fieldtype_id ) {
                        // Reset a datafield's markdown text if it's not longer a markdown field
                        if ($new_fieldtype->getTypeName() !== 'Markdown')
                            $submitted_data->setMarkdownText('');

                        // Clear properties related to radio options and tags if it's no longer
                        //  one of those fieldtypes
                        if ($new_fieldtype->getTypeClass() !== 'Radio' && $new_fieldtype->getTypeClass() !== 'Tag') {
                            // These properties are shared by radio options and tags
                            $submitted_data->setRadioOptionNameSort(false);
                            $submitted_data->setRadioOptionDisplayUnselected(false);
                        }
                        if ($new_fieldtype->getTypeClass() !== 'Tag') {
                            // These properties are only used by tags
                            $submitted_data->setTagsAllowMultipleLevels(false);
                            $submitted_data->setTagsAllowNonAdminEdit(false);
                        }
                    }

                    // If the radio options or tags are now supposed to be sorted by name, ensure
                    //  that happens
                    $sort_radio_options = false;
                    if ( $submitted_data->getRadioOptionNameSort() == true && $current_datafield_meta->getRadioOptionNameSort() == false )
                        $sort_radio_options = true;


                    // ----------------------------------------
                    // Save all changes made via the submitted form
                    $properties = array(
                        'fieldType' => $submitted_data->getFieldType()->getId(),
                        'renderPlugin' => $datafield->getRenderPlugin()->getId(),

                        'fieldName' => $submitted_data->getFieldName(),
                        'description' => $submitted_data->getDescription(),
                        'xml_fieldName' => $submitted_data->getXmlFieldName(),
                        'markdownText' => $submitted_data->getMarkdownText(),
                        'regexValidator' => $submitted_data->getRegexValidator(),
                        'phpValidator' => $submitted_data->getPhpValidator(),
                        'required' => $submitted_data->getRequired(),
                        'is_unique' => $submitted_data->getIsUnique(),
                        'allow_multiple_uploads' => $submitted_data->getAllowMultipleUploads(),
                        'shorten_filename' => $submitted_data->getShortenFilename(),
                        'newFilesArePublic' => $submitted_data->getNewFilesArePublic(),
                        'children_per_row' => $submitted_data->getChildrenPerRow(),
                        'radio_option_name_sort' => $submitted_data->getRadioOptionNameSort(),
                        'radio_option_display_unselected' => $submitted_data->getRadioOptionDisplayUnselected(),
                        'tags_allow_multiple_levels' => $submitted_data->getTagsAllowMultipleLevels(),
                        'tags_allow_non_admin_edit' => $submitted_data->getTagsAllowNonAdminEdit(),
                        'searchable' => $submitted_data->getSearchable(),
                        'publicDate' => $submitted_data->getPublicDate(),
                        'internal_reference_name' => $submitted_data->getInternalReferenceName(),
                    );
                    $emm_service->updateDatafieldMeta($user, $datafield, $properties);
                    $em->refresh($datafield);

                    // TODO - might be race condition issue with design_ajax
                    if ($sort_radio_options) {
                        $submitted_typeclass = $submitted_data->getFieldType()->getTypeClass();
                        if ($submitted_typeclass === 'Radio')
                            $sort_service->sortRadioOptionsByName($user, $datafield);
                        else if ($submitted_typeclass === 'Tag')
                            $sort_service->sortTagsByName($user, $datafield);
                    }

                    if ($check_image_sizes)
                        $ec_service->createImageSizes($user, $datafield);

                    if ($migrate_data)
                        self::startDatafieldMigration($em, $user, $datafield, $old_fieldtype, $new_fieldtype);


                    // ----------------------------------------
                    // Mark the datatype as updated
                    $dti_service->updateDatatypeCacheEntry($datatype, $user);

                    // TODO - when/if sort fields can change their fieldtype, this will be needed
//                    $dti_service->resetDatatypeSortOrder($datatype->getId());

                    // Don't need to update cached datarecords or themes

                    // This is probably slightly overkill...
                    $search_cache_service->onDatafieldModify($datafield);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($datafield_form);
                    throw new ODRException($error_str);
                }

            }


            if ( !$datafield_form->isSubmitted() || !$datafield_form->isValid() || $force_slideout_reload ) {
                // This was a GET request, or the form wasn't valid originally, or the form was valid but needs to be reloaded anyways
                $em->refresh($datafield);
                $em->refresh($datafield->getDataFieldMeta());

                // Create the form for the datafield entry
                $datafield_meta = $datafield->getDataFieldMeta();
                $datafield_form = $this->createForm(
                    UpdateDataFieldsForm::class,
                    $datafield_meta,
                    array(
                        'allowed_fieldtypes' => $allowed_fieldtypes,
                        'current_typename' => $datafield->getFieldType()->getTypeName(),
                    )
                );


                // Keep track of conditions where parts of the datafield shouldn't be changed...
                $ret = self::canChangeFieldtype($em, $datafield);
                $prevent_fieldtype_change = $ret['prevent_change'];
                $prevent_fieldtype_change_message = $ret['prevent_change_message'];

                // Render the html for the form
                $templating = $this->get('templating');
                $return['d'] = array(
                    'force_slideout_reload' => $force_slideout_reload,
                    'html' => $templating->render(
                        'ODRAdminBundle:Displaytemplate:datafield_properties_form.html.twig',
                        array(
                            'has_multiple_uploads' => $has_multiple_uploads,
                            'has_multiple_levels' => $has_multiple_levels,
                            'prevent_fieldtype_change' => $prevent_fieldtype_change,
                            'prevent_fieldtype_change_message' => $prevent_fieldtype_change_message,

                            'used_by_table_theme' => $used_by_table_theme,

                            'datafield' => $datafield,
                            'datafield_form' => $datafield_form->createView(),
                        )
                    )
                );
            }

        }
        catch (\Exception $e) {
            $source = 0xa7c7c3ae;
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
     * Helper function to determine whether a datafield can have its fieldtype changed
     * TODO - move into a datafield info service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataFields $datafield
     *
     * @return array
     */
    private function canChangeFieldtype($em, $datafield)
    {
        $ret = array(
            'prevent_change' => false,
            'prevent_change_message' => '',
        );

        // Prevent a datafield's fieldtype from being changed if a migration is in progress
        /** @var TrackedJob $tracked_job */
        $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy(
            array(
                'job_type' => 'migrate',
                'target_entity' => 'datafield_'.$datafield->getId(),
                'completed' => null
            )
        );
        if ($tracked_job !== null) {
            $ret = array(
                'prevent_change' => true,
                'prevent_change_message' => "The Fieldtype can't be changed because the server hasn't finished migrating this Datafield's data to the currently displayed Fieldtype.",
            );
        }

        // TODO - not technically true...but still needs to be restricted to some subset of fieldtypes
        // Also prevent a fieldtype change if the datafield is marked as unique
        if ($datafield->getIsUnique() == true) {
            $ret = array(
                'prevent_change' => true,
                'prevent_change_message' => "The Fieldtype can't be changed because the Datafield is currently marked as Unique.",
            );
        }


        // TODO - without this, the user can change to unsortable fieldtypes...fix the rest of the logic so this isn't needed
        // Also prevent a fieldtype change if the datafield is being used as the sort field by any datatype
        $query = $em->createQuery(
           'SELECT dtm.shortName
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.sortField = df
            JOIN ODRAdminBundle:DataType AS dt WITH dtm.dataType = dt
            WHERE df.id = :datafield_id
            AND df.deletedAt IS NULL AND dtm.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters( array('datafield_id' => $datafield->getId()) );
        $results = $query->getArrayResult();

        if ( !empty($results) ) {
            if (count($results) == 1) {
                $ret = array(
                    'prevent_change' => true,
                    'prevent_change_message' => "The Fieldtype can't be changed because the Datafield is being used to sort the ".$results[0]['shortName']." Datatype.",
                );
            }
            else {
                $ret = array(
                    'prevent_change' => true,
                    'prevent_change_message' => "The Fieldtype can't be changed because the Datafield is being used to sort multiple Datatypes",
                );
            }
        }


        // Prevent a datafield's fieldtype from changing if it's derived from a template
        if ( !is_null($datafield->getMasterDataField()) ) {
            $ret = array(
                'prevent_change' => true,
                'prevent_change_message' => "The Fieldtype can't be changed because the Datafield is derived from a Master Template.",
            );
        }

        // TODO - remove the need for this
        $derived_datafields = $em->getRepository('ODRAdminBundle:DataFields')->findBy(
            array(
                'masterDataField' => $datafield->getId()
            )
        );
        if ( !empty($derived_datafields) ) {
            $ret = array(
                'prevent_change' => true,
                'prevent_change_message' => "The Fieldtype can't be changed because template synchronization can't migrate fieldtypes yet..."
            );
        }

        return $ret;
    }


    /**
     * Helper function to determine whether a datafield has multiple files/images uploaded or not.
     *
     * TODO - move into a datafield info service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user
     * @param DataFields $datafield
     *
     * @return int 1 if a file/image field has multiple uploads already, 0 otherwise
     */
    private function hasMultipleUploads($em, $user, $datafield)
    {
        /** @var EntityMetaModifyService $emm_service */
        $emm_service = $this->container->get('odr.entity_meta_modify_service');

        // Should only be run on a file/image datafield
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ($typeclass !== 'File' && $typeclass !== 'Image')
            return 0;

        $has_multiple_uploads = 0;

        // Count how many files/images are attached to this datafield across all datarecords
        $str =
           'SELECT COUNT(e.dataRecord)
            FROM ODRAdminBundle:'.$typeclass.' AS e
            JOIN ODRAdminBundle:DataFields AS df WITH e.dataField = df
            JOIN ODRAdminBundle:DataRecord AS dr WITH e.dataRecord = dr
            WHERE e.deletedAt IS NULL AND dr.deletedAt IS NULL AND df.id = :datafield';
        if ($typeclass == 'Image')
            $str .= ' AND e.original = 1 ';
        $str .= ' GROUP BY dr.id';

        $query = $em->createQuery($str)->setParameters( array('datafield' => $datafield) );
        $results = $query->getResult();

        foreach ($results as $result) {
            if ( intval($result[1]) > 1 ) {
                if ( $datafield->getAllowMultipleUploads() == 0 ) {
                    // This datafield somehow has multiple files uploaded to it while being set to
                    //  only allow a single upload...fix that
                    $properties = array(
                        'allow_multiple_uploads' => true,
                    );
                    $emm_service->updateDatafieldMeta($user, $datafield, $properties);
                }

                $has_multiple_uploads = 1;
                break;
            }
        }

        return $has_multiple_uploads;
    }


    /**
     * Helper function to determine whether a datafield's tag structure has multiple levels or not.
     *
     * TODO - move into a datafield info service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user
     * @param DataFields $datafield
     *
     * @return int 1 if a tag field has multiple levels already, 0 otherwise
     */
    private function hasMultipleLevels($em, $user, $datafield)
    {
        /** @var EntityMetaModifyService $emm_service */
        $emm_service = $this->container->get('odr.entity_meta_modify_service');

        // Should only be run on a tag datafield
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ($typeclass !== 'Tag')
            return 0;

        $has_multiple_levels = 0;

        // Determine whether there are any tag tree entries for the given datafield
        $query = $em->createQuery(
           'SELECT COUNT(tt.id) AS tag_tree_count
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:Tags AS t WITH t.dataField = df
            JOIN ODRAdminBundle:TagTree AS tt WITH tt.parent = t
            WHERE df.id = :datafield_id
            AND df.deletedAt IS NULL AND t.deletedAt IS NULL AND tt.deletedAt IS NULL'
        )->setParameters( array('datafield_id' => $datafield->getId()) );
        $results = $query->getArrayResult();

        foreach ($results as $result) {
            if ( intval($result['tag_tree_count']) > 0 ) {
                if ( $datafield->getTagsAllowMultipleLevels() == 0 ) {
                    // This datafield somehow has multiple levels of tags while being set to only
                    //  allow a single level...fix that
                    $properties = array(
                        'tags_allow_multiple_levels' => true,
                    );
                    $emm_service->updateDatafieldMeta($user, $datafield, $properties);
                }

                $has_multiple_levels = 1;
                break;
            }
        }

        return $has_multiple_levels;
    }


    /**
     * Returns an array of fieldtype ids that the datafield is allowed to have in its current context.
     * TODO - move into a datafield info service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataFields $datafield
     *
     * @return array
     */
    private function getAllowedFieldtypes($em, $datafield)
    {
        // Get a list of all fieldtype ids
        /** @var FieldType[] $tmp */
        $tmp = $em->getRepository('ODRAdminBundle:FieldType')->findAll();
        $allowed_fieldtypes = array();
        foreach ($tmp as $ft)
            $allowed_fieldtypes[] = $ft->getId();

        // Determine if the datafield has a render plugin applied to it...
        $df_fieldtypes = $allowed_fieldtypes;
        if ( $datafield->getRenderPlugin()->getPluginClassName() !== 'odr_plugins.base.default' ) {
            /** @var RenderPluginInstance $rpi */
            $rpi = $em->getRepository('ODRAdminBundle:RenderPluginInstance')->findOneBy(
                array(
                    'renderPlugin' => $datafield->getRenderPlugin()->getId(),
                    'dataField' => $datafield->getId()
                )
            );

            if ($rpi !== null) {
                /** @var RenderPluginMap $rpm */
                $rpm = $em->getRepository('ODRAdminBundle:RenderPluginMap')->findOneBy(
                    array(
                        'renderPluginInstance' => $rpi->getId(),
                        'dataField' => $datafield->getId()
                    )
                );
                $rpf = $rpm->getRenderPluginFields();

                $df_fieldtypes = explode(',', $rpf->getAllowedFieldtypes());
            }
        }

        // Determine if the datafield's datatype has a render plugin applied to it...
        $datatype = $datafield->getDataType();

        $dt_fieldtypes = $allowed_fieldtypes;
        if ( $datatype->getRenderPlugin()->getPluginClassName() !== 'odr_plugins.base.default' ) {
            // This datafield's datatype is using a render plugin...need to see if some part of
            //  the render plugin is mapped to this datafield
            /** @var RenderPluginInstance $rpi */
            $rpi = $em->getRepository('ODRAdminBundle:RenderPluginInstance')->findOneBy(
                array(
                    'renderPlugin' => $datatype->getRenderPlugin()->getId(),
                    'dataType' => $datatype->getId()
                )
            );

            /** @var RenderPluginMap|null $rpm */
            $rpm = null;
            if ($rpi !== null) {
                $rpm = $em->getRepository('ODRAdminBundle:RenderPluginMap')->findOneBy(
                    array(
                        'renderPluginInstance' => $rpi->getId(),
                        'dataField' => $datafield->getId()
                    )
                );
            }

            if ($rpm !== null) {
                // Datafield in use, get restrictions
                $rpf = $rpm->getRenderPluginFields();

                $dt_fieldtypes = explode(',', $rpf->getAllowedFieldtypes());
            }
            else {
                // This datafield is not being used by its datatype's render plugin, so there
                //  are no fieldtype restrictions placed on it
            }
        }

        // The allowed fieldtypes could be restricted by both the datafield's render plugin and the
        //  datafield's datatype's render plugin...use the intersection of the restriction
        $allowed_fieldtypes = array_intersect($df_fieldtypes, $dt_fieldtypes);
        $allowed_fieldtypes = array_values($allowed_fieldtypes);


        return $allowed_fieldtypes;
    }


    /**
     * Helper function to determine whether a datafield can be deleted.  Changes to this also need
     * to be made in ODRRenderService, ODRAdminBundle:Displaytemplate:design_fieldarea.html.twig,
     * and ODRAdminBundle:Displaytemplate:design_datafield.html.twig.
     *
     * TODO - move into a datafield info service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataFields $datafield
     *
     * @return array
     */
    private function canDeleteDatafield($em, $datafield)
    {
        $ret = array(
            'prevent_deletion' => false,
            'prevent_deletion_message' => '',
        );

        $datatype = $datafield->getDataType();
        if ($datatype->getExternalIdField() !== null && $datatype->getExternalIdField()->getId() == $datafield->getId()) {
            return array(
                'prevent_deletion' => true,
                'prevent_deletion_message' => "This datafield is currently in use as the Datatype's external ID field...unable to delete",
            );
        }

        if ( $datatype->getRenderPlugin()->getPluginClassName() !== 'odr_plugins.base.default' ) {
            // Datafield is part of a Datatype using a render plugin...check to see if the Datafield is actually in use for the render plugin
            $query = $em->createQuery(
               'SELECT rpf.fieldName
                FROM ODRAdminBundle:RenderPluginInstance AS rpi
                JOIN ODRAdminBundle:RenderPluginMap AS rpm WITH rpm.renderPluginInstance = rpi
                JOIN ODRAdminBundle:RenderPluginFields AS rpf WITH rpm.renderPluginFields = rpf
                WHERE rpi.dataType = :datatype_id AND rpm.dataField = :datafield_id AND rpf.active = 1
                AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL AND rpf.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId(), 'datafield_id' => $datafield->getId()) );
            $results = $query->getArrayResult();

            if ( count($results) > 0 ) {
                return array(
                    'prevent_deletion' => true,
                    'prevent_deletion_message' => 'This Datafield is currently required by the "'.$datatype->getRenderPlugin()->getPluginName().'" for this Datatype...unable to delete',
                );
            }
        }

        // TODO - also prevent if !is_null( $datatype->getMasterDataType() )?
        if (  !is_null($datafield->getMasterDataField()) ) {
            return array(
                'prevent_deletion' => true,
                'prevent_deletion_message' => "This datafield is currently required by the Datatype's master template...unable to delete",
            );
        }

        return $ret;
    }


    /**
     * Begins the process of migrating a Datafield from one Fieldtype to another
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param FieldType $old_fieldtype
     * @param FieldType $new_fieldtype
     *
     */
    private function startDatafieldMigration($em, $user, $datafield, $old_fieldtype, $new_fieldtype)
    {
        // ----------------------------------------
        // Grab necessary stuff for pheanstalk...
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');
        $api_key = $this->container->getParameter('beanstalk_api_key');
        $pheanstalk = $this->get('pheanstalk');

        $url = $this->container->getParameter('site_baseurl');
        $url .= $this->container->get('router')->generate('odr_migrate_field');


        // ----------------------------------------
        // Locate all datarecords of this datatype for purposes of this fieldtype migration
        $datatype = $datafield->getDataType();
        $query = $em->createQuery(
           'SELECT dr.id
            FROM ODRAdminBundle:DataRecord AS dr
            WHERE dr.dataType = :dataType AND dr.deletedAt IS NULL'
        )->setParameters( array('dataType' => $datatype) );
        $results = $query->getResult();

        if ( count($results) > 0 ) {
            // Need to determine the top-level datatype this datafield belongs to, so other background processes won't attempt to render any part of it and disrupt the migration
            $top_level_datatype_id = $datatype->getGrandparent()->getId();


            // Get/create an entity to track the progress of this datafield migration
            $job_type = 'migrate';
            $target_entity = 'datafield_'.$datafield->getId();
            $additional_data = array('description' => '', 'old_fieldtype' => $old_fieldtype->getTypeName(), 'new_fieldtype' => $new_fieldtype->getTypeName());
            $restrictions = 'datatype_'.$top_level_datatype_id;
            $total = count($results);
            $reuse_existing = false;

            $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
            $tracked_job_id = $tracked_job->getId();


            // ----------------------------------------
            // Create jobs for beanstalk to asynchronously migrate data
            foreach ($results as $num => $result) {
                $datarecord_id = $result['id'];

                // Insert the new job into the queue
//                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        "tracked_job_id" => $tracked_job_id,
                        "user_id" => $user->getId(),
                        "datarecord_id" => $datarecord_id,
                        "datafield_id" => $datafield->getId(),
                        "old_fieldtype_id" => $old_fieldtype->getId(),
                        "new_fieldtype_id" => $new_fieldtype->getId(),
//                        "scheduled_at" => $current_time->format('Y-m-d H:i:s'),
                        "redis_prefix" => $redis_prefix,    // debug purposes only
                        "url" => $url,
                        "api_key" => $api_key,
                    )
                );

                $pheanstalk->useTube('migrate_datafields')->put($payload);
            }

            // TODO - Lock the datatype so no more edits?
            // TODO - Lock other stuff?
        }
    }


    /**
     * TODO - re-implement this
     * Gets a list of all datafields that have been deleted from a given datatype.
     *
     * @param integer $datatype_id The database id of the DataType to lookup deleted DataFields from...
     * @param Request $request
     *
     * @return Response
     */
    public function getdeleteddatafieldsAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $em = null;

        try {
            throw new ODRNotImplementedException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows, because we want to display deleted datafields

            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                WHERE df.dataType = :datatype AND df.deletedAt IS NOT NULL'
            )->setParameters( array('datatype' => $datatype) );
            $results = $query->getResult();

            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            // Collapse results array
            $count = 0;
            $datafields = array();
            foreach ($results as $num => $df) {
                /** @var DataFields $df */
                $date = $df->getDeletedAt()->format('Y-m-d').'_'.$count;
                $count++;
                $datafields[$date] = $df;
            }

            krsort($datafields);

            // Get Templating Object
            $templating = $this->get('templating');
            $return['t'] = 'html';
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:undelete_fields_dialog_form.html.twig',
                    array(
                        'datafields' => $datafields,
                    )
                )
            );

        }
        catch (\Exception $e) {
            if ($em !== null)
                $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            $source = 0xf9e63ad1;
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
     * TODO - re-implement this
     * Undeletes a deleted DataField.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function undeleteDatafieldAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            throw new ODRNotImplementedException();

$debug = true;
$debug = false;

            $post = $request->request->all();
//            print_r($post);  return;

            if ( !isset($post['datafield_id']) )
                throw new ODRBadRequestException();
            $datafield_id = $post['datafield_id'];


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows, because we want to display old selected mappings/options


            // need to do checking that stuff won't crash
            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                WHERE df.id = :datafield_id'
            )->setParameters( array('datafield_id' => $datafield_id) );
            $results = $query->getResult();

            // Ensure datafield matches undelete conditions
            /** @var DataFields $datafield */
            $datafield = null;
            if ( count($results) == 1 ) {
                $datafield = $results[0];
                if ($datafield->getDeletedAt() === null) {
                    throw new \Exception('DataField is not deleted.');
                }
            }
            else {
                throw new \Exception('DataField does not exist.');
            }

            // no point undeleting datafield if datatype doesn't exist...
            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() !== null) {
                throw new \Exception('DataType of DataField is deleted.');
            }

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // TODO - must have at least one theme_element_field?  or should it create one if one doesn't exist...will attach to theme element thanks to step 4
            $query = $em->createQuery(
               'SELECT tef
                FROM ODRAdminBundle:ThemeElementField AS tef
                WHERE tef.dataFields = :datafield'
            )->setParameters( array('datafield' => $datafield) );
            $results = $query->getResult();

            if ( count($results) < 1 ) {
                throw new \Exception('No ThemeElementField entry for DataField.');
            }

            // Step 1: undelete the datafield itself
            $datafield->setDeletedAt(null);
            $datafield->setUpdatedBy($user);
            $em->persist($datafield);
            $em->flush();
            $em->refresh($datafield);

            // Step 1.5: re-activate theme_datafield entries for theme 1
            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataFields' => $datafield->getId(), 'theme' => 1) );
            $theme_datafield->setActive(1);
            $em->persist($theme_datafield);

if ($debug)
    print 'undeleted datafield '.$datafield->getId().' of datatype '.$datatype->getId()."\n\n";

            // Step 2: undelete the datarecordfield entries associated with this datafield to recover the data
            $query = $em->createQuery(
               'SELECT drf
                FROM ODRAdminBundle:DataRecordFields AS drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE drf.dataField = :datafield AND dr.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield) );
            $results = $query->getResult();

            /** @var DataRecordFields[] $results */
            foreach ($results as $drf) {
if ($debug)
    print 'datarecordfield '.$drf->getId().'...'."\n";
                $typeclass = $datafield->getFieldType()->getTypeClass();
                if ($typeclass == 'File' || $typeclass == 'Image' || $typeclass == 'Radio') {
                    $drf->setDeletedAt(null);
                    $drf->setUpdatedBy($user);
                    $em->persist($drf);

if ($debug)
    print 'undeleting datarecordfield '.$drf->getId().' on principle because it is a '.$typeclass.'...'."\n";   // TODO - right thing to do?
                }
                else {
                    if ($drf->getAssociatedEntity() !== null && $drf->getAssociatedEntity()->getDeletedAt() === null) { // TODO - these entities technically should never be deleted?
                        if ($drf->getAssociatedEntity()->getFieldType()->getTypeClass() == $datafield->getFieldType()->getTypeClass()) {
                            $drf->setDeletedAt(null);
                            $drf->setUpdatedBy($user);
                            $em->persist($drf);
if ($debug)
    print 'undeleted datarecordfield '.$drf->getId().' of datarecord '.$drf->getDataRecord()->getId()."\n";
                        }
                        else {
if ($debug)
    print 'skipped datarecordfield '.$drf->getId().' of datarecord '.$drf->getDataRecord()->getId().", wrong fieldtype\n";
                        }
                    }
                    else {
if ($debug)
    print 'skipped datarecordfield '.$drf->getId().' of datarecord '.$drf->getDataRecord()->getId().", associated entity is deleted\n";
                    }
                }
            }

            // Step 3: undelete the theme_element_field entries associated with this datafield so it can actually be rendered
            $query = $em->createQuery(
               'SELECT tef
                FROM ODRAdminBundle:ThemeElementField AS tef
                WHERE tef.dataFields = :datafield'
            )->setParameters( array('datafield' => $datafield) );
            $results = $query->getResult();
/*
            // although it should never happen, need to deal with the possibility that there can be more than one theme_element_field for a datafield...
            $theme_element_field = null;
            if ( count($results) > 1 ) {
                // ...going to assume that any undeleted theme_element_field is correct and do nothing
                $all_deleted = true;
                $tmp = null;
                foreach ($results as $tef) {
                    $tmp = $tef;
                    if ($tef->getDeletedAt() === null) {
                        $theme_element_field = $tef;
if ($debug)
    print 'using pre-existing theme_element_field '.$theme_element_field->getId()." for datafield\n";
                        $all_deleted = false;
                        break;
                    }
                }
                // ...if they're all deleted, just undelete the last one...good as any?
                if ($all_deleted) {
                    $tmp->setDeletedAt(null);
                    $tmp->setUpdatedBy($user);
                    $em->persist($tmp);
                    $em->flush();
                    $em->refresh($tmp);

                    $theme_element_field = $tmp;
if ($debug)
    print 'all usable theme_element_field entries deleted, undeleting theme_element_field '.$theme_element_field->getId()."\n";
                }
            }
            else {
*/
            /** @var ThemeElementField[] $results */
            foreach ($results as $theme_element_field) {
                $theme_element_field->setDeletedAt(null);
                $theme_element_field->setUpdatedBy($user);
                $em->persist($theme_element_field);
                $em->flush();
                $em->refresh($theme_element_field);

if ($debug)
    print 'undeleting theme_element_field '.$theme_element_field->getId()."\n";
            }


            // Step 4: move the theme_element_field to a different theme_element if the original theme_element got deleted
            $query = $em->createQuery(
               'SELECT te
                FROM ODRAdminBundle:ThemeElementField AS tef
                JOIN ODRAdminBundle:ThemeElement te WITH tef.themeElement = te
                WHERE tef.dataFields = :datafield AND te.theme = :theme'
            )->setParameters( array('datafield' => $datafield->getId(), 'theme' => 1) );
            $results = $query->getResult();

            /** @var ThemeElement $theme_element */
            $theme_element = $results[0];
            if ($theme_element->getDeletedAt() !== NULL) {
                // theme_element IS DELETED
if ($debug)
    print '-- theme_element '.$theme_element->getId().' deleted, locating new theme_element...'."\n";

                // need to locate the first datafield-oriented theme element in this datatype
                /** @var ThemeElement[] $theme_elements */
                $theme_elements = array();
                foreach ($datatype->getThemeElement() as $te) {
                    if ($te->getDeletedAt() === NULL && $te->getTheme()->getID() == 1)   // TODO - deleted check needed?
                        $theme_elements[$te->getDisplayOrder()] = $te;
                }

                $found = false;
                foreach ($theme_elements as $display_order => $te) {
                    foreach ($te->getThemeElementField() as $tef) {
                        if ($tef->getDeletedAt() === NULL && $tef->getDataFields() !== null) {  // TODO - deleted check needed?
                            $theme_element_field->setThemeElement( $tef->getThemeElement() );
                            $em->persist($theme_element_field);

if ($debug)
    print "-- attaching theme_element_field to theme_element ".$tef->getThemeElement()->getId()."\n";

                            $theme_element = $tef->getThemeElement();   // save for the return value
                            $found = true;
                            break;
                        }
                    }

                    if ($found)
                        break;
                }
            }
            $em->flush();

            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            $return['d'] = array(
//                'datatype_id' => $datafield->getDataType()->getId(),
                'theme_element_id' => $theme_element->getId()
            );

        }
        catch (\Exception $e) {
            $em->getFilters()->enable('softdeleteable');    // Re-enable the filter

            $source = 0xf3b47c90;
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
     * Toggles the public status of a DataType.
     *
     * @param integer $datatype_id The database id of the DataType to modify.
     * @param Request $request
     *
     * @return Response
     */
    public function datatypepublicAction($datatype_id, Request $request)
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


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // If the datatype is public, make it non-public...if datatype is non-public, make it public
            if ( $datatype->isPublic() ) {
                // Make the datatype non-public
                $properties = array(
                    'publicDate' => new \DateTime('2200-01-01 00:00:00')
                );
                $emm_service->updateDatatypeMeta($user, $datatype, $properties);
            }
            else {
                // Make the datatype public
                $properties = array(
                    'publicDate' => new \DateTime()
                );
                $emm_service->updateDatatypeMeta($user, $datatype, $properties);
            }

            // Updated cached version of datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Don't need to update cached datarecords or themes
        }
        catch (\Exception $e) {
            $source = 0xe2231afc;
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
     * Toggles the public status of a Datafield.
     *
     * @param integer $datafield_id The database id of the Datafield to modify.
     * @param Request $request
     *
     * @return Response
     */
    public function datafieldpublicAction($datafield_id, Request $request)
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
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


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


            // If the datafield is public, make it non-public...if datafield is non-public, make it public
            if ( $datafield->isPublic() ) {
                // Make the datafield non-public
                $properties = array(
                    'publicDate' => new \DateTime('2200-01-01 00:00:00')
                );
                $emm_service->updateDatafieldMeta($user, $datafield, $properties);
            }
            else {
                // Make the datafield public
                $properties = array(
                    'publicDate' => new \DateTime()
                );
                $emm_service->updateDatafieldMeta($user, $datafield, $properties);
            }

            // Update cached version of datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Don't need to update cached datarecords or themes

            // Do need to clear some search cache entries
            $search_cache_service->onDatafieldPublicStatusChange($datafield);
        }
        catch (\Exception $e) {
            $source = 0xbd3dc347;
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
     * Checks to see whether the given Datafield can be marked as unique or not.
     * TODO - move into a datafield info service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataFields $datafield
     *
     * @return boolean true if the datafield has no duplicate values, false otherwise
     */
    private function datafieldCanBeUnique($em, $datafield)
    {
        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');

        // Going to need these...
        $datafield_id = $datafield->getId();
        $datatype = $datafield->getDataType();
        $datatype_id = $datatype->getId();
        $fieldtype = $datafield->getFieldType();
        $typeclass = $fieldtype->getTypeClass();

        // Only run queries if field can be set to unique
        if ($fieldtype->getCanBeUnique() == 0)
            return false;

        // Determine if this datafield belongs to a top-level datatype or not
        $is_child_datatype = false;
        $datatree_array = $dti_service->getDatatreeArray();
        if ( isset($datatree_array['descendant_of'][$datatype_id]) && $datatree_array['descendant_of'][$datatype_id] !== '' )
            $is_child_datatype = true;

        if ( !$is_child_datatype ) {
            // Get a list of all values in the datafield
            $query = $em->createQuery(
               'SELECT e.value
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE e.dataField = :datafield
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            $results = $query->getArrayResult();

            // Determine if there are any duplicates in the datafield...
            $values = array();
            foreach ($results as $result) {
                $value = $result['value'];
                if ( isset($values[$value]) )
                    // Found duplicate, return false
                    return false;
                else
                    // Found new value, save and continue checking
                    $values[$value] = 1;
            }
        }
        else {
            // Get a list of all values in the datafield, grouped by parent datarecord
            $query = $em->createQuery(
               'SELECT e.value, parent.id AS parent_id
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
                WHERE e.dataField = :datafield
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield_id) );
            $results = $query->getArrayResult();

            // Determine if there are any duplicates in the datafield...
            $values = array();
            foreach ($results as $result) {
                $value = $result['value'];
                $parent_id = $result['parent_id'];

                if ( !isset($values[$parent_id]) )
                    $values[$parent_id] = array();

                if ( isset($values[$parent_id][$value]) )
                    // Found duplicate, return false
                    return false;
                else
                    // Found new value, save and continue checking
                    $values[$parent_id][$value] = 1;
            }
        }

        // Didn't find a duplicate, return true
        return true;
    }


    /**
     * This otherwise trivial controller action is needed in order to work with the modal dialog...
     *
     * @param Request $request
     *
     * @return Response
     */
    public function markdownhelpAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $return['d'] = array(
                'html' => $this->get('templating')->render(
                    'ODRAdminBundle:Displaytemplate:markdown_help_dialog_form.html.twig'
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x6c5fbda1;
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
     * Saves changes to search notes from the search page.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function savesearchnotesAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $post = $request->request->all();

            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            if ( !isset($post['upper_value']) || !isset($post['lower_value']) )
                throw new ODRBadRequestException('Invalid Form');


            // Set the properties array correctly and save to the database
            $properties = array(
                'searchNotesUpper' => $post['upper_value'],
                'searchNotesLower' => $post['lower_value'],
            );
            $emm_service->updateDatatypeMeta($user, $datatype, $properties);

            // TODO - return something?
        }
        catch (\Exception $e) {
            $source = 0xc3bf4313;
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
     * It's possible that the process of synchronizing a datatype with its master template could
     * take long enough for a single request to time out, so for safety it needs to be handled by
     * a background process...
     *
     * @param int $datatype_id
     * @param bool $sync_metadata
     * @param Request $request
     *
     * @return Response
     */
    public function syncwithtemplateAction($datatype_id, $sync_metadata, Request $request)
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
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Don't start the synchronization process if it's pointless to do so
            if ( !$clone_template_service->canSyncWithTemplate($datatype, $user) )
                throw new ODRBadRequestException('The derived datatype is already synchronized');

            // If $sync_metadata is true, then this action needs to have been called on a metadata
            //  datatype...
            if ( $sync_metadata && is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to sync metadata for a datatype that does not have metadata');


            // ----------------------------------------
            // Grab necessary stuff for pheanstalk...
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');

            $url = $this->container->getParameter('site_baseurl');
            $url .= $this->container->get('router')->generate('odr_sync_with_template_worker');

            // Create a job and insert into beanstalk's queue
//            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    "user_id" => $user->getId(),
                    "datatype_id" => $datatype->getId(),

                    "redis_prefix" => $redis_prefix,    // debug purposes only
                    "url" => $url,
                    "api_key" => $api_key,
                )
            );

            $pheanstalk->useTube('synch_template')->put($payload);

            // TODO - this checker construct needs a database entry, but i'm pretty sure this isn't the intended use
            $datatype->setDatatypeType('synchronizing');
            $em->persist($datatype);
            $em->flush();


            // ----------------------------------------
            // Redirect the user to the status checker
            $return['d'] = array(
                'url' => $this->generateUrl(
                    'odr_design_check_sync_with_template',
                    array(
                        'datatype_id' => $datatype->getId(),
                        'sync_metadata' => $sync_metadata
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x0d869f58;
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
     * It's possible that the process of synchronizing a datatype with its master template could
     * take long enough for a single request to time out, so there needs to be a controller action
     * to check on the progress of the background process...
     *
     * @param int $datatype_id
     * @param bool $sync_metadata
     * @param Request $request
     *
     * @return Response
     */
    public function checksyncwithtemplateAction($datatype_id, $sync_metadata, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $templating = $this->get('templating');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // If $sync_metadata is true, then this action needs to have been called on a metadata
            //  datatype...
            if ( $sync_metadata && is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to sync metadata for a datatype that does not have metadata');


            // Ensure the in-memory version of the datatype is up to date
            $em->refresh($datatype);
            if ($datatype->getDatatypeType() === 'synchronizing') {
                // The datatype is still being synchronized
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Datatype:sync_status_checker.html.twig',
                        array(
                            'datatype' => $datatype,
                            'sync_metadata' => $sync_metadata,
                        )
                    )
                );
            }
            else {
                // The datatype is done being synchronized

                // Usually want to redirect the user back to the design page of the datatype that
                //  just got synchronized...
                $target_datatype = $datatype;
                if ($sync_metadata) {
                    // ...unless they clicked on the link to synchronize this datatype's metadata
                    //  datatype instead
                    $target_datatype = $datatype->getMetadataFor();
                }

                // Redirect the user back to the correct datatype
                $url = $this->generateUrl(
                    'odr_design_master_theme',
                    array(
                        'datatype_id' => $target_datatype->getId()
                    ),
                    false
                );

                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Datatype:sync_status_checker_redirect.html.twig',
                        array(
                            "url" => $url
                        )
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x9f58b300;
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
