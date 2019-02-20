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
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Symfony
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
    public function gettaglistAction($datafield_id, Request $request)
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
            if ($datafield->getFieldType()->getTypeClass() !== 'Tag')
                throw new ODRBadRequestException();


            // TODO - should tag design actually be on its own page?  wouldn't have to modify the master layout page for users without the "is_datatype_admin" permissions...
            $datatype_array = $dti_service->getDatatypeArray($datatype->getGrandparent()->getId());

            $stacked_tag_list = array();
            foreach ($datatype_array as $dt_id => $dt) {
                foreach ($dt['dataFields'] as $df_id => $df) {
                    if ($df_id === $datafield->getId() ){
                        $datafield = $df;
                        if ( isset($df['tags']) )
                            $stacked_tag_list = $df['tags'];
                    }
                }
            }

            // Render and return the html for the list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Tags:tag_wrapper.html.twig',
                    array(
                        'datafield' => $datafield,
                        'stacked_tags' => $stacked_tag_list,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0x3a2fe831;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
            if ($datafield->getFieldType()->getTypeClass() !== 'Tag')
                throw new ODRBadRequestException();


            // Create a new tag
            $force_create = true;
            $tag_name = "New Tag";
            $tag = $ec_service->createTag($user, $datafield, $force_create, $tag_name);

            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() ) {
                $dfm_properties['master_revision'] = $datafield->getMasterRevision() + 1;
                $emm_service->updateDatafieldMeta($user, $datafield, $dfm_properties, true);
            }

            // createTag() does not automatically flush when $force_create == true
            $em->flush();
            $em->refresh($tag);

            // If the datafield is configured to sort tags by name, then force a re-sort
            if ($datafield->getRadioOptionNameSort() == true)
                self::sortTagsByName($user, $datafield);


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
                    'ODRAdminBundle:Tags:tag_design.html.twig',
                    array(
                        'datafield' => $df_array,
                        'current_tag' => $tag_array,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xdc63b458;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
    public function importtaglistAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // TODO - figure out whether tag design is its own page or not first
            throw new ODRNotImplementedException();

            // Ensure required options exist
            $post = $request->request->all();
            if ( !isset($post['tag_list']) )
                throw new ODRBadRequestException();

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
            if ($datafield->getFieldType()->getTypeClass() !== 'Tag')
                throw new ODRBadRequestException();


            $tag_list = array();
            if ( strlen($post['tag_list']) > 0 )
                $tag_list = preg_split("/\n/", $post['tag_list']);

            // Parse and process tags
            $processed_tags = array();
            foreach ($tag_list as $tag_name) {
                // Remove whitespace
                $tag_name = trim($tag_name);

                // ensure length > 0
                if ( strlen($tag_name) < 1 )
                    continue;

                if ( !in_array($tag_name, $processed_tags) ) {
                    // Create a new Tag
                    $force_create = true;
                    $ec_service->createTag(
                        $user,
                        $datafield,
                        $force_create,
                        $tag_name
                    );

                    array_push($processed_tags, $tag_name);
                }
            }

            // Now that all the tags are created...
            // Master Template Data Fields must increment Master Revision on all change requests.
            if ( $datafield->getIsMasterField() ) {
                $dfm_properties['master_revision'] = $datafield->getMasterRevision() + 1;
                $emm_service->updateDatafieldMeta($user, $datafield, $dfm_properties, true);
            }

            // createTag() does not automatically flush when $force_create == true
            $em->flush();


            // If the datafield is sorting its tags by name, then all of its tags need a re-sort
            if ($datafield->getRadioOptionNameSort() == true)
                self::sortTagsByName($user, $datafield);


            // Update the cached version of the datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Also need to clear a few search cache entries
            $search_cache_service->onDatafieldModify($datafield);
        }
        catch (\Exception $e) {
            $source = 0x33fed4b7;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO - test this
     * Sorts this datafield's tags based on their current name.
     *
     * @param ODRUser $user
     * @param DataFields $datafield
     */
    private function sortTagsByName($user, $datafield)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var EntityMetaModifyService $emm_service */
        $emm_service = $this->container->get('odr.entity_meta_modify_service');


        // ----------------------------------------
        // Need to create a lookup of tags incase any property needs changed later...
        $query = $em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Tags AS t
            WHERE t.dataField = :datafield_id
            AND t.deletedAt IS NULL'
        )->setParameters( array('datafield_id' => $datafield->getId()) );
        /** @var Tags[] $results */
        $results = $query->getResult();

        // Organize the tags by their id...
        /** @var Tags[] $tag_list */
        $tag_list = array();
        foreach ($results as $tag)
            $tag_list[ $tag->getId() ] = $tag;


        // Also need the actual tag names to sort on
        $query = $em->createQuery(
           'SELECT t.id AS tag_id, tm.tagName, p_t.id AS parent_tag_id
            FROM ODRAdminBundle:Tags AS t
            JOIN ODRAdminBundle:TagMeta AS tm WITH tm.tag = t
            LEFT JOIN ODRAdminBundle:TagTree AS tt WITH tt.child = t
            LEFT JOIN ODRAdminBundle:Tags AS p_t WITH tt.parent = p_t
            WHERE t.dataField = :datafield_id
            AND t.deletedAt IS NULL AND tm.deletedAt IS NULL
            AND tt.deletedAt IS NULL AND p_t.deletedAt IS NULL'
        )->setParameters( array('datafield_id' => $datafield->getId()) );
        $results = $query->getArrayResult();

        $tag_groups = array();
        foreach ($results as $result) {
            $tag_id = $result['tag_id'];
            $tag_name = $result['tagName'];
            $parent_tag_id = $result['parent_tag_id'];

            if ( is_null($parent_tag_id) )
                $parent_tag_id = 0;

            // Each of the tags needs to be "grouped" by its parent
            if ( !isset($tag_groups[$parent_tag_id]) )
                $tag_groups[$parent_tag_id] = array();
            $tag_groups[$parent_tag_id][$tag_id] = $tag_name;
        }


        // ----------------------------------------
        // Each "group" of tags can then be sorted individually
        foreach ($tag_groups as $parent_tag_id => $tag_group) {
            $tmp = $tag_group;
            asort($tmp);
            $tag_groups[$parent_tag_id] = $tmp;
        }

        // Now that each "group" of tags is sorted...
        $changes_made = false;
        foreach ($tag_groups as $parent_tag_id => $tag_group) {
            $index = 0;
            foreach ($tag_group as $tag_id => $tag_name) {
                $tag = $tag_list[$tag_id];

                if ( $tag->getDisplayOrder() !== $index ) {
                    // ...update each tag's displayOrder to match the sorted list
                    $properties = array(
                        'displayOrder' => $index
                    );
                    $emm_service->updateTagMeta($user, $tag, $properties, true);    // don't flush immediately...
                    $changes_made = true;
                }

                $index++;
            }
        }

        // Flush now that all changes have been made
        if ($changes_made)
            $em->flush();
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

        try {
            // TODO - figure out how to handle deletion of non-leaf tags
            throw new ODRNotImplementedException();

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
            if ($datafield->getFieldType()->getTypeClass() !== 'Tag')
                throw new ODRBadRequestException();


            // ----------------------------------------
            // Delete all tag selection entities attached to the tag
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:TagSelection AS ts
                SET ts.deletedAt = :now
                WHERE ts.tag = :tag_id AND ts.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'tag_id' => $tag->getId()
                )
            );
            $updated = $query->execute();


            // Save who deleted this tag
            $tag->setDeletedBy($user);
            $em->persist($tag);
            $em->flush($tag);

            // Delete the tag and its current associated metadata entry
            $tag_meta = $tag->getTagMeta();
            $em->remove($tag);
            $em->remove($tag_meta);
            $em->flush();


            // ----------------------------------------
            // Mark this datatype as updated
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Ensure that the cached tag hierarchy doesn't reference this tag
            $cache_service->delete('cached_tag_tree_'.$grandparent_datatype_id);

            // Wipe cached data for all the datatype's datarecords
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :datatype_id'
            )->setParameters( array('datatype_id' => $grandparent_datatype_id) );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $cache_service->delete('cached_datarecord_'.$dr_id);
                $cache_service->delete('cached_table_data_'.$dr_id);
            }

            // Delete any cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);

        }
        catch (\Exception $e) {
            $source = 0x0f39547e;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() != null )
                throw new ODRNotFoundException('Datatype');

            // This should only work on a Tag field
            if ($datafield->getFieldType()->getTypeClass() !== 'Tag')
                throw new ODRBadRequestException();


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
            $child_tag_id = $post['child_tag_id'];
            $parent_tag_id = $post['parent_tag_id'];


            // ----------------------------------------
            // Require $child_tag_id to be a non-zero numerical value
            $pattern = '/^\d+$/';
            if ( preg_match($pattern, $child_tag_id) === false )
                throw new ODRBadRequestException();

            $child_tag_id = intval($child_tag_id);
            if ($child_tag_id === 0)
                throw new ODRBadRequestException();

            /** @var Tags $child_tag */
            $child_tag = $em->getRepository('ODRAdminBundle:Tags')->find($child_tag_id);
            if ($child_tag == null)
                throw new ODRNotFoundException('Child Tag');

            // Also require $child_tag to belong to the given datafield
            if ($child_tag->getDataField()->getId() !== $datafield->getId())
                throw new ODRBadRequestException();


            // $parent_tag_id should not exist if the datafield only permits a "flat" tag list
            $parent_tag = null;
            if ( $parent_tag_id !== '' && !$datafield->getTagsAllowMultipleLevels() )
                throw new ODRBadRequestException();

            // If $parent_tag_id exists, also require it to be non-zero
            if ($parent_tag_id !== '') {
                if ( preg_match($pattern, $parent_tag_id) === false )
                    throw new ODRBadRequestException();

                $parent_tag_id = intval($parent_tag_id);
                if ($parent_tag_id === 0)
                    throw new ODRBadRequestException();

                /** @var Tags $parent_tag */
                $parent_tag = $em->getRepository('ODRAdminBundle:Tags')->find($parent_tag_id);
                if ($parent_tag == null)
                    throw new ODRNotFoundException('Parent Tag');

                // Also require $parent_tag to belong to the given datafield
                if ($parent_tag->getDataField()->getId() !== $datafield->getId())
                    throw new ODRBadRequestException();
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
                    throw new ODRBadRequestException();
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

            $delete_old_entry = false;
            $create_new_entry = false;
            // Determine whether the database entry's listed parent tag matches the one in the post
            if ( is_null($tag_tree) && is_null($parent_tag) ) {
                // The tag was top-level before, and still is...do nothing
            }
            else if ( is_null($tag_tree) && !is_null($parent_tag) ) {
                // The tag is no longer top-level...
                $create_new_entry = true;
            }
            else if ( !is_null($tag_tree) && is_null($parent_tag) ) {
                // The tag is once again top-level...
                $delete_old_entry = true;
            }
            else if ( !is_null($tag_tree) && !is_null($parent_tag) ) {
                if ( $tag_tree->getParent()->getId() !== $parent_tag->getId() ) {
                    // The tag was moved to a different parent...
                    $delete_old_entry = true;
                    $create_new_entry = true;
                }
                else {
                    // Otherwise, the tag is still under the same parent...do nothing
                }
            }

            // Delete the old TagTree if needed...
            if ($delete_old_entry) {
                $tag_tree->setDeletedBy($user);
                $em->persist($tag_tree);
                $em->flush();

                $em->remove($tag_tree);
                $em->flush();
            }

            // Create a new TagTree if needed...
            if ($create_new_entry) {
                $ec_service->createTagTree($user, $parent_tag, $child_tag);
            }


            // ----------------------------------------
            // If the datafield is set to automatically sort by tag name...
            if ( $datafield->getRadioOptionNameSort() ) {
                // ...then ignore whatever is in $tag_ordering and resort the entire tag list
                self::sortTagsByName($user, $datafield);
            }
            else {
                // Otherwise, modify each Tag to have the newly defined order
                $changes_made = false;
                foreach ($tag_ordering as $display_order => $tag_id) {
                    $tag = $tag_list[$tag_id];
                    if ($tag->getDisplayOrder() !== $display_order) {
                        $properties = array(
                            'displayOrder' => $display_order
                        );
                        $emm_service->updateTagMeta($user, $tag, $properties, true);    // don't flush immediately...
                        $changes_made = true;
                    }
                }

                if ($changes_made)
                    $em->flush();
            }


            // ----------------------------------------
            // Update cached version of datatype
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Don't need to update cached versions of datarecords or search results unless tag
            //  parentage got changed...
            if ($create_new_entry || $delete_old_entry) {
                // Delete the separately cached tag tree for this datatype's grandparent
                $grandparent_datatype = $datatype->getGrandparent();
                $cache_service->delete('cached_tag_tree_'.$grandparent_datatype->getId());

                // All of the cached datarecord entries of this datatype have a 'child_tagSelections'
                //  entry somewhere in them because otherwise Display mode can't handle the
                //  "display_unselected_radio_options" config option...this entry depended on the
                //  "cached_tag_tree_<dt_id>" entry that just got deleted, so all of the cached
                //  datarecord entries for this datatype also need to get deleted...
                $dr_list = $search_service->getCachedSearchDatarecordList($grandparent_datatype->getId());
                foreach ($dr_list as $dr_id => $parent_dr_id)
                    $cache_service->delete('cached_datarecord_'.$dr_id);

                // Also need to clear a whole pile of cached data specifically required for searching
                $search_cache_service->onDatafieldModify($datafield);
            }


            // ----------------------------------------
            // TODO - Don't need to return anything because the page is already correct?
//            $return['d'] = array(
//                'reload_datafield' => $reload_datafield    // TODO - ???
//            );

        }
        catch (\Exception $e) {
            $source = 0xec51b8f8;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
            if ($datafield->getFieldType()->getTypeClass() !== 'Tag')
                throw new ODRBadRequestException();


            // Update the tag's name
            $properties = array(
                'tagName' => trim($post['tag_name'])
            );
            $emm_service->updateTagMeta($user, $tag, $properties);


            // Update the cached version of the datatype...
            $dti_service->updateDatatypeCacheEntry($datatype, $user);

            // Delete any cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);

        }
        catch (\Exception $e) {
            $source = 0x480125c5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
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
                throw new ODRBadRequestException();
            if ( $datarecord->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException();


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
            if ($datafield->getFieldType()->getTypeClass() !== 'Tag')
                throw new ODRBadRequestException();

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
                throw new ODRBadRequestException();


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
            // Ensure that the cached datarecord entry can properly set the "child_is_selected"
            //  property for its non-leaf tags
            $cache_service->delete('cached_tag_tree_'.$datatype->getGrandparent()->getId());

            // Mark this datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);

            // Delete any cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);

        }
        catch (\Exception $e) {
            $source = 0xb85a700b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
