<?php

/**
 * Open Data Repository Data Publisher
 * Link Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller handles everything required to link/unlink datatypes in design mode, or
 * link/unlink datarecords in edit mode.
 */

namespace ODR\AdminBundle\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneThemeService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\TableThemeHelperService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class LinkController extends ODRCustomController
{

    /**
     * Gets a list of DataTypes that could serve as linked DataTypes.
     *
     * @param integer $datatype_id      The database id of the DataType that is looking to link to another DataType...
     * @param integer $theme_element_id The database id of the ThemeElement that is/would be where the linked DataType
     *                                  rendered in this DataType...
     * @param Request $request
     *
     * @return Response
     */
    public function getlinkabledatatypesAction($datatype_id, $theme_element_id, Request $request)
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


            /** @var DataType $local_datatype */
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $local_datatype = $repo_datatype->find($datatype_id);
            if ($local_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $local_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure that this action isn't being called on a derivative theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to modify links to Datatypes outside of the master Theme');

            // Ensure there are no datafields in this theme_element
            /** @var ThemeDataField[] $theme_datafields */
            $theme_datafields = $em->getRepository('ODRAdminBundle:ThemeDataField')->findBy( array('themeElement' => $theme_element_id) );
            if ( count($theme_datafields) > 0 )
                throw new ODRBadRequestException('Unable to create a link to a remote Datatype in a ThemeElement that already has Datafields');


            // TODO - this is currently blocked...otherwise linking/unlinking a datatype would get
            // TODO -  attached to a "copy" of the theme for the linked datatype...it wouldn't get
            // TODO -  located and synchronized to any other theme
            // Ensure this isn't being called on a linked datatype
            $parent_theme_datatype_id = $theme->getParentTheme()->getDataType()->getGrandparent()->getId();
            $grandparent_datatype_id = $local_datatype->getGrandparent()->getId();
            if ($grandparent_datatype_id !== $parent_theme_datatype_id)
                throw new ODRBadRequestException('Unable to link to or unlink from a Datatype inside a Linked Datatype');


            // ----------------------------------------
            // Locate the previously linked datatype if it exists
            /** @var DataType|null $current_remote_datatype */
            $has_linked_datarecords = false;
            $current_remote_datatype = null;
            if ($theme_element->getThemeDataType()->count() > 0) {
                $current_remote_datatype = $theme_element->getThemeDataType()->first()->getDataType();  // should only ever be one theme_datatype entry

                // Determine whether any datarecords of the local datatype link to datarecords of the remote datatype
                $query = $em->createQuery(
                   'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord AS ancestor
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor.dataType = :local_datatype_id AND descendant.dataType = :remote_datatype_id
                    AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'local_datatype_id' => $local_datatype->getId(),
                        'remote_datatype_id' => $current_remote_datatype->getId()
                    )
                );
                $results = $query->getArrayResult();

                if ( count($results) > 0 )
                    $has_linked_datarecords = true;
            }


            // Going to need the id of the local datatype's grandparent datatype
            $current_datatree_array = $dti_service->getDatatreeArray();
            $grandparent_datatype_id = $local_datatype->getGrandparent()->getId();


            // ----------------------------------------
            // Grab all the ids of all datatypes currently in the database
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id, dt.is_master_type as is_master_type, dtm.publicDate AS public_date
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                WHERE dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

            $all_datatype_ids = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $is_public = true;
                if ( $result['public_date']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' )
                    $is_public = false;

                // Check if this is a Master Template.  If so, only other master templates
                // (isMasterType = 1) can be linked.  TODO - check this
                if ($local_datatype->getIsMasterType() && $result['is_master_type'] > 0) {
                    $all_datatype_ids[$dt_id] = $is_public;
                }
                else if (!$local_datatype->getIsMasterType()) {
                    $all_datatype_ids[$dt_id] = $is_public;
                }
            }

            // Ensure user can't link to a datatype they aren't able to see
            foreach ($all_datatype_ids as $dt_id => $datatype_is_public) {
                // "Manually" determining permissions on purpose
                $can_view_datatype = false;
                if ( isset($datatype_permissions[$dt_id])
                    && isset($datatype_permissions[$dt_id]['dt_view'])
                ) {
                    $can_view_datatype = true;
                }

                // If the datatype is not public and the user doesn't have view permissions,
                //  then remove it from the array
                if ( !($datatype_is_public || $can_view_datatype) )
                    unset( $all_datatype_ids[$dt_id] );
            }

            // Iterate through the remaining datatype ids...
            $linkable_datatype_ids = array();
            foreach ($all_datatype_ids as $dt_id => $datatype_is_public) {
                // Don't allow linking to child datatypes
                if ( isset($current_datatree_array['descendant_of'][ $dt_id ])
                    && $current_datatree_array['descendant_of'][ $dt_id ] !== ''
                ) {
                    continue;
                }

                // Don't allow linking to the local datatype's grandparent
                if ($dt_id == $grandparent_datatype_id)
                    continue;

                // Don't allow the local datatype to link to a remote datatype more than once
                if ( isset($current_datatree_array['linked_from'][$dt_id])
                    && in_array($local_datatype->getId(), $current_datatree_array['linked_from'][$dt_id])
                ) {
                    continue;
                }

                // Don't allow the local datatype to link to this remote datatype if it would cause
                //  recursion...for instance, if datatype_a is linked to datatype_b, don't allow
                //  datatype_b to link to datatype_a.
                // Also don't allow situatiosn like datatype_a => datatype_b,
                //  datatype_b => datatype_c, and datatype_c => datatype_a, etc
                if ( self::willDatatypeLinkRecurse($current_datatree_array, $local_datatype->getId(), $dt_id) )
                    continue;

                // Otherwise, linking to this datatype is acceptable
                $linkable_datatype_ids[] = $dt_id;
            }


            // If this theme element currently "contains" a linked datatype, ensure that the linked
            //  datatype exists in the array
            if ($current_remote_datatype !== null) {
                if ( !in_array($current_remote_datatype->getId(), $linkable_datatype_ids) )
                    $linkable_datatype_ids[] = $current_remote_datatype->getId();
            }

            // Load all datatypes which can be linked to
            $linkable_datatypes = array();
            foreach ($linkable_datatype_ids as $dt_id)
                $linkable_datatypes[] = $repo_datatype->find($dt_id);
            /** @var DataType[] $linkable_datatypes */

            // Sort the linkable datatypes list by name
            usort($linkable_datatypes, function($a, $b) {
                /** @var DataType $a */
                /** @var DataType $b */
                return strcmp($a->getShortName(), $b->getShortName());
            });


            // ----------------------------------------
            // TODO - think about this
            // Need to display a warning when the potential remote datatype doesn't have a table theme
            $datatypes_with_table_themes = array();
            foreach ($linkable_datatypes as $l_dt) {

                if ($l_dt->getSetupStep() == DataType::STATE_OPERATIONAL)
                    $datatypes_with_table_themes[ $l_dt->getId() ] = 1;

//                foreach ($l_dt->getThemes() as $t) {
                    /** @var Theme $t */
//                    if ($t->getThemeType() == 'table')
//                        $datatypes_with_table_themes[ $l_dt->getId() ] = 1;
//                }
            }
