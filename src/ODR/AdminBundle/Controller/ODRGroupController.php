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

use ODR\AdminBundle\Exception\ODRMethodNotAllowedException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupMeta;
use ODR\AdminBundle\Entity\GroupDatafieldPermissions;
use ODR\AdminBundle\Entity\GroupDatatypePermissions;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeElement;
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
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


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

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            if ($datatype->getIsMasterType())
                throw new ODRMethodNotAllowedException('Master Templates are not allowed to have Groups');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            if ($datatype->getIsMasterType())
                throw new ODRMethodNotAllowedException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            if ($datatype->getIsMasterType())
                throw new ODRMethodNotAllowedException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                throw new ODRForbiddenException();
            // --------------------


            // Create a new group
            $pm_service->createGroup($user, $datatype);

            // Don't need to delete any cached entries since this is a new non-default group...nobody will immediately have/need membership in it
            // permissions_wrapper.html.twig will reload the list of groups automatically
        }
        catch (\Exception $e) {
            $source = 0xf78fc1d5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');

            $datatype = $group->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            if ($datatype->getIsMasterType())
                throw new ODRMethodNotAllowedException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
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


            // Delete all UserGroup entities
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:UserGroup AS ug
                SET ug.deletedAt = :now, ug.deletedBy = :user_id
                WHERE ug.group = :group_id AND ug.deletedAt IS NULL'
            )->setParameters( array('now' => new \DateTime(), 'user_id' => $user->getId(), 'group_id' => $group_id) );
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


            // Save who deleted the Group
            $group->setDeletedBy($user);
            $em->persist($group);
            $em->flush();

            // Delete the Group and its meta entry
            $em->remove($group->getGroupMeta());
            $em->remove($group);
            $em->flush();


            // ----------------------------------------
            // Delete the cached version of this group
            $cache_service->delete('group_'.$group_id.'_permissions');

            // Delete cached permisions for all users who were members of the now deleted group
            foreach ($user_list as $num => $user_id)
                $cache_service->delete('user_'.$user_id.'_permissions');
        }
        catch (\Exception $e) {
            $source = 0x8f1ef340;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');

            $datatype = $group->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            if ($datatype->getIsMasterType())
                throw new ODRMethodNotAllowedException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
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
                    parent::ODR_copyGroupMeta($em, $user, $group, $properties);

                    // TODO - Delete cached versions of group/user permissions once datarecord_restriction is added
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($group_form);
                    throw new ODRException($error_str);
                }
            }
            else {
                // GET request...load the actual ThemeMeta entity
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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

            if ($datatype->getIsMasterType())
                throw new ODRMethodNotAllowedException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                throw new ODRForbiddenException();
            // --------------------


            // Get all non-super admin users who are members of this group
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
                $roles = $result['roles'];

                // Never display a super-admin as a member of the group...it's effectively assumed they belong to all groups
                if ( !in_array('ROLE_SUPER_ADMIN', $roles) ) {
                    $user_data = $pm_service->cleanUserData($result);
                    $user_list[$user_id] = $user_data;
                }
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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

            if ($datatype->getIsMasterType())
                throw new ODRMethodNotAllowedException('Master Templates are not allowed to have Groups');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
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

                            if ( $ug['user']['enabled'] == 1 && !in_array('ROLE_SUPER_ADMIN', $ug['user']['roles']) ) {
                                $user = $pm_service->cleanUserData($ug['user']);
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Lists all groups the user belongs to, filtered by what the calling user is allowed to view.
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
            $admin_permissions = parent::getUserPermissionsArray($em, $admin_user->getId());
            $datatype_permissions = $admin_permissions['datatypes'];

            $admin_permission_count = 0;
            foreach ($datatype_permissions as $dt_id => $dt_permission) {
                if ( isset($dt_permission['dt_admin']) && $dt_permission['dt_admin'] == 1 )
                    $admin_permission_count++;
            }

//            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && $admin_permission_count == 0 )  // provide access to super admins or those with at least one 'is_datatype_admin' permission
            if ( !$admin_user->hasRole('ROLE_ADMIN') || $admin_permission_count == 0 )          // deny access if user does not have any 'is_datatype_admin' permissions, or if user is not admin/super admin
                throw new ODRForbiddenException();
            // --------------------

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ( $user == null || !$user->isEnabled() )
                throw new ODRNotFoundException('User');

            if ($user->getId() == $admin_user->getId())
                throw new ODRBadRequestException('Unable to change own group membership.');
            if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRBadRequestException('Unable to change group membership for a Super-Admin.');


            // ----------------------------------------
            // Get a listing of all top level datatypes
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
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

            // Only save datatypes that the admin user has the 'dt_admin' permission for
            $datatypes = array();
            foreach ($results as $dt_num => $dt) {
                $dt_id = $dt['id'];

                if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dt_admin']) ) {
                    $dt['dataTypeMeta'] = $dt['dataTypeMeta'][0];
                    $dt['createdBy'] = $pm_service->cleanUserData( $dt['createdBy'] );
                    $datatypes[$dt_id] = $dt;

                    // Categorize groups by the original purpose of the group if stated, or by group id if a custom group
                    unset( $datatypes[$dt_id]['groups'] );
                    foreach ($dt['groups'] as $num => $g) {
                        $group_id = $g['id'];
                        $purpose = $g['purpose'];

                        $g['createdBy'] = $pm_service->cleanUserData( $g['createdBy'] );
                        $g['groupMeta'] = $g['groupMeta'][0];

                        if ($purpose !== '')
                            $datatypes[$dt_id]['groups'][$purpose] = $g;
                        else
                            $datatypes[$dt_id]['groups'][$group_id] = $g;
                    }
                }
            }
//print '<pre>'.print_r($datatypes, true).'</pre>';  exit();

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

//print '<pre>'.print_r($user_group_list, true).'</pre>';
//print '<pre>'.print_r($user_datatype_group_membership, true).'</pre>';

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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

            if ($datatype->getIsMasterType())
                throw new ODRMethodNotAllowedException('Master Templates are not allowed to have Groups');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $admin_permissions = parent::getUserPermissionsArray($em, $admin_user->getId());
            $datatype_permissions = $admin_permissions['datatypes'];

            if ( !$admin_user->hasRole('ROLE_ADMIN') )
                throw new ODRForbiddenException();

            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ]['dt_admin'])) )
                throw new ODRForbiddenException();
            // --------------------


            if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRBadRequestException('Unable to change group membership for a Super-Admin.');
            if ($user->getId() == $admin_user->getId())
                throw new ODRBadRequestException('Unable to change own group membership.');


            // ----------------------------------------
            $value = intval($value);
            if ($value == 1) {
                // If user is supposed to be added to a default group...
                if ($group->getPurpose() !== '') {
                    // ...remove them from all other default groups for this datatype since they should only ever be a member of a single group at a time
                    $query = $em->createQuery(
                       'SELECT ug.id AS ug_id
                        FROM ODRAdminBundle:Group AS g
                        JOIN ODRAdminBundle:UserGroup AS ug WITH ug.group = g
                        WHERE ug.user = :user_id AND g.purpose != :purpose AND g.dataType = :datatype_id
                        AND ug.deletedAt IS NULL AND g.deletedAt IS NULL'
                    )->setParameters( array('user_id' => $user->getId(), 'purpose' => '', 'datatype_id' => $datatype->getId()) );
                    $results = $query->getArrayResult();

                    foreach ($results as $result) {
                        $user_group_id = $result['ug_id'];

                        /** @var UserGroup $user_group */
                        $user_group = $repo_user_group->find($user_group_id);
                        $user_group->setDeletedBy($admin_user);
                        $em->persist($user_group);
                        $em->remove($user_group);
                    }

                    $em->flush();
                }

                // Add this user to the desired group
                $pm_service->createUserGroup($user, $group, $admin_user);
            }
            else {
                // Otherwise, user is supposed to be removed from the indicated group
                /** @var UserGroup $user_group */
                $user_group = $repo_user_group->findOneBy( array('user' => $user->getId(), 'group' => $group->getId()) );
                if ($user_group == null) {
                    /* user already doesn't belong to this group, do nothing */
                }
                else {
                    // Delete the UserGroup entity so the user is no longer linked to the group
                    $user_group->setDeletedBy($admin_user);
                    $em->persist($user_group);
                    $em->remove($user_group);
                    $em->flush();
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');
//            if ($group->getPurpose() !== '')
//                throw new ODRBadRequestException('Unable to modify permissions for a default Group');

            $datatype = $group->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            if ($datatype->getIsMasterType())
                throw new ODRMethodNotAllowedException('Master Templates are not allowed to have Groups');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require admin user to have at least admin role to do this...
            if ( $user->hasRole('ROLE_ADMIN') ) {
                // Grab permissions of both target user and admin
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];

                // If requesting user isn't an admin for this datatype, don't allow them to set datafield permissions for other users
                if ( !isset($datatype_permissions[$datatype_id]) || !isset($datatype_permissions[$datatype_id]['dt_admin']) )
                    throw new ODRForbiddenException();
            }
            else {
                throw new ODRForbiddenException();
            }
            // --------------------


            // Get the html for assigning datafield permissions
            $return['d'] = array(
                'html' => self::GetDisplayData($group, $datatype_id, 'default', $datatype_id, $request)
            );
        }
        catch (\Exception $e) {
            $source = 0xf41ca927;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a child DataType div in the design.
     * @deprecated
     *
     * @param integer $group_id            The group being modified
     * @param integer $source_datatype_id  The database id of the top-level Datatype
     * @param integer $childtype_id        The database id of the child DataType that needs to be re-rendered.
     * @param Request $request
     *
     * @return Response
     */
    public function reloadchildtypeAction($group_id, $source_datatype_id, $childtype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new ODRNotImplementedException();

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                throw new ODRNotFoundException('Source Datatype');

            /** @var DataType $childtype */
            $childtype = $em->getRepository('ODRAdminBundle:DataType')->find($childtype_id);
            if ($childtype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $childtype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');
            if ($group->getPurpose() !== '')
                throw new ODRBadRequestException('Unable to modify permissions for a default Group');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require admin user to have at least admin role to do this...
            if ( $user->hasRole('ROLE_ADMIN') ) {
                // Grab permissions of both target user and admin
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];

                // If requesting user isn't an admin for this datatype, don't allow them to set datafield permissions for other users
                if ( !isset($datatype_permissions[$source_datatype_id]) || !isset($datatype_permissions[$source_datatype_id]['dt_admin']) )
                    throw new ODRForbiddenException();
            }
            else {
                throw new ODRForbiddenException();
            }
            // --------------------

            $return['d'] = array(
                'datatype_id' => $childtype_id,
                'html' => self::GetDisplayData($group, $source_datatype_id, 'child_datatype', $childtype_id, $request),
            );
        }
        catch (\Exception $e) {
            $source = 0x6995341d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a ThemeElement in the design.
     * @deprecated
     *
     * @param integer $group_id            The group being modified
     * @param integer $source_datatype_id  The database id of the top-level datatype being rendered?
     * @param integer $theme_element_id    The database id of the ThemeElement that needs to be re-rendered.
     * @param Request $request
     *
     * @return Response
     */
    public function reloadthemeelementAction($group_id, $source_datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new ODRNotImplementedException();

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

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
                throw new ODRBadRequestException("Not allowed to re-render a ThemeElement that doesn't belong to the master Theme");

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                throw new ODRNotFoundException('Group');
            if ($group->getPurpose() !== '')
                throw new ODRBadRequestException('Unable to modify permissions for a default Group');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require admin user to have at least admin role to do this...
            if ( $user->hasRole('ROLE_ADMIN') ) {
                // Grab permissions of both target user and admin
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];

                // If requesting user isn't an admin for this datatype, don't allow them to set datafield permissions for other users
                if ( !isset($datatype_permissions[$source_datatype_id]) || !isset($datatype_permissions[$source_datatype_id]['dt_admin']) )
                    throw new ODRForbiddenException();
            }
            else {
                throw new ODRForbiddenException();
            }
            // --------------------

            $datatype_id = null;
            $return['d'] = array(
                'theme_element_id' => $theme_element_id,
                'html' => self::GetDisplayData($group, $source_datatype_id, 'theme_element', $theme_element_id, $request),
            );
        }
        catch (\Exception $e) {
            $source = 0xcaf44744;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders the HTML required to display/reload a portion of the Group Permissions changer UI
     *
     * @param Group $group                 The group being modified
     * @param integer $source_datatype_id  The top-level datatype that $user is having permissions modified for
     * @param string $template_name        One of 'default', 'child_datatype', 'theme_element'
     * @param integer $target_id           If $template_name == 'default', then $target_id should be a top-level datatype id
     *                                     If $template_name == 'child_datatype', then $target_id should be a child/linked datatype id
     *                                     If $template_name == 'theme_element', then $target_id should be a theme_element id
     * @param Request $request
     *
     * @return string
     */
    private function GetDisplayData($group, $source_datatype_id, $template_name, $target_id, $request)
    {
        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_theme = $em->getRepository('ODRAdminBundle:Theme');

        /** @var CacheService $cache_service */
        $cache_service = $this->container->get('odr.cache_service');
        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');

        // Always bypass cache if in dev mode?
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;

        // Load permissions for the specified group
        $permissions = $cache_service->get('group_'.$group->getId().'_permissions');
        if ($bypass_cache || $permissions == false) {
            $permissions = parent::rebuildGroupPermissionsArray($em, $group->getId());
            $cache_service->set('group_'.$group->getId().'_permissions', $permissions);
        }
//print '<pre>'.print_r($permissions, true).'</pre>';  exit();

        $datatree_array = $dti_service->getDatatreeArray();

        $datatype_permissions = $permissions['datatypes'];
        $datafield_permissions = $permissions['datafields'];


        $prevent_all_changes = false;
        if ($group->getPurpose() !== '')
            $prevent_all_changes = true;


        // ----------------------------------------
        // Load required objects based on parameters
        /** @var DataType $datatype */
        $datatype = null;
        /** @var Theme $theme */
        $theme = null;

        /** @var DataType|null $child_datatype */
        $child_datatype = null;
        /** @var ThemeElement|null $theme_element */
        $theme_element = null;


        // Don't need to check whether these entities are deleted or not
        if ($template_name == 'default') {
            $datatype = $repo_datatype->find($target_id);
            $theme = $repo_theme->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
        }
        else if ($template_name == 'child_datatype') {
            $child_datatype = $repo_datatype->find($target_id);
            $theme = $repo_theme->findOneBy( array('dataType' => $child_datatype->getId(), 'themeType' => 'master') );

            // Need to determine the top-level datatype to be able to load all necessary data for rendering this child datatype
            if ( isset($datatree_array['descendant_of'][ $child_datatype->getId() ]) && $datatree_array['descendant_of'][ $child_datatype->getId() ] !== '' ) {
                $grandparent_datatype_id = $dti_service->getGrandparentDatatypeId($child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
            }
            else if ( !isset($datatree_array['descendant_of'][ $child_datatype->getId() ]) || $datatree_array['descendant_of'][ $child_datatype->getId() ] == '' ) {
                // Was actually a re-render request for a top-level datatype...re-rendering should still work properly if various flags are set right
                $datatype = $child_datatype;
            }
        }
        else if ($template_name == 'theme_element') {
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($target_id);
            $theme = $theme_element->getTheme();

            // This could be a theme element from a child datatype...make sure objects get set properly if it is
            $datatype = $theme->getDataType();
            if ( isset($datatree_array['descendant_of'][ $datatype->getId() ]) && $datatree_array['descendant_of'][ $datatype->getId() ] !== '' ) {
                $child_datatype = $theme->getDataType();
                $grandparent_datatype_id = $dti_service->getGrandparentDatatypeId($child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
            }
        }


        // ----------------------------------------
        // Determine which datatypes/childtypes to load from the cache
        $include_links = false;
        $associated_datatypes = $dti_service->getAssociatedDatatypes(array($datatype->getId()), $include_links);
//print '<pre>'.print_r($associated_datatypes, true).'</pre>'; exit();

        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatype_array = $dti_service->getDatatypeArray($associated_datatypes);
//print '<pre>'.print_r($datatype_array, true).'</pre>'; exit();


        // ----------------------------------------
        // No need to filter display by user permissions...the only people who can currently access this functionality already have permissions to view/edit everything


        // ----------------------------------------
        // Render the required version of the page
        $templating = $this->get('templating');

        $html = '';
        if ($template_name == 'default') {
            $html = $templating->render(
                'ODRAdminBundle:ODRGroup:permissions_ajax.html.twig',
                array(
                    'group' => $group,
                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'datatype_array' => $datatype_array,
                    'initial_datatype_id' => $source_datatype_id,
                    'theme_id' => $theme->getId(),

                    'prevent_all_changes' => $prevent_all_changes,
                )
            );
        }
        else if ($template_name == 'child_datatype') {
            // Set variables properly incase this was a theme_element for a child/linked datatype
            $target_datatype_id = $child_datatype->getId();
            $is_top_level = 1;
            if ($child_datatype->getId() !== $datatype->getId())
                $is_top_level = 0;


            // TODO - not really preventing this earlier i think...
            // If the top-level datatype id found doesn't match the original datatype id of the design page, then this is a request for a linked datatype
            $is_link = 0;
            if ($source_datatype_id != $datatype->getId()) {
                $is_top_level = 0;
                $is_link = 1;
            }

            $html = $templating->render(
                'ODRAdminBundle:ODRGroup:permissions_childtype.html.twig',
                array(
                    'group' => $group,
                    'datatype_array' => $datatype_array,
                    'target_datatype_id' => $target_datatype_id,
                    'theme_id' => $theme->getId(),

                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'is_top_level' => $is_top_level,
                    'prevent_all_changes' => $prevent_all_changes,
                )
            );
        }
        else if ($template_name == 'theme_element') {
            // Set variables properly incase this was a theme_element for a child/linked datatype
            $target_datatype_id = $datatype->getId();
            $is_top_level = 1;
            if ($child_datatype !== null) {
                $target_datatype_id = $child_datatype->getId();
                $is_top_level = 0;
            }

            // TODO - not really preventing this earlier i think...
            // If the top-level datatype id found doesn't match the original datatype id of the design page, then this is a request for a linked datatype
            $is_link = 0;
            if ($source_datatype_id != $datatype->getId())
                $is_link = 1;

            // design_fieldarea.html.twig attempts to render all theme_elements in the given theme...
            // Since this is a request to only re-render one of them, unset all theme_elements in the theme other than the one the user wants to re-render
            foreach ($datatype_array[ $target_datatype_id ]['themes'][ $theme->getId() ]['themeElements'] as $te_num => $te) {
                if ( $te['id'] != $target_id )
                    unset( $datatype_array[ $target_datatype_id ]['themes'][ $theme->getId() ]['themeElements'][$te_num] );
            }

//print '<pre>'.print_r($datatype_array, true).'</pre>'; exit();

            $html = $templating->render(
                'ODRAdminBundle:ODRGroup:permissions_fieldarea.html.twig',
                array(
                    'group' => $group,
                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'datatype_array' => $datatype_array,
                    'target_datatype_id' => $target_datatype_id,
                    'theme_id' => $theme->getId(),

                    'is_top_level' => $is_top_level,
                    'prevent_all_changes' => $prevent_all_changes,
                )
            );
        }

        return $html;
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

            if ($datatype->getIsMasterType())
                throw new ODRMethodNotAllowedException('Master Templates are not allowed to have Groups');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $admin_permissions = parent::getUserPermissionsArray($em, $admin_user->getId());
            $datatype_permissions = $admin_permissions['datatypes'];

            if ( !$admin_user->hasRole('ROLE_ADMIN') )
                throw new ODRForbiddenException();
            if ( !(isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_admin'])) )
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
                    // Due to the INSERT INTO query for datafields later on, don't continue if the group already has the "is_datatype_admin" permission
                    if ( $gdtp->getIsDatatypeAdmin() )
                        throw new ODRException('Already have the "is_datatype_admin" permission');

                    // ----------------------------------------
                    // Set all datatypes affected by this group to have the "is_datatype_admin" permission
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
                        'is_datatype_admin' => 1,
                    );

                    foreach ($results as $gdtp)
                        parent::ODR_copyGroupDatatypePermission($em, $admin_user, $gdtp, $properties);


                    // ----------------------------------------
                    // Ensure all datafields are set to can-view/can-edit to avoid edge-cases
                    $query = $em->createQuery(
                       'SELECT gdfp.id AS gdfp_id, df.id AS df_id, df.deletedAt AS df_deletedAt
                        FROM ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                        JOIN ODRAdminBundle:DataFields AS df WITH gdfp.dataField = df
                        WHERE gdfp.group = :group_id AND (gdfp.can_view_datafield = 0 OR gdfp.can_edit_datafield = 0)
                        AND gdfp.deletedAt IS NULL AND df.deletedAt IS NULL'
                    )->setParameters( array('group_id' => $group->getId()) );
                    $results = $query->getArrayResult();

                    $permission_list = array();
                    $datafield_list = array();
                    foreach ($results as $result) {
                        $permission_list[] = $result['gdfp_id'];

                        $df_deletedAt = "NULL";
                        if ( !is_null($result['df_deletedAt']) )
                            $df_deletedAt = '"'.$df_deletedAt->format('Y-m-d H:i:s').'"';
                        $datafield_list[ $result['df_id'] ] = $df_deletedAt;
                    }

                    // Delete the specified GroupDatafieldPermissions entities
                    $query = $em->createQuery(
                       'UPDATE ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                        SET gdfp.deletedAt = :now
                        WHERE gdfp.id IN (:permission_list) AND gdfp.deletedAt IS NULL'
                    )->setParameters( array('now' => new \DateTime(), 'permission_list' => $permission_list) );
                    $rows = $query->execute();

                    // Build a single INSERT INTO query to add GroupDatafieldPermissions entries for all datafields of this top-level datatype and its children
                    $query_str = '
                        INSERT INTO odr_group_datafield_permissions (
                            group_id, data_field_id,
                            can_view_datafield, can_edit_datafield,
                            created, createdBy, updated, updatedBy, deletedAt
                        )
                        VALUES ';

                    foreach ($datafield_list as $df_id => $df_deletedAt)
                        $query_str .= '("'.$group->getId().'", "'.$df_id.'", "1", "1", NOW(), "'.$admin_user->getId().'", NOW(), "'.$admin_user->getId().'", '.$df_deletedAt.'),'."\n";

                    // Get rid of the trailing comma and replace with a semicolon
                    $query_str = substr($query_str, 0, -2).';';
                    $conn = $em->getConnection();
                    $rowsAffected = $conn->executeUpdate($query_str);
                }
                else {
                    // Set all datatypes affected by this group to not have the "is_datatype_admin" permission
                    $query = $em->createQuery(
                       'SELECT gdtp
                        FROM ODRAdminBundle:GroupDatatypePermissions AS gdtp
                        WHERE gdtp.group = :group_id
                        AND gdtp.deletedAt IS NULL'
                    )->setParameters( array('group_id' => $group->getId()) );
                    $results = $query->getResult();

                    $properties['is_datatype_admin'] = 0;
                    foreach ($results as $gdtp)
                        parent::ODR_copyGroupDatatypePermission($em, $admin_user, $gdtp, $properties);
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
                    // If any permission is selected, ensure the "can_view_datatype" permission is selected as well
                    $properties['can_view_datatype'] = 1;
                }
                else if ($permission == 'dt_view' && $value == 0) {
                    // If the "can_view_datatype" permission is deselected, ensure all other permissions are deselected as well
                    // Don't need to worry about the 'is_datatype_admin' permission...if it's set to 1, then this line of code can't be reached
                    $properties = array(
                        'can_view_datatype' => 0,
                        'can_view_datarecord' => 0,
                        'can_add_datarecord' => 0,
                        'can_delete_datarecord' => 0,
                    );
                }

                // Update the database
                parent::ODR_copyGroupDatatypePermission($em, $admin_user, $gdtp, $properties);
            }


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


            // Could be quite a few changes to the cached group array...just delete it
            $cache_service->delete('group_'.$group_id.'_permissions');

            // Clear cached version of permissions for all users of this group
            // Not updating cached entry because it's a combination of all group permissions, and would take as much work to figure out what all to change as it would to just rebuild it
            foreach ($user_list as $user_id)
                $cache_service->delete('user_'.$user_id.'_permissions');

        }
        catch (\Exception $e) {
            $source = 0x87b4186b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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

            if ($datatype->getIsMasterType())
                throw new ODRMethodNotAllowedException('Master Templates are not allowed to have Groups');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $admin_permissions = parent::getUserPermissionsArray($em, $admin_user->getId());
            $datatype_permissions = $admin_permissions['datatypes'];

            if ( !$admin_user->hasRole('ROLE_ADMIN') )
                throw new ODRForbiddenException();
            if ( !(isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_admin'])) )
                throw new ODRForbiddenException();
            // --------------------


            // If the group has the "is_datatype_admin" permission, then don't allow the user to change the any datafield permissions away from can-view/can-edit
            /** @var GroupDatatypePermissions $gdtp */
            $gdtp = $gdfp->getGroup()->getGroupDatatypePermissions()->first();
            if ($gdtp->getIsDatatypeAdmin())
                throw new ODRBadRequestException('Unable to change other permissions since this group has the "is_datatype_admin" permission');


            // Doesn't make sense to say a user can't view this datafield when it's already public
            if ($datafield->isPublic() && $value == 0)
                throw new ODRBadRequestException('Groups must have the "can_view_datafield" permission for public Datafields');


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
            parent::ODR_copyGroupDatafieldPermission($em, $admin_user, $gdfp, $properties);


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

            // Immediately update group permissions with the new datatype, if a cached version of those permissions exists
            $group_permissions = $cache_service->get('group_'.$group->getId().'_permissions');
            if ($group_permissions != false) {
                if ( !isset($group_permissions['datafields'][$datatype_id]) )
                    $group_permissions['datafields'][$datatype_id] = array();

                $group_permissions['datafields'][$datatype_id][$datafield->getId()] = $cache_update;
                $cache_service->set('group_'.$group->getId().'_permissions', $group_permissions);
            }

            // Clear cached version of permissions for all users of this group
            // Not updating cached entry because it's a combination of all group permissions, and would take as much work to figure out what all to change as it would to just rebuild it
            foreach ($user_list as $user_id)
                $cache_service->delete('user_'.$user_id.'_permissions');

        }
        catch (\Exception $e) {
            $source = 0xaf7407e0;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
