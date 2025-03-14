<?php

/**
 * Open Data Repository Data Publisher
 * ODRGroup Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The user controller handles creation, modification, and deletion of
 * permissions groups for datatypes, as well as changing which groups a
 * user belongs to.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupMeta;
use ODR\AdminBundle\Entity\GroupDatafieldPermissions;
use ODR\AdminBundle\Entity\GroupDatatypePermissions;
use ODR\AdminBundle\Entity\UserGroup;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Forms
use ODR\AdminBundle\Form\UpdateGroupForm;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\ODRUserGroupMangementService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Utility
use ODR\AdminBundle\Component\Utility\UserUtility;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;


class ODRGroupController extends ODRCustomController
{

    /**
     * Loads the wrapper for the group management interface
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function managegroupsAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
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

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Modification of groups should only be performed through top-level datatypes
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Group management interface only works from top-level datatypes');


            // Render and return the wrapper HTML
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRGroup:permissions_wrapper.html.twig',
                    array(
                        'datatype' => $datatype,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x4996d75a;
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
     * Lists all groups for the given datatype.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function grouplistAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
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

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Modification of groups should only be performed through top-level datatypes
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Group management interface only works from top-level datatypes');


            // Load all groups for this Datatype
            $query = $em->createQuery(
               'SELECT g, gm
                FROM ODRAdminBundle:Group AS g
                JOIN g.groupMeta AS gm
                WHERE g.dataType = :datatype_id
                AND g.deletedAt IS NULL AND gm.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId()) );
            $results = $query->getArrayResult();

            $group_list = array();
            foreach ($results as $num => $result) {
                $group_list[$num] = $result;
                $group_list[$num]['groupMeta'] = $group_list[$num]['groupMeta'][0];
            }
//print_r($group_list);  exit();

            // Render and return the wrapper HTML
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRGroup:group_list.html.twig',
                    array(
                        'datatype' => $datatype,
                        'group_list' => $group_list,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xe68cb492;
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
     * Creates a new group for the given datatype.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function addgroupAction($datatype_id, Request $request)
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

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Groups should only be attached to top-level datatypes...child datatypes inherit groups
            //  from their parent
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Unable to add a group to a child datatype');


            // Create a new group
            $entity_create_service->createGroup($user, $datatype);

            // Don't need to delete any user's cached permissions entries since this is a new
            //  non-default group...nobody immediately needs or has membership in it

            // permissions_wrapper.html.twig will reload the list of groups automatically
        }
        catch (\Exception $e) {
            $source = 0xf78fc1d5;
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
     * Deletes the specified group.
     *
     * @param integer $group_id
     * @param Request $request
     *
     * @return Response
     */
    public function deletegroupAction($group_id, Request $request)
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
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');

            $datatype = $group->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Groups should only be attached to top-level datatypes...child datatypes inherit groups
            //  from their parent
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Unable to delete a group from a child datatype');

            if ($group->getPurpose() !== '')
                throw new ODRBadRequestException('Not allowed to delete a default group');


            // Get all users that are going to be affected by this
            $query = $em->createQuery(
               'SELECT DISTINCT(u.id) AS user_id
                FROM ODROpenRepositoryUserBundle:User AS u
                JOIN ODRAdminBundle:UserGroup AS ug WITH ug.user = u
                WHERE ug.group = :group_id
                AND ug.deletedAt IS NULL'
            )->setParameters( array('group_id' => $group_id) );
            $results = $query->getArrayResult();

            $user_list = array();
            foreach ($results as $result)
                $user_list[] = $result['user_id'];

            // Don't need to do anything special for super-admins...default groups can't be deleted,
            //  and super-admins can't ever be members of a non-default group

            // Delete all UserGroup entities
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:UserGroup AS ug
                SET ug.deletedAt = :now, ug.deletedBy = :user_id
                WHERE ug.group = :group_id AND ug.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'user_id' => $user->getId(),
                    'group_id' => $group_id
                )
            );
            $rows = $query->execute();

            // Delete all GroupDatatypePermissions entities
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:GroupDatatypePermissions AS gdtp
                SET gdtp.deletedAt = :now
                WHERE gdtp.group = :group_id AND gdtp.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'group_id' => $group_id) );
            $rows = $query->execute();

            // Delete all GroupDatafieldPermissions entities
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                SET gdfp.deletedAt = :now
                WHERE gdfp.group = :group_id AND gdfp.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'group_id' => $group_id) );
            $rows = $query->execute();


            // Delete the Group and its meta entry
            $group_meta = $group->getGroupMeta();
            $group_meta->setDeletedAt(new \DateTime());
            $em->persist($group_meta);

            $group->setDeletedBy($user);
            $group->setDeletedAt(new \DateTime());
            $em->persist($group);

            $em->flush();


            // ----------------------------------------
            // Delete cached permisions for all users who were members of the now deleted group
            foreach ($user_list as $num => $user_id)
                $cache_service->delete('user_'.$user_id.'_permissions');
        }
        catch (\Exception $e) {
            $source = 0x8f1ef340;
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
     * Loads/Saves an ODR Group Properties Form.
     *
     * @param integer $group_id
     * @param Request $request
     *
     * @return Response
     */
    public function grouppropertiesAction($group_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');
            $group_meta = $group->getGroupMeta();

            $datatype = $group->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $is_super_admin = $user->hasRole('ROLE_SUPER_ADMIN');

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This shouldn't happen since $group->getDatatype() should always return a top-level
            //  datatype...but be thorough
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Invalid Group configuration');


            // Prevent users from changing this group if it's one of the default groups for the datatype
            $prevent_all_changes = true;
            if ($group->getPurpose() == '')
                $prevent_all_changes = false;


            // Populate new Group form
            $submitted_data = new GroupMeta();
            $group_form = $this->createForm(
                UpdateGroupForm::class,
                $submitted_data,
                array(
                    'is_super_admin' => $is_super_admin,
                )
            );

            $group_form->handleRequest($request);

            if ($group_form->isSubmitted()) {

                if ($prevent_all_changes)
                    $group_form->addError( new FormError('Not allowed to make changes to a default Group') );

                // Have to be a super-admin to change the datarecord restriction
                if ( !$is_super_admin )
                    $submitted_data->setDatarecordRestriction( $group_meta->getDatarecordRestriction() );

                $datarecord_restriction_changed = false;
                if ( $submitted_data->getDatarecordRestriction() !== $group_meta->getDatarecordRestriction() )
                    $datarecord_restriction_changed = true;

                if ($group_form->isValid()) {
                    // If a value in the form changed, create a new GroupMeta entity to store the change
                    // TODO - datarecord_restriction, but in a way that doesn't suck
                    $properties = array(
                        'groupName' => $submitted_data->getGroupName(),
                        'groupDescription' => $submitted_data->getGroupDescription(),
                        'datarecord_restriction' => $submitted_data->getDatarecordRestriction(),
                    );
                    $entity_modify_service->updateGroupMeta($user, $group, $properties);


                    // ----------------------------------------
                    if ( $datarecord_restriction_changed ) {
                        // Now that the database is up to date, load the list of users that have had their
                        //  permissions affected by this change
                        $query = $em->createQuery(
                           'SELECT DISTINCT(u.id) AS user_id
                            FROM ODRAdminBundle:UserGroup AS ug
                            JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                            WHERE ug.group = :group_id
                            AND ug.deletedAt IS NULL'
                        )->setParameters( array('group_id' => $group->getId()) );
                        $results = $query->getArrayResult();

                        $user_list = array();
                        foreach ($results as $result)
                            $user_list[] = $result['user_id'];

                        // Super-admins won't ever be affected by this

                        // Clear cached version of permissions for all users in this group
                        // Not updating the cache entry because it's a combination of all group permissions,
                        //  and figuring out what all to change is more work than just rebuilding it
                        foreach ($user_list as $user_id)
                            $cache_service->delete('user_'.$user_id.'_permissions');
                    }
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($group_form);
                    throw new ODRException($error_str);
                }
            }
            else {
                // GET request...load the actual GroupMeta entity
                $group_meta = $group->getGroupMeta();
                $group_form = $this->createForm(UpdateGroupForm::class, $group_meta);

                $readable_search_key = '';
                $datarecord_restriction = $group_meta->getDatarecordRestriction();
                if ( !is_null($datarecord_restriction) && $datarecord_restriction !== '' )
                    $readable_search_key = $search_key_service->getReadableSearchKey($datarecord_restriction);

                // Return the slideout html
                $return['d'] = $templating->render(
                    'ODRAdminBundle:ODRGroup:group_properties_form.html.twig',
                    array(
                        'datatype' => $datatype,
                        'group' => $group,
                        'group_form' => $group_form->createView(),

                        'prevent_all_changes' => $prevent_all_changes,
                        'is_super_admin' => $is_super_admin,
                        'readable_search_key' => $readable_search_key,
                    )
                );
                $return['prevent_all_changes'] = $prevent_all_changes;
            }

        }
        catch (\Exception $e) {
            $source = 0x1f79b99c;
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
     * Returns a list of all users in all groups of the specified datatype.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function datatypegroupmembersAction($datatype_id, Request $request)
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

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Modification of groups should only be performed through top-level datatypes
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Group management interface only works from top-level datatypes');


            // Get a list of all users for all groups of this datatype
            $query = $em->createQuery(
               'SELECT dt, g, gm, ug, u

                FROM ODRAdminBundle:DataType AS dt
                LEFT JOIN dt.groups AS g
                LEFT JOIN g.groupMeta AS gm
                LEFT JOIN g.userGroups AS ug
                LEFT JOIN ug.user AS u

                WHERE dt.id = :datatype_id
                AND dt.deletedAt IS NULL AND g.deletedAt IS NULL AND gm.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype_id) );
            $results = $query->getArrayResult();

            $group_list = array();
            foreach ($results as $result) {
                foreach ($result['groups'] as $num => $g) {
                    $group_id = $g['id'];

                    $group_list[$group_id] = $g;
                    $group_list[$group_id]['groupMeta'] = $g['groupMeta'][0];

                    $group_list[$group_id]['users'] = array();

                    if ( isset($group_list[$group_id]['userGroups']) ) {
                        foreach ($g['userGroups'] as $num => $ug) {
                            $user_id = $ug['user']['id'];

                            // Filter down the list to enabled users
                            if ( $ug['user']['enabled'] == 1 ) {
                                $user = UserUtility::cleanUserData($ug['user']);
                                $group_list[$group_id]['users'][$user_id] = $user;
                            }
                        }

                        unset($group_list[$group_id]['userGroups']);
                    }
                }
            }


            // Render and return the user list
            $return['d'] = $templating->render(
                'ODRAdminBundle:ODRGroup:user_list_datatype.html.twig',
                array(
                    'datatype' => $datatype,
                    'group_list' => $group_list,
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x1a83c7b1;
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
     * Returns a list of which groups a given user belongs to, filtered to only display the datatypes
     * that the calling user has the "is_datatype_admin" permission for.
     *
     * @param integer $user_id
     * @param Request $request
     *
     * @return Response
     */
    public function manageusergroupsAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatreeInfoService $datatree_info_service */
            $datatree_info_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // --------------------
            // Ensure calling user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $permissions_service->getDatatypePermissions($admin_user);

            // Deny access when the user isn't an admin of any datatype
            $datatypes_with_admin_permission = array();
            foreach ($datatype_permissions as $dt_id => $dt_permission) {
                if ( isset($dt_permission['dt_admin']) && $dt_permission['dt_admin'] == 1 )
                    $datatypes_with_admin_permission[$dt_id] = 1;
            }

            if ( empty($datatypes_with_admin_permission) )
                throw new ODRForbiddenException();
            // --------------------

            // Verify the target user can have their permissions modified
            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ( $user == null || !$user->isEnabled() )
                throw new ODRNotFoundException('User');

            if ( $user->getId() == $admin_user->getId() )
                throw new ODRBadRequestException('Unable to change own group membership.');
            if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRBadRequestException('Unable to change group membership for a Super-Admin.');


            // ----------------------------------------
            // Need to get all datatypes at first...
            $top_level_datatypes = $datatree_info_service->getTopLevelDatatypes();
            $query = $em->createQuery(
               'SELECT dt, dtm, g, g_cb, gm, dt_cb
                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.dataTypeMeta AS dtm
                JOIN dt.groups AS g
                JOIN dt.createdBy AS dt_cb
                JOIN g.createdBy AS g_cb
                JOIN g.groupMeta AS gm
                WHERE dt.id IN (:datatype_ids) AND dt.setup_step IN (:setup_steps)
                AND dt.metadata_for IS NULL
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND g.deletedAt IS NULL AND gm.deletedAt IS NULL
                ORDER BY dtm.shortName'
            )->setParameters(
                array(
                    'datatype_ids' => $top_level_datatypes,
                    'setup_steps' => DataType::STATE_VIEWABLE
                )
            );
            $results = $query->getArrayResult();

            // ...mostly because the user may not have admin permissions to the "template group"
            //  datatype that they do have admin permissions for
            $dt_name_lookup = array();

            $datatypes = array();
            foreach ($results as $dt_num => $dt) {
                $dt_id = $dt['id'];
                $dt_uuid = $dt['unique_id'];

                $dt['dataTypeMeta'] = $dt['dataTypeMeta'][0];
                $dt['createdBy'] = UserUtility::cleanUserData( $dt['createdBy'] );
                $datatypes[$dt_id] = $dt;

                $dt_name_lookup[$dt_uuid] = $dt['dataTypeMeta']['shortName'];

                // ...categorize the groups for this datatype by their original purpose if stated,
                //  or by group_id if they're not a default group
                unset( $datatypes[$dt_id]['groups'] );
                foreach ($dt['groups'] as $num => $g) {
                    $group_id = $g['id'];
                    $purpose = $g['purpose'];

                    $g['createdBy'] = UserUtility::cleanUserData( $g['createdBy'] );
                    $g['groupMeta'] = $g['groupMeta'][0];

                    if ($purpose !== '')
                        $datatypes[$dt_id]['groups'][$purpose] = $g;
                    else
                        $datatypes[$dt_id]['groups'][$group_id] = $g;
                }
            }

            // Only want the top-level datatypes ids where the calling user is an admin...
            foreach ($datatypes as $dt_id => $dt) {
                if ( !isset($datatypes_with_admin_permission[$dt_id]) )
                    unset( $datatypes[$dt_id] );
            }

            // Now that all the groups have been organized per datatype, split the templates from
            //  the datatypes
            $templates = array();
            foreach ($datatypes as $dt_id => $dt) {
                if ( $dt['is_master_type'] ) {
                    $templates[$dt_id] = $dt;
                    unset( $datatypes[$dt_id] );
                }
            }


            // ----------------------------------------
            // Also going to need which groups the target user is currently a member of
            /** @var UserGroup[] $user_groups */
            $user_groups = $em->getRepository('ODRAdminBundle:UserGroup')->findBy( array('user' => $user->getId()) );

            $user_group_list = array();
            foreach ($user_groups as $user_group)
                $user_group_list[ $user_group->getGroup()->getId() ] = 1;

            // Also store a quick indication of whether a user belongs to any group for this datatype
            $user_datatype_group_membership = array();
            foreach ($user_groups as $user_group)
                $user_datatype_group_membership[ $user_group->getGroup()->getDataType()->getId() ] = 1;


            // ----------------------------------------
            // Render and return the interface
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRGroup:manage_user_groups.html.twig',
                    array(
                        'target_user' => $user,

                        'dt_name_lookup' => $dt_name_lookup,
                        'datatypes' => $datatypes,
                        'templates' => $templates,

                        'user_group_list' => $user_group_list,
                        'user_datatype_group_membership' => $user_datatype_group_membership,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x09f55927;
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
     * Changes whether a given user is a member of a given group or not.
     *
     * @param integer $user_id
     * @param integer $group_id  The group that the user is being added to/removed from
     * @param integer $value     '0' for removal from a group, '1' for addition to a group
     * @param Request $request
     *
     * @return Response
     */
    public function changeusergroupAction($user_id, $group_id, $value, Request $request)
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
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ODRUserGroupMangementService $user_group_management_service */
            $user_group_management_service = $this->container->get('odr.user_group_management_service');


            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($user == null || !$user->isEnabled())
                throw new ODRNotFoundException('User');

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');

            $datatype = $group->getDataType();
            if ($datatype->getDeletedAt() !== null)
                throw new ODRNotFoundException('Datatype');

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // If requesting user isn't an admin for this datatype, don't allow them to make changes
            if ( !$permissions_service->isDatatypeAdmin($admin_user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This shouldn't happen since $group->getDatatype() should always return a top-level
            //  datatype...but be thorough
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Invalid Group configuration');

            if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRBadRequestException('Unable to change group membership for a Super-Admin.');
            if ( $user->getId() == $admin_user->getId() )
                throw new ODRBadRequestException('Unable to change own group membership.');


            // ----------------------------------------
            $value = intval($value);
            if ($value == 1) {
                // User is supposed to be added to the indicated group
                $user_group_management_service->addUserToGroup($admin_user, $user, $group);
            }
            else {
                // Otherwise, user is supposed to be removed from the indicated group
                $user_group_management_service->removeUserFromGroup($admin_user, $user, $group);
            }


            // ----------------------------------------
            // Notify the AJAX handler whether the user is still in a group for this datatype or not
            $query = $em->createQuery(
               'SELECT g.id AS group_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODRAdminBundle:Group AS g WITH ug.group = g
                WHERE ug.user = :user_id AND g.dataType = :datatype_id
                AND ug.deletedAt IS NULL AND g.deletedAt IS NULL'
            )->setParameters( array('user_id' => $user->getId(), 'datatype_id' => $datatype->getId()) );
            $results = $query->getArrayResult();

            $in_datatype_group = false;
            if ( count($results) > 0 )
                $in_datatype_group = true;

            $return['datatype_id'] = $datatype->getId();    // Usually would automatically determine this id in the AJAX handler, but doesn't want to cooperate...so doing it here
            if ($in_datatype_group)
                $return['in_datatype_group'] = 1;
            else
                $return['in_datatype_group'] = 0;


            // ----------------------------------------
            // Delete cached version of user's permissions
            $cache_service->delete('user_'.$user_id.'_permissions');
        }
        catch (\Exception $e) {
            $source = 0xbee40f81;
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
     * Renders and returns an interface for modifying permissions for a given Group.
     *
     * @param integer $group_id The database id of the Group being modified
     * @param Request $request
     *
     * @return Response
     */
    public function grouppermissionsAction($group_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->get('odr.render_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');

            // While not allowed to modify permissions for a default Group, the user should still
            //  have a way to view what the group can do...which is why this is commented out here
//            if ($group->getPurpose() !== '')
//                throw new ODRBadRequestException('Unable to modify permissions for a default Group');

            $datatype = $group->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $is_super_admin = $user->hasRole('ROLE_SUPER_ADMIN');

            // If requesting user isn't an admin for this datatype, don't allow them to make changes
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // This shouldn't happen since $group->getDatatype() should always return a top-level
            //  datatype...but be thorough
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Invalid Group configuration');

            // Prevent users from changing this group if it's one of the default groups for the datatype
            $prevent_all_changes = true;
            if ($group->getPurpose() == '')
                $prevent_all_changes = false;


            // ----------------------------------------
            // Need three blocks of HTML for group administration...
            $return['d'] = array();


            // ...first is the html for assigning datafield permissions
            $page_html = $odr_render_service->getGroupHTML($user, $group);
            $return['d']['group_content_html'] = $page_html;


            // ...second is the list of users assigned to this group
            $query = $em->createQuery(
               'SELECT u
                FROM ODROpenRepositoryUserBundle:User AS u
                JOIN ODRAdminBundle:UserGroup AS ug WITH ug.user = u
                WHERE ug.group = :group_id
                AND u.enabled = 1 AND ug.deletedAt IS NULL'
            )->setParameters( array('group_id' => $group_id) );
            $results = $query->getArrayResult();

            $user_list = array();
            foreach ($results as $result) {
                $user_id = $result['id'];

                $user_data = UserUtility::cleanUserData($result);
                $user_list[$user_id] = $user_data;
            }

            $return['d']['user_list_html'] = $templating->render(
                'ODRAdminBundle:ODRGroup:user_list.html.twig',
                array(
                    'group' => $group,
                    'user_list' => $user_list,
                )
            );


            // ...and third is the properties form for this group
            $group_meta = $group->getGroupMeta();
            $group_form = $this->createForm(
                UpdateGroupForm::class,
                $group_meta,
                array(
                    'is_super_admin' => $is_super_admin,
                )
            );

            $readable_search_key = '';
            $datarecord_restriction = $group_meta->getDatarecordRestriction();
            if ( !is_null($datarecord_restriction) && $datarecord_restriction !== '' )
                $readable_search_key = $search_key_service->getReadableSearchKey($datarecord_restriction);

            $return['d']['group_properties_html'] = $templating->render(
                'ODRAdminBundle:ODRGroup:group_properties_form.html.twig',
                array(
                    'datatype' => $datatype,
                    'group' => $group,
                    'group_form' => $group_form->createView(),

                    'prevent_all_changes' => $prevent_all_changes,
                    'is_super_admin' => $is_super_admin,
                    'readable_search_key' => $readable_search_key,
                )
            );
            $return['d']['prevent_all_changes'] = $prevent_all_changes;
        }
        catch (\Exception $e) {
            $source = 0xf41ca927;
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
     * Saves a change made to a GroupDatatypePermission object.
     *
     * @param integer $group_id     Which Group is being changed
     * @param integer $datatype_id  Which Datatype this is for
     * @param integer $value        '0' for "does not have permission", '1' for "has permission"
     * @param string $permission    'dt_view' OR 'dr_view' OR 'dr_add' OR 'dr_delete' OR 'dt_admin'
     * @param Request $request
     *
     * @return Response
     */
    public function savedatatypepermissionAction($group_id, $datatype_id, $value, $permission, Request $request)
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
            /** @var DatatreeInfoService $datatree_info_service */
            $datatree_info_service = $this->container->get('odr.datatree_info_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');
            if ($group->getPurpose() !== '')
                throw new ODRBadRequestException('Unable to change Datatype permissions for a default group');

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // If requesting user isn't an admin for this datatype, don't allow them to make changes
            if ( !$permissions_service->isDatatypeAdmin($admin_user, $group->getDataType()) )
                throw new ODRForbiddenException();
            // --------------------

            // Ensure that the given datatype and the given group are related to each other
            if ( $group->getDataType()->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Datatype is not related to Group');

            /** @var GroupDatatypePermissions $gdtp */
            $gdtp = $em->getRepository('ODRAdminBundle:GroupDatatypePermissions')->findOneBy(
                array(
                    'group' => $group->getId(),
                    'dataType' => $datatype->getId()
                )
            );
            if ($gdtp == null)
                throw new ODRNotFoundException('GroupDatatypePermissions');

            // The 'can_view_datatype' permission should remain true for a top-level datatype...
            //  there's no point to the group if not having this permission means they can't view
            //  the datatype
            $top_level_datatypes = $datatree_info_service->getTopLevelDatatypes();
            if ($permission == 'dt_view' && in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('Unable to change the "can_view_datatype" permission on a top-level datatype');
            if ($permission == 'dt_admin' && !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('Unable to change the "is_datatype_admin" permission on a child datatype');


            // If the group has the "is_datatype_admin" permission, then it must have all other
            //  permissions as well
            if ( $gdtp->getIsDatatypeAdmin() && $permission != 'dt_admin' )
                throw new ODRBadRequestException('Unable to change other permissions since this group has the "is_datatype_admin" permission');


            if ($permission == 'dt_admin') {
                if ($value == 1) {
                    // Don't continue if the group already has the "is_datatype_admin" permission
                    if ( $gdtp->getIsDatatypeAdmin() )
                        throw new ODRBadRequestException('Already have the "is_datatype_admin" permission');

                    // ----------------------------------------
                    // Ensure all datatypes affected by this group have all the permissions
                    $query = $em->createQuery(
                       'SELECT gdtp
                        FROM ODRAdminBundle:GroupDatatypePermissions AS gdtp
                        WHERE gdtp.group = :group_id
                        AND gdtp.deletedAt IS NULL'
                    )->setParameters( array('group_id' => $group->getId()) );
                    $results = $query->getResult();

                    $properties = array(
                        'can_view_datatype' => 1,
                        'can_view_datarecord' => 1,
                        'can_add_datarecord' => 1,
                        'can_delete_datarecord' => 1,
                        'can_change_public_status' => 1,
                        'can_design_datatype' => 1,    // TODO - implement this permission
                        'is_datatype_admin' => 1,
                    );

                    foreach ($results as $gdtp)
                        $entity_modify_service->updateGroupDatatypePermission($admin_user, $gdtp, $properties, true);    // Don't flush immediately


                    // ----------------------------------------
                    // Ensure all datafields are set to can-view/can-edit to avoid edge-cases
                    $query = $em->createQuery(
                       'SELECT gdfp
                        FROM ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                        JOIN ODRAdminBundle:DataFields AS df WITH gdfp.dataField = df
                        WHERE gdfp.group = :group_id
                        AND (gdfp.can_view_datafield = 0 OR gdfp.can_edit_datafield = 0)
                        AND gdfp.deletedAt IS NULL AND df.deletedAt IS NULL'
                    )->setParameters( array('group_id' => $group->getId()) );
                    $results = $query->getResult();

                    $properties = array(
                        'can_view_datafield' => 1,
                        'can_edit_datafield' => 1,
                    );

                    foreach ($results as $gdfp)
                        $entity_modify_service->updateGroupDatafieldPermission($admin_user, $gdfp, $properties, true);    // Don't flush immediately...
                    $em->flush();
                }
                else {
                    // Ensure all datatypes affected by this group do not have the
                    //  "is_datatype_admin" permission
                    $query = $em->createQuery(
                       'SELECT gdtp
                        FROM ODRAdminBundle:GroupDatatypePermissions AS gdtp
                        WHERE gdtp.group = :group_id
                        AND gdtp.deletedAt IS NULL'
                    )->setParameters( array('group_id' => $group->getId()) );
                    $results = $query->getResult();

                    $properties = array(
                        'can_design_datatype' => 0,    // TODO - implement this permission
                        'is_datatype_admin' => 0,
                    );
                    foreach ($results as $gdtp)
                        $entity_modify_service->updateGroupDatatypePermission($admin_user, $gdtp, $properties, true);    // Don't flush immediately...
                    $em->flush();
                }
            }
            else {
                // Make the requested change to this group's permissions
                $properties = array();
                switch ($permission) {
                    case 'dt_view':
                        $properties['can_view_datatype'] = $value;
                        break;
                    case 'dr_view':
                        $properties['can_view_datarecord'] = $value;
                        break;
                    case 'dr_add':
                        $properties['can_add_datarecord'] = $value;
                        break;
                    case 'dr_delete':
                        $properties['can_delete_datarecord'] = $value;
                        break;
                    case 'dr_public':
                        $properties['can_change_public_status'] = $value;
                        break;
                }

                if ($permission != 'dt_view' && $value == 1) {
                    // If any permission is selected, ensure the "can_view_datatype" permission is
                    //  selected as well
                    $properties['can_view_datatype'] = 1;
                }
                else if ($permission == 'dt_view' && $value == 0) {
                    // If the "can_view_datatype" permission is deselected, ensure all other
                    //  permissions are deselected as well...if the 'is_datatype_admin' permission
                    //  was set to 1, then this block of code can't be reached
                    $properties = array(
                        'can_view_datatype' => 0,
                        'can_view_datarecord' => 0,
                        'can_add_datarecord' => 0,
                        'can_delete_datarecord' => 0,
                        'can_change_public_status' => 0,
                    );
                }

                // Update the database
                $entity_modify_service->updateGroupDatatypePermission($admin_user, $gdtp, $properties);
            }


            // ----------------------------------------
            // Now that the database is up to date, load the list of users that have had their
            //  permissions affected by this change
            $query = $em->createQuery(
               'SELECT DISTINCT(u.id) AS user_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE ug.group = :group_id
                AND ug.deletedAt IS NULL'
            )->setParameters( array('group_id' => $group->getId()) );
            $results = $query->getArrayResult();

            $user_list = array();
            foreach ($results as $result)
                $user_list[] = $result['user_id'];

            // Super-admins won't ever be affected by this

            // Clear cached version of permissions for all users in this group
            // Not updating the cache entry because it's a combination of all group permissions,
            //  and figuring out what all to change is more work than just rebuilding it
            foreach ($user_list as $user_id)
                $cache_service->delete('user_'.$user_id.'_permissions');

        }
        catch (\Exception $e) {
            $source = 0x87b4186b;
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
     * Saves a change made to a GroupDatafieldPermission object.
     *
     * @param integer $group_id      Which Group is being changed
     * @param integer $datafield_id  Which Datafield this is for
     * @param integer $value         '2' => can view/can edit, '1' => can view/no edit, '0' => no view/no edit
     * @param Request $request
     *
     * @return Response
     */
    public function savedatafieldpermissionAction($group_id, $datafield_id, $value, Request $request)
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

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');
            if ($group->getPurpose() !== '')
                throw new ODRBadRequestException('Unable to change Datafield permissions for a default group');

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // If requesting user isn't an admin for this datatype, don't allow them to make changes
            if ( !$permissions_service->isDatatypeAdmin($admin_user, $group->getDataType()) )
                throw new ODRForbiddenException();
            // --------------------

            // Ensure that the given datatype and the given group are related to each other
            if ( $group->getDataType()->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Datatype is not related to Group');

            /** @var GroupDatafieldPermissions $gdfp */
            $gdfp = $em->getRepository('ODRAdminBundle:GroupDatafieldPermissions')->findOneBy(
                array(
                    'group' => $group->getId(),
                    'dataField' => $datafield->getId()
                )
            );
            if ($gdfp == null)
                throw new ODRNotFoundException('GroupDatafieldPermissions');

            // If the group has the "is_datatype_admin" permission, then it must have all other
            //  permissions as well
            /** @var GroupDatatypePermissions $gdtp */
            $gdtp = $em->getRepository('ODRAdminBundle:GroupDatatypePermissions')->findOneBy(
                array(
                    'group' => $group->getId(),
                    'dataType' => $datatype->getId()
                )
            );
            if ($gdtp == null)
                throw new ODRNotFoundException('GroupDatatypePermissions');
            if ($gdtp->getIsDatatypeAdmin())
                throw new ODRBadRequestException('Unable to change other permissions since this group has the "is_datatype_admin" permission');

            // Don't allow markdown datafields to be set to editable
            if ($datafield->getFieldType()->getTypeName() == 'Markdown' && $value == 2)
                throw new ODRBadRequestException('Unable to set the "can_edit_datafield" permission on a Markdown datafield');

            // Doesn't make sense to say a user can't view this datafield when it's already public
            if ($datafield->isPublic() && $value == 0)
                throw new ODRBadRequestException('Unable to remove the "can_view_datafield" permission on public Datafields');


            // Make the requested change to this group's permissions
            $properties = array();
            $cache_update = array();

            if ($value == 2) {
                $properties['can_edit_datafield'] = 1;
                $properties['can_view_datafield'] = 1;

                $cache_update = array('view' => 1, 'edit' => 1);
            }
            else if ($value == 1) {
                $properties['can_edit_datafield'] = 0;
                $properties['can_view_datafield'] = 1;

                $cache_update = array('view' => 1);
            }
            else if ($value == 0) {
                $properties['can_edit_datafield'] = 0;
                $properties['can_view_datafield'] = 0;

                /* no need to update the cache entry with this */
            }
            $entity_modify_service->updateGroupDatafieldPermission($admin_user, $gdfp, $properties);


            // ----------------------------------------
            // Load the list of users this will have been affected by this
            $query = $em->createQuery(
               'SELECT DISTINCT(u.id) AS user_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE ug.group = :group_id
                AND ug.deletedAt IS NULL'
            )->setParameters( array('group_id' => $group->getId()) );
            $results = $query->getArrayResult();

            $user_list = array();
            foreach ($results as $result)
                $user_list[] = $result['user_id'];

            // Clear cached version of permissions for all users in this group
            // Not updating cached entry because it's a combination of all group permissions, and
            //  it's easier to just rebuild the entire entry
            foreach ($user_list as $user_id)
                $cache_service->delete('user_'.$user_id.'_permissions');
        }
        catch (\Exception $e) {
            $source = 0xaf7407e0;
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