//print '<pre>'.print_r($datatypes_with_table_themes, true).'</pre>';  exit();


            // ----------------------------------------
            // Get Templating Object
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Link:link_type_dialog_form.html.twig',
                    array(
                        'local_datatype' => $local_datatype,
                        'remote_datatype' => $current_remote_datatype,
                        'theme_element' => $theme_element,
                        'linkable_datatypes' => $linkable_datatypes,

                        'has_linked_datarecords' => $has_linked_datarecords,
                        'datatypes_with_table_themes' => $datatypes_with_table_themes,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xf8083699;
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
     * Parses a $_POST request to create/delete a link from a 'local' DataType to a 'remote'
     * DataType.  If linked, DataRecords of the 'local' DataType will have the option to link to
     * DataRecords of the 'remote' DataType.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function linkdatatypeAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        $conn = null;

        try {
            // Grab the data from the POST request
            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['local_datatype_id']) || !isset($post['selected_datatype']) || !isset($post['previous_remote_datatype']) || !isset($post['theme_element_id']) )
                throw new ODRBadRequestException('Invalid Form');

            $local_datatype_id = $post['local_datatype_id'];
            $remote_datatype_id = $post['selected_datatype'];
            $previous_remote_datatype_id = $post['previous_remote_datatype'];
            $theme_element_id = $post['theme_element_id'];


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');
            /** @var CloneThemeService $clone_theme_service */
            $clone_theme_service = $this->container->get('odr.clone_theme_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            /** @var DataType $local_datatype */
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $local_datatype = $repo_datatype->find($local_datatype_id);
            if ($local_datatype == null)
                throw new ODRNotFoundException('Local Datatype');

            $grandparent_datatype_id = $local_datatype->getGrandparent()->getId();


            $remote_datatype = null;
            if ($remote_datatype_id !== '')
                $remote_datatype = $repo_datatype->find($remote_datatype_id);   // Looking to create a link
            else
                $remote_datatype = $repo_datatype->find($previous_remote_datatype_id);   // Looking to remove a link
            /** @var DataType $remote_datatype */

            if ($remote_datatype == null)
                throw new ODRNotFoundException('Remote Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be creating a link to another datatype
            if ( !$pm_service->isDatatypeAdmin($user, $local_datatype) )
                throw new ODRForbiddenException();

            // Prevent user from linking to a datatype they don't have permissions to view
            if ( !$pm_service->canViewDatatype($user, $remote_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure that this action isn't being called on a derivative theme
            if ($theme->getThemeType() !== 'master')
                throw new ODRBadRequestException('Unable to link to a remote Datatype outside of the master Theme');

            // Ensure there are no datafields in this theme_element before attempting to link to a remote datatype
            /** @var ThemeDataField[] $theme_datafields */
            $theme_datafields = $em->getRepository('ODRAdminBundle:ThemeDataField')->findBy( array('themeElement' => $theme_element_id) );
            if ( count($theme_datafields) > 0 )
                throw new ODRBadRequestException('Unable to link a remote Datatype into a ThemeElement that already has Datafields');

            // TODO - this is currently blocked...otherwise linking/unlinking a datatype would get
            // TODO -  attached to a "copy" of the theme for the linked datatype...it wouldn't get
            // TODO -  located and synchronized to any other theme
            // Ensure this isn't being called on a linked datatype
            $parent_theme_datatype_id = $theme->getParentTheme()->getDataType()->getGrandparent()->getId();
            $grandparent_datatype_id = $local_datatype->getGrandparent()->getId();
            if ($grandparent_datatype_id !== $parent_theme_datatype_id)
                throw new ODRBadRequestException('Unable to link to or unlink from a Datatype inside a Linked Datatype');


            // ----------------------------------------
            // Get the most recent version of the datatree array
            $current_datatree_array = $dti_service->getDatatreeArray();

            // Perform various checks to ensure that this link request is valid
            if ($local_datatype_id == $remote_datatype_id)
                throw new ODRBadRequestException("A Datatype can't be linked to itself");
            if ($remote_datatype_id == $previous_remote_datatype_id)
                throw new ODRBadRequestException("Already linked to this Datatype");


            if ( isset($current_datatree_array['descendant_of'][$remote_datatype_id])
                && $current_datatree_array['descendant_of'][$remote_datatype_id] !== ''
            ) {
                throw new ODRBadRequestException("Not allowed to link to child Datatypes");
            }

            if ($remote_datatype_id == $grandparent_datatype_id) {
                throw new ODRBadRequestException("A Datatype isn't allowed to link to its parent");
            }

            if ( isset($current_datatree_array['linked_from'][$remote_datatype_id])
                && in_array($local_datatype_id, $current_datatree_array['linked_from'][$remote_datatype_id])
            ) {
                throw new ODRBadRequestException("Unable to link to the same Datatype multiple times");
            }


            if ($remote_datatype_id !== '') {
                // If a link currently exists, remove it from the array for purposes of finding any recursion
                if ($previous_remote_datatype_id !== '') {
                    $key = array_search(
                        $local_datatype_id,
                        $current_datatree_array['linked_from'][$previous_remote_datatype_id]
                    );
                    unset( $current_datatree_array['linked_from'][$previous_remote_datatype_id][$key] );
                }

                // Determine whether this link would cause infinite rendering recursion
                if ( self::willDatatypeLinkRecurse($current_datatree_array, $local_datatype_id, $remote_datatype_id) )
                    throw new ODRBadRequestException('Unable to link these two datatypes...rendering would become stuck in an infinite loop');
            }


            // ----------------------------------------
            // Now that this link request is guaranteed to be valid...

            // If a previous remote dataype is specified, then it got changed to something else...
            if ($previous_remote_datatype_id !== '') {
                // Going to mass-delete a pile of stuff...wrap it in a transaction, since DQL doesn't
                //  allow multi-table updates
                $conn = $em->getConnection();
                $conn->beginTransaction();

                // Soft-delete all theme entries linking the local and the remote datatype together
                self::deleteLinkedThemes($em, $user, $local_datatype_id, $previous_remote_datatype_id);

                // Locate and delete all Datatree and LinkedDatatree entries for the previous link
                $datarecords_to_update = self::deleteDatatreeEntries($em, $user, $local_datatype_id, $previous_remote_datatype_id);

                // Mark all Datarecords that used to link to the remote datatype as updated
                self::updateDatarecordEntries($em, $user, $datarecords_to_update);

                // Done making mass updates, commit everything
                $conn->commit();


                // ----------------------------------------
                // Delete the cached version of the datatree array because a link between datatypes got deleted
                $cache_service->delete('cached_datatree_array');
                $cache_service->delete('associated_datatypes_for_'.$local_datatype->getGrandparent()->getId());

                // Mark the ancestor datatype as has having been updated
                $dti_service->updateDatatypeCacheEntry($local_datatype, $user);
                // Mark the ancestor datatype's theme as having been updated
                $theme_service->updateThemeCacheEntry($theme, $user);
                // Also delete the list of top-level themes, just incase...
                $cache_service->delete('top_level_themes');
            }


            // ----------------------------------------
            // If a new remote datatype was specified...
            $using_linked_type = 0;
            if ($remote_datatype_id !== '') {
                // ...create a link between the two datatypes
                $using_linked_type = 1;

                $is_link = true;
                $multiple_allowed = true;
                parent::ODR_addDatatree($em, $user, $local_datatype, $remote_datatype, $is_link, $multiple_allowed);

                // Locate the master theme for the remote datatype
                $source_theme = $theme_service->getDatatypeMasterTheme($remote_datatype->getId());

                // Create a copy of that theme in this theme element
                $clone_theme_service->cloneIntoThemeElement($user, $theme_element, $source_theme, $remote_datatype, 'master');


                // ----------------------------------------
                // Since a link between datatypes got created, delete the cached datatree array
                $cache_service->delete('cached_datatree_array');
                $cache_service->delete('associated_datatypes_for_'.$local_datatype->getGrandparent()->getId());

                // Mark the ancestor datatype has having been updated
                $dti_service->updateDatatypeCacheEntry($local_datatype, $user);
                // Mark the the ancestor datatype's master theme as updated
                $theme_service->updateThemeCacheEntry($theme, $user);
            }


            if ($remote_datatype_id === '')
                $remote_datatype_id = $previous_remote_datatype_id;

            // Reload the theme element
            $return['d'] = array(
                'element_id' => $theme_element->getId(),
                'using_linked_type' => $using_linked_type,
                'linked_datatype_id' => $remote_datatype_id,
            );
        }
        catch (\Exception $e) {
            // Don't commit changes if any error was encountered...
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0xa1ee8e79;
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
     * Returns whether a potential link from $local_datatype_id to $remote_datatype_id would cause
     * infinite loops in the template rendering.
     *
     * @param array $datatree_array
     * @param integer $local_datatype_id   The datatype attempting to become the "local" datatype
     * @param integer $remote_datatype_id  The datatype that could become the "remote" datatype
     *
     * @return boolean
     */
    private function willDatatypeLinkRecurse($datatree_array, $local_datatype_id, $remote_datatype_id)
    {
        // Easiest way to determine whether a link from local_datatype to remote_datatype will
        //  recurse is to see if a cycle emerges by adding said link
        $datatree_array = $datatree_array['linked_from'];

        // 1) Temporarily add a link from local_datatype to remote_datatype
        if ( !isset($datatree_array[$remote_datatype_id]) )
            $datatree_array[$remote_datatype_id] = array();
        if ( !in_array($local_datatype_id, $datatree_array[$remote_datatype_id]) )
            $datatree_array[$remote_datatype_id][] = $local_datatype_id;

        // 2) Treat the datatree array as a graph, and, starting from $remote_datatype_id...
        $is_cyclic = false;
        foreach ($datatree_array[$remote_datatype_id] as $parent_datatype_id) {
            // 3) ...run a depth-first search on the graph to see if a cycle can be located
            if ( isset($datatree_array[$parent_datatype_id]) )
                $is_cyclic = self::datatypeLinkRecursionWorker($datatree_array, $remote_datatype_id, $parent_datatype_id);

            // 4) If a cycle was found, then adding a link from $local_datatype_id to
            //     $remote_datatype_id would cause rendering recursion...therefore, do not allow
            //     this link to be created
            if ($is_cyclic)
                return true;
        }

        // Otherwise, no cycle was found...adding a link from $local_datatype_id to
        //  $remote_datatype_id will not cause rendering recursion
        return false;
    }


    /**
     * Handles the recursive depth-first search needed for self::willDatatypeLinkRecurse()
     *
     * @param array $datatree_array
     * @param integer $target_datatype_id
     * @param integer $current_datatype_id
     *
     * @return boolean
     */
    private function datatypeLinkRecursionWorker($datatree_array, $target_datatype_id, $current_datatype_id)
    {
        $is_cyclic = false;
        foreach ($datatree_array[$current_datatype_id] as $parent_datatype_id) {
            // If $target_datatype_id is in this part of the array, then there's a cycle in this
            //  graph...return true since a link will cause rendering recursion
            if ( $parent_datatype_id == $target_datatype_id )
                return true;

            // ...otherwise, continue the depth-first search
            if ( isset($datatree_array[$parent_datatype_id]) )
                $is_cyclic = self::datatypeLinkRecursionWorker($datatree_array, $target_datatype_id, $parent_datatype_id);

            // If a cycle was found, return true
            if ($is_cyclic)
                return true;
        }

        // Otherwise, no cycles found in this section of the graph
        return false;
    }


    /**
     * Locates all theme entities that link $remote_datatype_id into $local_datatype_id, and
     * deletes them.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user
     * @param int $local_datatype_id
     * @param int $remote_datatype_id
     */
    private function deleteLinkedThemes($em, $user, $local_datatype_id, $remote_datatype_id)
    {
        $ids_to_delete = array(
            'themes' => array(),
            'theme_elements' => array(),
            'theme_datafields' => array(),
            'theme_datatypes' => array(),
        );

        // Locate all ThemeElements in all Themes of $local_datatype_id that contain links to
        //  $remote_datatype_id
        $query = $em->createQuery(
           'SELECT te.id AS theme_element_id
            FROM ODRAdminBundle:ThemeDataType AS tdt
            JOIN ODRAdminBundle:ThemeElement AS te WITH tdt.themeElement = te
            JOIN ODRAdminBundle:Theme AS t WITH te.theme = t
            WHERE tdt.dataType = :remote_datatype_id AND t.dataType = :local_datatype_id
            AND tdt.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
        )->setParameters(
            array(
                'remote_datatype_id' => $remote_datatype_id,
                'local_datatype_id' => $local_datatype_id
            )
        );
        $results = $query->getArrayResult();

        // For each of those ThemeElements, build up a list of ids of various theme-related entities
        //  that need to get deleted so rendering doesn't break later on...
        foreach ($results as $result) {
            $te_id = $result['theme_element_id'];
            self::deleteLinkedTheme_worker($em, $te_id, $ids_to_delete);
        }

        // Remove any duplicates from the 'theme_elements' section of the array
        $ids_to_delete['theme_elements'] = array_unique( $ids_to_delete['theme_elements'] );

        // Find all top-level themes that the soon-to-be-deleted themes belong to
        $query = $em->createQuery(
           'SELECT parent.id AS parent_id
            FROM ODRAdminBundle:Theme AS t
            JOIN ODRAdminBundle:Theme AS parent WITH t.parentTheme = parent
            WHERE t.id IN (:theme_ids)
            AND t.deletedAt IS NULL AND parent.deletedAt IS NULL'
        )->setParameters( array('theme_ids' => $ids_to_delete['themes']) );
        $results = $query->getArrayResult();

        $top_level_themes = array();
        foreach ($results as $result)
            $top_level_themes[] = $result['parent_id'];


        // ----------------------------------------
        // There are six different entities to mark as deleted based on the prior criteria...
        // ...theme entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:Theme AS t
            SET t.deletedAt = :now, t.deletedBy = :deleted_by
            WHERE t.id IN (:theme_ids) AND t.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'deleted_by' => $user->getId(),
                'theme_ids' => $ids_to_delete['themes'],
            )
        );
        $rows = $query->execute();

        // ...theme_meta entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:ThemeMeta AS tm
            SET tm.deletedAt = :now
            WHERE tm.theme IN (:theme_ids) AND tm.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'theme_ids' => $ids_to_delete['themes'],
            )
        );
        $rows = $query->execute();

        // ...theme_element entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:ThemeElement AS te
            SET te.deletedAt = :now, te.deletedBy = :deleted_by
            WHERE te.id IN (:theme_element_ids) AND te.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'deleted_by' => $user->getId(),
                'theme_element_ids' => $ids_to_delete['theme_elements'],
            )
        );
        $rows = $query->execute();

        // ...theme_element_meta entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:ThemeElementMeta AS tem
            SET tem.deletedAt = :now
            WHERE tem.themeElement IN (:theme_element_ids) AND tem.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'theme_element_ids' => $ids_to_delete['theme_elements'],
            )
        );
        $rows = $query->execute();

        // ...theme_datafield entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:ThemeDataField AS tdf
            SET tdf.deletedAt = :now, tdf.deletedBy = :deleted_by
            WHERE tdf.id IN (:theme_datafield_ids) AND tdf.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'deleted_by' => $user->getId(),
                'theme_datafield_ids' => $ids_to_delete['theme_datafields'],
            )
        );
        $rows = $query->execute();

        // ...theme_datatype entries
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:ThemeDataType AS tdt
            SET tdt.deletedAt = :now, tdt.deletedBy = :deleted_by
            WHERE tdt.id IN (:theme_datatype_ids) AND tdt.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'deleted_by' => $user->getId(),
                'theme_datatype_ids' => $ids_to_delete['theme_datatypes'],
            )
        );
        $rows = $query->execute();


        // ----------------------------------------
        // Cache entries need to be wiped too...
        /** @var CacheService $cache_service */
        $cache_service = $this->container->get('odr.cache_service');
        foreach ($top_level_themes as $t_id)
            $cache_service->delete('cached_theme_'.$t_id);
    }


    /**
     * Recursively iterates through the given theme_element to determine the ids of all Theme
     * stuff that needs to be marked as deleted when unlinking a linked datatype.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param int $theme_element_id
     * @param array $ids_to_delete
     */
    private function deleteLinkedTheme_worker($em, $theme_element_id, &$ids_to_delete)
    {
        $query = $em->createQuery(
           'SELECT partial te.{id}, partial tdt.{id},
               partial c_t.{id}, partial c_te.{id}, partial c_tdf.{id}, partial c_tdt.{id}
            FROM ODRAdminBundle:ThemeElement AS te
            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.childTheme AS c_t
            LEFT JOIN c_t.themeElements AS c_te
            LEFT JOIN c_te.themeDataFields AS c_tdf
            LEFT JOIN c_te.themeDataType AS c_tdt
            WHERE te.id = :theme_element_id
            AND te.deletedAt IS NULL AND tdt.deletedAt IS NULL
            AND c_t.deletedAt IS NULL AND c_te.deletedAt IS NULL
            AND c_tdf.deletedAt IS NULL AND c_tdt.deletedAt IS NULL'
        )->setParameters( array('theme_element_id' => $theme_element_id) );
        $results = $query->getArrayResult();

        foreach ($results as $result) {
            $tdt_id = $result['themeDataType'][0]['id'];
            $ids_to_delete['theme_datatypes'][] = $tdt_id;

            $c_t_id = $result['themeDataType'][0]['childTheme']['id'];
            $ids_to_delete['themes'][] = $c_t_id;

            foreach ($result['themeDataType'][0]['childTheme']['themeElements'] as $te_num => $te) {
                // This will create a duplicate id in the theme_elements section if this theme_element
                //  has a theme_datatype entry...
                $te_id = $te['id'];
                $ids_to_delete['theme_elements'][] = $te_id;

                if ( isset($te['themeDataFields']) ) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        $tdf_id = $tdf['id'];
                        $ids_to_delete['theme_datafields'][] = $tdf_id;
                    }
                }

                if ( isset($te['themeDataType']) && isset($te['themeDataType'][0]) )
                    self::deleteLinkedTheme_worker($em, $te_id, $ids_to_delete);
            }
        }
    }


    /**
     * Deletes all datatree and linked_datatree entries linking the given local and remote datatypes,
     * and returns an array of datarecord ids that need updated as a result.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user
     * @param int $local_datatype_id
     * @param int $remote_datatype_id
     *
     * @return int[]
     */
    private function deleteDatatreeEntries($em, $user, $local_datatype_id, $remote_datatype_id)
    {
        // Delete the Datatree entry tying the local and the remote datatype together
        /** @var DataTree $datatree */
        $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
            array(
                'ancestor' => $local_datatype_id,
                'descendant' => $remote_datatype_id
            )
        );
        if ( !is_null($datatree) ) {
            $datatree_meta = $datatree->getDataTreeMeta();

            $datatree->setDeletedBy($user);
            $em->persist($datatree);

            $em->remove($datatree);
            $em->remove($datatree_meta);
        }


        // Locate all LinkedDatatree entries between the local and the remote datatype
        $query = $em->createQuery(
           'SELECT ancestor.id AS ancestor_id, ldt.id AS ldt_id
            FROM ODRAdminBundle:DataRecord AS ancestor
            JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
            JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
            WHERE ancestor.dataType = :ancestor_datatype AND descendant.dataType = :descendant_datatype
            AND ancestor.deletedAt IS NULL AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters(
            array(
                'ancestor_datatype' => $local_datatype_id,
                'descendant_datatype' => $remote_datatype_id
            )
        );
        $results = $query->getArrayResult();


        // Need to get two lists...one of all the LinkedDatatree entries that need deleting,
        //  and another for all of the ancestor datarecords that need updating
        $ldt_ids = array();
        $datarecord_ids = array();
        foreach ($results as $result) {
            $dr_id = $result['ancestor_id'];
            $ldt_id = $result['ldt_id'];

            $datarecord_ids[] = $dr_id;
            $ldt_ids[] = $ldt_id;
        }
        $datarecord_ids = array_unique($datarecord_ids);

        if ( count($ldt_ids) > 0 ) {
            // Perform a DQL mass update to soft-delete all the LinkedDatatree entries
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:LinkedDataTree AS ldt
                SET ldt.deletedAt = :now, ldt.deletedBy = :user_id
                WHERE ldt.id IN (:ldt_ids) AND ldt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'user_id' => $user->getId(),
                    'ldt_ids' => $ldt_ids
                )
            );
            $query->execute();
        }

        // Return a list of datarecord ids that need to get recached
        return $datarecord_ids;
    }


    /**
     * Given a list of datarecord ids compiled by self::deleteDatatreeEntries, marks all those
     * datarecord ids as updated and deletes related cache entries.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user
     * @param int[] $datarecord_ids
     */
    private function updateDatarecordEntries($em, $user, $datarecord_ids)
    {
        // Mark all datarecords that linked to a datarecord in the remote datatype as updated
        $query = $em->createQuery(
           'UPDATE ODRAdminBundle:DataRecord AS dr
            SET dr.updated = :now, dr.updatedBy = :user_id
            WHERE dr.id IN (:datarecord_ids) AND dr.deletedAt IS NULL'
        )->setParameters(
            array(
                'now' => new \DateTime(),
                'user_id' => $user->getId(),
                'datarecord_ids' => $datarecord_ids,
            )
        );
        $query->execute();

        // Locate the grandparent ids of all datarecords that got updated for cache clearing purposes
        $query = $em->createQuery(
           'SELECT grandparent.id AS grandparent_id
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
            WHERE dr.id IN (:datarecord_ids)
            AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters(
            array(
                'datarecord_ids' => $datarecord_ids
            )
        );
        $results = $query->getArrayResult();

        // Delete cache entries for all datarecords that got updated...
        $updated = array();
        $cache_service = $this->container->get('odr.cache_service');
        foreach ($results as $result) {
            $grandparent_id = $result['grandparent_id'];

            if ( !isset($updated[$grandparent_id]) ) {
                $updated[$grandparent_id] = 1;

                // ...delete the cache entry that stores what this datarecord is linked to
                $cache_service->delete('associated_datarecords_for_'.$grandparent_id);
                // ...delete its cached entry
                $cache_service->delete('cached_datarecord_'.$grandparent_id);
            }
        }
    }


    /**
     * Builds and returns a list of available 'descendant' datarecords to link to from this
     * 'ancestor' datarecord.  If such a link exists, GetDisplayData() will render a read-only
     * version of the 'remote' datarecord in a ThemeElement of the 'local' datarecord.
     *
     * @param integer $ancestor_datatype_id   The DataType that is being linked from
     * @param integer $descendant_datatype_id The DataType that is being linked to
     * @param integer $local_datarecord_id    The DataRecord being modified.
     * @param integer $search_theme_id
     * @param string $search_key              The current search on this tab
     * @param Request $request
     *
     * @return Response
     */
    public function getlinkabledatarecordsAction($ancestor_datatype_id, $descendant_datatype_id, $local_datarecord_id, $search_theme_id, $search_key, Request $request)
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var TableThemeHelperService $tth_service */
            $tth_service = $this->container->get('odr.table_theme_helper_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


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
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array(
                    'ancestor' => $ancestor_datatype->getId(),
                    'descendant' => $descendant_datatype->getId()
                )
            );
            if ($datatree == null)
                throw new ODRNotFoundException('DataTree');

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
                if ($search_theme->getDataType()->getId() !== $local_datatype->getId())
                    throw new ODRBadRequestException();
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canViewDatatype($user, $ancestor_datatype) )
                throw new ODRForbiddenException();

            // TODO - should adding a link be controlled by the "can_add_datarecord" permission?
            // TODO - should removing a link be controlled by the "can_delete_datarecord" permission?
            // TODO - ...or alternately, add a new permission specifically for linking?
            if ( !$pm_service->canEditDatarecord($user, $local_datarecord) )
                throw new ODRForbiddenException();

            if ( !$pm_service->canViewDatatype($user, $descendant_datatype) )
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

            // Ensure the remote datatype has a suitable theme...
            if ($remote_datatype->getSetupStep() != DataType::STATE_OPERATIONAL)
                throw new ODRBadRequestException('Remote Datatype does not have a suitable Theme');

            // Since the above statement didn't thrown an exception, the one below shouldn't either...
            $theme_id = $theme_service->getPreferredTheme($user, $remote_datatype->getId(), 'search_results');
            if ($theme_id == null)
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
                    WHERE ancestor = :local_datarecord AND descendant.dataType = :remote_datatype
                    AND descendant.provisioned = false
                    AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'local_datarecord' => $local_datarecord->getId(),
                        'remote_datatype' => $remote_datatype->getId()
                    )
                );
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
                    WHERE descendant = :local_datarecord AND ancestor.dataType = :remote_datatype
                    AND ancestor.provisioned = false
                    AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'local_datarecord' => $local_datarecord->getId(),
                        'remote_datatype' => $remote_datatype->getId()
                    )
                );
                $results = $query->getResult();

                foreach ($results as $num => $data) {
                    $ancestor_id = $data['ancestor_id'];
                    if ( $ancestor_id == null || trim($ancestor_id) == '' )
                        continue;

                    $linked_datarecords[ $ancestor_id ] = 1;
                }
            }

