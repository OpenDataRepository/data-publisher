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
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
// Utility
use ODR\AdminBundle\Component\Utility\UserUtility;


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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');

            // Groups should only be attached to top-level datatypes...child datatypes inherit groups
            //  from their parent
            if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
                throw new ODRBadRequestException('Child Datatypes are not allowed to have groups of their own.');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Render and return the wrapper HTML
            $templating = $this->get('templating');
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

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
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


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
            $templating = $this->get('templating');
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

            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

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
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Create a new group
            $ec_service->createGroup($user, $datatype);

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
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

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
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

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

            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

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
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Prevent users from changing this group if it's one of the default groups for the datatype
            $prevent_all_changes = true;
            if ($group->getPurpose() == '')
                $prevent_all_changes = false;


            // Populate new Group form
            $submitted_data = new GroupMeta();
            $group_form = $this->createForm(UpdateGroupForm::class, $submitted_data);

            $group_form->handleRequest($request);

            if ($group_form->isSubmitted()) {

                if ($prevent_all_changes)
                    $group_form->addError( new FormError('Not allowed to make changes to a default Group') );

                if ($group_form->isValid()) {
                    // If a value in the form changed, create a new GroupMeta entity to store the change
                    // TODO - datarecord_restriction
                    $properties = array(
                        'groupName' => $submitted_data->getGroupName(),
                        'groupDescription' => $submitted_data->getGroupDescription(),
                    );
                    $emm_service->updateGroupMeta($user, $group, $properties);

                    // TODO - Delete cached versions of group/user permissions once datarecord_restriction is added
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

                // Return the slideout html
                $templating = $this->get('templating');
                $return['d'] = $templating->render(
                    'ODRAdminBundle:ODRGroup:group_properties_form.html.twig',
                    array(
                        'datatype' => $datatype,
                        'group' => $group,
                        'group_form' => $group_form->createView(),

                        'prevent_all_changes' => $prevent_all_changes,
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
     * Renders and returns a list of all users who are members of the specified group.
     *
     * @param integer $group_id
     * @param Request $request
     *
     * @return Response
     */
    public function groupmembersAction($group_id, Request $request)
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
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Get all users who are members of this group...twig will print a blurb about super-admins
            //  being in the datatype's admin group
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


            // Render and return the list of all users that belong to this group
            $templating = $this->get('templating');
            $return['d'] = $templating->render(
                'ODRAdminBundle:ODRGroup:user_list.html.twig',
                array(
                    'group' => $group,
                    'user_list' => $user_list,
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xb66e0e95;
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

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
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


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
            $templating = $this->get('templating');
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($admin_user);

            // Deny access when the user isn't an admin of any datatype
            $datatypes_with_admin_permission = array();
            foreach ($datatype_permissions as $dt_id => $dt_permission) {
                if ( isset($dt_permission['dt_admin']) && $dt_permission['dt_admin'] == 1 )
                    $datatypes_with_admin_permission[$dt_id] = 1;
            }

            if ( empty($datatypes_with_admin_permission) )
                throw new ODRForbiddenException();
            // --------------------

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ( $user == null || !$user->isEnabled() )
                throw new ODRNotFoundException('User');

            if ( $user->getId() == $admin_user->getId() )
                throw new ODRBadRequestException('Unable to change own group membership.');
            if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRBadRequestException('Unable to change group membership for a Super-Admin.');


            // ----------------------------------------
            // Only want the top-level datatypes ids where the calling user is an admin...
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            foreach ($top_level_datatypes as $num => $dt_id) {
                if ( !isset($datatypes_with_admin_permission[$dt_id]) )
                    unset( $top_level_datatypes[$num] );
            }

            // ...so that all relevant groups for just those datatypes can be loaded
            $query = $em->createQuery(
               'SELECT dt, dtm, g, g_cb, gm, dt_cb
                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.dataTypeMeta AS dtm
                JOIN dt.groups AS g
                JOIN dt.createdBy AS dt_cb
                JOIN g.createdBy AS g_cb
                JOIN g.groupMeta AS gm
                WHERE dt.id IN (:datatype_ids) AND dt.setup_step IN (:setup_steps) AND dt.is_master_type = 0
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND g.deletedAt IS NULL AND gm.deletedAt IS NULL
                ORDER BY dtm.shortName'
            )->setParameters( array('datatype_ids' => $top_level_datatypes, 'setup_steps' => DataType::STATE_VIEWABLE) );
            $results = $query->getArrayResult();

            // For each of the datatypes that the calling user has the 'dt_admin' permission for...
            $datatypes = array();
            foreach ($results as $dt_num => $dt) {
                $dt_id = $dt['id'];

                $dt['dataTypeMeta'] = $dt['dataTypeMeta'][0];
                $dt['createdBy'] = UserUtility::cleanUserData( $dt['createdBy'] );
                $datatypes[$dt_id] = $dt;

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
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRGroup:manage_user_groups.html.twig',
                    array(
                        'target_user' => $user,

                        'datatypes' => $datatypes,
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
            $repo_user_group = $em->getRepository('ODRAdminBundle:UserGroup');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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
            if ( !$pm_service->isDatatypeAdmin($admin_user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRBadRequestException('Unable to change group membership for a Super-Admin.');
            if ( $user->getId() == $admin_user->getId() )
                throw new ODRBadRequestException('Unable to change own group membership.');


            // ----------------------------------------
            $value = intval($value);
            if ($value == 1) {
                // If user is supposed to be added to a default group...
                if ($group->getPurpose() !== '') {
                    // ...remove them from all other default groups for this datatype since users
                    //  are only supposed to be a member of a single default group per datatype
                    $query = $em->createQuery(
                       'SELECT ug
                        FROM ODRAdminBundle:Group AS g
                        JOIN ODRAdminBundle:UserGroup AS ug WITH ug.group = g
                        WHERE ug.user = :user_id AND g.purpose != :purpose AND g.dataType = :datatype_id
                        AND ug.deletedAt IS NULL AND g.deletedAt IS NULL'
                    )->setParameters( array('user_id' => $user->getId(), 'purpose' => '', 'datatype_id' => $datatype->getId()) );
                    $results = $query->getResult();

                    // Only supposed to be in a single default group, but use foreach incase the
                    //  database got messed up somehow...
                    $changes_made = false;
                    foreach ($results as $ug) {
                        /** @var UserGroup $ug */

                        // Don't remove the user from the group that they're supposed to be added to
                        if ( $ug->getGroup()->getId() !== $group->getId() ) {
                            // Can't just call $em->remove($ug)...that won't set deletedBy
                            $ug->setDeletedBy($admin_user);
                            $ug->setDeletedAt(new \DateTime());
                            $em->persist($ug);

                            $changes_made = true;
                        }
                    }

                    // Flush now that all the updates have been made
                    if ($changes_made)
                        $em->flush();

                    // Calling $em->remove($ug) on a $ug that's already soft-deleted completely
                    //  deletes the $ug out of the backend database
                }

                // Add this user to the desired group
                $ec_service->createUserGroup($user, $group, $admin_user);
            }
            else {
                // Otherwise, user is supposed to be removed from the indicated group
                /** @var UserGroup $user_group */
                $user_group = $repo_user_group->findOneBy(
                    array(
                        'user' => $user->getId(),
                        'group' => $group->getId()
                    )
                );

                if ( is_null($user_group) ) {
                    /* user already doesn't belong to this group, do nothing */
                }
                else {
                    // Delete the UserGroup entity so the user is no longer linked to the group
                    // Can't just call $em->remove($ug)...that won't set deletedBy
                    $user_group->setDeletedBy($admin_user);
                    $user_group->setDeletedAt(new \DateTime());
                    $em->persist($user_group);

                    $em->flush();

                    // Can't just setDeletedBy() then remove()...doctrine only commits the remove()
                    $em->detach($user_group);
                }
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

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

            // If requesting user isn't an admin for this datatype, don't allow them to make changes
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Get the html for assigning datafield permissions
            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->get('odr.render_service');
            $page_html = $odr_render_service->getGroupHTML($user, $group);

            $return['d'] = array(
                'html' => $page_html
            );
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

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');
            if ($group->getPurpose() !== '')
                throw new ODRBadRequestException('Unable to change Datatype permissions for a default group');

            /** @var GroupDatatypePermissions $gdtp */
            $gdtp = $em->getRepository('ODRAdminBundle:GroupDatatypePermissions')->findOneBy( array('group' => $group->getId(), 'dataType' => $datatype->getId()) );
            if ($gdtp == null)
                throw new ODRNotFoundException('Permissions Entity');

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // If requesting user isn't an admin for this datatype, don't allow them to make changes
            if ( !$pm_service->isDatatypeAdmin($admin_user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // The 'can_view_datatype' permission should remain true for a top-level datatype...there's no point to the group if not having this permission means they can't view the datatype
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ($permission == 'dt_view' && in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('Unable to change the "can_view_datatype" permission on a top-level datatype');
            if ($permission == 'dt_admin' && !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('Unable to change the "is_datatype_admin" permission on a child datatype');


            // If the group has the "is_datatype_admin" permission, then only allow the user to remove the "is_datatype_admin"
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
                        'can_design_datatype' => 1,    // TODO - implement this permission
                        'is_datatype_admin' => 1,
                    );

                    foreach ($results as $gdtp)
                        $emm_service->updateGroupDatatypePermission($admin_user, $gdtp, $properties, true);    // Don't flush immediately


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
                        $emm_service->updateGroupDatafieldPermission($admin_user, $gdfp, $properties, true);    // Don't flush immediately...
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
                        $emm_service->updateGroupDatatypePermission($admin_user, $gdtp, $properties, true);    // Don't flush immediately...
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
                    );
                }

                // Update the database
                $emm_service->updateGroupDatatypePermission($admin_user, $gdtp, $properties);
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
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');
            if ($group->getPurpose() !== '')
                throw new ODRBadRequestException('Unable to change Datafield permissions for a default group');

            /** @var GroupDatafieldPermissions $gdfp */
            $gdfp = $em->getRepository('ODRAdminBundle:GroupDatafieldPermissions')->findOneBy( array('group' => $group->getId(), 'dataField' => $datafield->getId()) );
            if ($gdfp == null)
                throw new ODRNotFoundException('Permissions Entity');

            // TODO - Was there a reason for this beyond trying to enforce that a "master template" was different than a "datatype"?
            // if ($datatype->getIsMasterType())
                // throw new ODRBadRequestException('Master Templates are not allowed to have Groups');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // If requesting user isn't an admin for this datatype, don't allow them to make changes
            if ( !$pm_service->isDatatypeAdmin($admin_user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // If the group has the "is_datatype_admin" permission, then don't allow the user to change the any datafield permissions away from can-view/can-edit
            /** @var GroupDatatypePermissions $gdtp */
            $gdtp = $gdfp->getGroup()->getGroupDatatypePermissions()->first();
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
            $emm_service->updateGroupDatafieldPermission($admin_user, $gdfp, $properties);


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
