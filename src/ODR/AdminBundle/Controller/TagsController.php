<?php

/**
 * Open Data Repository Data Publisher
 * Tags Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Since most of these actions are available from both the design page and the edit page, it makes
 * more sense to have the tag-related actions in their own controller.
 */

namespace ODR\AdminBundle\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Entity\TagTree;
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
use ODR\AdminBundle\Component\Service\CloneTemplateService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\TagHelperService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
use ODR\AdminBundle\Component\Service\UUIDService;
// Symfony
use Doctrine\DBAL\Connection as DBALConnection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Templating\EngineInterface;


class TagsController extends ODRCustomController
{

    /**
     * Returns the HTML to modify a specific tag tree
     *
     * @param int $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function loadtagmodalAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CloneTemplateService $clone_template_service */
            $clone_template_service = $this->container->get('odr.clone_template_service');
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to load tags from a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $permissions_service->getDatatypePermissions($user);

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$permissions_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }

            // If this is a derived field...
            $can_modify_template = false;
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( $datafield->getMasterDataField()->getTagsAllowNonAdminEdit() ) {
                    if ( $permissions_service->canEditDatafield($user, $datafield->getMasterDataField()) )
                        $can_modify_template = true;
                }
                else {
                    if ( $permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                        $can_modify_template = true;
                }

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
            // The main problem with this modal is the existence of the 'tag_rebuild' tracked job
            // Strictly speaking...if that job is active, then tags could get created/renamed, and
            //  also moved around so long as their parent tag doesn't change...but deleting a tag
            //  or moving a tag and changing its parent aren't allowed
            $conflicting_job = null;

            // However, making the modal smart enough to distinguish between these actions is a pain
            // To reduce the amount of work required for what should be a pretty rare occurrence,
            //  I'm just going to block attempts to access the modal until the job finishes
            $job_data = $tracked_job_service->getJobDataByType('tag_rebuild', $datatype_permissions);

            if ( !empty($job_data) ) {
                foreach ($job_data as $num => $job) {
                    if ( $job['can_delete'] === false ) {
                        $desc = $job['description'];
                        if ( strpos($desc, 'Tag Rebuild of Datafield') === false )
                            throw new ODRException('Unexpected description for tag_rebuild job');

                        $matches = array();
                        preg_match('/(\d+)/', $desc, $matches);
                        $df_id = $matches[0];
                        if ( $datafield->getId() == $df_id ) {
                            $conflicting_job = $job;
                            break;
                        }
                    }
                }
            }


            // ----------------------------------------
            // Since tag design can be modified from the edit page, and by people without the
            //  "is_datatype_admin" permission, it makes more sense for tag design to be in a modal
            // This also reduces the chances that jQuery Sortable will mess something up
            if ( !is_null($conflicting_job) ) {
                // If there is a conflicting job, then return a different version of the tag modal
                //  that checks for the job to be finished
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Tags:tag_design_checker.html.twig',
                        array(
                            'tracked_job' => $conflicting_job,
                            'datafield_id' => $datafield_id,
                        )
                    )
                );
            }
            else {
                // If there's no conflicting job, then execute as normal
                $datatype_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't need links here
                if ( !isset($datatype_array[$datatype->getId()]['dataFields'][$datafield->getId()]) )
                    throw new ODRException('unable to locate the cached entry for this datafield');

                $cached_df = $datatype_array[$datatype->getId()]['dataFields'][$datafield->getId()];
                $stacked_tag_list = array();
                if ( isset($cached_df['tags']) )
                    $stacked_tag_list = $cached_df['tags'];

                // Render and return the html for the list
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Tags:tag_design_wrapper.html.twig',
                        array(
                            'datafield' => $cached_df,
                            'stacked_tags' => $stacked_tag_list,

                            'is_derived_field' => $is_derived_field,
                            'can_modify_template' => $can_modify_template,
                            'out_of_sync' => $out_of_sync,
                        )
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x3a2fe831;
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
     * Creates a new Tag with a default name for the given datafield.
     *
     * @param int $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function createtagAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure required options exist
            $post = $request->request->all();
            if ( !isset($post['tag_name']) || !isset($post['parent_tag_id']) )
                throw new ODRBadRequestException();
            if ( trim($post['tag_name']) === '' )
                throw new ODRBadRequestException();

            // Need to unescape this value if it's coming from a wordpress install...
            $tag_name = $post['tag_name'];
            $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
            if ( $is_wordpress_integrated )
                $tag_name = stripslashes($tag_name);

            $parent_tag_id = intval($post['parent_tag_id']);


            // Grab necessary objects
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
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');
            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$permissions_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }

            // If this is a derived field...
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( $datafield->getMasterDataField()->getTagsAllowNonAdminEdit() ) {
                    if ( !$permissions_service->canEditDatafield($user, $datafield->getMasterDataField()) )
                        throw new ODRForbiddenException();
                }
                else {
                    if ( !$permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                        throw new ODRForbiddenException();
                }

                // If this point is reached, then the user can also modify the master datafield
            }
            // --------------------

            // NOTE: don't need to check for a conflict here...creating an unselected tag has no
            //  impact on the logic run by TagHelperService::updateSelectedTags()

            // If this is getting called on a derived field...
            if ( $is_derived_field ) {
                // ...then the relevant datafields need to be in sync before continuing
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    throw new ODRBadRequestException('Not allowed to create a new tag when the derived field is out of sync with its master field');
            }

            if ( !$datafield->getTagsAllowMultipleLevels() && $parent_tag_id !== 0 )
                throw new ODRBadRequestException('Not allowed to specify a parent tag for a tag field that only has a single level of tags');

            $master_parent_tag = null;
            $parent_tag = null;

            if ( $parent_tag_id !== 0 ) {
                /** @var Tags|null $parent_tag */
                $parent_tag = $em->getRepository('ODRAdminBundle:Tags')->find($parent_tag_id);
                if ($parent_tag == null)
                    throw new ODRNotFoundException('Parent Tag');
                if ( $parent_tag->getDataField()->getId() != $datafield_id )
                    throw new ODRNotFoundException('Parent Tag');

                if ( $is_derived_field ) {
                    /** @var Tags|null $master_parent_tag */
                    $master_parent_tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                        array(
                            'dataField' => $datafield->getMasterDataField()->getId(),
                            'tagUuid' => $parent_tag->getTagUuid(),
                        )
                    );
                    if ($master_parent_tag == null)
                        throw new ODRNotFoundException('Master Parent Tag');
                }
            }

            // NOTE: duplicate tag names are technically allowed, so don't need to verify that


            // ----------------------------------------
            // The request to create this tag could come from either a template or a derived field...
            $new_master_tag = null;
            $new_tag = null;

            if ( $is_derived_field ) {
                // ...this is a request to create a tag for a derived field, which means two tags
                //  need to get created

                // Create the master tag first...
                $new_master_tag = $entity_create_service->createTag(
                    $user,
                    $datafield->getMasterDataField(),
                    true,    // always create a new tag
                    $tag_name
                );

                // ...then create the derived tag
                $new_tag = $entity_create_service->createTag(
                    $user,
                    $datafield,
                    true,    // always create a new tag
                    $tag_name,
                    true    // don't randomly generate a uuid for the derived tag
                );

                // The derived tag needs the UUID of its new master tag
                $new_tag->setTagUuid( $new_master_tag->getTagUuid() );
                $em->persist($new_tag);
            }
            else {
                // Otherwise, this is a request to create a tag for a field which is not derived,
                //  or a request to create a tag directly from a template
                $new_tag = $entity_create_service->createTag(
                    $user,
                    $datafield,
                    true,    // always create a new tag
                    $tag_name
                );
            }

            // createTag() does not automatically flush when $force_create == true
            $em->flush();

            // Should refresh after flushing...
            if ( !is_null($new_master_tag) )
                $em->refresh($new_master_tag);
            $em->refresh($new_tag);

            // If the new tag was supposed to be a child of some other tag, then that needs to be
            //  set prior to attempting a resort of the tags in the datafield
            if ( !is_null($parent_tag) ) {
                // ...then set that as well
                $entity_create_service->createTagTree($user, $parent_tag, $new_tag);

                // ...and also set the master tag if it's a derived field
                if ( $is_derived_field )
                    $entity_create_service->createTagTree($user, $master_parent_tag, $new_master_tag);

                // Don't have to flush after these
            }

            // If the tags are supposed to be sorted by name, then force a re-sort
            if ( $is_derived_field && $datafield->getMasterDataField()->getRadioOptionNameSort() === true )
                $sort_service->sortTagsByName($user, $datafield->getMasterDataField());
            if ($datafield->getRadioOptionNameSort() === true)
                $sort_service->sortTagsByName($user, $datafield);


            // ----------------------------------------
            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield);
            else if ( $is_derived_field )
                $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield->getMasterDataField());

            // Fire off events related to datafields
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

            // Fire off events related to datatypes
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
            // Don't need to clear the tag tree entries...the new tag is top level, and has no children


            // ----------------------------------------
            // Instruct the page to reload to get the updated HTML
            $return['d'] = array(
                'datafield_id' => $datafield->getId(),
                'reload_datafield' => true,
                'tag_id' => $new_tag->getId(),
            );
        }
        catch (\Exception $e) {
            $source = 0xdc63b458;
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
     * Imports a newline-separated list of Tag names.
     *
     * @param int $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function validatetaglistAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure required options exist
            $post = $request->request->all();
            if ( !isset($post['tag_list']) || trim($post['tag_list']) === '' )
                throw new ODRBadRequestException();

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CloneTemplateService $clone_template_service */
            $clone_template_service = $this->container->get('odr.clone_template_service');
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ODRTabHelperService $tab_helper_service */
            $tab_helper_service = $this->container->get('odr.tab_helper_service');
            /** @var TagHelperService $tag_helper_service */
            $tag_helper_service = $this->container->get('odr.tag_helper_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to import tags into a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$permissions_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }

            // If this is a derived field...
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( $datafield->getMasterDataField()->getTagsAllowNonAdminEdit() ) {
                    if ( !$permissions_service->canEditDatafield($user, $datafield->getMasterDataField()) )
                        throw new ODRForbiddenException();
                }
                else {
                    if ( !$permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                        throw new ODRForbiddenException();
                }

                // If this point is reached, then the user can also modify the master datafield
            }
            // --------------------

            // Also, require a delimiter to be set if this field allows parent/child relationships
            if ( $datafield->getTagsAllowMultipleLevels() && !isset($post['tag_hierarchy_delimiter']) )
                throw new ODRBadRequestException('Missing tag hierarchy delimiter');

            // If this is getting called on a derived field...
            if ( $is_derived_field ) {
                // ...then the relevant datafields need to be in sync before continuing
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    throw new ODRBadRequestException('Not allowed to import new tags when the derived field is out of sync with its master field');
            }


            // ----------------------------------------
            // Verify that the tag data passed in is reasonable
            $lines = array();
            $errors = array();
            $posted_tags = array();

            $tag_list = $post['tag_list'];
            if ( strlen($tag_list) > 0 ) {
                // Need to unescape this value if it's coming from a wordpress install...
                $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
                if ( $is_wordpress_integrated )
                    $tag_list = stripslashes($tag_list);

                $lines = explode("\n", $tag_list);
            }

            $line_num = 0;
            foreach ($lines as $line) {
                // Skip over blank lines
                $line_num++;
                $line = trim($line);
                if ($line === '')
                    continue;

                $new_tags = array($line);
                if ( $datafield->getTagsAllowMultipleLevels() )
                    $new_tags = explode($post['tag_hierarchy_delimiter'], $line);

                foreach ($new_tags as $num => $tag) {
                    // TODO - other errors?
                    $tag = trim($tag);
                    if ($tag === '') {
                        $errors[] = array(
                            'line_num' => $line_num,
                            'message' => 'Blank tag at level '.($num+1),
                            'line' => $line,
                        );
                    }
                    else {
                        $new_tags[$num] = $tag;
                    }

                    // Store the trimmed tags for the next step
                    $posted_tags[$line_num] = $new_tags;
                }
            }


            // ----------------------------------------
            // Only proceed with rendering the new tag list if there were no errors...
            if ( count($errors) === 0 ) {
                // ...going to need the datafield array entry for later
                $cached_dt = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);
                $cached_df = $cached_dt[$datatype->getId()]['dataFields'][$datafield->getId()];

                // Convert any existing tags into a slightly different format
                $stacked_tag_array = $tag_helper_service->convertTagsForListImport($cached_df['tags']);

                // Splice this tag into the stacked array of existing tags
                $would_create_new_tag = false;
                foreach ($posted_tags as $num => $new_tags)
                    $stacked_tag_array = $tag_helper_service->insertTagsForListImport($stacked_tag_array, $new_tags, $would_create_new_tag);

                // Ensure the tags are sorted by name
                $tag_helper_service->orderStackedTagArray($stacked_tag_array, true);

                // Going to store the potential import data in the user's session...
                $session = $request->getSession();
                $tag_import_lists = array();
                if ( $session->has('tag_import_lists') )
                    $tag_import_lists = $session->get('tag_import_lists');

                // Don't bother storing the tag list if nothing would get changed on import
                $token = '';
                if ( $would_create_new_tag ) {
                    $token = $tab_helper_service->createTabId();
                    $tag_import_lists[$token] = $posted_tags;

                    $session->set('tag_import_lists', $tag_import_lists);
                }


                // Render and return the given tag list as HTML so it can be verified
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Tags:tag_import_validate.html.twig',
                        array(
                            'would_create_new_tag' => $would_create_new_tag,
                            'stacked_tags' => $stacked_tag_array,

                            'datafield_id' => $datafield->getId(),
                            'token' => $token,
                        )
                    )
                );

            }
            else {
                // ...otherwise, render a quick little error dialogue
                $return['r'] = 1;
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Tags:tag_import_errors.html.twig',
                        array(
                            'errors' => $errors,
                        )
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x33fed4b7;
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
     * Turns a given POST request into a pile of new tag commits
     *
     * @param int $datafield_id
     * @param string $token
     * @param Request $request
     *
     * @return Response
     */
    public function importtaglistAction($datafield_id, $token, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CloneTemplateService $clone_template_service */
            $clone_template_service = $this->container->get('odr.clone_template_service');
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var TagHelperService $tag_helper_service */
            $tag_helper_service = $this->container->get('odr.tag_helper_service');
            /** @var UUIDService $uuid_service */
            $uuid_service = $this->container->get('odr.uuid_service');

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $grandparent_datatype_id = $datatype->getGrandparent()->getId();

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to import tags into a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$permissions_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }

            // If this is a derived field...
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( $datafield->getMasterDataField()->getTagsAllowNonAdminEdit() ) {
                    if ( !$permissions_service->canEditDatafield($user, $datafield->getMasterDataField()) )
                        throw new ODRForbiddenException();
                }
                else {
                    if ( !$permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                        throw new ODRForbiddenException();
                }

                // If this point is reached, then the user can also modify the master datafield
            }
            // --------------------

            // NOTE: don't need to check for a conflict here...creating unselected tags has no
            //  impact on the logic run by TagHelperService::updateSelectedTags()

            // If this is getting called on a derived field...
            if ( $is_derived_field ) {
                // ...then the relevant datafields need to be in sync before continuing
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    throw new ODRBadRequestException('Not allowed to import new tags when the derived field is out of sync with its master field');
            }

            // ----------------------------------------
            // Require this token to be set in the user's session
            $session = $request->getSession();
            if ( !$session->has('tag_import_lists') )
                throw new ODRBadRequestException('Tag import attempted without previous session');
            $tag_import_lists = $session->get('tag_import_lists');
            if ( !isset($tag_import_lists[$token]) )
                throw new ODRBadRequestException('Tag import attempted with invalid session');

            // Extract the previously posted tag list out of the user's session
            $posted_tags = $tag_import_lists[$token];

            // Remove the tag list from the user's session
            unset( $tag_import_lists[$token] );
            $session->set('tag_import_lists', $tag_import_lists);


            // ----------------------------------------
            // Going to need a stacked array version of the tags to combine with the posted data
            $dt_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);
            $df_array = $dt_array[$datatype->getId()]['dataFields'][$datafield->getId()];
            $stacked_tag_array = $tag_helper_service->convertTagsForListImport($df_array['tags']);

            // If this is being called on a derived datafield, then the import function needs to
            //  simultaneously create tags for both the derived and the master datafield...
            $master_datafield = null;
            if ( $is_derived_field )
                $master_datafield = $datafield->getMasterDataField();

            // Splice each of the posted tag trees into the existing stacked tag structure
            // Flushing can be delayed until afterwards
            $hydrated_tag_array = array();

            foreach ($posted_tags as $num => $new_tags) {
                $stacked_tag_array = self::createTagsForListImport(
                    $em,
                    $entity_create_service,
                    $uuid_service,
                    $user,
                    $datafield,
                    $master_datafield,
                    $hydrated_tag_array,
                    $stacked_tag_array,
                    $new_tags,
                    null    // no uuid of a parent tag here, since the first call is for top-level tags
                );
            }

            // Flush now that all the tags have been created
            $em->flush();


            // ----------------------------------------
            // If the tags are supposed to be sorted by name, then force a re-sort
            if ( $is_derived_field && $datafield->getMasterDataField()->getRadioOptionNameSort() === true )
                $sort_service->sortTagsByName($user, $datafield->getMasterDataField());
            if ($datafield->getRadioOptionNameSort() === true)
                $sort_service->sortTagsByName($user, $datafield);


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
        }
        catch (\Exception $e) {
            $source = 0x75de8980;
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
     * Updates the existing tags for a datafield so it has everything listed in $posted_tags
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EntityCreationService $entity_create_service
     * @param UUIDService $uuid_service
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param DataFields|null $master_datafield Will be null unless $datafield is derived
     * @param Tags[] $hydrated_tag_array A flat array of all tags for this datafield, organized by
     *                                   their uuids
     * @param array $stacked_tag_array @see TagHelperService::convertTagsForListImport()
     * @param array $posted_tags A flat array of the tag(s) that may end up being inserted into the
     *                           datafield...top level tag at index 0, its child at 1, etc
     * @param string|null $parent_tag_uuid
     *
     * @return array
     */
    private function createTagsForListImport($em, $entity_create_service, $uuid_service, $user, $datafield, $master_datafield, &$hydrated_tag_array, &$stacked_tag_array, $posted_tags, $parent_tag_uuid)
    {
        // Going to need this in case the tag has children
        $current_tag_uuid = null;

        // Need to locate the uuid of the tag if it already exists
        $tag_name = $posted_tags[0];
        if ( isset($stacked_tag_array[$tag_name]) ) {
            $current_tag_uuid = $stacked_tag_array[$tag_name]['tagUuid'];
        }
        else {
            // ...since the tag doesn't exist, it needs to be created
            $datafield_id = $datafield->getId();
            $new_master_tag = null;
            $new_tag = null;

            if ( !is_null($master_datafield) ) {
                // If a master datafield is given, then this function needs to first create a tag
                //  for that datafield...
                $master_datafield_id = $master_datafield->getId();

                $new_master_tag = $entity_create_service->createTag(
                    $user,
                    $datafield->getMasterDataField(),
                    true,    // always create a new tag
                    $tag_name
                );

                // ...then create the derived tag
                $new_tag = $entity_create_service->createTag(
                    $user,
                    $datafield,
                    true,    // always create a new tag
                    $tag_name,
                    true    // don't randomly generate a uuid for the derived tag
                );

                // The derived tag needs the UUID of its new master tag
                $current_tag_uuid = $new_master_tag->getTagUuid();
                $new_tag->setTagUuid($current_tag_uuid);
                $em->persist($new_tag);

                // Should store the new tags for later reference...
                $hydrated_tag_array[$master_datafield_id.'_'.$current_tag_uuid] = $new_master_tag;
                $hydrated_tag_array[$datafield_id.'_'.$current_tag_uuid] = $new_tag;
            }
            else {
                // Otherwise, this is a request to create a tag for a field which is not derived,
                //  or a request to create a tag directly from a template
                $new_tag = $entity_create_service->createTag(
                    $user,
                    $datafield,
                    true,    // always create a new tag
                    $tag_name
                );

                // Should store the new tags for later reference...
                $current_tag_uuid = $new_tag->getTagUuid();
                $hydrated_tag_array[$datafield_id.'_'.$current_tag_uuid] = $new_tag;
            }

            // If $parent_tag_uuid is not null, then this new tag also needs a new TagTree entry to
            //  insert it at the correct spot in the tag hierarchy
            if ( !is_null($parent_tag_uuid) ) {
                // NOTE - ...unable to use createTagTree() here, because it needs a flush in order to lock properly
//                $entity_create_service->createTagTree($user, $parent_tag, $new_tag);

                // Hopefully the parent tag has already been hydrated...
                $parent_tag = null;
                if ( isset($hydrated_tag_array[$datafield_id.'_'.$parent_tag_uuid]) )
                    $parent_tag = $hydrated_tag_array[$datafield_id.'_'.$parent_tag_uuid];

                if ( is_null($parent_tag) ) {
                    // ...but if it hasn't, then hydrate it directly
                    // NOTE - theoretically this should only get triggered on tags that haven't been created by this function
                    $parent_tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                        array(
                            'tagUuid' => $parent_tag_uuid,
                            'dataField' => $datafield_id,
                        )
                    );
                    $hydrated_tag_array[$datafield_id.'_'.$parent_tag_uuid] = $parent_tag;
                }
                /** @var Tags $parent_tag */

                // Create a new TagTree entry linking the parent tag with the newly created child
                $tag_tree = new TagTree();
                $tag_tree->setParent($parent_tag);
                $tag_tree->setChild($new_tag);

                $tag_tree->setCreatedBy($user);

                $em->persist($tag_tree);

                if ( !is_null($master_datafield) ) {
                    // If a master datafield is given, then this function needs to also create a new
                    //  TagTree entry for the new master tag...

                    // Like before, hopefully the parent master tag has already been hydrated...
                    $parent_master_tag = null;
                    if ( isset($hydrated_tag_array[$master_datafield_id.'_'.$parent_tag_uuid]) )
                        $parent_master_tag = $hydrated_tag_array[$master_datafield_id.'_'.$parent_tag_uuid];

                    if ( is_null($parent_master_tag) ) {
                        // ...but if it hasn't, then hydrate it directly
                        // NOTE - theoretically this should only get triggered on tags that haven't been created by this function
                        $parent_master_tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                            array(
                                'tagUuid' => $parent_tag_uuid,
                                'dataField' => $master_datafield_id,
                            )
                        );
                        $hydrated_tag_array[$master_datafield_id.'_'.$parent_tag_uuid] = $parent_master_tag;
                    }
                    /** @var Tags $parent_master_tag */

                    $tag_tree = new TagTree();
                    $tag_tree->setParent($parent_master_tag);
                    $tag_tree->setChild($new_master_tag);

                    $tag_tree->setCreatedBy($user);

                    $em->persist($tag_tree);
                }
            }

            $stacked_tag_array[$tag_name] = array(
                'id' => $current_tag_uuid,    // Don't really care what the ID is...only used for rendering
                'tagMeta' => array(
                    'tagName' => $tag_name
                ),
                'tagUuid' => $current_tag_uuid,
            );
        }

        // If $posted_tags has more than one tag in the array, then need to check for descendents...
        if ( count($posted_tags) > 1 ) {
            // ...easiest way to do this is to cut out the current tag from the array...
            $new_tags = array_slice($posted_tags, 1);

            // ...and get any children the existing tag already has
            $existing_child_tags = array();
            if ( isset($stacked_tag_array[$tag_name]['children']) )
                $existing_child_tags = $stacked_tag_array[$tag_name]['children'];

            // ...then continue working recursively
            $stacked_tag_array[$tag_name]['children'] = self::createTagsForListImport(
                $em,
                $entity_create_service,
                $uuid_service,
                $user,
                $datafield,
                $master_datafield,
                $hydrated_tag_array,
                $existing_child_tags,
                $new_tags,
                $current_tag_uuid
            );
        }

        return $stacked_tag_array;
    }


    /**
     * Deletes the given Tag.
     *
     * @param int $tag_id
     * @param Request $request
     *
     * @return Response
     */
    public function deletetagAction($tag_id, Request $request)
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


            /** @var Tags $tag */
            $tag_id = intval($tag_id);
            $tag = $em->getRepository('ODRAdminBundle:Tags')->find($tag_id);
            if ($tag == null)
                throw new ODRNotFoundException('Tag');

            $datafield = $tag->getDataField();
            if ( $datafield->getDeletedAt() != null )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');

            $grandparent_datatype = $datatype->getGrandparent();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent Datatype');
            $grandparent_datatype_id = $grandparent_datatype->getId();

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to delete tags from a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$permissions_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }

            // If this is a derived field...
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( $datafield->getMasterDataField()->getTagsAllowNonAdminEdit() ) {
                    if ( !$permissions_service->canEditDatafield($user, $datafield->getMasterDataField()) )
                        throw new ODRForbiddenException();
                }
                else {
                    if ( !$permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                        throw new ODRForbiddenException();
                }

                // If this point is reached, then the user can also modify the master datafield
            }
            // --------------------

            // If this is getting called on a derived field...
            if ( $is_derived_field ) {
                // ...then the relevant datafields need to be in sync before continuing
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    throw new ODRBadRequestException('Not allowed to delete a tag when the derived field is out of sync with its master field');
            }


            // Check whether any jobs that are currently running would interfere with the deletion
            //  of this tag
            $new_job_data = array(
                'job_type' => 'delete_tag',
                'target_entity' => $datafield,
            );

            $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to delete this Tag, as it would interfere with an already running '.$conflicting_job.' job');


            // ----------------------------------------
            // When deleting a tag, all of its children need deleted too...
            $relevant_tags = array($tag);
            if ( $is_derived_field ) {
                // ...if this is a request to delete a tag from a derived field, then its master
                //  tag also needs to be deleted
                /** @var Tags $master_tag */
                $master_tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                    array(
                        'dataField' => $datafield->getMasterDataField(),
                        'tagUuid' => $tag->getTagUuid(),
                    )
                );

                $relevant_tags[] = $master_tag;
            }

            $tags_to_delete = self::findTagsToDelete($relevant_tags);

            // NOTE: unlike the deletion of derived tags deleting their master template tags...
            //  ...it doesn't make sense to also delete derived tags when master template tags get
            //  deleted...the template synchronization needs to tell the user what will be changed,
            //  or changes get made without the user's knowledge/consent....which is bad.


            // ----------------------------------------
            // Run a query to get all of the tag tree entries that are going to need deletion
            $query = $em->createQuery(
               'SELECT tt.id
                FROM ODRAdminBundle:TagTree AS tt
                WHERE (tt.parent IN (:parent_tags) OR tt.child IN (:child_tags) )
                AND tt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'parent_tags' => $tags_to_delete,
                    'child_tags' => $tags_to_delete,
                )
            );
            $results = $query->getArrayResult();

            $tag_trees_to_delete = array();
            foreach ($results as $num => $tt)
                $tag_trees_to_delete[] = $tt['id'];


            // Do the same to get all of the tag selection entries that need deletion
            $query = $em->createQuery(
               'SELECT ts.id
                FROM ODRAdminBundle:TagSelection AS ts
                WHERE ts.tag IN (:tag_list) AND ts.deletedAt IS NULL'
            )->setParameters( array('tag_list' => $tags_to_delete) );
            $results = $query->getArrayResult();

            $tag_selections_to_delete = array();
            foreach ($results as $num => $ts)
                $tag_selections_to_delete[] = $ts['id'];


            // ----------------------------------------
            // Wrap this mass deletion inside a mysql transaction
            $conn = $em->getConnection();
            $conn->beginTransaction();

            // Delete all Tag and TagMeta entries
            $query_str =
               'UPDATE odr_tags AS t, odr_tag_meta AS tm
                SET t.deletedAt = NOW(), tm.deletedAt = NOW(),
                    t.deletedBy = '.$user->getId().'
                WHERE tm.tag_id = t.id AND t.id IN (?)
                AND t.deletedAt IS NULL AND tm.deletedAt IS NULL';
            $parameters = array(1 => $tags_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);

            // Delete the tag tree entries
            $query_str =
               'UPDATE odr_tag_tree AS tt
                SET tt.deletedAt = NOW(), tt.deletedBy = '.$user->getId().'
                WHERE tt.id IN (?)';
            $parameters = array(1 => $tag_trees_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);

            // Delete the tag selection entries
            $query_str =
               'UPDATE odr_tag_selection AS ts
                SET ts.deletedAt = NOW()
                WHERE ts.id IN (?)';
            $parameters = array(1 => $tag_selections_to_delete);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);

            // No error encountered, commit changes
            $conn->commit();


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
                    $event = new DatatypeModifiedEvent($datatype->getMasterDataType(), $user, true);    // need to wipe cached datarecord entries since could've deleted a selected tag
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }

                $event = new DatatypeModifiedEvent($datatype, $user, true);    // need to wipe cached datarecord entries since could've deleted a selected tag
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
            // Abort a transaction if one is active
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x0f39547e;
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
     * The tag modal UI allows users to directly modify tags in a derived field...which means that
     * deletion logic might need to also simultaneously work on the master field.  As such, it's
     * easier to have the logic for identifying every tag to be deleted in its own function.
     *
     * @param Tags[] $relevant_tags
     * @return array
     */
    private function findTagsToDelete($relevant_tags)
    {
        $tags_to_delete = array();

        /** @var TagHelperService $tag_helper_service */
        $tag_helper_service = $this->container->get('odr.tag_helper_service');

        foreach ($relevant_tags as $tag) {
            $tag_id = $tag->getId();
            $datafield_id = $tag->getDataField()->getId();
            $datatype_id = $tag->getDataField()->getDataType()->getId();
            $grandparent_datatype_id = $tag->getDataField()->getDataType()->getGrandparent()->getId();

            // May need to traverse the tag tree hierarchy to properly delete this tag...
            $tag_hierarchy = $tag_helper_service->getTagHierarchy($grandparent_datatype_id);
            if ( isset($tag_hierarchy[$datatype_id][$datafield_id]) )
                // Only interested in the hierarchy for this tag's datafield, if it exists
                $tag_hierarchy = $tag_hierarchy[$datatype_id][$datafield_id];
            else
                // ...if it doesn't, then there won't be any child tags to find
                $tag_hierarchy = array();

            // Use the tag hierarchy to locate all children of the tag being deleted
            $tags_to_delete[] = $tag_id;

            $tags_to_process = array($tag_id);
            while ( !empty($tags_to_process) ) {
                // While there's still tags to be processed...
                $tmp = $tags_to_process;
                $tags_to_process = array();

                foreach ($tmp as $num => $t_id) {
                    // ...if this tag has children...
                    if ( isset($tag_hierarchy[$t_id]) ) {
                        foreach ($tag_hierarchy[$t_id] as $child_tag_id => $val) {
                            // ...they're going to be deleted as well
                            $tags_to_delete[] = $child_tag_id;
                            // ...and need to be checked for child tags of their own
                            $tags_to_process[] = $child_tag_id;
                        }
                    }
                }
            }
        }

        return $tags_to_delete;
    }


    /**
     * Parses a post request for rearranging tags.
     *
     * @param int $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function movetagAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
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
            /** @var TrackedJobService $tracked_job_service */
            $tracked_job_service = $this->container->get('odr.tracked_job_service');


            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to move tags in a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // ----------------------------------------
            // Extract data from the post request and verify it
            $post = $request->request->all();

            if ( !isset($post['tag_ordering'])
                || !isset($post['child_tag_id'])
                || !isset($post['parent_tag_id'])
            ) {
                throw new ODRBadRequestException('Invalid Form');
            }

            $tag_ordering = $post['tag_ordering'];
            if ( !is_array($tag_ordering) )
                $tag_ordering = array();

            $child_tag_id = $post['child_tag_id'];
            $parent_tag_id = $post['parent_tag_id'];


            // ----------------------------------------
            // Require $child_tag_id to be a non-zero numerical value
            $pattern = '/^\d+$/';
            if ( preg_match($pattern, $child_tag_id) === false )
                throw new ODRBadRequestException('Non-numeric tag id given');

            $child_tag_id = intval($child_tag_id);
            if ($child_tag_id === 0)
                throw new ODRBadRequestException('Invalid tag id given');

            /** @var Tags $child_tag */
            $child_tag = $em->getRepository('ODRAdminBundle:Tags')->find($child_tag_id);
            if ($child_tag == null)
                throw new ODRNotFoundException('Child Tag');

            // Also require $child_tag to belong to the given datafield
            if ($child_tag->getDataField()->getId() !== $datafield->getId())
                throw new ODRBadRequestException('Tag does not belong to field');


            // $parent_tag_id should not exist if the datafield only permits a "flat" tag list
            $parent_tag = null;
            if ( $parent_tag_id !== '' && !$datafield->getTagsAllowMultipleLevels() )
                throw new ODRBadRequestException();

            // If $parent_tag_id exists, also require it to be non-zero
            if ($parent_tag_id !== '') {
                if ( preg_match($pattern, $parent_tag_id) === false )
                    throw new ODRBadRequestException('Non-numeric tag id');

                $parent_tag_id = intval($parent_tag_id);
                if ($parent_tag_id === 0)
                    throw new ODRBadRequestException('Invalid tag id');

                /** @var Tags $parent_tag */
                $parent_tag = $em->getRepository('ODRAdminBundle:Tags')->find($parent_tag_id);
                if ($parent_tag == null)
                    throw new ODRNotFoundException('Parent Tag');

                // Also require $parent_tag to belong to the given datafield
                if ($parent_tag->getDataField()->getId() !== $datafield->getId())
                    throw new ODRBadRequestException('Tag does not belong to given datafield');
            }

            // Due to potentially needing to work on both a template field and a derived field at
            //  the same time, it's minimally easier to work with the tag uuids TODO - true or false?
            $child_tag_uuid = $child_tag->getTagUuid();
            $parent_tag_uuid = null;
            if ( !is_null($parent_tag) )
                $parent_tag_uuid = $parent_tag->getTagUuid();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$permissions_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }

            // If this is a derived field...
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( $datafield->getMasterDataField()->getTagsAllowNonAdminEdit() ) {
                    if ( !$permissions_service->canEditDatafield($user, $datafield->getMasterDataField()) )
                        throw new ODRForbiddenException();
                }
                else {
                    if ( !$permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                        throw new ODRForbiddenException();
                }

                // If this point is reached, then the user can also modify the master datafield
            }
            // --------------------

            // If this is getting called on a derived field...
            if ( $is_derived_field ) {
                // ...then the relevant datafields need to be in sync before continuing
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    throw new ODRBadRequestException('Not allowed to move tags when the derived field is out of sync with its master field');
            }

            // TODO - technically, tags should work similarly to radio options...so long as the parent tag doesn't change, then the "meaning" of the field also doesn't change
            // TODO - ...but actually implementing that doesn't seem to be trivial

            // Should also verify that all tags in $tag_ordering belong to this datafield
            $query = $em->createQuery(
               'SELECT df.id AS df_id
                FROM ODRAdminBundle:Tags t
                JOIN ODRAdminBundle:DataFields df WITH t.dataField = df
                WHERE t IN (:tags)
                AND t.deletedAt IS NULL AND df.deletedAt IS NULL'
            )->setParameters( array('tags' => $tag_ordering) );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                if ( $result['df_id'] != $datafield->getId() )
                    throw new ODRBadRequestException('Tag does not belong to the given datafield');
            }


            // ----------------------------------------
            // Check whether any jobs that are currently running would interfere with a newly
            //  created 'tag_rebuild' job for this datatype
            $new_job_data = array(
                'job_type' => 'tag_rebuild',
                'target_entity' => $datafield,
            );

            $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to move this tag, as it would interfere with an already running '.$conflicting_job.' job');

            // TODO - technically, this is overkill...this error doesn't need to happen when moving a tag doesn't change its parent
            // TODO - however, blocking all movements means I don't have to explain to users why it makes a difference...


            // ----------------------------------------
            // Only create/delete tag tree entries if the tag field allows child/parent tags
            $changes_made = array();
            if ( $datafield->getTagsAllowMultipleLevels() ) {
                // The request to move this tag can come from one of three places...
                if ( $is_derived_field ) {
                    // ...this is a request to move a tag for a derived field, which means the master
                    //  tag also needs to get moved
                    self::updateTagTrees($em, $entity_create_service, $user, $datafield->getMasterDataField(), $child_tag_uuid, $parent_tag_uuid);
                    // NOTE - ignoring $changes_made here, since it'll be identical to the following call...
                }

                // The requested tag should always get moved
                $changes_made = self::updateTagTrees($em, $entity_create_service, $user, $datafield, $child_tag_uuid, $parent_tag_uuid);
            }

            $create_new_entry = $delete_old_entry = $tag_hierarchy_changed = false;
            if ( !empty($changes_made) ) {
                $create_new_entry = $changes_made['create_new_entry'];
                $delete_old_entry = $changes_made['delete_old_entry'];
                $tag_hierarchy_changed = $changes_made['tag_hierarchy_changed'];

                // TODO - if the above ODRConflictException doesn't fire 100% of the time...
                // TODO - ...then this changeset needs to be determined before changes are made in self::updateTagTrees()
            }


            // ----------------------------------------
            // If the datafield is set to automatically sort by tag name...
            $tag_sort_order_changed = null;
            if ( $datafield->getRadioOptionNameSort() ) {
                // ...then ignore whatever is in $tag_ordering and resort the entire tag list
                $tag_sort_order_changed = $sort_service->sortTagsByName($user, $datafield);

                // If this is a derived field, then should sort that too
                if ( $is_derived_field )
                    $sort_service->sortTagsByName($user, $datafield->getMasterDataField());
            }
            else {
                // Need to potentially look up tags if their displayOrder gets changed
                $repo_tags = $em->getRepository('ODRAdminBundle:Tags');

                $query = $em->createQuery(
                   'SELECT t.id AS t_id, tm.displayOrder
                    FROM ODRAdminBundle:Tags AS t
                    JOIN t.tagMeta AS tm
                    WHERE t.dataField = :datafield
                    AND t.deletedAt IS NULL AND tm.deletedAt IS NULL'
                )->setParameters( array('datafield' => $datafield_id) );
                $results = $query->getArrayResult();

                // Organize by the id of the tag
                $tag_list = array();
                foreach ($results as $result) {
                    $t_id = $result['t_id'];
                    $display_order = $result['displayOrder'];

                    $tag_list[$t_id] = $display_order;
                }

                $tag_sort_order_changed = false;
                foreach ($tag_ordering as $display_order => $tag_id) {
                    if ($tag_list[$tag_id] !== $display_order) {
                        // ...if a tag is not in the correct order, then hydrate it...
                        /** @var Tags $tag */
                        $tag = $repo_tags->find($tag_id);

                        // ...and update its displayOrder
                        $properties = array(
                            'displayOrder' => $display_order
                        );
                        $entity_modify_service->updateTagMeta($user, $tag, $properties, true);    // don't flush immediately...
                        $tag_sort_order_changed = true;

                        if ( $is_derived_field ) {
                            // If this is a derived field, then need to also update the master tag
                            /** @var Tags $master_tag */
                            $master_tag = $repo_tags->findOneBy(
                                array(
                                    'dataField' => $datafield->getMasterDataField(),
                                    'tagUuid' => $tag->getTagUuid()
                                )
                            );

                            // Can just reuse the properties array for the derived tag
                            $entity_modify_service->updateTagMeta($user, $master_tag, $properties, true);    // don't flush immediately...
                        }
                    }
                }

                if ($tag_sort_order_changed)
                    $em->flush();
            }


            // ----------------------------------------
            if ($tag_hierarchy_changed || $tag_sort_order_changed) {
                // Update cached version of datatype
                try {
                    if ( $is_derived_field ) {
                        $event = new DatatypeModifiedEvent($datatype->getMasterDataType(), $user);    // don't need to delete datarecord entries...neither tag parents nor sort order is stored in them
                        $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                    }

                    $event = new DatatypeModifiedEvent($datatype, $user);    // don't need to delete datarecord entries...neither tag parents nor sort order is stored in them
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }

                // Don't need to update cached versions of search results unless tag parentage got changed...
                if ($create_new_entry || $delete_old_entry) {
                    // Master Template Data Fields must increment Master Revision on all change requests.
                    if ( $datafield->getIsMasterField() )
                        $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield);
                    else if ( $is_derived_field )
                        $entity_modify_service->incrementDatafieldMasterRevision($user, $datafield->getMasterDataField());

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
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }
                }
            }

            // If the tag changed parents...
            if ( $tag_hierarchy_changed ) {
                // ...then need to trigger a rebuild
                self::triggerTagRebuild($em, $datafield, $user, $tracked_job_service);
            }

            // Don't need to return anything, the javascript on the page already has all the data
            //  it needs
        }
        catch (\Exception $e) {
            $source = 0xec51b8f8;
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
     * Creates/deletes TagTree entities so that $child_tag is at its correct position in the
     * tag hierarchy.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EntityCreationService $entity_create_service
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param string $child_tag_uuid The UUID of the tag being moved
     * @param string|null $parent_tag_uuid The (new) UUID of $child_tag's parent...if null, then
     *                                      $child_tag is now top-level
     *
     * @return array
     */
    private function updateTagTrees($em, $entity_create_service, $user, $datafield, $child_tag_uuid, $parent_tag_uuid)
    {
        /** @var Tags $child_tag */
        $child_tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
            array(
                'dataField' => $datafield->getId(),
                'tagUuid' => $child_tag_uuid
            )
        );

        /** @var Tags|null $parent_tag */
        $parent_tag = null;
        if ( !is_null($parent_tag_uuid) ) {
            $parent_tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                array(
                    'dataField' => $datafield->getId(),
                    'tagUuid' => $parent_tag_uuid
                )
            );
        }

        // The child tag's parent needs to be changed prior to re-ordering the tags...
        //  self::sortTagsByName() relies on this relationship being set correctly
        $query = $em->createQuery(
           'SELECT tt
            FROM ODRAdminBundle:Tags AS t_c
            LEFT JOIN ODRAdminBundle:TagTree AS tt WITH tt.child = t_c
            LEFT JOIN ODRAdminBundle:Tags AS t_p WITH tt.parent = t_p
            WHERE t_c = :tag_id
            AND t_c.deletedAt IS NULL AND tt.deletedAt IS NULL AND t_p.deletedAt IS NULL'
        )->setParameters( array('tag_id' => $child_tag->getId()) );
        $result = $query->getResult();

        /** @var TagTree $tag_tree */
        $tag_tree = $result[0];

        $tag_hierarchy_changed = null;
        $delete_old_entry = false;
        $create_new_entry = false;
        // Determine whether the database entry's listed parent tag matches the one in the post
        if ( is_null($tag_tree) && is_null($parent_tag) ) {
            // The tag was top-level before, and still is...do nothing
            $tag_hierarchy_changed = false;
        }
        else if ( is_null($tag_tree) && !is_null($parent_tag) ) {
            // The tag was top-level, but now has a parent...
            $create_new_entry = true;
            $tag_hierarchy_changed = true;
        }
        else if ( !is_null($tag_tree) && is_null($parent_tag) ) {
            // The tag was not top-level, but is now...
            $delete_old_entry = true;
            $tag_hierarchy_changed = true;
        }
        else if ( !is_null($tag_tree) && !is_null($parent_tag) ) {
            if ( $tag_tree->getParent()->getId() !== $parent_tag->getId() ) {
                // The tag was moved to a different parent...
                $delete_old_entry = true;
                $create_new_entry = true;

                $tag_hierarchy_changed = true;
            }
            else {
                // Otherwise, the tag is still under the same parent...do nothing
                $tag_hierarchy_changed = false;
            }
        }

        // Delete the old TagTree if needed...
        if ($delete_old_entry) {
            $tag_tree->setDeletedBy($user);
            $tag_tree->setDeletedAt(new \DateTime());

            $em->persist($tag_tree);
            $em->flush();
        }

        // Create a new TagTree if needed...
        if ($create_new_entry)
            $entity_create_service->createTagTree($user, $parent_tag, $child_tag);

        return array(
            'create_new_entry' => $create_new_entry,
            'delete_old_entry' => $delete_old_entry,
            'tag_hierarchy_changed' => $tag_hierarchy_changed,
        );
    }


    /**
     * If a tag gets moved so that it has a new parent tag, then that could result in situations
     * where parents of a selected tag are not themselves selected.  As such, there needs to be a
     * way to trigger a tag_rebuild job after changing a tag's parent...
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataFields $datafield
     * @param ODRUser $user
     * @param TrackedJobService $tracked_job_service
     *
     * @return void
     */
    private function triggerTagRebuild($em, $datafield, $user, $tracked_job_service)
    {
        // ----------------------------------------
        // Going to need these...
        $datatype = $datafield->getDataType();
        $top_level_datatype = $datatype->getGrandparent();


        // ----------------------------------------
        // Check whether any jobs that are currently running would interfere with a newly
        //  created 'tag_rebuild' job for this datatype
        $new_job_data = array(
            'job_type' => 'tag_rebuild',
            'target_entity' => $datafield,
        );

        $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
        if ( !is_null($conflicting_job) )
            throw new ODRConflictException('Unable to start a new TagRebuild job, as it would interfere with an already running '.$conflicting_job.' job');


        // ----------------------------------------
        // Get a list of all datarecords with this datafield
        $query = $em->createQuery(
           'SELECT dr.id AS dr_id
            FROM ODRAdminBundle:DataRecord dr
            WHERE dr.dataType = :datatype_id
            AND dr.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        if ( !empty($results) ) {
            // Not entirely sure how many datarecords can be handled at once
            $records_per_job = 100;

            // Create a tracked job for this...
            $job_type = 'tag_rebuild';
            $target_entity = 'datafield_'.$datafield->getId();
            $additional_data = array('description' => 'Tag Rebuild of Datafield '.$datafield->getId().', DataType '.$datatype->getId());
            $restrictions = 'datatype_'.$top_level_datatype->getId();

            $total = intval( count($results) / $records_per_job );
            if ( $total * $records_per_job < count($results) )
                $total += 1;

            $reuse_existing = false;
//$reuse_existing = true;

            $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
            $tracked_job_id = $tracked_job->getId();


            // Going to also need these values
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');    // debug purposes only
            $url = $this->generateUrl('odr_tag_rebuild_worker', array(), UrlGeneratorInterface::ABSOLUTE_URL);

            $priority = 1024;   // should be roughly default priority
            $delay = 1;

            $datarecord_list = array();
            $count = 0;
            foreach ($results as $result) {
                $dr_id = $result["dr_id"];
                $datarecord_list[] = $dr_id;
                $count++;

                if ( ($count % $records_per_job) === 0) {

                    $priority = 1024;   // should be roughly default priority
                    $payload = array(
                        "job_type" => 'tag_rebuild',
                        "tracked_job_id" => $tracked_job_id,

                        "user_id" => $user->getId(),
                        "datarecord_list" => implode(',', $datarecord_list),
                        "datafield_id" => $datafield->getId(),

                        "redis_prefix" => $redis_prefix,    // debug purposes only
                        "url" => $url,
                        "api_key" => $api_key,
                    );
                    $payload = json_encode($payload);

                    $pheanstalk->useTube('tag_rebuild')->put($payload, $priority, $delay);

                    // Reset for next pile of datarecords
                    $datarecord_list = array();
                }
            }

            // Update any remaining datarecords
            if ( !empty($datarecord_list) ) {
                $payload = array(
                    "job_type" => 'tag_rebuild',
                    "tracked_job_id" => $tracked_job_id,

                    "user_id" => $user->getId(),
                    "datarecord_list" => implode(',', $datarecord_list),
                    "datafield_id" => $datafield->getId(),

                    "redis_prefix" => $redis_prefix,    // debug purposes only
                    "url" => $url,
                    "api_key" => $api_key,
                );
                $payload = json_encode($payload);

                $pheanstalk->useTube('tag_rebuild')->put($payload, $priority, $delay);
            }
        }
    }


    /**
     * Renames a given Tag.
     *
     * @param int $tag_id
     * @param Request $request
     *
     * @return Response
     */
    public function renametagAction($tag_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $request->request->all();
            if ( !isset($post['tag_name']) )
                throw new ODRBadRequestException();
            $tag_name = trim( $post['tag_name'] );
            if ($tag_name === '')
                throw new ODRBadRequestException("Tag Names can't be blank");

            // Need to unescape this value if it's coming from a wordpress install...
            $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
            if ( $is_wordpress_integrated )
                $tag_name = stripslashes($tag_name);


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


            /** @var Tags $tag */
            $tag = $em->getRepository('ODRAdminBundle:Tags')->find($tag_id);
            if ($tag == null)
                throw new ODRNotFoundException('Tag');

            $datafield = $tag->getDataField();
            if ( $datafield->getDeletedAt() != null )
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to rename a tag for a '.$typeclass.' field');

            // If this is a derived field, then some stuff is different
            $is_derived_field = false;
            if ( !is_null($datafield->getMasterDataField()) )
                $is_derived_field = true;


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$permissions_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }

            // If this is a derived field...
            if ( $is_derived_field ) {
                // ...then the same permissions checks need to be run on the template field
                if ( $datafield->getMasterDataField()->getTagsAllowNonAdminEdit() ) {
                    if ( !$permissions_service->canEditDatafield($user, $datafield->getMasterDataField()) )
                        throw new ODRForbiddenException();
                }
                else {
                    if ( !$permissions_service->isDatatypeAdmin($user, $datatype->getMasterDataType()) )
                        throw new ODRForbiddenException();
                }

                // If this point is reached, then the user can also modify the master datafield
            }
            // --------------------

            // If this is getting called on a derived field...
            if ( $is_derived_field ) {
                // ...then the relevant datafields need to be in sync before continuing
                if ( $clone_template_service->isDatafieldOutOfSync($datafield) )
                    throw new ODRBadRequestException('Not allowed to rename a tag when the derived field is out of sync with its master field');
            }

            // Check whether any jobs that are currently running would interfere with the deletion
            //  of this datarecord
            $new_job_data = array(
                'job_type' => 'rename_tag',
                'target_entity' => $datafield,
            );

            $conflicting_job = $tracked_job_service->getConflictingBackgroundJob($new_job_data);
            if ( !is_null($conflicting_job) )
                throw new ODRConflictException('Unable to rename this Tag, as it would interfere with an already running '.$conflicting_job.' job');


            // ----------------------------------------
            // Could have to rename more than one tag...
            $master_tag = null;
            $properties = array(
                'tagName' => $tag_name
            );

            // The request to rename this tag can come from one of three places...
            if ( $is_derived_field ) {
                // ...if this is a request to rename a tag from a derived field, then its master
                //  tag also needs to be renamed
                /** @var Tags $master_tag */
                $master_tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                    array(
                        'dataField' => $datafield->getMasterDataField(),
                        'tagUuid' => $tag->getTagUuid(),
                    )
                );
                $entity_modify_service->updateTagMeta($user, $master_tag, $properties, true);    // don't flush immediately
            }

            // The tag this controller action was called with should always be updated
            $entity_modify_service->updateTagMeta($user, $tag, $properties);
            // Flushing here is intentional

            // If the datafield is being sorted by name, then also update the displayOrder
            $changes_made = false;
            if ( $datafield->getRadioOptionNameSort() )
                $changes_made = $sort_service->sortTagsByName($user, $datafield);
            if ( $is_derived_field && $datafield->getMasterDataField()->getRadioOptionNameSort() === true )
                $sort_service->sortTagsByName($user, $datafield->getMasterDataField());


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
                    $event = new DatatypeModifiedEvent($datatype->getMasterDataType(), $user, true);    // need to wipe cached datarecord entries since they have tag names
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                }

                $event = new DatatypeModifiedEvent($datatype, $user, true);    // need to wipe cached datarecord entries since they have tag names
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
                'tag_id' => $tag->getId(),
            );
        }
        catch (\Exception $e) {
            $source = 0x480125c5;
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
     * Toggles whether a given Tag is selected or not.
     *
     * @param int $datarecord_id
     * @param int $datafield_id
     * @param int $tag_id
     * @param Request $request
     *
     * @return Response
     */
    public function tagselectionAction($datarecord_id, $datafield_id, $tag_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var TagHelperService $tag_helper_service */
            $tag_helper_service = $this->container->get('odr.tag_helper_service');


            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var Tags $tag */
            $tag = $em->getRepository('ODRAdminBundle:Tags')->find($tag_id);
            if ($tag == null)
                throw new ODRNotFoundException('Tag');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to select/deselect a tag for a '.$typeclass.' field');

            if ( $tag->getDataField()->getId() !== $datafield->getId() )
                throw new ODRBadRequestException('Tag does not belong to the given datafield');
            if ( $datarecord->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException('Datarecord does not belong to the given datatype');

            // Doesn't make sense for a master template to do this
            // TODO - ...same for most of the rest of the Edit page stuff?
            if ( $datatype->getIsMasterType() )
                throw new ODRBadRequestException('Unable to make selections on a Master Template');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$permissions_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }

            if ( $datafield->getPreventUserEdits() )
                throw new ODRForbiddenException("The Database's administrator has blocked changes to this Datafield.");

            // Do not make changes to the record if edits are blocked
            if ( $datarecord->getGrandparent()->getPreventUserEdits() )
                throw new ODRForbiddenException("The Database's administrator has blocked changes to this Record.");
            // --------------------


            // ----------------------------------------
            // Locate the existing datarecordfield entry, or create one if it doesn't exist
            $drf = $entity_create_service->createDatarecordField($user, $datarecord, $datafield);

            // This array entry requests that:
            // 1) if the tag selection does not exist, then create it and set to 'selected'
            // 2) if the tag selection does exist, then toggle between 'selected'/'unselected'
            $selections = array($tag->getId() => '!');

            // Perform the update
            $tag_helper_service->updateSelectedTags($user, $drf, $selections);


            // ----------------------------------------
            // Mark this datarecord as updated
            try {
                $event = new DatarecordModifiedEvent($datarecord, $user);
                $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Fire off an event notifying that the modification of the datafield is done
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

        }
        catch (\Exception $e) {
            $source = 0xb85a700b;
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