if ($debug) {
    print "\nlinked datarecords:\n";
    foreach ($linked_datarecords as $id => $value)
        print '-- '.$id."\n";
}


            // ----------------------------------------
            // Store whether the link allows multiples or not
            $datatree_array = $dti_service->getDatatreeArray();

            $allow_multiple_links = false;
            if ( isset($datatree_array['multiple_allowed'][$descendant_datatype->getId()])
                && in_array(
                    $ancestor_datatype->getId(),
                    $datatree_array['multiple_allowed'][$descendant_datatype->getId()]
                )
            ) {
                $allow_multiple_links = true;
            }

if ($debug) {
    if ($allow_multiple_links)
        print "\nallow multiple links: true\n";
    else
        print "\nallow multiple links: false\n";
}

            // ----------------------------------------
            // Determine which, if any, datarecords can't be linked to because doing so would
            //  violate the "multiple_allowed" rule
            $illegal_datarecords = array();
            if ($local_datarecord_is_ancestor) {
                /* do nothing...the "multiple_allowed" rule will be enforced elsewhere */
            }
            else if (!$allow_multiple_links) {
                // If linking from descendant side, and link is setup to only allow to linking to a
                //  single descendant, then determine which datarecords on the ancestor side
                //  already have links to datarecords on the descendant side
                $query = $em->createQuery(
                   'SELECT ancestor.id
                    FROM ODRAdminBundle:DataRecord AS descendant
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = descendant
                    JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                    WHERE descendant.dataType = :descendant_datatype AND ancestor.dataType = :ancestor_datatype
                    AND descendant.deletedAt IS NULL AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'descendant_datatype' => $descendant_datatype->getId(),
                        'ancestor_datatype' => $ancestor_datatype->getId()
                    )
                );
                $results = $query->getArrayResult();
