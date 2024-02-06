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

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\StoredSearchKey;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldCreatedEvent;
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatatypeCreatedEvent;
use ODR\AdminBundle\Component\Event\DatatypeModifiedEvent;
use ODR\AdminBundle\Component\Event\DatatypePublicStatusChangedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
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
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatafieldInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityDeletionService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchSidebarService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
// Symfony
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Templating\EngineInterface;


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

            /** @var EntityDeletionService $entity_deletion_service */
            $entity_deletion_service = $this->container->get('odr.entity_deletion_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


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
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // deleteDatafield() will throw an exception if the datafield shouldn't be deleted
            $entity_deletion_service->deleteDatafield($datafield, $user);

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

            /** @var EntityDeletionService $entity_deletion_service */
            $entity_deletion_service = $this->container->get('odr.entity_deletion_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
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
            // deleteDatatype() will throw an exception if the datafield shouldn't be deleted
            $entity_deletion_service->deleteDatatype($datatype, $user);

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

            /** @var EngineInterface $templating */
            $templating = $this->get('templating');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Check if this is a master template based datatype that is still in the creation process...
            $return['t'] = "html";

            if ($datatype->getSetupStep() == DataType::STATE_CLONE_FAIL) {
                throw new ODRException('Cloning failure, please contact the ODR team');
            }
            else if ($datatype->getSetupStep() == DataType::STATE_INITIAL && $datatype->getMasterDataType() != null) {
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
                $baseurl = 'https:'.$this->getParameter('site_baseurl').'/';
                if ( $this->container->getParameter('kernel.environment') === 'dev' )
                    $baseurl .= 'app_dev.php/';

                if ($datatype->getMetadataFor() !== null)  {
                    // Properties datatype - redirect to properties page
                    $baseurl .= $datatype->getMetadataFor()->getSearchSlug();

                    $url = $this->generateUrl(
                        'odr_datatype_properties',
                        array(
                            'datatype_id' => $datatype->getMetadataFor()->getId(),
                            'wizard' => 1
                        )
                    );
                }
                else {
                    // Redirect to master layout page
                    $baseurl .= $datatype->getSearchSlug();

                    $url = $this->generateUrl(
                        'odr_design_master_theme',
                        array(
                            'datatype_id' => $datatype->getId(),
                        )
                    );
                }

                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Datatype:create_status_checker_redirect.html.twig',
                        array(
                            'url' => $baseurl.'#'.$url
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

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


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
            if ($datatype->getSetupStep() == DataType::STATE_CLONE_FAIL) {
                throw new ODRException('Cloning failure, please contact the ODR team');
            }
            else if ($datatype->getSetupStep() == DataType::STATE_INITIAL && $datatype->getMasterDataType() != null) {
                // The database is still in the process of being created...return the HTML for the page that'll periodically check for progress
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
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
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

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


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
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure this is only called on a 'master' theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Datafields can only be added to a "master" theme');

            // Ensure there's nothing in this theme_element before creating a new datafield
            if ( $theme_element->getThemeDataType()->count() > 0 )
                throw new ODRBadRequestException('Unable to add a new Datafield into a ThemeElement that already has a child/linked Datatype');
            if ( $theme_element->getThemeRenderPluginInstance()->count() > 0 )
                throw new ODRBadRequestException('Unable to add a new Datafield into a ThemeElement that is being used by a RenderPlugin');


            // Going to need these...
            $parent_theme = $theme->getParentTheme();
            $source_theme = $theme->getSourceTheme();

            // Check whether the user is trying to add a datafield to a linked datatype...
            $parent_theme_datatype_id = $parent_theme->getDataType()->getGrandparent()->getId();
            $grandparent_datatype_id = $datatype->getGrandparent()->getId();

            // ...because performing this action on a linked datatype is different than when adding
            //  to the local datatype or one of its children
            $add_to_linked_datatype = false;
            if ($grandparent_datatype_id !== $parent_theme_datatype_id)
                $add_to_linked_datatype = true;


            // Grab objects required to create a datafield entity
            /** @var FieldType $fieldtype */
            $fieldtype = $em->getRepository('ODRAdminBundle:FieldType')->findOneBy( array('typeName' => 'Short Text') );

            // Create the datafield...works the same whether it's for a local or a linked datatype
            $datafield = $entity_create_service->createDatafield($user, $datatype, $fieldtype, true);    // Don't flush immediately...

            // Don't need to worry about datafield permissions here, those are taken care of inside createDatafield()

            // Tie the datafield to the specified theme element regardless of whether it's a local
            //  or a linked datatype...the new field MUST become visible where the user expects it
            //  to be, and using CloneThemeService::syncThemeWithSource() will instead place the
            //  field in a hidden themeElement (although design mode ignores hidden themeElements)
            $entity_create_service->createThemeDatafield($user, $theme_element, $datafield, true);    // Don't flush immediately...

            if ( $add_to_linked_datatype ) {
                // When adding to a linked datatype...at this point there's a ThemeDatafield entry
                //  in the local datatype's copy of the linked datatype's theme.  The new datafield
                //  also needs a ThemeDatafield entry in the linked datatype's master theme, otherwise
                //  it won't show up there.
                $theme_data = $theme_info_service->getThemeArray($source_theme->getId());

                // Since there's not necessarily a correlation between the ThemeElements in the linked
                //  datatype's master theme and the ThemeElements in the local datatype's copy of
                //  the linked datatype's theme...
                $added = false;
                if ( !empty($theme_data) ) {    // $theme_data will be empty when $source_theme has no ThemeElements
                    foreach ($theme_data[$source_theme->getId()]['themeElements'] as $te_num => $te) {
                        if ( $te['themeElementMeta']['hidden'] == 0 ) {
                            if ( isset($te['themeDataFields']) || !isset($te['themeDataType']) ) {
                                // ...find and use the first visible ThemeElement that either already
                                //  has datafields, or at least is not being used for a child/linked
                                //  datatype
                                /** @var ThemeElement $linked_theme_element */
                                $linked_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find( $te['id'] );

                                // Create a ThemeDatafield entry so the new datafield shows up in
                                //  the ThemeElement that was just located
                                $entity_create_service->createThemeDatafield($user, $linked_theme_element, $datafield, true);    // Don't flush immediately...

                                // Don't need to look for another themeElement
                                $added = true;
                                break;
                            }
                        }
                    }
                }

                // The linked datatype isn't guaranteed to have a suitable ThemeElement, however...
                if (!$added) {
                    // ...in which case a new ThemeElement needs to get created...
                    $new_te = $entity_create_service->createThemeElement($user, $source_theme, true);    // Don't flush immediately...
                    // ...so the new datafield can be attached to it
                    $entity_create_service->createThemeDatafield($user, $new_te, $datafield, true);    // Don't flush immediately...
                }

                // Increment the linked datatype's master theme's "sourceSyncVersion" property so
                //  that all themes derived from it know they need to get updated...otherwise the
                //  new datafield won't appear in these derived themes
                $properties = array(
                    'sourceSyncVersion' => $source_theme->getSourceSyncVersion() + 1
                );
                $entity_modify_service->updateThemeMeta($user, $source_theme, $properties, true);    // Don't flush immediately...
            }

            // Adding a new datafield requires an update of the "master_revision" property of the
            //  datatype it got added to
            if ( $datatype->getIsMasterType() )
                $entity_modify_service->incrementDatatypeMasterRevision($user, $datatype, true);    // Don't flush immediately...

            // Increment the "sourceSyncVersion" property of the theme that just received the new
            //  datafield, so that all derived/search results themes know they need update themselves
            //  with the new datafield
            $properties = array(
                'sourceSyncVersion' => $theme->getSourceSyncVersion() + 1
            );
            $entity_modify_service->updateThemeMeta($user, $theme, $properties);    // Flush here


            // ----------------------------------------
            // Notify that a datafield was just created
            try {
                $event = new DatafieldCreatedEvent($datafield, $user);
                $dispatcher->dispatch(DatafieldCreatedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Update the cached version of the datatype and its master theme
            try {
                $event = new DatatypeModifiedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            $theme_info_service->updateThemeCacheEntry($theme, $user);

            if ( $add_to_linked_datatype ) {
                // If the field was added to a linked datatype, then need to also mark the linked
                //  datatype's master theme as updated
                $theme_info_service->updateThemeCacheEntry($source_theme, $user);
            }

            // NOTE: this doesn't need to clear any of the datarecord caches...they're built/used
            //  under the assumption that a "missing" datafield means "no value"


            // ----------------------------------------
            if ( !$add_to_linked_datatype ) {
                // If not adding to a linked datatype, then reloading the theme element is sufficient
                $return['d'] = array(
                    'reload' => 'theme_element',
                    'id' => $theme_element_id
                );
            }
            else {
                // If adding to a linked datatype, then all instances of that datatype that are
                //  currently displayed need to be reloaded
                $return['d'] = array(
                    'reload' => 'datatype',
                    'id' => $source_theme->getDataType()->getId()
                );
            }

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

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatafieldInfoService $datafield_info_service */
            $datafield_info_service = $this->container->get('odr.datafield_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


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
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Don't allow cloning of a datafield outside the master theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to clone a datafield outside of a "master" theme');

            // Ensure there's nothing in this theme_element before creating a new datafield
            if ( $theme_element->getThemeDataType()->count() > 0 )
                throw new ODRBadRequestException('Unable to add a new Datafield into a ThemeElement that already has a child/linked Datatype');
            if ( $theme_element->getThemeRenderPluginInstance()->count() > 0 )
                throw new ODRBadRequestException('Unable to add a new Datafield into a ThemeElement that is being used by a RenderPlugin');


            // This should not work on a datafield that is derived from a master template
            if ( !is_null($old_datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to clone a derived field');
            // TODO - ...allow this to happen, but clear any master datafield stuff?  Meh...

            // Several other conditions can prevent copying of a datafield too
            $datatype_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links
            $datafield_properties = $datafield_info_service->getDatafieldProperties($datatype_array, $old_datafield->getId());
            if ( !$datafield_properties['can_copy'] )
                throw new ODRBadRequestException('Unable to clone this field');


            // Going to need these...
            $parent_theme = $theme->getParentTheme();
            $source_theme = $theme->getSourceTheme();

            // Check whether the user is trying to copy a datafield in a linked datatype...
            $parent_theme_datatype_id = $parent_theme->getDataType()->getGrandparent()->getId();
            $grandparent_datatype_id = $datatype->getGrandparent()->getId();

            // ...because copying a datafield is handled differently when it belongs to a linked
            //  datatype
            $add_to_linked_datatype = false;
            if ($grandparent_datatype_id !== $parent_theme_datatype_id)
                $add_to_linked_datatype = true;


            // ----------------------------------------
            // Clone the old datafield...
            /** @var DataFields $new_df */
            $new_df = clone $old_datafield;

            // TODO - any other properties that need resetting when a copy occurs?
            $new_df->setMasterDataField(null);

            // Ensure the "in-memory" version of $datatype knows about the new datafield
            $datatype->addDataField($new_df);
            self::persistObject($em, $new_df, $user, true);    // Don't flush immediately...


            // Clone the old datafield's meta entry...
            /** @var DataFieldsMeta $new_df_meta */
            $new_df_meta = clone $old_datafield->getDataFieldMeta();
            $new_df_meta->setDataField($new_df);
            $new_df_meta->setFieldName('Copy of '.$old_datafield->getFieldName());

            $new_df_meta->setMasterRevision(0);
            $new_df_meta->setMasterPublishedRevision(0);
            $new_df_meta->setTrackingMasterRevision(0);

            // Ensure the "in-memory" version of $new_df knows about the new meta entry
            $new_df->addDataFieldMetum($new_df_meta);
            self::persistObject($em, $new_df_meta, $user, true);    // Don't flush immediately...

            // Need to create the groups for the new datafield...
            $entity_create_service->createGroupsForDatafield($user, $new_df, true);    // Don't flush immediately...


            // Clone the old datafield's ThemeDatafield entry so it shows up on the page...
            /** @var ThemeDataField $new_tdf */
            $new_tdf = clone $old_theme_datafield;
            $new_tdf->setDataField($new_df);
            // Intentionally not changing displayOrder...new field should appear just after the
            //  old datafield, in theory

            // Ensure the "in-memory" theme_element knows about the new theme_datafield entry
            $theme_element->addThemeDataField($new_tdf);
            self::persistObject($em, $new_tdf, $user, true);    // Don't flush immediately...

            if ($add_to_linked_datatype) {
                // When copying to a linked datatype...at this point there's a ThemeDatafield entry
                //  in the local datatype's copy of the linked datatype's theme.  The new datafield
                //  also needs a ThemeDatafield entry in the linked datatype's master theme, otherwise
                //  it won't show up there.
                $theme_data = $theme_info_service->getThemeArray($source_theme->getId());

                // Might as well ensure the copied datafield appears immediately after its source
                //  datafield in the linked datatype's master theme...
                foreach ($theme_data[$source_theme->getId()]['themeElements'] as $te_num => $te) {
                    if ( isset($te['themeDataFields']) ) {
                        foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                            if ( $tdf['dataField']['id'] === $old_datafield->getId() ) {
                                /** @var ThemeDataField $linked_tdf */
                                $linked_tdf = $em->getRepository('ODRAdminBundle:ThemeDataField')->find( $tdf['id'] );

                                $new_linked_tdf = clone $linked_tdf;
                                $new_linked_tdf->setDataField($new_df);
                                // Intentionally not changing displayOrder...new field should appear just after the
                                //  old datafield, in theory

                                self::persistObject($em, $new_linked_tdf, $user, true);    // Don't flush immediately...
                            }
                        }
                    }
                }

                // Increment the linked datatype's master theme's "sourceSyncVersion" property so
                //  that all themes derived from it know they need to get updated...otherwise the
                //  new datafield won't appear in these derived themes
                $properties = array(
                    'sourceSyncVersion' => $source_theme->getSourceSyncVersion() + 1
                );
                $entity_modify_service->updateThemeMeta($user, $source_theme, $properties, true);    // Don't flush immediately...
            }

            // Copying a datafield requires an update of the "master_revision" property of the
            //  datatype it got added to
            if ( $datatype->getIsMasterType() )
                $entity_modify_service->incrementDatatypeMasterRevision($user, $datatype, true);    // Don't flush immediately...

            // Increment the "sourceSyncVersion" property of the theme that just received the new
            //  datafield, so that all derived/search results themes know they need update themselves
            //  with the new datafield
            $properties = array(
                'sourceSyncVersion' => $theme->getSourceSyncVersion() + 1
            );
            $entity_modify_service->updateThemeMeta($user, $theme, $properties);    // Flush here


            // ----------------------------------------
            // Notify that a datafield was just created
            try {
                $event = new DatafieldCreatedEvent($new_df, $user);
                $dispatcher->dispatch(DatafieldCreatedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Updated the cached version of the datatype and its master theme
            try {
                $event = new DatatypeModifiedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            $theme_info_service->updateThemeCacheEntry($theme, $user);

            if ( $add_to_linked_datatype ) {
                // If the field was copied to a linked datatype, then need to also mark the linked
                //  datatype's master theme as updated
                $theme_info_service->updateThemeCacheEntry($source_theme, $user);
            }

            // NOTE: this doesn't need to clear any of the datarecord caches...they're built/used
            //  under the assumption that a "missing" datafield means "no value"


            // ----------------------------------------
            if ( !$add_to_linked_datatype ) {
                // If not copying a field inside a linked datatype, then reloading the theme element
                //  is sufficient
                $return['d'] = array(
                    'reload' => 'theme_element',
                    'id' => $theme_element_id
                );
            }
            else {
                // If copying a field inside a linked datatype, then all instances of that datatype
                //  that are currently displayed need to be reloaded
                $return['d'] = array(
                    'reload' => 'datatype',
                    'id' => $source_theme->getDataType()->getId()
                );
            }

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
     * @param bool $delay_flush If true, don't flush immediately
     */
    private function persistObject($em, $obj, $user, $delay_flush = false)
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

        if ($delay_flush) {
            $em->flush();
            $em->refresh($obj);
        }
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

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


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
            if ( !$permissions_service->isDatatypeAdmin($user, $parent_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure that this action isn't being called on a derivative theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to create a new child Datatype outside of the master Theme');

            // Ensure there's nothing in this theme_element before creating a child datatype
            if ( $theme_element->getThemeDataFields()->count() > 0 )
                throw new ODRBadRequestException('Unable to add a child Datatype into a ThemeElement that already has Datafields');
            if ( $theme_element->getThemeDataType()->count() > 0 )
                throw new ODRBadRequestException('Unable to add a child Datatype into a ThemeElement that already has a child/linked Datatype');
            if ( $theme_element->getThemeRenderPluginInstance()->count() > 0 )
                throw new ODRBadRequestException('Unable to add a child Datatype into a ThemeElement that is being used by a RenderPlugin');


            // Going to need these...
            $parent_theme = $theme->getParentTheme();
            $source_theme = $theme->getSourceTheme();

            // Check whether the user is trying to add a child datatype to a linked datatype...
            $parent_theme_datatype_id = $parent_theme->getDataType()->getGrandparent()->getId();
            $grandparent_datatype_id = $parent_datatype->getGrandparent()->getId();

            // ...because adding a child datatype to a linked datatype is more complicated
            $add_to_linked_datatype = false;
            if ($grandparent_datatype_id !== $parent_theme_datatype_id)
                $add_to_linked_datatype = true;


            // ----------------------------------------
            // Create the new child datatype...
            $child_datatype = $entity_create_service->createDatatype($user, 'New Child', true);    // Don't flush immediately...

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
            $entity_create_service->createDatatree($user, $parent_datatype, $child_datatype, $is_link, $multiple_allowed, true);    // don't flush immediately...


            // ----------------------------------------
            // If the new child datatype is being added to a linked datatype...
            $child_source_theme = null;
            if ( $add_to_linked_datatype ) {
                // ...then it needs to be created with two Themes...a "master" Theme belonging to
                //  the linked datatype's master theme, and a second Theme representing a "copy" in
                //  the local datatype theme (defined after this block)
                $child_source_theme = $entity_create_service->createTheme($user, $child_datatype, true);    // don't flush immediately...
                $child_source_theme->setParentTheme($source_theme->getParentTheme());    // this theme belongs with the rest of the "master" themes for the linked datatype
                $child_source_theme->setSourceTheme($child_source_theme);    // this is *the* master theme for this child datatype, so should use itself as source
                $em->persist($child_source_theme);

                // Need to inherit the default/shared settings from the parent theme
                $child_source_theme_meta = $child_source_theme->getThemeMeta();
                $child_source_theme_meta->setDefaultFor($source_theme->getDefaultFor());
                $child_source_theme_meta->setShared($source_theme->isShared());
                $em->persist($child_source_theme_meta);
            }

            // Create a new Theme for this child datatype, attached to the local datatype's
            //  master Theme...
            $child_theme = $entity_create_service->createTheme($user, $child_datatype, true);    // don't flush immediately...
            $child_theme->setParentTheme($theme->getParentTheme());    // this theme belongs with the rest of the "master" themes for the local datatype
            if ( !$add_to_linked_datatype )
                $child_theme->setSourceTheme($child_theme);    // not being created for a linked datatype, so should use itself as source
            else
                $child_theme->setSourceTheme($child_source_theme);    // being created for a linked datatype, so should use the previously created theme as source instead
            $em->persist($child_theme);

            // Need to inherit the default/shared settings from the parent theme
            $child_theme_meta = $child_theme->getThemeMeta();
            $child_theme_meta->setDefaultFor($theme->getDefaultFor());
            $child_theme_meta->setShared($theme->isShared());
            $em->persist($child_theme_meta);


            if ( $add_to_linked_datatype ) {
                // When adding to a linked datatype, two ThemeDatatype entries need to be created...
                //  one for the linked datatype, and another for the local datatype (defined after
                //  this block).  Without both of these, the new child datatype won't show up

                // Don't bother looking for an empty ThemeElement, just create a new one...
                $new_te = $entity_create_service->createThemeElement($user, $source_theme, true);    // Don't flush immediately...
                // ...so the new child datatype can be attached to it
                $entity_create_service->createThemeDatatype($user, $new_te, $child_datatype, $child_source_theme, true);    // Don't flush immediately...

                // Increment the linked datatype's master theme's "sourceSyncVersion" property so
                //  that all themes derived from it know they need to get updated...otherwise the
                //  new datafield won't appear in these derived themes
                $properties = array(
                    'sourceSyncVersion' => $new_te->getTheme()->getSourceSyncVersion() + 1
                );
                $entity_modify_service->updateThemeMeta($user, $source_theme, $properties, true);    // Don't flush immediately...
            }

            // Create a new ThemeDatatype entry to let the renderer know it has to render a child
            //  datatype in this ThemeElement
            $entity_create_service->createThemeDatatype($user, $theme_element, $child_datatype, $child_theme, true);    // don't flush immediately...

            // Since a child datatype was added, any themes that use this master theme as their
            //  source need to get updated themselves
            $properties = array(
                'sourceSyncVersion' => $theme->getSourceSyncVersion() + 1
            );
            $entity_modify_service->updateThemeMeta($user, $theme, $properties, true);    // don't flush immediately

            // Adding a new child datatype requires an update of the "master_revision" property of
            //  the datatype it got added to
            if ( $child_datatype->getParent()->getIsMasterType() )
                $entity_modify_service->incrementDatatypeMasterRevision($user, $child_datatype->getParent(), true);    // don't flush immediately...


            // ----------------------------------------
            // Now that most of the required entities have been created, flush and reload the child
            //  datatype so that native SQL queries can copy groups for this child datatype
            $em->flush();
            $em->refresh($child_datatype);

            // Create the default groups for this child datatype
            $entity_create_service->createGroupsForDatatype($user, $child_datatype);

            // Child datatype should be fully operational now
            $child_datatype->setSetupStep(DataType::STATE_OPERATIONAL);
            $em->persist($child_datatype);
            $em->flush();


            // ----------------------------------------
            if ( $add_to_linked_datatype ) {
                // If the child datatype was added to a linked datatype, then need to also mark the
                //  linked datatype's master theme as updated
                $theme_info_service->updateThemeCacheEntry($source_theme, $user);
            }
            // Do the same for the cached version of this theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);


            // ----------------------------------------
            // Fire off a DatatypeCreated event for the new child datatype
            try {
                $event = new DatatypeCreatedEvent($child_datatype, $user);
                $dispatcher->dispatch(DatatypeCreatedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Mark the new child datatype's parent as updated
            try {
                $event = new DatatypeModifiedEvent($parent_datatype, $user);
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            // ----------------------------------------
            if ( !$add_to_linked_datatype ) {
                // If not creating a childtype inside a linked datatype, then reloading the theme
                //  element is sufficient
                $return['d'] = array(
                    'reload' => 'theme_element',
                    'id' => $theme_element_id
                );
            }
            else {
                // If creating a childtype inside a linked datatype, then all instances of that
                //  datatype that are currently displayed need to be reloaded
                $return['d'] = array(
                    'reload' => 'datatype',
                    'id' => $source_theme->getDataType()->getId()
                );
            }
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
     * @param integer $theme_element_id Which ThemeElement to reload
     * @param Request $request
     *
     * @return Response
     */
    public function reloadthemeelementAction($theme_element_id, Request $request)
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
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ( !is_null($theme->getDeletedAt()) )
                throw new ODRNotFoundException('Theme');
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException("Not allowed to re-render something that doesn't belong to the master Theme");


            // The provided theme_element may be referencing a "copy" of the master theme for a
            //  linked (remote) datatype...these copies exist specifically to allow users to be able
            //  to change how a "remote" datatype looks from the context of the "local" datatype.
            // While the controller action needs to work on the provided theme_element, any
            //  permissions checking needs to instead be run against the local datatype.
            $grandparent_theme = $theme->getParentTheme();
            if ( !is_null($grandparent_theme->getDeletedAt()) )
                throw new ODRNotFoundException('Grandparent Theme');

            $grandparent_datatype = $grandparent_theme->getDataType();
            if ( !is_null($grandparent_datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Grandparent Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $grandparent_datatype) )
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
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


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
            $theme_info_service->getDatatypeMasterTheme($datatype->getId());


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $source_datatype) || !$permissions_service->canViewDatafield($user, $datafield) )
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
     * Loads/saves a DataType properties Form, and also loads the related Datatree and ThemeDataType
     * properties forms as well when $datatype_id is a child of, or linked to by, $parent_datatype_id
     *
     * @param integer $datatype_id The database id of the Datatype that is being modified
     * @param mixed $parent_datatype_id Either the id of the Datatype of the parent of $datatype_id, or the empty string
     * @param mixed $theme_element_id The ThemeElement containing the (child/linked) Datatype, or the empty string if top-level
     * @param Request $request
     *
     * @return Response
     */
    public function datatypepropertiesAction($datatype_id, $parent_datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = array();

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $site_baseurl = $request->getSchemeAndHttpHost();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatafieldInfoService $datafield_info_service */
            $datafield_info_service = $this->container->get('odr.datafield_info_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // Either both $parent_datatype_id and $theme_element_id have to be the empty string, or
            //  they both have to have a value
            if ( ($parent_datatype_id === '' && $theme_element_id !== '')
                || ($parent_datatype_id !== '' && $theme_element_id === '')
            ) {
                throw new ODRBadRequestException();
            }


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( is_null($datatype) )
                throw new ODRNotFoundException('Datatype');

            /** @var ThemeElement|null $theme_element */
            $theme_element = null;
            if ( $theme_element_id !== '' ) {
                $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
                if ( is_null($theme_element) )
                    throw new ODRNotFoundException('ThemeElement');
            }


            // The provided datatype/theme_element may be referencing a "copy" of the master theme
            //  for a linked (remote) datatype...these copies exist specifically to allow users to be
            //  able to change how a "remote" datatype looks from the context of the "local" datatype.
            // While the controller action needs to work on the provided datatype/theme_element, any
            //  permissions checking needs to instead be run against the local datatype.
            /** @var Theme|null $grandparent_theme */
            $grandparent_theme = null;
            $grandparent_datatype = null;
            if ( !is_null($theme_element) ) {
                $grandparent_theme = $theme_element->getTheme()->getParentTheme();
                if ( !is_null($grandparent_theme->getDeletedAt()) )
                    throw new ODRNotFoundException('Grandparent Theme');

                $grandparent_datatype = $grandparent_theme->getDataType();
                if ( !is_null($grandparent_datatype->getDeletedAt()) )
                    throw new ODRNotFoundException('Grandparent Datatype');
            }

            // If $grandparent_datatype is still null, then fall back to the grandparent of $datatype
            if ( is_null($grandparent_datatype) )
                $grandparent_datatype = $datatype->getGrandparent();
            /** @var DataType $grandparent_datatype */


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $permissions_service->getUserPermissionsArray($user);

            // Intentionally checking permissions against $grandparent_datatype here...users that aren't
            //  admins of linked datatypes still need to be able to view the Datatree and ThemeDatatype
            //  forms that describe the link to the remote datatype
            if ( !$permissions_service->isDatatypeAdmin($user, $grandparent_datatype) )
                throw new ODRForbiddenException();

            // Still need to store whether the user is an admin of the datatype that got clicked on,
            //  since they might not actually be allowed to modify it
            $is_target_datatype_admin = $permissions_service->isDatatypeAdmin($user, $datatype);
            // --------------------

            // The dialog to change searchable/public status for multiple datafields at once depends
            //  on whether the user can make changes to the datatype in question...NOT whatever is
            //  in $grandparent_datatype
            $can_open_multi_df_dialog = $permissions_service->isDatatypeAdmin($user, $datatype);


            // If $parent_datatype_id is set, locate the datatree and theme_datatype entities
            //  linking $datatype_id and $parent_datatype_id
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

                $theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDatatype')->findOneBy(
                    array(
                        'themeElement' => $theme_element_id,
                        'dataType' => $datatype_id,    // the id of the child/linked datatype
                    )
                );
                if ( $theme_datatype == null )
                    throw new ODRNotFoundException('ThemeDatatype');
            }


            // ----------------------------------------
            // These variables are used both for rendering and for validation
            $is_top_level = true;
            if ( $parent_datatype_id !== '' && $parent_datatype_id !== $datatype_id )
                $is_top_level = false;

            $is_link = false;
            if ( !is_null($datatree) && $datatree->getIsLink() )
                $is_link = true;

            // Determine which child/linked datatypes have usable sortfields for this datatype
            $sortfield_datatypes = self::getSortfieldDatatypes($datatype);


            // ----------------------------------------
            // Create the form for the Datatype
            $submitted_data = new DataTypeMeta();

            $datatype_form = $this->createForm(
                UpdateDataTypeForm::class,
                $submitted_data,
                array(
                    'datatype_id' => $datatype->getId(),
                    'is_target_datatype_admin' => $is_target_datatype_admin,
                    'is_top_level' => $is_top_level,
                    'is_link' => $is_link,

                    'sortfield_datatypes' => $sortfield_datatypes,
                )
            );
            $datatype_form->handleRequest($request);

            if ($datatype_form->isSubmitted()) {
                // This is a POST request attempting to save changes...the user needs to be an
                //  admin of the datatype they're trying to save to in order to continue
                // The related Datatree and ThemeDatatype forms are handled by other controller
                //  actions, and therefore aren't blocked by this
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();


                // Verify that the submitted data makes sense

                // ----------------------------------------
                // For a regular datatype...ensure these fields weren't set to empty
                if ($submitted_data->getShortName() == '')
                    $datatype_form->addError( new FormError("The database's name can't be blank") );
                if ($submitted_data->getLongName() == '')
                    $datatype_form->addError( new FormError("The database's name can't be blank") );

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
                       'SELECT dtm.id
                        FROM ODRAdminBundle:DataTypeMeta AS dtm
                        WHERE dtm.searchSlug = :search_slug
                        AND dtm.deletedAt IS NULL'
                    )->setParameters(array('search_slug' => $submitted_data->getSearchSlug()));
                    $results = $query->getArrayResult();

                    if ( count($results) > 0 )
                        $datatype_form->addError( new FormError('A different Datatype is already using this abbreviation') );
                }

                // The fieldtypes of the external_id field needs to be verified
                if ( !is_null($submitted_data->getExternalIdField()) ) {
                    if ( $submitted_data->getExternalIdField()->getFieldType()->getCanBeUnique() !== true )
                        $datatype_form->addError( new FormError('Invalid external id field') );
                }
                // The name, sort, and background image fields should not be changed here
                $submitted_data->setNameField( $datatype->getNameField() );
                $submitted_data->setSortField( $datatype->getSortField() );
                $submitted_data->setBackgroundImageField( $datatype->getBackgroundImageField() );


                // ----------------------------------------
                // May need to change the URL in the browser...
                $new_search_slug = null;

                if ($datatype_form->isValid()) {
                    // Store the ids of the current "special" fields for this datatype...
                    $old_external_id_field = $datatype->getExternalIdField();
                    if ( !is_null($old_external_id_field) )
                        $old_external_id_field = $old_external_id_field->getId();
                    /** @var int|null $old_external_id_field */

                    // ...because if any of the "special" fields got changed, then multiple cache
                    //  entries need to be modified/rebuilt
                    $new_external_id_field = $submitted_data->getExternalIdField();
                    if ( !is_null($new_external_id_field) )
                        $new_external_id_field = $new_external_id_field->getId();
                    /** @var int|null $new_external_id_field */

                    if ( !is_null($submitted_data->getSearchSlug()) && $datatype->getSearchSlug() !== $submitted_data->getSearchSlug() )
                        $new_search_slug = $submitted_data->getSearchSlug();


                    // Convert the submitted Form entity into an array of relevant properties
                    // This should only have properties listed in the UpdateDataTypeForm
                    $properties = array(
                        'externalIdField' => null,
                        'nameField' => null,
                        'sortField' => null,
                        'backgroundImageField' => null,

                        'searchSlug' => $submitted_data->getSearchSlug(),
                        'shortName' => $submitted_data->getLongName(),    // short name should be equivalent to long name
                        'longName' => $submitted_data->getLongName(),
                        'description' => $submitted_data->getDescription(),

                        'newRecordsArePublic' => $submitted_data->getNewRecordsArePublic(),
                    );

                    // These datafields are permitted to be null
                    if ( $submitted_data->getExternalIdField() !== null )
                        $properties['externalIdField'] = $submitted_data->getExternalIdField();
                    if ( $submitted_data->getNameField() !== null )
                        $properties['nameField'] = $submitted_data->getNameField();
                    if ( $submitted_data->getSortField() !== null )
                        $properties['sortField'] = $submitted_data->getSortField();
                    if ( $submitted_data->getBackgroundImageField() !== null )
                        $properties['backgroundImageField'] = $submitted_data->getBackgroundImageField();

                    // Commit all changes to the DatatypeMeta entry to the database
                    $entity_modify_service->updateDatatypeMeta($user, $datatype, $properties);
                    $em->refresh($datatype);


                    // ----------------------------------------
                    // Usually don't need to update cached versions of datarecords, themes, or
                    //  search results as a result of changes to the DatatypeMeta entry...
                    $clear_datarecord_caches = false;
                    if ( $old_external_id_field !== $new_external_id_field ) {
                        // ...but changes to the external_id field require rebuilding cached datarecord
                        //  entries, since the values in that field are stored in those entries
                        $clear_datarecord_caches = true;

                        // Changing sort/name fields needs to trigger this too, but those fields
                        //  are changed via self::savespecialdatafieldsAction()
                    }

                    // Update cached version of datatype
                    try {
                        $event = new DatatypeModifiedEvent($datatype, $user, $clear_datarecord_caches);
                        $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }


                    // ----------------------------------------
                    // This controller action may have changed whether datafields can be deleted or
                    //  not (changes to other properties may also be possible)
                    $datatype_array = $database_info_service->getDatatypeArray($grandparent_datatype->getId());    // do want links here
                    $datarecord_array = array();
                    $permissions_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

                    $datafield_properties = json_encode($datafield_info_service->getDatafieldProperties($datatype_array));
                    $return['d']['datafield_properties'] = $datafield_properties;

                    // Any change to the search slug needs to be reflected in the URL, otherwise
                    //  any subsequent attempt to search or view dashboard will immediately throw errors
                    if ( !is_null($new_search_slug) ) {
                        $baseurl = $this->generateUrl(
                            'odr_search',
                            array(
                                'search_slug' => $new_search_slug
                            ),
                            UrlGeneratorInterface::ABSOLUTE_URL
                        );
                        // ...and need to redirect after that to the new database's master layout design page
                        $url = $this->generateUrl(
                            'odr_design_master_theme',
                            array(
                                'datatype_id' => $datatype->getId(),
                            )
                        );

                        // TODO - ...would be nice to not have to reload, but it seems unavoidable
                        $return['d']['new_url'] = $baseurl.'#'.$url;
                    }
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($datatype_form);
                    throw new ODRException($error_str);
                }
            }
            else {
                // ----------------------------------------
                // This is a GET request...recreate the DatatypeForm in case handleRequest() did
                //  something to it
                $datatype_meta = $datatype->getDataTypeMeta();
                $datatype_form = $this->createForm(
                    UpdateDataTypeForm::class,
                    $datatype_meta,
                    array(
                        'datatype_id' => $datatype->getId(),
                        'is_target_datatype_admin' => $is_target_datatype_admin,
                        'is_top_level' => $is_top_level,
                        'is_link' => $is_link,

                        'sortfield_datatypes' => $sortfield_datatypes
                    )
                );


                // ----------------------------------------
                // Create the form for the Datatree entity if it exists (stores whether the parent
                //  datatype is allowed to have multiple datarecords of the child datatype)
                $force_multiple = false;
                $affects_sortfield = false;

                $datatree_form = null;
                if ( !is_null($datatree) ) {
                    // Determine whether the ancestor datatype is using a sortfield from the
                    //  descendant datatype
                    $ancestor_datatype = $datatree->getAncestor();
                    $descendant_datatype = $datatree->getDescendant();

                    foreach ($ancestor_datatype->getSortFields() as $display_order => $sort_df) {
                        if ( $sort_df->getDataType()->getId() === $descendant_datatype->getId() )
                            $affects_sortfield = true;
                    }

                    // Create the form itself
                    $datatree_form = $this->createForm(
                        UpdateDataTreeForm::class,
                        $datatree_meta
                    )->createView();

                    $results = array();
                    if ($datatree_meta->getIsLink() == 0) {
                        // Determine whether a datarecord of this datatype has multiple child
                        //  datarecords...if so, then the "multiple allowed" property of the
                        //  datatree must remain true
                        $query = $em->createQuery(
                           'SELECT parent.id AS ancestor_id, child.id AS descendant_id
                            FROM ODRAdminBundle:DataRecord AS parent
                            JOIN ODRAdminBundle:DataRecord AS child WITH child.parent = parent
                            WHERE parent.dataType = :parent_datatype AND child.dataType = :child_datatype AND parent.id != child.id
                            AND parent.deletedAt IS NULL AND child.deletedAt IS NULL'
                        )->setParameters(
                            array(
                                'parent_datatype' => $parent_datatype_id,
                                'child_datatype' => $datatype_id
                            )
                        );
                        $results = $query->getArrayResult();
                    }
                    else {
                        // Determine whether a datarecord of this datatype is linked to multiple
                        //  datarecords...if so, the "multiple allowed" property of the datatree
                        //  must remain true
                        $query = $em->createQuery(
                           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
                            FROM ODRAdminBundle:DataRecord AS ancestor
                            JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                            JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                            WHERE ancestor.dataType = :ancestor_datatype AND descendant.dataType = :descendant_datatype
                            AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                        )->setParameters(
                            array(
                                'ancestor_datatype' => $parent_datatype_id,
                                'descendant_datatype' => $datatype_id
                            )
                        );
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


                // ----------------------------------------
                // Create the form for the ThemeDatatype entry if it exists (stores whether the
                //  child/linked datatype should use 'accordion', 'tabbed', 'dropdown', or 'list'
                //  rendering style)
                $theme_datatype_form = null;
                if ( !is_null($theme_datatype) ) {
                    $theme_datatype_form = $this->createForm(
                        UpdateThemeDatatypeForm::class,
                        $theme_datatype,
                        array(
                            'is_top_level' => $is_top_level,
                            'multiple_allowed' => $datatree->getMultipleAllowed(),
                        )
                    )->createView();
                }


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
                $return['d']['html'] = $templating->render(
                    'ODRAdminBundle:Displaytemplate:datatype_properties_form.html.twig',
                    array(
                        'show_name' => $show_name,
                        'show_description' => $show_description,

                        'datatype' => $datatype,
                        'is_target_datatype_admin' => $is_target_datatype_admin,
                        'datatype_form' => $datatype_form->createView(),
                        'site_baseurl' => $site_baseurl,
                        'is_top_level' => $is_top_level,
                        'can_open_multi_df_dialog' => $can_open_multi_df_dialog,

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
        /** @var DatatreeInfoService $datatree_info_service */
        $datatree_info_service = $this->container->get('odr.datatree_info_service');

        // Locate the ids of all datatypes that the given parent datatype links to
        $datatree_array = $datatree_info_service->getDatatreeArray();
        $linked_descendants = $datatree_info_service->getLinkedDescendants( array($datatype->getId()), $datatree_array );

        // The parent datatype should always be in here, otherwise no fields will get listed as
        //  candidates for a sortfield
        $sortfield_datatypes = array();
        $sortfield_datatypes[] = $datatype->getId();

        foreach ($linked_descendants as $num => $ldt_id) {
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

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


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
            if ( !$permissions_service->isDatatypeAdmin($user, $ancestor_datatype) )
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

            // Determine whether the ancestor datatype is using a sortfield from the descendant datatype
            $ancestor_datatype = $datatree->getAncestor();
            $descendant_datatype = $datatree->getDescendant();

            $affects_sortfield = false;
            foreach ($ancestor_datatype->getSortFields() as $display_order => $sort_df) {
                if ( $sort_df->getDataType()->getId() === $descendant_datatype->getId() )
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
//                        'is_link' => $submitted_data->getIsLink(),    // Not allowed to change this value through this controller action
                    );
                    $entity_modify_service->updateDatatreeMeta($user, $datatree, $properties);

                    // If multiple descendant records are now allowed for this descendant datatype,
                    //  and at least one of the ancestor datatype's sortfields comes from a descendant...
                    $clear_datarecord_cache = false;
                    if ( $affects_sortfield && $submitted_data->getMultipleAllowed() == true ) {
                        // ...then need to ensure that the ancestor datatype does not use a sortfield
                        //  from the descendant datatype in question
                        $query = $em->createQuery(
                           'SELECT dtsf
                            FROM ODRAdminBundle:DataTypeSpecialFields dtsf
                            JOIN ODRAdminBundle:DataFields df WITH dtsf.dataField = df
                            JOIN ODRAdminBundle:DataType dt WITH df.dataType = dt
                            WHERE dtsf.dataType = :ancestor_datatype_id AND dt = :descendant_datatype_id
                            AND dtsf.deletedAt IS NULL AND dtsf.field_purpose = :field_purpose
                            AND df.deletedAt IS NULL AND dt.deletedAt IS NULL'
                        )->setParameters(
                            array(
                                'ancestor_datatype_id' => $ancestor_datatype->getId(),
                                'descendant_datatype_id' => $descendant_datatype->getId(),
                                'field_purpose' => DataTypeSpecialFields::SORT_FIELD,
                            )
                        );
                        $results = $query->getResult();

                        if ( !empty($results) ) {
                            // Going to need to clear datarecord cache entries since the ancestor
                            //  datatype's sort fields are being changed
                            $clear_datarecord_cache = true;

                            /** @var DataTypeSpecialFields[] $results */
                            $datafield_ids = array();
                            foreach ($results as $dtsf)
                                $datafield_ids[] = $dtsf->getDataField()->getId();

                            $query = $em->createQuery(
                               'UPDATE ODRAdminBundle:DataTypeSpecialFields AS dtsf
                                SET dtsf.deletedBy = :user, dtsf.deletedAt = :now
                                WHERE dtsf.dataType = :datatype_id AND dtsf.dataField IN (:datafield_ids)
                                AND dtsf.field_purpose = :field_purpose AND dtsf.deletedAt IS NULL'
                            )->setParameters(
                                array(
                                    'user' => $user->getId(),
                                    'now' => new \DateTime(),
                                    'datatype_id' => $ancestor_datatype->getId(),
                                    'datafield_ids' => $datafield_ids,
                                    'field_purpose' => DataTypeSpecialFields::SORT_FIELD
                                )
                            );
                            $updated = $query->execute();
                        }
                    }


                    // ----------------------------------------
                    // Need to delete the cached version of the datatree array
                    $cache_service->delete('cached_datatree_array');

                    // Then delete the cached version of the affected datatype
                    try {
                        $event = new DatatypeModifiedEvent($ancestor_datatype, $user, $clear_datarecord_cache);
                        $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }

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
     * Loads/saves an ODR DataFields properties Form.
     *
     * @param integer $datafield_id The database id of the DataField being modified.
     * @param Request $request
     *
     * @return Response
     */
    public function datafieldpropertiesAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = array();

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatafieldInfoService $datafield_info_service */
            $datafield_info_service = $this->container->get('odr.datafield_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // TODO - what should you be allowed to modify on a derived datafield?
//            // This should not work on a datafield that is derived from a master template
//            if ( !is_null($datafield->getMasterDataField()) )
//                throw new ODRBadRequestException();


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( is_null($datafield) )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ( !is_null($grandparent_datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            // Ensure the datatype has a master theme...
            $theme_info_service->getDatatypeMasterTheme($datatype->getId());

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
            // --------------------


            // Need to immediately force a reload of the right design slideout if certain fieldtypes change
            $force_slideout_reload = false;
            // May also need to force a reload of the datafield itself
            $reload_datafield = false;
            // Only allowed to begin migrating data under specific situations
            $migrate_data = false;
            // Migration to an image field requires a check for image size entities
            $check_image_sizes = false;


            // ----------------------------------------
            // Don't really need this when validating a form, but do need it for rendering one
            $datatype_array = $database_info_service->getDatatypeArray($grandparent_datatype->getId());
            $datafield_properties = $datafield_info_service->getDatafieldProperties($datatype_array, $datafield->getId());

            // TODO - incorrect fieldtypes show up as one of the fieldtypes required by the plugin, regardless of what it actually is

            // Determine which changes, if any, can be made to this datafield's fieldtype
            $fieldtype_info = $datafield_info_service->getFieldtypeInfo($datatype_array, $datafield->getDataType()->getId(), array($datafield->getId()));
            $prevent_fieldtype_change = $fieldtype_info[$datafield->getId()]['prevent_change'];
            $prevent_fieldtype_change_message = $fieldtype_info[$datafield->getId()]['prevent_change_message'];
            $allowed_fieldtypes = $fieldtype_info[$datafield->getId()]['allowed_fieldtypes'];


            // Store whether the "allow multiple uploads" checkbox needs to be disabled for file/image fields
            $has_multiple_uploads = $datafield_info_service->hasMultipleUploads($datafield);
            // Store whether the "allow multiple levels" checkbox needs to be disabled for tag fields
            $has_tag_hierarchy = $datafield_properties['has_tag_hierarchy'];
            // Render plugins can demand that a file/image datafield only allows a single upload...
            $single_uploads_only = $datafield_properties['single_uploads_only'];
            // ...or that the field must remain unique...
            $must_be_unique = $datafield_properties['must_be_unique'];
            // ...or that the user isn't allowed to make changes in Edit mode
            $no_user_edits = $datafield_properties['no_user_edits'];

            // Additionally, datafields which are being used as the external id field must be unique
            $external_id_df = $datatype->getExternalIdField();
            if ( !is_null($external_id_df) && $external_id_df->getId() === $datafield->getId() )
                $must_be_unique = true;


            // ----------------------------------------
            // Populate new DataFields form
            $submitted_data = new DataFieldsMeta();
            $datafield_form = $this->createForm(
                UpdateDataFieldsForm::class,
                $submitted_data,
                array(
                    'is_derived_field' => $is_derived_field,
                    'allowed_fieldtypes' => $allowed_fieldtypes,
                    'current_typeclass' => $datafield->getFieldType()->getTypeClass(),
                    'prevent_fieldtype_change' => $prevent_fieldtype_change,
                    'must_be_unique' => $must_be_unique,
                    'no_user_edits' => $no_user_edits,
                    'has_tag_hierarchy' => $has_tag_hierarchy,
                    'single_uploads_only' => $single_uploads_only,
                    'has_multiple_uploads' => $has_multiple_uploads,
                )
            );
            $datafield_form->handleRequest($request);

            if ($datafield_form->isSubmitted()) {
                // ----------------------------------------
                // Refresh just in case
                $em->refresh($datafield);
                $em->refresh($datafield->getDataFieldMeta());

                // The original fieldtype will always exist...
                $old_fieldtype = $datafield->getFieldType();
                $old_fieldtype_id = $old_fieldtype->getId();
                $old_fieldtype_typeclass = $old_fieldtype->getTypeClass();
                $old_fieldtype_typename = $old_fieldtype->getTypeName();

                // ...but the form isn't guaranteed to have a value in the fieldtype field, mostly
                //  when fieldtype changing is disabled
                $new_fieldtype = $submitted_data->getFieldType();
                // If the form element was disabled, just use the old fieldtype
                if ( is_null($new_fieldtype) )
                    $new_fieldtype = $old_fieldtype;

                $new_fieldtype_id = $new_fieldtype->getId();
                $new_fieldtype_typeclass = $new_fieldtype->getTypeClass();
                $new_fieldtype_typename = $new_fieldtype->getTypeName();


                if ( $prevent_fieldtype_change ) {
                    // Not allowed to change fieldtype, ensure a change doesn't get saved
                    $submitted_data->setFieldType($old_fieldtype);
                }
                else if ( !in_array($new_fieldtype_id, $allowed_fieldtypes) ) {
                    // ...don't allow the user to change to an invalid fieldtype
                    $datafield_form->addError( new FormError("Not allowed to change to the \"".$new_fieldtype_typeclass."\" fieldtype") );
                    $force_slideout_reload = true;
                }
                else if ($old_fieldtype_id !== $new_fieldtype_id) {
                    // ...otherwise, only need to do stuff if the fieldtype got changed

                    // Determine whether an in-progress background job would interfere with a
                    //  potential change of fieldtype
                    $new_job_data = array(
                        'job_type' => 'migrate',
                        'target_entity' => $datafield,
                    );
                    $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
                    if ( !is_null($conflicting_job) )
                        throw new ODRConflictException('Unable to change the fieldtype of this Datafield since a background job is already in progress');

                    // Check whether the change in fieldtype requires a data migration
                    $migrate_data = self::mustMigrateDatafield($old_fieldtype, $new_fieldtype);

                    // If the fieldtype got changed to/from Markdown, File, Image, Radio, or Tags...
                    //  then force a reload of the right slideout, because the displayed options
                    //  on that slideout are different for these fieldtypes
                    switch ($old_fieldtype_typeclass) {
                        case 'Radio':
                        case 'File':
                        case 'Image':
                        case 'Markdown':
                        case 'Tag':
                            $force_slideout_reload = true;
                            break;
                    }
                    switch ($new_fieldtype_typeclass) {
                        case 'Radio':
                        case 'File':
                        case 'Image':
                        case 'Markdown':
                        case 'Tag':
                            $force_slideout_reload = true;
                            break;
                    }

                    if ( $new_fieldtype_typeclass === 'Image' ) {
                        // If the fieldtype got changed to an Image, then need to verify the
                        //  image_sizes entries exist
                        $check_image_sizes = true;
                    }

                    // While not technically needed 100% of the time, it's easier if the datafield
                    //  always gets reloaded when the fieldtype gets changed
                    $reload_datafield = true;
                }

                // ----------------------------------------
                // If a datafield is derived, then several of its properties must remain synchronized
                //  with its master datafield
                if ( !is_null($datafield->getMasterDataField()) ) {
                    $master_datafield = $datafield->getMasterDataField();

                    // The commented lines are there in case I change my mind later on...
//                    $submitted_data->setRequired( $master_datafield->getRequired() );
                    $submitted_data->setIsUnique( $master_datafield->getIsUnique() );
//                    $submitted_data->setPreventUserEdits( $master_datafield->getPreventUserEdits() );
                    $submitted_data->setAllowMultipleUploads( $master_datafield->getAllowMultipleUploads() );
//                    $submitted_data->setShortenFilename( $master_datafield->getShortenFilename() );
//                    $submitted_data->setNewFilesArePublic( $master_datafield->getNewFilesArePublic() );
//                    $submitted_data->setQualityStr( $master_datafield->getQualityStr() );
//                    $submitted_data->setChildrenPerRow( $master_datafield->getChildrenPerRow() );
                    $submitted_data->setRadioOptionNameSort( $master_datafield->getRadioOptionNameSort() );
//                    $submitted_data->setRadioOptionDisplayUnselected( $master_datafield->getRadioOptionDisplayUnselected() );
                    $submitted_data->setTagsAllowMultipleLevels( $master_datafield->getTagsAllowMultipleLevels() );
//                    $submitted_data->setTagsAllowNonAdminEdit( $master_datafield->getTagsAllowNonAdminEdit() );
//                    $submitted_data->setSearchable( $master_datafield->getSearchable() );
//                    $submitted_data->setInternalReferenceName( $master_datafield->getInternalReferenceName() );

                    // NOTE - if adding/removing any of these datafieldMeta entries, need to modify
                    //  both CloneTemplateService and UpdateDataFieldsForm as well
                }

                // ----------------------------------------
                // Ensure the datafield is marked as unique if it needs to be
                if ( $must_be_unique )
                    $submitted_data->setIsUnique(true);

                // If the datafield is currently marked as "unique"...
                if ( $submitted_data->getIsUnique() ) {
                    // ...ensure its fieldtype is allowed to be "unique"
                    if ( !$new_fieldtype->getCanBeUnique() )
                        $datafield_form->addError( new FormError("The \"".$new_fieldtype_typeclass."\" fieldtype can't be set to 'unique'") );

                    // ...if it has duplicate values, manually add an error to the Symfony form
                    if ( !$datafield_info_service->canDatafieldBeUnique($datafield) )
                        $datafield_form->addError( new FormError("This Datafield can't be set to 'unique' because some Datarecords have duplicate values stored in this Datafield...click the list icon to view the duplicates.") );
                }

                // NOTE: do not attempt to verify the JSON input for quality_str here...failures
                //  force a reload of the form, which destroys any in-progress typing
                if ( is_null($submitted_data->getQualityStr()) ) {
                    // Apparently need to ensure this value is non-null
                    $submitted_data->setQualityStr('');
                }
                else if ( strlen($submitted_data->getQualityStr()) > 255 ) {
                    // ...forcing a reload is appropriate when the length is exceeded, however
                    $datafield_form->addError( new FormError("'Quality Type' is not allowed to exceed 255 characters") );
                }

//                $datafield_form->addError( new FormError("Do not continue") );

                if ($datafield_form->isValid()) {
                    // No errors in form

                    // Ensure the datafield only allows single uploads if it needs to
                    if ( $single_uploads_only )
                        $submitted_data->setAllowMultipleUploads(false);

                    // If the file/image field has multiple uploads, ensure that option remains
                    //  checked even if it's supposed to only allow single uploads...
                    if ( $has_multiple_uploads )
                        $submitted_data->setAllowMultipleUploads(true);


                    // Ensure the datafield prevents user edits if it needs to
                    if ( $no_user_edits )
                        $submitted_data->setPreventUserEdits(true);

                    // If the tag field has multiple levels, ensure that option remains checked
                    if ( $has_tag_hierarchy )
                        $submitted_data->setTagsAllowMultipleLevels(true);

                    // If the unique status of the datafield got changed at all, force a slideout
                    //  reload so the fieldtype will have the correct state
                    if ( $datafield->getIsUnique() !== $submitted_data->getIsUnique() )
                        $force_slideout_reload = true;

                    // If the radio options or tags are now supposed to be sorted by name, ensure
                    //  that happens
                    $sort_radio_options = false;
                    if ( $datafield->getRadioOptionNameSort() == false && $submitted_data->getRadioOptionNameSort() == true ) {
                        $sort_radio_options = true;

                        // Also need to reload the datafield so the options show up in the correct
                        // order on the page
                        $reload_datafield = true;
                    }


                    // ----------------------------------------
                    // Save all changes made via the submitted form
                    $properties = array(
                        'fieldType' => $submitted_data->getFieldType(),

                        'fieldName' => $submitted_data->getFieldName(),
                        'description' => $submitted_data->getDescription(),
                        'xml_fieldName' => $submitted_data->getXmlFieldName(),
                        'markdownText' => $submitted_data->getMarkdownText(),
                        'regexValidator' => $submitted_data->getRegexValidator(),
                        'phpValidator' => $submitted_data->getPhpValidator(),
                        'required' => $submitted_data->getRequired(),
                        'is_unique' => $submitted_data->getIsUnique(),
                        'prevent_user_edits' => $submitted_data->getPreventUserEdits(),
                        'allow_multiple_uploads' => $submitted_data->getAllowMultipleUploads(),
                        'shorten_filename' => $submitted_data->getShortenFilename(),
                        'newFilesArePublic' => $submitted_data->getNewFilesArePublic(),
                        'quality_str' => $submitted_data->getQualityStr(),
                        'children_per_row' => $submitted_data->getChildrenPerRow(),
                        'radio_option_name_sort' => $submitted_data->getRadioOptionNameSort(),
                        'radio_option_display_unselected' => $submitted_data->getRadioOptionDisplayUnselected(),
                        'tags_allow_multiple_levels' => $submitted_data->getTagsAllowMultipleLevels(),
                        'tags_allow_non_admin_edit' => $submitted_data->getTagsAllowNonAdminEdit(),
                        'searchable' => $submitted_data->getSearchable(),
                        'publicDate' => $submitted_data->getPublicDate(),
                        'internal_reference_name' => $submitted_data->getInternalReferenceName(),
                    );
                    $entity_modify_service->updateDatafieldMeta($user, $datafield, $properties);
                    $em->refresh($datafield);


                    // ----------------------------------------
                    // Now that the datafield has been updated in the database...

                    // TODO - might be race condition issue with design_ajax
                    if ($sort_radio_options) {
                        // The datafield is automatically reloaded afterwards
                        $submitted_typeclass = $submitted_data->getFieldType()->getTypeClass();
                        if ($submitted_typeclass === 'Radio')
                            $sort_service->sortRadioOptionsByName($user, $datafield);
                        else if ($submitted_typeclass === 'Tag')
                            $sort_service->sortTagsByName($user, $datafield);
                    }

                    // If migrating to an image fieldtype, then ensure ImageSize entities exist for
                    //  this datafield
                    if ($check_image_sizes)
                        $entity_create_service->createImageSizes($user, $datafield);

                    // If migrating data, then begin the migration now
                    if ($migrate_data)
                        self::startDatafieldMigration($em, $user, $datafield, $old_fieldtype, $new_fieldtype);


                    // NOTE: don't need to have stuff to deal with updating DatatypeSpecialField
                    //  entries here, because the rest of ODR doesn't allow a datafield to change
                    //  its fieldtype when it's being used as a sortfield


                    // ----------------------------------------
                    // Fire off an event notifying that the modification of the datafield is done
                    // ...though this won't necessarily be true if the fieldtype is getting changed
                    try {
                        $event = new DatafieldModifiedEvent($datafield, $user);
                        $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }

                    // Mark the datatype as updated
                    try {
                        $event = new DatatypeModifiedEvent($datatype, $user);
                        $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);

                        // While this controller action can make changes that would require a rebuild
                        //  of the cached datarecord entries, it doesn't need to be done here...
                        //  if required, WorkerController::migrateAction() will handle it
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }

                    // Don't need to update cached datarecords or themes


                    // ----------------------------------------
                    // Don't think that any modifications here can change the result of the
                    //  getDatafieldProperties() call, but the computation is cheap and the array
                    //  entry needs to get recached anyways
                    $datatype_array = $database_info_service->getDatatypeArray($grandparent_datatype->getId());
                    // Don't need to filter here
                    $datafield_properties = $datafield_info_service->getDatafieldProperties($datatype_array, $datafield->getId());
                    $return['d']['datafield_properties'] = json_encode($datafield_properties);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($datafield_form);
                    throw new ODRException($error_str);
                }
            }


            // ----------------------------------------
            if ( !$datafield_form->isSubmitted()
                || !$datafield_form->isValid()
                || $reload_datafield
                || $force_slideout_reload
            ) {
                // This was a GET request, or the form wasn't valid originally, or the form was
                //  valid but needs to be reloaded anyways
                $em->refresh($datafield);
                $em->refresh($datafield->getDataFieldMeta());

                // Rebuild the form for the datafield entry
                $datafield_meta = $datafield->getDataFieldMeta();
                $datafield_form = $this->createForm(
                    UpdateDataFieldsForm::class,
                    $datafield_meta,
                    array(
                        'is_derived_field' => $is_derived_field,
                        'allowed_fieldtypes' => $allowed_fieldtypes,
                        'current_typeclass' => $datafield->getFieldType()->getTypeClass(),
                        'prevent_fieldtype_change' => $prevent_fieldtype_change,
                        'must_be_unique' => $must_be_unique,
                        'no_user_edits' => $no_user_edits,
                        'has_tag_hierarchy' => $has_tag_hierarchy,
                        'single_uploads_only' => $single_uploads_only,
                        'has_multiple_uploads' => $has_multiple_uploads,
                    )
                );

                // The return array may already have some datafield properties in it, don't want to
                //  completely overwrite...
                $return['d']['force_slideout_reload'] = $force_slideout_reload;
                $return['d']['reload_datafield'] = $reload_datafield;

                // Easier to check whether any quality json is valid out here
                $valid_quality_json = false;
                $quality_json_error = '';
                if ( $datafield->getQualityStr() !== '' ) {
                    $quality_str = $datafield->getQualityStr();

                    // NOTE: these quality strings are effectively defined in Displaytemplate::datafield_properties_form.html.twig
                    if ( $quality_str !== 'toggle' && $quality_str !== 'stars5' ) {
                        $ret = ValidUtility::isValidQualityJSON($quality_str);
                        if ( is_array($ret) )
                            $valid_quality_json = true;
                        else
                            $quality_json_error = $ret;
                    }
                }


                // ----------------------------------------
                // Render the html for the form
                $return['d']['html'] = $templating->render(
                    'ODRAdminBundle:Displaytemplate:datafield_properties_form.html.twig',
                    array(
                        'is_derived_field' => $is_derived_field,

                        'must_be_unique' => $must_be_unique,
                        'no_user_edits' => $no_user_edits,
                        'single_uploads_only' => $single_uploads_only,

                        'has_multiple_uploads' => $has_multiple_uploads,
                        'has_tag_hierarchy' => $has_tag_hierarchy,

                        'prevent_fieldtype_change' => $prevent_fieldtype_change,
                        'prevent_fieldtype_change_message' => $prevent_fieldtype_change_message,

                        'valid_quality_json' => $valid_quality_json,
                        'quality_json_error' => $quality_json_error,

                        'datatype' => $datatype,
                        'datafield' => $datafield,
                        'datafield_form' => $datafield_form->createView(),
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
     * Returns whether a background job needs to be created to migrate data from one fieldtype to
     * another.
     *
     * @param FieldType $old_fieldtype
     * @param FieldType $new_fieldtype
     *
     * @return boolean
     */
    private function mustMigrateDatafield($old_fieldtype, $new_fieldtype)
    {
        // Don't migrate if there's no change in fieldtype
        if ( $old_fieldtype->getId() === $new_fieldtype->getId() )
            return false;

        // Easier to do comparisons with typeclasses and typenames
        $old_fieldtype_typeclass = $old_fieldtype->getTypeClass();
        $old_fieldtype_typename = $old_fieldtype->getTypeName();
        $new_fieldtype_typeclass = $new_fieldtype->getTypeClass();
        $new_fieldtype_typename = $new_fieldtype->getTypeName();

        // Check whether the fieldtype got changed from something that could be migrated...
        $migrate_data = true;
        switch ($old_fieldtype_typeclass) {
            case 'IntegerValue':
            case 'LongText':
            case 'LongVarchar':
            case 'MediumVarchar':
            case 'ShortVarchar':
            case 'DecimalValue':
            case 'DatetimeValue':    // ...can convert datetime to a text field
                break;

            default:
                $migrate_data = false;
                break;
        }

        // ...to something that needs the migration proccess
        switch ($new_fieldtype_typeclass) {
            case 'IntegerValue':
            case 'LongText':
            case 'LongVarchar':
            case 'MediumVarchar':
            case 'ShortVarchar':
            case 'DecimalValue':
//            case 'DatetimeValue':    // ...can't really convert anything to a date though
                break;

            default:
                $migrate_data = false;
                break;
        }

        // Not allowed to convert DatetimeValues into anything other than text
        if ( $old_fieldtype_typeclass === 'DatetimeValue'
            && !($new_fieldtype_typeclass === 'ShortVarchar'
                || $new_fieldtype_typeclass === 'MediumVarchar'
                || $new_fieldtype_typeclass === 'LongVarchar'
                || $new_fieldtype_typeclass === 'LongText'
            )
        ) {
            $migrate_data = false;
        }

        // If going from Multiple radio/select to Single radio/select...then need to run
        //  the migration process to ensure that at most one RadioSelection is selected
        //  for each drf entry
        if ( ($old_fieldtype_typename == 'Multiple Select' || $old_fieldtype_typename == 'Multiple Radio')
            && ($new_fieldtype_typename == 'Single Select' || $new_fieldtype_typename == 'Single Radio')
        ) {
            $migrate_data = true;
        }

        return $migrate_data;
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

        $url = $this->generateUrl('odr_migrate_field', array(), UrlGeneratorInterface::ABSOLUTE_URL);


        // ----------------------------------------
        if ( ($old_fieldtype->getTypeName() == 'Multiple Radio' || $old_fieldtype->getTypeName() == 'Multiple Select')
            && ($new_fieldtype->getTypeName() == 'Single Radio' || $new_fieldtype->getTypeName() == 'Single Select')
        ) {
            // Converting from a multiple radio/select to a single radio/select requires php to
            //  go through all datarecords and ensure at most one option is selected
            $datatype = $datafield->getDataType();
            $query = $em->createQuery(
               'SELECT dr.id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :dataType AND dr.deletedAt IS NULL'
            )->setParameters( array('dataType' => $datatype) );
            $results = $query->getResult();

            if ( count($results) > 0 ) {
                // Need to determine the top-level datatype this datafield belongs to, so other
                //  background processes won't attempt to render any part of it and disrupt the migration
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
                // Create one beanstalk job per datarecord
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
            }
        }
        else {
            // All other conversions are performed with a single background job
            $top_level_datatype_id = $datafield->getDataType()->getGrandparent()->getId();

            // NOTE - while technically running this should only do something when the datatype has
            //  records...there are a pair of maintenance UPDATEs in WorkerController that could
            //  be beneficial to run if the datatype is somehow screwed up

            // Get/create an entity to track the progress of this datafield migration
            $job_type = 'migrate';
            $target_entity = 'datafield_'.$datafield->getId();
            $additional_data = array('description' => '', 'old_fieldtype' => $old_fieldtype->getTypeName(), 'new_fieldtype' => $new_fieldtype->getTypeName());
            $restrictions = 'datatype_'.$top_level_datatype_id;
            $total = 1;
            $reuse_existing = false;

            $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
            $tracked_job_id = $tracked_job->getId();

            $payload = json_encode(
                array(
                    "tracked_job_id" => $tracked_job_id,
                    "user_id" => $user->getId(),
                    "datarecord_id" => 0,    // NOTE - the background job uses a combination of SELECT/CAST/INSERT INTO to run...technically no individual datarecords are involved
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
        $return['t'] = 'html';
        $return['d'] = '';

        $em = null;

        try {
            throw new ODRNotImplementedException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
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

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


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

            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
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

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // If the datatype is public, make it non-public...if datatype is non-public, make it public
            if ( $datatype->isPublic() ) {
                // Make the datatype non-public
                $properties = array(
                    'publicDate' => new \DateTime('2200-01-01 00:00:00')
                );
                $entity_modify_service->updateDatatypeMeta($user, $datatype, $properties);
            }
            else {
                // Make the datatype public
                $properties = array(
                    'publicDate' => new \DateTime()
                );
                $entity_modify_service->updateDatatypeMeta($user, $datatype, $properties);
            }


            // ----------------------------------------
            // Updated cached version of datatype
            try {
                $event = new DatatypePublicStatusChangedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypePublicStatusChangedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

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

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


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


            // If the datafield is public, make it non-public...if datafield is non-public, make it public
            if ( $datafield->isPublic() ) {
                // Make the datafield non-public
                $properties = array(
                    'publicDate' => new \DateTime('2200-01-01 00:00:00')
                );
                $entity_modify_service->updateDatafieldMeta($user, $datafield, $properties);
            }
            else {
                // Make the datafield public
                $properties = array(
                    'publicDate' => new \DateTime()
                );
                $entity_modify_service->updateDatafieldMeta($user, $datafield, $properties);
            }


            // ----------------------------------------
            // Notify that the datafield has been changed
            // NOTE: intentionally don't have a DatafieldPublicStatusChanged event...it would only
            //  really be used here, as any other place that could fire it might also need to fire
            //  off a DatafieldModified event anyways
            try {
                $event = new DatafieldModifiedEvent($datafield, $user);
                $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Update cached version of datatype
            try {
                $event = new DatatypeModifiedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Don't need to update cached datarecords or themes
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
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');

            $return['d'] = array(
                'html' => $templating->render(
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
            $post = $request->request->all();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            if ( !isset($post['upper_value']) || !isset($post['lower_value']) )
                throw new ODRBadRequestException('Invalid Form');


            // Set the properties array correctly and save to the database
            $properties = array(
                'searchNotesUpper' => $post['upper_value'],
                'searchNotesLower' => $post['lower_value'],
            );
            $entity_modify_service->updateDatatypeMeta($user, $datatype, $properties);


            // ----------------------------------------
            // Marking the datatype as updated and clearing caches is probably overkill, but meh.
            try {
                $event = new DatatypeModifiedEvent($datatype, $user);
                $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }
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
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
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
//            $clone_template_service->syncWithTemplate($user, $datatype);

            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');

            $url = $this->generateUrl('odr_sync_with_template_worker', array(), UrlGeneratorInterface::ABSOLUTE_URL);

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

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
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


    /**
     * Renders and returns a form to change the searchable and public status properties for
     * multiple datafields at the same time.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function getmultiplefieldpropertiesAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var CsrfTokenManager $token_manager */
            $token_manager = $this->container->get('security.csrf.token_manager');

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatafieldInfoService $datafield_info_service */
            $datafield_info_service = $this->container->get('odr.datafield_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Most of the data can come from the cached datatype array
            $datatype_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't include links
            $df_array = $datatype_array[$datatype_id]['dataFields'];

            // Generate a csrf token for the form before the datafields are sorted by name
            $token_key = 'Form_';
            foreach ($df_array as $df_id => $df)
                $token_key .= $df_id.'_';
            $token_key .= 'Datafields';
            $token = $token_manager->getToken($token_key)->getValue();

            // Sort the datafields by name so they're easier to locate in the list
            uasort($df_array, function ($a, $b) {
                return strcmp($a['dataFieldMeta']['fieldName'], $b['dataFieldMeta']['fieldName']);
            });


            // Going to also need a list of all fieldtypes, and which datafields can have their
            //  fieldtypes changed
            /** @var FieldType[] $all_fieldtypes */
            $all_fieldtypes = $em->getRepository('ODRAdminBundle:FieldType')->findAll();
            $fieldtype_map = array();
            foreach ($all_fieldtypes as $ft)
                $fieldtype_map[$ft->getId()] = $ft->getTypeName();

            $fieldtype_info = $datafield_info_service->getFieldtypeInfo($datatype_array, $datatype->getId());


            // ----------------------------------------
            // Render and return the dialog
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:multi_datafield_properties_dialog_form.html.twig',
                    array(
                        'datafields' => $df_array,
                        'token' => $token,

                        'fieldtype_info' => $fieldtype_info,
                        'fieldtype_map' => $fieldtype_map,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0xe7659339;
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
     * Validates and saves a form to change the searchable and public status properties for
     * multiple datafields at the same time.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function savemultiplefieldpropertiesAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure required variables exist
            $post = $request->request->all();
            if ( !isset($post['_token']) || !isset($post['searchable']) || !isset($post['public_status']) )
                throw new ODRBadRequestException();

            foreach ($post['searchable'] as $df_id => $val)
                $post['searchable'][$df_id] = intval($val);
            foreach ($post['public_status'] as $df_id => $val)
                $post['public_status'][$df_id] = intval($val);

            // The fieldtypes variable might not be set, since those form entries are disabled when
            //  the user isn't allowed to change fieldtypes
            if ( isset($post['fieldtypes']) ) {
                foreach ($post['fieldtypes'] as $df_id => $val)
                    $post['fieldtypes'][$df_id] = intval($val);
            }
            else
                $post['fieldtypes'] = array();


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatafieldInfoService $datafield_info_service */
            $datafield_info_service = $this->container->get('odr.datafield_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');
            /** @var CsrfTokenManager $token_manager */
            $token_manager = $this->container->get('security.csrf.token_manager');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
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
            // Going to need hydrated arrays of datafields and fieldtypes to be able to correctly
            //  migrate data between fieldtypes
            /** @var FieldType[] $tmp */
            $tmp = $em->getRepository('ODRAdminBundle:FieldType')->findAll();
            $fieldtype_map = array();
            foreach ($tmp as $ft)
                $fieldtype_map[$ft->getId()] = $ft;
            /** @var FieldType[] $fieldtype_map */

            /** @var DataFields[] $tmp */
            $tmp = $em->getRepository('ODRAdminBundle:DataFields')->findBy( array('dataType' => $datatype->getId()) );
            $datafield_map = array();
            foreach ($tmp as $df)
                $datafield_map[$df->getId()] = $df;
            /** @var DataFields[] $datafield_map */


            // ----------------------------------------
            // Get the cached datatype array to verify the form
            $datatype_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't include links
            $df_array = $datatype_array[$datatype_id]['dataFields'];


            // Verify the csrf token
            $token_key = 'Form_';
            foreach ($df_array as $df_id => $df)
                $token_key .= $df_id.'_';
            $token_key .= 'Datafields';
            $token = $token_manager->getToken($token_key)->getValue();

            if ( $token !== $post['_token'] )
                throw new ODRBadRequestException();


            // Also need to verify that the provided fieldtypes are valid
            $fieldtype_info = $datafield_info_service->getFieldtypeInfo($datatype_array, $datatype->getId());


            // Ensure that the provided datafields match the datatype
            foreach ($df_array as $df_id => $df) {
                // Verify that none of the datafields got changed to a fieldtype they're not allowed
                //  to have
                if ( isset($post['fieldtypes'][$df_id]) ) {
                    // If a fieldtype entry for this datafield exists in the post, then it's supposed
                    //  to allow its fieldtype to get changed
                    if ( $fieldtype_info[$df_id]['prevent_change'] === true )
                        throw new ODRBadRequestException('Datafield '.$df_id.' is not allowed to change its fieldtype');

                    // Verify that the submitted fieldtype is on the list of allowed fieldtypes
                    if ( !in_array($post['fieldtypes'][$df_id], $fieldtype_info[$df_id]['allowed_fieldtypes']) )
                        throw new ODRBadRequestException('Datafield '.$df_id.' is not allowed to change to fieldtype '.$post['fieldtypes'][$df_id]);
                }
                else {
                    // If a fieldtype entry for this datafield does not exist in the post, then it's
                    //  not supposed to allow its fieldtype to get changed
                    if ( $fieldtype_info[$df_id]['prevent_change'] === false )
                        throw new ODRBadRequestException('Form submited without fieldtype for datafield '.$df_id);

                    // Verifying the "searchable" entry is easier if a fieldtype entry exists though
                    $post['fieldtypes'][$df_id] = $df['dataFieldMeta']['fieldType']['id'];
                }


                // Verify that all datafields in the post have an entry for public_status...
                if ( !isset($post['public_status'][$df_id]) )
                    throw new ODRBadRequestException('Form submitted without public_status for datafield '.$df_id);

                // ...and that the public status is a boolean
                $public_status = $post['public_status'][$df_id];
                if ( $public_status !== 0 && $public_status !== 1 )
                    throw new ODRBadRequestException('Form submitted with invalid public status for datafield '.$df_id);


                // Verify that all fields other than markdown fields have a searchable entry
                $submitted_fieldtype_id = $post['fieldtypes'][$df_id];
                $submitted_typeclass = $fieldtype_map[$submitted_fieldtype_id]->getTypeClass();
                if ( $submitted_typeclass === 'Markdown' && isset($post['searchable'][$df_id]) )
                    throw new ODRBadRequestException('Form submited with search status for Markdown datafield '.$df_id);
                else if ( $submitted_typeclass !== 'Markdown' && !isset($post['searchable'][$df_id]) )
                    throw new ODRBadRequestException('Form submitted without search status for datafield '.$df_id);

                if ( isset($post['searchable'][$df_id]) ) {
                    $searchable = $post['searchable'][$df_id];
                    if ($searchable < DataFields::NOT_SEARCHED || $searchable > DataFields::ADVANCED_SEARCH_ONLY)
                        throw new ODRBadRequestException('Form submitted with illegal search status for datafield '.$df_id);
                }

                // Don't want to force a searchable value right this second, since it depends on
                //  whether any fieldtype changes are allowed to proceed
            }


            // ----------------------------------------
            // Avoid updating cache entries or reloading the page if no changes have been made
            $change_made = false;

            // Now that the form is valid, update all the datafields
            $datafields_needing_events = array();
            foreach ($df_array as $df_id => $df) {
                $datafield = $datafield_map[$df_id];

                // public date is never affected by changing the fieldtype, but want to set it equal
                //  to the datafield's current public date if it's not being changed
                $public_date = new \DateTime('2200-01-01 00:00:00');
                if ( $post['public_status'][$df_id] === 1 ) {
                    if ( $datafield->isPublic() )
                        $public_date = $datafield->getPublicDate();
                    else
                        $public_date = new \DateTime();
                }


                // Don't change the fieldtype if it's not allowed
                $old_fieldtype = $new_fieldtype = $datafield->getFieldType();
                if ( $fieldtype_info[$df_id]['prevent_change'] !== true )
                    $new_fieldtype = $fieldtype_map[ $post['fieldtypes'][$df_id] ];

                // If the user wants to change the fieldtype...
                $migrate_data = false;
                if ( $old_fieldtype->getId() !== $new_fieldtype->getId() ) {
                    // ...then need to verify that changing the fieldtype won't cause a conflict
                    //  with any in-progress background jobs
                    $new_job_data = array(
                        'job_type' => 'migrate',
                        'target_entity' => $datafield,
                    );
                    $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
                    if ( !is_null($conflicting_job) ) {
                        // Changing the fieldtype here would interfere with a currently running
                        //  background job...however, throwing an error would interfere with saving
                        //  this form, so silently prevent the fieldtype from changing instead
                        $new_fieldtype = $old_fieldtype;
                    }
                    else {
                        // Otherwise, no problem changing the fieldtype...so save whether a migration
                        //  of data is required
                        $migrate_data = self::mustMigrateDatafield($old_fieldtype, $new_fieldtype);

                        // Can't actually start the migration here...the fieldtype needs to be
                        //  saved first
                    }
                }


                // Only want to save if something got changed...
                if ( $old_fieldtype->getId() !== $new_fieldtype->getId()
                    || $datafield->getSearchable() !== $post['searchable'][$df_id]
                    || $datafield->getPublicDate() !== $public_date
                ) {
                    $change_made = true;

                    $properties = array(
                        'fieldType' => $new_fieldtype,
                        'searchable' => $post['searchable'][$df_id],
                        'publicDate' => $public_date,
                    );
                    $entity_modify_service->updateDatafieldMeta($user, $datafield, $properties, true);    // don't flush immediately

                    // If the fieldtype changed...
                    if ( $old_fieldtype->getId() !== $new_fieldtype->getId() ) {
                        // ...then start a migration if it's required
                        if ( $migrate_data )
                            self::startDatafieldMigration($em, $user, $datafield, $old_fieldtype, $new_fieldtype);

                        // If migrating to an image fieldtype, then ensure ImageSize entities exist
                        //  for this datafield
                        if ($new_fieldtype->getTypeClass() === 'Image')
                            $entity_create_service->createImageSizes($user, $datafield, true);    // don't flush immediately
                    }

                    // Need to fire events for these datafields in a bit
                    $datafields_needing_events[] = $datafield;
                }

                // NOTE: don't need to have stuff to deal with updating DatatypeSpecialField entries
                //  here, because the rest of ODR doesn't allow a datafield to change its fieldtype
                //  when it's being used as a sortfield
            }


            // ----------------------------------------
            // Avoid updating cache entries or reloading the page if no changes have been made
            if ( $change_made ) {
                // Changes made, flush the database
                $em->flush();

                foreach ($datafields_needing_events as $df) {
                    // Fire off an event notifying that the modification of the datafield is done
                    try {
                        $event = new DatafieldModifiedEvent($df, $user);
                        $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }
                }

                // Mark the datatype as updated
                try {
                    $event = new DatatypeModifiedEvent($datatype, $user);
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }

            $return['d'] = array(
                'reload_child' => $change_made
            );
        }
        catch (\Exception $e) {
            $source = 0x96406f68;
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
     * Renders and returns a form to change the datatype's name or sort fields.
     *
     * @param integer $datatype_id
     * @param string $type
     * @param Request $request
     *
     * @return Response
     */
    public function getspecialdatafieldsAction($datatype_id, $type, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');
            /** @var CsrfTokenManager $token_manager */
            $token_manager = $this->container->get('security.csrf.token_manager');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            if ($type !== 'name' && $type !== 'sort')
                throw new ODRBadRequestException('Invalid $type value');

            // Both these fields only allow certain typeclasses
            $query = $em->createQuery(
               'SELECT ft.typeClass
                FROM ODRAdminBundle:FieldType ft
                WHERE ft.canBeSortField = 1
                AND ft.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

            $allowed_typeclasses = array();
            foreach ($results as $result) {
                $typeclass = $result['typeClass'];
                $allowed_typeclasses[$typeclass] = 1;
            }

            // Need to build two arrays of datafields
            $current_datafields = array();
            $available_datafields = array();

            // All of the data can come from the cached datatype array
            $datatype_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId());    // do want links
            $dt = $datatype_array[$datatype->getId()];

            // The current fields only have the datafield id...
            $fields = array();
            if ( $type === 'name' )
                $fields = $dt['nameFields'];
            else
                $fields = $dt['sortFields'];

            // ...going to need to also find their names from the cached datatype array
            foreach ($fields as $display_order => $df_id)
                $current_datafields[$df_id] = array('display_order' => $display_order, 'field_name' => '');


            $datatypes_to_check = array($datatype->getId());
            while ( !empty($datatypes_to_check) ) {
                $tmp = array();

                foreach ($datatypes_to_check as $dt_id) {
                    $dt = $datatype_array[$dt_id];
                    $dt_name = $dt['dataTypeMeta']['shortName'];
                    $available_datafields[$dt_id] = array('datatype_name' => $dt_name, 'datafields' => array());

                    foreach ($dt['dataFields'] as $df_id => $df) {
                        // Only save this field if it has the correct typeclass
                        $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                        if ( isset($allowed_typeclasses[$typeclass]) )
                            $available_datafields[$dt_id]['datafields'][$df_id] = $df['dataFieldMeta']['fieldName'];

                        // Also fill in the names for the current datafields while here
                        if ( isset($current_datafields[$df_id]) )
                            $current_datafields[$df_id]['field_name'] = $df['dataFieldMeta']['fieldName'];
                    }

                    // If searching for sort fields...
                    if ( $type === 'sort' && isset($dt['descendants']) ) {
                        // ...then also need to go looking into linked descendants that only allow
                        //  a single record
                        foreach ($dt['descendants'] as $descendant_dt_id => $data) {
                            if ( $data['is_link'] === 1 && $data['multiple_allowed'] === 0 )
                                $tmp[] = $descendant_dt_id;
                        }

                        // Currently not allowed to use fields from child descendants for sorting
                    }
                }

                // Reset for next loop
                $datatypes_to_check = $tmp;
            }

            // Generate a csrf token for the form before the datafields are sorted by name
            $token_key = 'Form_';
            foreach ($available_datafields as $dt_id => $dt_data) {
                foreach ($dt_data['datafields'] as $df_id => $df_name)
                    $token_key .= $df_id.'_';
            }
            $token_key .= 'Datafields';
            $token = $token_manager->getToken($token_key)->getValue();


            // Sort each set of available datafields by their name so they're easier to locate
            foreach ($available_datafields as $dt_id => $dt_data) {
                if ( !empty($dt_data['datafields']) ) {
                    $tmp = $dt_data['datafields'];
                    asort($tmp);
                    $available_datafields[$dt_id]['datafields'] = $tmp;
                }
            }
            // Do the same for the datatypes
            uasort($available_datafields, function($a, $b) {
                return strcmp($a['datatype_name'], $b['datatype_name']);
            });

            // The currently selected datafields need to be sorted by display order
            uasort($current_datafields, function($a, $b) {
                return $a['display_order'] <=> $b['display_order'];
            });


            // ----------------------------------------
            // Render and return the dialog
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:special_datafield_selection_dialog_form.html.twig',
                    array(
                        'token' => $token,
                        'purpose' => $type,

                        'available_datafields' => $available_datafields,
                        'current_datafields' => $current_datafields,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0x19444cda;
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
     * Validates and saves a form to change the datatype's name or sort fields.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function savespecialdatafieldsAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure required variables exist
            $post = $request->request->all();
            if ( !isset($post['_token']) || !isset($post['purpose']) )
                throw new ODRBadRequestException();

            $purpose = $post['purpose'];
            if ( $purpose !== 'name' && $purpose !== 'sort' )
                throw new ODRBadRequestException();

            $seen_datafields = array();
            $df_list = array();
            if ( isset($post['datafields']) ) {
                foreach ($post['datafields'] as $display_order => $df_id) {
                    $df_list[intval($df_id)] = intval($display_order);
                    $seen_datafields[$df_id] = false;
                }
            }


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var CsrfTokenManager $token_manager */
            $token_manager = $this->container->get('security.csrf.token_manager');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Both these fields only allow certain typeclasses
            $query = $em->createQuery(
               'SELECT ft.typeClass
                FROM ODRAdminBundle:FieldType ft
                WHERE ft.canBeSortField = 1
                AND ft.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

            $allowed_typeclasses = array();
            foreach ($results as $result) {
                $typeclass = $result['typeClass'];
                $allowed_typeclasses[$typeclass] = 1;
            }

            // Get the cached datatype array to verify the form
            $available_datafields = array();
            $datatype_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId());    // do want links

            $datatypes_to_check = array($datatype->getId());
            while ( !empty($datatypes_to_check) ) {
                $tmp = array();

                foreach ($datatypes_to_check as $dt_id) {
                    $dt = $datatype_array[$dt_id];
                    $dt_name = $dt['dataTypeMeta']['shortName'];
                    $available_datafields[$dt_id] = array('datatype_name' => $dt_name, 'datafields' => array());

                    foreach ($dt['dataFields'] as $df_id => $df) {
                        // Only save this field if it has the correct typeclass
                        $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                        if ( isset($allowed_typeclasses[$typeclass]) ) {
                            $available_datafields[$dt_id]['datafields'][$df_id] = $df['dataFieldMeta']['fieldName'];

                            // Mark that this field in the $_POST belongs to the correct datatype...
                            if ( isset($seen_datafields[$df_id]) )
                                $seen_datafields[$df_id] = true;
                        }
                    }

                    // If searching for sort fields...
                    if ( $purpose === 'sort' && isset($dt['descendants']) ) {
                        // ...then also need to go looking into linked descendants that only allow
                        //  a single record
                        foreach ($dt['descendants'] as $descendant_dt_id => $data) {
                            if ( $data['is_link'] === 1 && $data['multiple_allowed'] === 0 )
                                $tmp[] = $descendant_dt_id;
                        }
                    }
                }

                // Reset for next loop
                $datatypes_to_check = $tmp;
            }

            // Generate a csrf token for the form before the datafields are sorted by name
            $token_key = 'Form_';
            foreach ($available_datafields as $dt_id => $dt_data) {
                foreach ($dt_data['datafields'] as $df_id => $df_name)
                    $token_key .= $df_id.'_';
            }
            $token_key .= 'Datafields';
            $token = $token_manager->getToken($token_key)->getValue();

            // If the token doesn't match, then throw an exception
            if ( $token !== $post['_token'] )
                throw new ODRBadRequestException('Invalid token');

            // If one of the submitted datafields is illegal, then throw an exception
            foreach ($seen_datafields as $df_id => $seen) {
                if ( !$seen )
                    throw new ODRBadRequestException('Illegal datafield '.$df_id);
            }


            // ----------------------------------------
            // Now that the given data is valid, load what's already in the database
            $query = $em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields df
                WHERE df IN (:datafield_ids)
                AND df.deletedAt IS NULL'
            )->setParameters( array('datafield_ids' => array_keys($df_list)) );
            $results = $query->getResult();

            $df_lookup = array();
            foreach ($results as $df) {
                /** @var DataFields $df */
                $df_lookup[ $df->getId() ] = $df;
            }

            $field_purpose = DataTypeSpecialFields::NAME_FIELD;
            if ( $purpose === 'sort' )
                $field_purpose = DataTypeSpecialFields::SORT_FIELD;

            $query = $em->createQuery(
               'SELECT dtsf
                FROM ODRAdminBundle:DataTypeSpecialFields dtsf
                WHERE dtsf.dataType = :datatype_id AND dtsf.field_purpose = :purpose
                AND dtsf.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId(), 'purpose' => $field_purpose) );
            $results = $query->getResult();

            $entities = array();
            foreach ($results as $dtsf) {
                /** @var DataTypeSpecialFields $dtsf */
                $entities[ $dtsf->getDataField()->getId() ] = $dtsf;
            }

            // For each of the datafields that are supposed to fulfill special purposes for this datatype...
            $changes_made = false;
            foreach ($df_list as $df_id => $display_order) {
                if ( !isset($entities[$df_id]) ) {
                    // ...this datafield is not already marked as a special field, so create a new
                    //  entry for it
                    $dtsf = $entity_create_service->createDatatypeSpecialField($user, $datatype, $df_lookup[$df_id], $field_purpose, $display_order, true);    // don't flush immediately...
                    $changes_made = true;

                    // Don't need to deal with this entity later on
                    unset( $entities[$df_id] );
                }
                else {
                    // ...this datafield was already marked as a special field...
                    $dtsf = $entities[$df_id];
                    if ( $display_order !== $dtsf->getDisplayOrder() ) {
                        // ...so ensure it's in the correct order
                        $props = array(
                            'displayOrder' => $display_order
                        );
                        $entity_modify_service->updateDatatypeSpecialField($user, $dtsf, $props, true);    // don't flush immediately...
                        $changes_made = true;
                    }

                    // Don't need to deal with this entity later on
                    unset( $entities[$df_id] );
                }
            }

            // Any of the DatatypeSpecialFields entities leftover need to be deleted
            foreach ($entities as $df_id => $dtsf) {
                $em->remove($dtsf);
                $changes_made = true;
            }


            // ----------------------------------------
            // Avoid updating cache entries or reloading the page if no changes have been made
            if ( $changes_made ) {
                // Changes made, flush the database
                $em->flush();

                // Mark the datatype as updated
                try {
                    $event = new DatatypeModifiedEvent($datatype, $user, true);    // Also need to rebuild datarecord cache entries because they store sort/name field values
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }

                // If the sort fields got changed, then need to reset some more cache entries
                if ( $purpose === 'sort' )
                    $cache_service->delete('datatype_'.$datatype->getId().'_record_order');
            }
        }
        catch (\Exception $e) {
            $source = 0x556cb893;
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
     * Renders and returns a form to view/change stored search keys for the datatype.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function getstoredsearchkeysAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatafieldInfoService $datafield_info_service */
            $datafield_info_service = $this->container->get('odr.datafield_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchSidebarService $search_sidebar_service */
            $search_sidebar_service = $this->container->get('odr.search_sidebar_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            if ( $datatype->getId() !== $datatype->getParent()->getId() )
                throw new ODRBadRequestException('Not allowed for child datatypes');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $permissions_service->getUserPermissionsArray($user);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Going to attempt to render the regular search sidebar here, which would allow the
            //  use of the SearchSidebarService...
            $datatype_array = $search_sidebar_service->getSidebarDatatypeArray($user, $datatype->getId());
            $datatype_relations = $search_sidebar_service->getSidebarDatatypeRelations($datatype_array, $datatype->getId());
            $datafields = $datafield_info_service->getDatafieldProperties($datatype_array);
            // Don't want the user list or the preferred theme id


            // TODO - going to need multiple stored search keys, eventually...
            // Load the current stored search key for the datatype, if one exists...
            $stored_search_keys = array();
            $search_key_descriptions = array();
            foreach ($datatype->getStoredSearchKeys() as $ssk) {
                /** @var StoredSearchKey $ssk */
                // Don't really want to use id to identify these things...due to soft-deletion,
                //  any modifications would always require reloads
                $uuid = md5($ssk->getSearchKey().'_'.$ssk->getCreatedBy()->getId());

                $stored_search_keys[$uuid] = array(
                    'search_key' => $ssk->getSearchKey(),
                    'label' => $ssk->getStorageLabel(),
                    'createdBy' => $ssk->getCreatedBy()->getUserString(),

                    'isDefault' => $ssk->getIsDefault(),
                    'isPublic' => $ssk->getIsPublic(),

                    'has_non_public_fields' => false,
                );

                // Need to also keep track of whether the search key is valid or not...which is
                //  made somewhat irritating because validateSearchKey() throws an error when it's
                //  not...
                try {
                    $search_key_service->validateSearchKey( $ssk->getSearchKey() );
                    // If no error thrown, then search key is valid
                    $stored_search_keys[$uuid]['is_valid'] = true;
                }
                catch (ODRBadRequestException $e) {
                    // If an error was thrown, then search key is not valid...but need to keep
                    //  checking the other search keys
                    $stored_search_keys[$uuid]['is_valid'] = false;
                }

                // Need to display a warning when search keys contain non-public fields, since they
                //  won't work properly with users that can't view said fields...
                $search_params = $search_key_service->decodeSearchKey( $ssk->getSearchKey() );
                foreach ($search_params as $key => $value) {
                    if ( isset($datafields[$key]) && $datafields[$key]['is_public'] === false ) {
                        $stored_search_keys[$uuid]['has_non_public_fields'] = true;
                        break;
                    }
                }

                // Also need a more readable description
                $search_key_descriptions[$uuid] = $search_key_service->getReadableSearchKey( $ssk->getSearchKey() );
            }

            // Need the default search key for this dataype so the sidebar inside the dialog can be reset
            $empty_search_key = $search_key_service->encodeSearchKey(
                array('dt_id' => $datatype->getId())
            );

            // If a search key exists, decode it into search parameters so that the sidebar will
            //  show them by default
            $default_search_key = '';
            $default_search_params = array();
            if ( !empty($stored_search_keys) ) {
                foreach ($stored_search_keys as $ssk) {
                    // Should only be one stored search key, for the moment
                    $default_search_key = $ssk['search_key'];
                    $default_search_params = $search_key_service->decodeSearchKey($default_search_key);
                    $search_sidebar_service->fixSearchParamsOptionsAndTags($datatype_array, $default_search_params);
                    break;
                }
            }


            // ----------------------------------------
            // Render and return the dialog
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:stored_search_keys_dialog_form.html.twig',
                    array(
                        'search_key' => $default_search_key,
                        'search_params' => $default_search_params,

                        'stored_search_keys' => $stored_search_keys,
                        'search_key_descriptions' => $search_key_descriptions,
                        'empty_search_key' => $empty_search_key,

                        // required twig/javascript parameters
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

//                        'user_list' => $user_list,
                        'logged_in' => true,
                        'intent' => 'default_settings',
//                        'sidebar_reload' => true,

                        // datatype/datafields to search
                        'target_datatype' => $datatype,
                        'datatype_array' => $datatype_array,
                        'datatype_relations' => $datatype_relations,

                        // theme selection
//                        'preferred_theme_id' => $preferred_theme_id,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0xad30a31e;
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
     * Takes a POST from the version of search_sidebar.html.twig that exists in the stored search
     * key dialog form, and returns the resulting search key/description.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function converttostoredsearchkeyAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure required variables exist
            $search_params = $request->request->all();
            if ( !isset($search_params['dt_id']) )
                throw new ODRBadRequestException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatafieldInfoService $datafield_info_service */
            $datafield_info_service = $this->container->get('odr.datafield_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');


            /** @var DataType $datatype */
            $dt_id = $search_params['dt_id'];
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($dt_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            if ( $datatype->getId() !== $datatype->getParent()->getId() )
                throw new ODRBadRequestException('Not allowed for child datatypes');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Convert the POST request into a search key and validate it
            $search_key = $search_key_service->convertPOSTtoSearchKey($search_params);
            $search_key_service->validateSearchKey($search_key);

            $search_params = $search_key_service->decodeSearchKey($search_key);
            $readable_search_key = $search_key_service->getReadableSearchKey($search_key);


            // Need to display a warning when the search key contains non-public fields, since it
            //  won't work properly with users that can't view said fields...
            $datatype_array = $database_info_service->getDatatypeArray($datatype->getId());
            $datafields = $datafield_info_service->getDatafieldProperties($datatype_array);

            // NOTE: don't need to use the SearchSidebarService to get the datatype array...the
            //  current user is a datatype admin, so the filtering done by that service is useless

            $contains_non_public_fields = false;
            foreach ($search_params as $key => $value) {
                if ( isset($datafields[$key]) && $datafields[$key]['is_public'] === false ) {
                    $contains_non_public_fields = true;
                    break;
                }
            }


            // ----------------------------------------
            // Render and return the dialog
            $return['d'] = array(
                'search_key' => $search_key,
                'readable_search_key' => $readable_search_key,

                'contains_non_public_fields' => $contains_non_public_fields,
            );
        }
        catch (\Exception $e) {
            $source = 0x90291cc3;
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
     * Creates/modifies/deletes the datatype's default search key, based on the POST from the
     * stored search key dialog form.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function savestoredsearchkeysAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure required variables exist
            $post = $request->request->all();
            if ( !isset($post['search_key']) )
                throw new ODRBadRequestException();
            $search_key = $post['search_key'];

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            if ( $datatype->getId() !== $datatype->getParent()->getId() )
                throw new ODRBadRequestException('Not allowed for child datatypes');

            // Verify that the search key is at least minimally correct if it exists
            if ( $search_key !== '' ) {
                $search_params = $search_key_service->decodeSearchKey($search_key);
                if ( !isset($search_params['dt_id']) || !is_numeric($search_params['dt_id']) )
                    throw new ODRBadRequestException('Invalid search key');
                if ( intval($search_params['dt_id']) !== $datatype->getId() )
                    throw new ODRBadRequestException('Invalid search key');
            }

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // If a non-blank search key was submitted...
            if ( $search_key !== '' ) {
                // ...perform a more complete verification on it
                $search_key_service->validateSearchKey($search_key);

                // Determine whether the datatype has an existing stored search key entry...
                $stored_search_keys = $datatype->getStoredSearchKeys();
                if ( $stored_search_keys->count() > 0 && $stored_search_keys->first() !== false ) {
                    // ...if so, then attempt to update it
                    /** @var StoredSearchKey $ssk */
                    $ssk = $stored_search_keys->first();
                    $props = array(
                        'searchKey' => $search_key,
                    );
                    $entity_modify_service->updateStoredSearchKey($user, $ssk, $props);
                }
                else {
                    // ...if no entry, then create a new one
                    $ssk = $entity_create_service->createStoredSearchKey(
                        $user,
                        $datatype,
                        $search_key,
                        'Default',
                        true    // don't flush here, going to modify it immediately...
                    );

                    // TODO - these are more for later, when a datatype is allowed to have more than one stored search key...
                    $ssk->setIsDefault(true);
                    $ssk->setIsPublic(true);

                    // Persist and flush the changes
                    $em->persist($ssk);
                    $em->flush();
                }
            }
            else {
                // ...otherwise, going to delete any existing stored search key entry for this
                //  datatype
                $stored_search_keys = $datatype->getStoredSearchKeys();
                if ( $stored_search_keys->count() > 0 && $stored_search_keys->first() !== false ) {
                    // ...if so, then attempt to update it
                    /** @var StoredSearchKey $ssk */
                    $ssk = $stored_search_keys->first();

                    $ssk->setDeletedBy($user);
                    $ssk->setDeletedAt(new \DateTime());

                    $em->persist($ssk);
                    $em->flush();
                }
            }

            // For the moment, don't need to clear any cache entries...but probably should refresh
            //  the datatype
            $em->refresh($datatype);
        }
        catch (\Exception $e) {
            $source = 0x051c341a;
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
