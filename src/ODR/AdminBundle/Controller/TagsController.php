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
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\TagHelperService;
use ODR\AdminBundle\Component\Service\UUIDService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Symfony
use Doctrine\DBAL\Connection as DBALConnection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to load tags from a '.$typeclass.' field');

            // Since re-ordering tags is fine, this controller action needs to work as well
            // TODO - technically, only re-ordering tags within their "subgroup" is fine
            // TODO - unfortunately, preventing parentage changing via the js is quite difficult...
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to load tags for a derived field');

            // Do need to store whether this is derived or not so the tag modal doesn't permit
            //  users to do stuff they shouldn't
//            $is_derived_field = false;
//            if ( !is_null($datafield->getMasterDataField()) )
//                $is_derived_field = true;


            // ----------------------------------------
            // Since tag design can be modified from the edit page, and by people without the
            //  "is_datatype_admin" permission, it makes more sense for tag design to be in a modal
            // This also reduces the chances that jQuery Sortable will mess something up
            $datatype_array = $dti_service->getDatatypeArray($datatype->getGrandparent()->getId());

            $df_array = array();
            $stacked_tag_list = array();
            foreach ($datatype_array as $dt_id => $dt) {
                foreach ($dt['dataFields'] as $df_id => $df) {
                    if ($df_id === $datafield->getId() ){
                        $df_array = $df;
                        if ( isset($df['tags']) )
                            $stacked_tag_list = $df['tags'];
                        break;
                    }
                }
            }

            // Render and return the html for the list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Tags:tag_design_wrapper.html.twig',
                    array(
                        'datafield' => $df_array,
                        'stacked_tags' => $stacked_tag_list,

//                        'is_derived_field' => $is_derived_field,
                    )
                )
            );

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
            // Grab necessary objects
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
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to create a new tag for a '.$typeclass.' field');
            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to create a new tag for a derived field');


            // ----------------------------------------
            // Create a new tag
            $force_create = true;
            $tag_name = "New Tag";
            $tag = $ec_service->createTag($user, $datafield, $force_create, $tag_name);

            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() )
                $emm_service->incrementDatafieldMasterRevision($user, $datafield, true);    // don't flush immediately...

            // createTag() does not automatically flush when $force_create == true
            $em->flush();
            $em->refresh($tag);

            // If the datafield is configured to sort tags by name, then force a re-sort
            if ($datafield->getRadioOptionNameSort() == true)
                $sort_service->sortTagsByName($user, $datafield);


            // ----------------------------------------
            // Update the cached version of the datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Don't need to update cached versions of datarecords or themes

            // Do need to clear some search cache entries however
            $search_cache_service->onDatafieldModify($datafield);


            // ----------------------------------------
            // Locate the array version of the new tag
            $datatype_array = $dti_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links

            $df_array = null;
            $tag_array = null;
            foreach ($datatype_array as $dt_id => $dt) {
                foreach ($dt['dataFields'] as $df_id => $df) {
                    if ($df_id === $datafield->getId() ){
                        $df_array = $df;
                        if ( isset($df['tags']) ) {   // should always be true
                            $tag_array = $df['tags'][$tag->getId()];
                            break;
                        }
                    }
                }
            }

            if ( is_null($df_array) || is_null($tag_array) )
                throw new ODRException('Could not find newly created tag?');


            // Render the HTML for the new tag so the entire page doesn't have to reload
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Tags:tag.html.twig',
                    array(
                        'datafield' => $df_array,
                        'tag' => $tag_array,
                    )
                )
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
            if ( !isset($post['tag_list']) )
                throw new ODRBadRequestException();

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ODRTabHelperService $tab_helper_service */
            $tab_helper_service = $this->container->get('odr.tab_helper_service');
            /** @var TagHelperService $th_service */
            $th_service = $this->container->get('odr.tag_helper_service');


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

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to import tags into a '.$typeclass.' field');
            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to import tags into a derived field');

            // Makes no sense to run this if the user didn't provide any tags...
            if ( trim($post['tag_list']) === 0 )
                throw new ODRBadRequestException('Empty tag list');
            // Also, require a delimiter to be set if this field allows parent/child relationships
            if ( $datafield->getTagsAllowMultipleLevels() && !isset($post['tag_hierarchy_delimiter']) )
                throw new ODRBadRequestException('Missing tag hierarchy delimiter');


            // ----------------------------------------
            // Verify that the tag data passed in is reasonable
            $errors = array();
            $posted_tags = array();

            $lines = array();
            if ( strlen($post['tag_list']) > 0 )
                $lines = explode("\n", $post['tag_list']);

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
            $templating = $this->get('templating');
            if ( count($errors) === 0 ) {
                // ...going to need the datafield array entry for later
                $dt_array = $dti_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);
                $df_array = $dt_array[$datatype->getId()]['dataFields'][$datafield->getId()];

                // Convert any existing tags into a slightly different format
                $stacked_tag_array = $th_service->convertTagsForListImport($df_array['tags']);

                // Splice this tag into the stacked array of existing tags
                $would_create_new_tag = false;
                foreach ($posted_tags as $num => $new_tags)
                    $stacked_tag_array = $th_service->insertTagsForListImport($stacked_tag_array, $new_tags, $would_create_new_tag);

                // Ensure the tags are sorted by name
                $th_service->orderStackedTagArray($stacked_tag_array, true);


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
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
            /** @var TagHelperService $th_service */
            $th_service = $this->container->get('odr.tag_helper_service');
            /** @var UUIDService $uuid_service */
            $uuid_service = $this->container->get('odr.uuid_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $grandparent_datatype_id = $datatype->getGrandparent()->getId();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to import tags into a '.$typeclass.' field');
            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to import tags into a derived field');


            // Require this token to be set in the user's session
            $session = $request->getSession();
            if ( !$session->has('tag_import_lists') )
                throw new ODRBadRequestException('Tag import attempted without previous session');
            $tag_import_lists = $session->get('tag_import_lists');
            if ( !isset($tag_import_lists[$token]) )
                throw new ODRBadRequestException('Tag import attempted with invalid session');


            // ----------------------------------------
            // Extract the previously posted tag list out of the user's session
            $posted_tags = $tag_import_lists[$token];

            // Remove the tag list from the user's session
            unset( $tag_import_lists[$token] );
            $session->set('tag_import_lists', $tag_import_lists);


            // Going to need the hydrated versions of all tags for this datafield in order to
            //  properly create TagTree entries...
            $query = $em->createQuery(
               'SELECT t
                FROM ODRAdminBundle:Tags AS t
                WHERE t.dataField = :datafield_id
                AND t.deletedAt IS NULL'
            )->setParameters( array('datafield_id' => $datafield->getId()) );
            $results = $query->getResult();

            /** @var Tags[] $results */
            $hydrated_tag_array = array();
            foreach ($results as $tag) {
                // Have to store by tag uuid because tag names aren't guaranteed to be unique
                //  across the entire tree
                $hydrated_tag_array[ $tag->getTagUuid() ] = $tag;
            }
            /** @var Tags[] $hydrated_tag_array */


            // ----------------------------------------
            // Going to need a stacked array version of the tags to combine with the posted data
            $dt_array = $dti_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);
            $df_array = $dt_array[$datatype->getId()]['dataFields'][$datafield->getId()];
            $stacked_tag_array = $th_service->convertTagsForListImport($df_array['tags']);

            // Splice each of the posted tag trees into the existing stacked tag structure
            // Flushing is delayed until the updateDatafieldMeta() call
            foreach ($posted_tags as $num => $new_tags) {
                $stacked_tag_array = self::createTagsForListImport(
                    $em,           // Needed to persist new tag uuids and tag tree entries
                    $ec_service,   // Needed to create new tags
                    $uuid_service, // Needed to create new tags
                    $user,         // Needed to create new tags
                    $datafield,    // Needed to create new tags
                    $hydrated_tag_array,
                    $stacked_tag_array,
                    $new_tags,
                    null    // This initial call is for top-level tags...they don't have a parent
                );
            }


            // ----------------------------------------
            // Now that all the tags are created...
            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() )
                $emm_service->incrementDatafieldMasterRevision($user, $datafield, true);    // don't flush immediately...

            // createTag() does not automatically flush when $force_create == true
            $em->flush();

            // Wipe the cached tag tree arrays
            $cache_service->delete('cached_tag_tree_'.$grandparent_datatype_id);
            $cache_service->delete('cached_template_tag_tree_'.$grandparent_datatype_id);

            // If the datafield is sorting its tags by name, then all of its tags need a re-sort
            if ($datafield->getRadioOptionNameSort() == true)
                $sort_service->sortTagsByName($user, $datafield);


            // Update the cached version of the datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Also need to clear a few search cache entries
            $search_cache_service->onDatafieldModify($datafield);
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
     * TODO -
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param EntityCreationService $ec_service
     * @param UUIDService $uuid_service
     * @param ODRUser $user
     * @param DataFields $datafield
     * @param Tags[] $hydrated_tag_array A flat array of all tags for this datafield, organized by
     *                                   their uuids
     * @param array $stacked_tag_array @see self::convertTagsForListImport()
     * @param array $posted_tags A flat array of the tag(s) that may end up being inserted into the
     *                           datafield...top level tag at index 0, its child at 1, etc
     * @param Tags|null $parent_tag
     *
     * @return array
     */
    private function createTagsForListImport($em, $ec_service, $uuid_service, $user, $datafield, &$hydrated_tag_array, &$stacked_tag_array, $posted_tags, $parent_tag)
    {
        $current_tag = null;
        $tag_name = $posted_tags[0];
        if ( isset($stacked_tag_array[$tag_name]) ) {
            // This tag exists already
            $tag_uuid = $stacked_tag_array[$tag_name]['tagUuid'];
            $current_tag = $hydrated_tag_array[$tag_uuid];
        }
        else {
            // A tag with this name doesn't exist at this level...create a new tag for it
            $force_create = true;
            $delay_uuid = true;
            $current_tag = $ec_service->createTag($user, $datafield, $force_create, $tag_name, $delay_uuid);

            // Generate a new uuid for this tag...
            $new_tag_uuid = $uuid_service->generateTagUniqueId();
            $current_tag->setTagUuid($new_tag_uuid);
            $em->persist($current_tag);

            // Need to store the new stuff for later reference...
            $hydrated_tag_array[$new_tag_uuid] = $current_tag;

            $stacked_tag_array[$tag_name] = array(
                'id' => $new_tag_uuid,    // Don't really care what the ID is...only used for rendering
                'tagMeta' => array(
                    'tagName' => $tag_name
                ),
                'tagUuid' => $new_tag_uuid,
            );


            // If the parent tag isn't null, then this new tag also needs a new TagTree entry to
            //  insert it at the correct spot in the tag hierarchy
            if ( !is_null($parent_tag) ) {
                // TODO - ...createTagTree() needs a flush before, or the lock file doesn't have all the info it needs to lock properly
//                $ec_service->createTagTree($user, $parent_tag, $new_tag);

                $tag_tree = new TagTree();
                $tag_tree->setParent($parent_tag);
                $tag_tree->setChild($current_tag);

                $tag_tree->setCreatedBy($user);

                $em->persist($tag_tree);
            }
        }

        // If there are more children/grandchildren to the tag to add...
        if ( count($posted_tags) > 1 ) {
            // ...get any children the existing tag already has
            $existing_child_tags = array();
            if ( isset($stacked_tag_array[$tag_name]['children']) )
                $existing_child_tags = $stacked_tag_array[$tag_name]['children'];

            // This level has been processed, move on to its children
            $new_tags = array_slice($posted_tags, 1);
            $stacked_tag_array[$tag_name]['children'] = self::createTagsForListImport(
                $em,           // Needed to persist new tag uuids and tag tree entries
                $ec_service,   // Needed to create new tags
                $uuid_service, // Needed to create new tags
                $user,         // Needed to create new tags
                $datafield,    // Needed to create new tags
                $hydrated_tag_array,
                $existing_child_tags,
                $new_tags,
                $current_tag
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


            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchService $search_service */
            $search_service = $this->container->get('odr.search_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var TagHelperService $th_service */
            $th_service = $this->container->get('odr.tag_helper_service');


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


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to delete tags from a '.$typeclass.' field');
            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to delete tags from a derived field');


            // As nice as it would be to delete any/all tags derived from a template tag here, the
            //  template synchronization needs to tell the user what will be changed, or changes
            //  get made without the user's knowledge/consent....which is bad.


            // ----------------------------------------
            // May need to traverse the tag tree hierarchy to properly delete this tag...
            $tag_hierarchy = $th_service->getTagHierarchy($grandparent_datatype->getId());
            if ( isset($tag_hierarchy[$datatype->getId()])
                && isset($tag_hierarchy[$datatype->getId()][$datafield->getId()])
            ) {
                $tag_hierarchy = $tag_hierarchy[$datatype->getId()][$datafield->getId()];
            }

            // Use the tag hierarchy to locate all children of the tag being deleted
            $tags_to_delete = array($tag_id);

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
                $emm_service->incrementDatafieldMasterRevision($user, $datafield, true);    // don't flush immediately...

            // Mark this datatype as updated
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Delete the separately cached tag tree for this datatype's grandparent
            if ( $grandparent_datatype->getIsMasterType() ) {
                // This is a master template datatype...cross-template searches use this entry
                //  in the same way a search on a single datatype uses 'cached_tag_tree_<dt_id>"
                $cache_service->delete('cached_template_tag_tree_'.$grandparent_datatype->getId());

                // Template datatypes also have this
                $cache_service->delete('cached_tag_tree_'.$grandparent_datatype->getId());
            }
            else {
                // This is not a master template datatype, so it will only have this cache entry
                $cache_service->delete('cached_tag_tree_'.$grandparent_datatype->getId());

                // Wipe cached data for all the datatype's datarecords
                $dr_list = $search_service->getCachedSearchDatarecordList($grandparent_datatype->getId());
                foreach ($dr_list as $dr_id => $parent_dr_id) {
                    $cache_service->delete('cached_datarecord_'.$dr_id);
                    // Tags can't be in table themes, so don't need to delete those cache entries
                }

                // Doesn't make sense to delete the "cached_table_data_<dr_id>" entry...tag data
                //  isn't displayed in table themes
            }

            // Delete any cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);

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
            /** @var SearchService $search_service */
            $search_service = $this->container->get('odr.search_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');


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
                throw new ODRBadRequestException('Unable to move tags within a '.$typeclass.' field');

            // Re-ordering tags in a derived datafield is fine...
            // TODO - technically, only re-ordering tags within their "subgroup" is fine
            // TODO - unfortunately, preventing parentage changing via the js is quite difficult...
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to move tags within a derived field');

            // ...but changing parents of tags is not, because that fundamentally changes meaning
//            $is_derived_field = false;
//            if ( !is_null($datafield->getMasterDataField()) )
//                $is_derived_field = true;


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


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }
            // --------------------


            // ----------------------------------------
            // Going to need a hydrated list of tags to make any changes to their displayOrder
            $query = $em->createQuery(
               'SELECT t
                FROM ODRAdminBundle:Tags AS t
                WHERE t.dataField = :datafield_id
                AND t.deletedAt IS NULL'
            )->setParameters( array('datafield_id' => $datafield->getId()) );
            /** @var Tags[] $result */
            $result = $query->getResult();

            // Organize all the tags by their id
            /** @var Tags[] $tag_list */
            $tag_list = array();
            foreach ($result as $tag)
                $tag_list[ $tag->getId() ] = $tag;

            // Verify that each tag in $tag_ordering belongs to the datafield
            foreach ($tag_ordering as $display_order => $tag_id) {
                if ( !isset($tag_list[$tag_id]) )
                    throw new ODRBadRequestException('Tag does not belong to the given datafield');
            }


            // ----------------------------------------
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
                // The tag is no longer top-level...
                $create_new_entry = true;
                $tag_hierarchy_changed = true;
            }
            else if ( !is_null($tag_tree) && is_null($parent_tag) ) {
                // The tag is once again top-level...
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
            if ($create_new_entry) {
                $ec_service->createTagTree($user, $parent_tag, $child_tag);
            }


            // ----------------------------------------
            // If the datafield is set to automatically sort by tag name...
            $tag_sort_order_changed = null;
            if ( $datafield->getRadioOptionNameSort() ) {
                // ...then ignore whatever is in $tag_ordering and resort the entire tag list
                $tag_sort_order_changed = $sort_service->sortTagsByName($user, $datafield);
            }
            else {
                // Otherwise, modify each Tag to have the newly defined order
                $tag_sort_order_changed = false;
                foreach ($tag_ordering as $display_order => $tag_id) {
                    $tag = $tag_list[$tag_id];
                    if ($tag->getDisplayOrder() !== $display_order) {
                        $properties = array(
                            'displayOrder' => $display_order
                        );
                        $emm_service->updateTagMeta($user, $tag, $properties, true);    // don't flush immediately...
                        $tag_sort_order_changed = true;
                    }
                }

                if ($tag_sort_order_changed)
                    $em->flush();
            }


            // ----------------------------------------
            if ($tag_hierarchy_changed || $tag_sort_order_changed) {
                // Update cached version of datatype
                $dti_service->updateDatatypeCacheEntry($datatype, $user);

                // Don't need to update cached versions of datarecords or search results unless tag
                //  parentage got changed...
                if ($create_new_entry || $delete_old_entry) {
                    // Delete the separately cached tag tree for this datatype's grandparent
                    $grandparent_datatype = $datatype->getGrandparent();
                    if ($grandparent_datatype->getIsMasterType()) {
                        // This is a master template datatype...cross-template searches use this entry
                        //  in the same way a search on a single datatype uses 'cached_tag_tree_<dt_id>"
                        $cache_service->delete('cached_template_tag_tree_'.$grandparent_datatype->getId());

                        // Template datatypes also have this
                        $cache_service->delete('cached_tag_tree_'.$grandparent_datatype->getId());
                    }
                    else {
                        // This is not a master template datatype, so it will only have this cache entry
                        $cache_service->delete('cached_tag_tree_'.$grandparent_datatype->getId());

                        // All of the cached datarecord entries of this datatype have a 'child_tagSelections'
                        //  entry somewhere in them because otherwise Display mode can't handle the
                        //  "display_unselected_radio_options" config option...this entry depended on
                        //  the "cached_tag_tree_<dt_id>" entry that just got deleted, so all of the
                        //  cached datarecord entries for this datatype also need to get deleted...
                        $dr_list = $search_service->getCachedSearchDatarecordList($grandparent_datatype->getId());
                        foreach ($dr_list as $dr_id => $parent_dr_id)
                            $cache_service->delete('cached_datarecord_'.$dr_id);

                        // Doesn't make sense to delete the "cached_table_data_<dr_id>" entry...tag data
                        //  isn't displayed in table themes
                    }

                    // Also need to clear a whole pile of cached data specifically required for searching
                    $search_cache_service->onDatafieldModify($datafield);
                }
            }

            // Inform the javascript whether any changes were made
            $changes_made = $tag_hierarchy_changed || $tag_sort_order_changed;
            $return['d'] = array(
                'changes_made' => $changes_made
            );
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
            $option_name = trim( $post['tag_name'] );
            if ($option_name === '')
                throw new ODRBadRequestException("Tag Names can't be blank");

            // Grab necessary objects
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


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Tag stuff doesn't necessarily require datatype admin to modify...
            if ( $datafield->getTagsAllowNonAdminEdit() ) {
                // ...but they need to at least have edit permissions for the datafield
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to rename a tag for a '.$typeclass.' field');
            // This should not work on a datafield that is derived from a master template
            if ( !is_null($datafield->getMasterDataField()) )
                throw new ODRBadRequestException('Not allowed to rename a tag for a derived field');


            // Update the tag's name
            $properties = array(
                'tagName' => trim($post['tag_name'])
            );
            $emm_service->updateTagMeta($user, $tag, $properties);


            // Update the cached version of the datatype...
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Delete any cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);


            // Get the javascript to reload the datafield
            $return['d'] = array(
                'datafield_id' => $datafield->getId()
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

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


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
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException();
            }
            else {
                // ...but if not configured for that, then the user does need to be an admin
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();
            }
            // --------------------

            // This should only work on a Tag field
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Tag')
                throw new ODRBadRequestException('Unable to select/deselect a tag for a '.$typeclass.' field');


            // ----------------------------------------
            // Don't allow changing a selection if the tag has a child
            $query = $em->createQuery(
               'SELECT c_t
                FROM ODRAdminBundle:Tags AS p_t
                JOIN ODRAdminBundle:TagTree AS tt WITH tt.parent = p_t
                JOIN ODRAdminBundle:Tags AS c_t WITH tt.child = c_t
                WHERE p_t = :tag_id
                AND p_t.deletedAt IS NULL AND tt.deletedAt IS NULL AND c_t.deletedAt IS NULL'
            )->setParameters( array('tag_id' => $tag->getId()) );
            $results = $query->getArrayResult();

            if ( !empty($results) )
                throw new ODRBadRequestException('Not allowed to select/deselect a non-leaf tag');


            // ----------------------------------------
            // Locate the existing datarecordfield entry, or create one if it doesn't exist
            $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

            // Locate the existing TagSelection entry, or create one if it doesn't exist
            $tag_selection = $ec_service->createTagSelection($user, $tag, $drf);

            // Default to a value of 'selected' if an older TagSelection entity does not exist
            $new_value = 1;
            if ($tag_selection !== null) {
                // An older version does exist...toggle the existing value for the new value
                if ($tag_selection->getSelected() == 1)
                    $new_value = 0;
            }

            // Update the TagSelection entity to match $new_value
            $properties = array('selected' => $new_value);
            $emm_service->updateTagSelection($user, $tag_selection, $properties);


            // ----------------------------------------
            // Mark this datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);

            // Delete any cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);

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