//print_r($results);

                foreach ($results as $num => $result) {
                    $dr_id = $result['id'];
                    $illegal_datarecords[$dr_id] = 1;
                }
            }

if ($debug) {
    print "\nillegal datarecords:\n";
    foreach ($illegal_datarecords as $key => $id)
        print '-- datarecord '.$id."\n";
}
//exit();

            // ----------------------------------------
            // Convert the list of linked datarecords into a slightly different format so the datatables plugin can use it
            $datarecord_list = array();
            foreach ($linked_datarecords as $dr_id => $value)
                $datarecord_list[] = $dr_id;

            $table_html = $tth_service->getRowData($user, $datarecord_list, $remote_datatype->getId(), $theme_id);
            $table_html = json_encode($table_html);
//print_r($table_html);

            // Grab the column names for the datatables plugin
            $column_data = $tth_service->getColumnNames($user, $remote_datatype->getId(), $theme_id);
            $column_names = $column_data['column_names'];
            $num_columns = $column_data['num_columns'];

//print '<pre>'.print_r($column_data, true).'</pre>';  exit();

            // Render the dialog box for this request
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Link:link_datarecord_form.html.twig',
                    array(
                        'search_theme_id' => $search_theme_id,
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Parses a $_POST request to modify whether a 'local' datarecord is linked to a 'remote'
     * datarecord.  If such a link exists, GetDisplayData() will render a read-only version of the
     * 'remote' datarecord in a ThemeElement of the 'local' datarecord.
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

            if ( !isset($post['local_datarecord_id']) || !isset($post['ancestor_datatype_id']) || !isset($post['descendant_datatype_id']))
                throw new ODRBadRequestException();

            $local_datarecord_id = $post['local_datarecord_id'];
            $ancestor_datatype_id = $post['ancestor_datatype_id'];
            $descendant_datatype_id = $post['descendant_datatype_id'];
            $datarecords = array();
            if ( isset($post['datarecords']) ) {
                if(isset($post['post_type']) && $post['post_type'] == 'JSON') {
                    foreach($post['datarecords'] as $index => $data) {
                        $datarecords[$data] = $data;
                    }
                }
                else {
                    $datarecords = $post['datarecords'];
                }

            }

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            $can_view_ancestor_datatype = $pm_service->canViewDatatype($user, $ancestor_datatype);
            $can_view_descendant_datatype = $pm_service->canViewDatatype($user, $descendant_datatype);
            $can_view_local_datarecord = $pm_service->canViewDatarecord($user, $local_datarecord);
            $can_edit_ancestor_datarecord = $pm_service->canEditDatatype($user, $ancestor_datatype);

            // If the datatype/datarecord is not public and the user doesn't have view permissions, or the user doesn't have edit permissions...don't undertake this action
            if ( !$can_view_ancestor_datatype || !$can_view_descendant_datatype || !$can_view_local_datarecord || !$can_edit_ancestor_datarecord )
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


            if(
                !isset($post['post_action'])
                || (
                    isset($post['post_action'])
                    && $post['post_action'] != 'ADD_ONLY'
                )
            ) {
                // If this is add only we don't check and remove
                foreach ($linked_datatree as $ldt) {
                    $remote_datarecord = null;
                    if ($local_datarecord_is_ancestor)
                        $remote_datarecord = $ldt->getDescendant();
                    else
                        $remote_datarecord = $ldt->getAncestor();

                    if ($local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $descendant_datatype->getId()) {
                        // print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match descendant datatype\n";
                        continue;
                    } else if (!$local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
                        // print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match ancestor datatype\n";
                        continue;
                    }

                    // If a descendant datarecord isn't listed in $datarecords, it got unlinked
                    if (!isset($datarecords[$remote_datarecord->getId()])) {
                        // print 'removing link between ancestor datarecord '.$ldt->getAncestor()->getId().' and descendant datarecord '.$ldt->getDescendant()->getId()."\n";

                        // Mark the ancestor datarecord as updated
                        $dri_service->updateDatarecordCacheEntry($ldt->getAncestor(), $user);
                        // Since a datarecord got unlinked, rebuild the list of what the ancestor datarecord links to
                        $cache_service->delete('associated_datarecords_for_' . $ldt->getAncestor()->getGrandparent()->getId());

                        // Remove the linked_data_tree entry
                        $ldt->setDeletedBy($user);
                        $em->persist($ldt);
                        $em->flush($ldt);

                        $em->remove($ldt);
                    } else {
                        // Otherwise, a datarecord was linked and still is linked...
                        unset($datarecords[$remote_datarecord->getId()]);
                        // print 'link between local datarecord '.$local_datarecord->getId().' and remote datarecord '.$remote_datarecord->getId()." already exists\n";
                    }
                }
            }


            // Anything remaining in $datarecords is a newly linked datarecord
            foreach ($datarecords as $id => $num) {
                $remote_datarecord = $repo_datarecord->find($id);

                // Must be a valid record
                if($remote_datarecord === null)
                    throw new ODRForbiddenException();


                // Attempt to find a link between these two datarecords that was deleted at some point in the past
                $ancestor_datarecord = null;
                $descendant_datarecord = null;
                if ($local_datarecord_is_ancestor) {
                    $ancestor_datarecord = $local_datarecord;
                    $descendant_datarecord = $remote_datarecord;
                    // print 'ensuring link from local datarecord '.$local_datarecord->getId().' to remote datarecord '.$remote_datarecord->getId()."\n";
                }
                else {
                    $ancestor_datarecord = $remote_datarecord;
                    $descendant_datarecord = $local_datarecord;
                    // print 'ensuring link from remote datarecord '.$remote_datarecord->getId().' to local datarecord '.$local_datarecord->getId()."\n";
                }

                // Ensure there is a link between the two datarecords
                // This function will also take care of marking as updated and cache clearing
                parent::ODR_linkDataRecords($em, $user, $ancestor_datarecord, $descendant_datarecord);
            }

            $em->flush();

            $return['d'] = array(
                'datatype_id' => $descendant_datatype->getId(),
                'datarecord_id' => $local_datarecord->getId()
            );

            // Any cached entries that needed clearing have already been cleared
        }
        catch (\Exception $e) {
            $source = 0xdd047dcd;
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
