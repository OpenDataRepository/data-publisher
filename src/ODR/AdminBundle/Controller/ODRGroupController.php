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
use ODR\AdminBundle\Entity\GroupDatafieldPermissions;
use ODR\AdminBundle\Entity\GroupDatatypePermissions;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\UserGroup;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Forms
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ODRGroupController extends ODRCustomController
{

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

    }


    /**
     * Loads the properties form for the given group.
     *
     * @param integer $group_id
     * @param Request $request
     *
     * @return Response
     */
    public function loadgroupAction($group_id, Request $request)
    {

    }


    /**
     * Saves changes made to a group properties form.
     *
     * @param integer $group_id
     * @param Request $request
     *
     * @return Response
     */
    public function savegroupAction($group_id, Request $request)
    {

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

            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && $admin_permission_count == 0 )
                return parent::permissionDeniedError();
            // --------------------

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);

            if ($user->getId() == $admin_user->getId())
                throw new \Exception('Unable to change own group membership.');
            if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                throw new \Exception('Unable to change group membership for a Super-Admin.');


            // ----------------------------------------
            // Get a listing of all top level datatypes
            $top_level_datatypes = parent::getTopLevelDatatypes();
            $query = $em->createQuery(
                'SELECT dt, dtm, g, gm, dt_cb
                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.dataTypeMeta AS dtm
                JOIN dt.groups AS g
                JOIN dt.createdBy AS dt_cb
                JOIN g.groupMeta AS gm
                WHERE dt.id IN (:datatype_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND g.deletedAt IS NULL AND gm.deletedAt IS NULL
                ORDER BY dtm.shortName'
            )->setParameters( array('datatype_ids' => $top_level_datatypes) );
            $results = $query->getArrayResult();

            // Only save datatypes that the admin user has the 'dt_admin' permission for
            $datatypes = array();
            foreach ($results as $dt_num => $dt) {
                $dt_id = $dt['id'];

                if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dt_admin']) ) {
                    $dt['dataTypeMeta'] = $dt['dataTypeMeta'][0];
                    $dt['createdBy'] = parent::cleanUserData( $dt['createdBy'] );
                    $datatypes[$dt_id] = $dt;

                    // Categorize groups by the original purpose of the group if stated, or by group id if a custom group
                    unset( $datatypes[$dt_id]['groups'] );
                    foreach ($dt['groups'] as $num => $g) {
                        $group_id = $g['id'];
                        $purpose = $g['purpose'];

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
                $user_group_list[ $user_group->getId() ] = 1;

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
                    )
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x3351756 ' . $e->getMessage();
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

            throw new \Exception('DO NOT CONTINUE');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_user_group = $em->getRepository('ODRAdminBundle:UserGroup');


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

            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && $admin_permission_count == 0 )
                return parent::permissionDeniedError();
            // --------------------


            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($user == null || !$user->isEnabled())
                return parent::deletedEntityError('User');

            if ($user->getId() == $admin_user->getId())
                throw new \Exception('Unable to change own group membership.');
            if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                throw new \Exception('Unable to change group membership for a Super-Admin.');

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                return parent::deletedEntityError('Group');

            $datatype = $group->getDataType();
            if ($datatype->getDeletedAt() !== null)
                return parent::deletedEntityError('DataType');


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
                        WHERE ug.user = :user_id AND g.purpose != "" AND g.dataType = :datatype_id
                        AND ug.deletedAt IS NULL AND g.deletedAt IS NULL'
                    )->setParameters( array('user_id' => $user->getId(), 'datatype_id' => $datatype->getId()) );
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
                parent::ODR_createUserGroup($em, $user, $group, $admin_user);
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

            // Delete cached version of user's permissions
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            $redis->del($redis_prefix.'.user_'.$user_id.'_permissions');
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x41387175 ' . $e->getMessage();
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
                return parent::deletedEntityError('Group');
//            if ($group->getPurpose() !== '')
//                throw new \Exception('Unable to modify permissions for a default Group');

            $datatype = $group->getDataType();
            if ($datatype->getDeletedAt() != null)
                return parent::deletedEntityError('Datatype');
            $datatype_id = $datatype->getId();

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');


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
                    return parent::permissionDeniedError();
            }
            else {
                return parent::permissionDeniedError();
            }
            // --------------------


            // Get the html for assigning datafield permissions
            $return['d'] = array(
                'html' => self::GetDisplayData($group, $datatype_id, 'default', $datatype_id, $request)
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x648413732 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a child DataType div in the design.
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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                return parent::deletedEntityError('Source Datatype');

            /** @var DataType $childtype */
            $childtype = $em->getRepository('ODRAdminBundle:DataType')->find($childtype_id);
            if ($childtype == null)
                return parent::deletedEntityError('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $childtype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                return parent::deletedEntityError('Group');
            if ($group->getPurpose() !== '')
                throw new \Exception('Unable to modify permissions for a default Group');


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
                    return parent::permissionDeniedError();
            }
            else {
                return parent::permissionDeniedError();
            }
            // --------------------

            $return['d'] = array(
                'datatype_id' => $childtype_id,
                'html' => self::GetDisplayData($group, $source_datatype_id, 'child_datatype', $childtype_id, $request),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x79163252' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a ThemeElement in the design.
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
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                return parent::deletedEntityError('Source Datatype');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');
            if ($theme->getThemeType() !== 'master')
                throw new \Exception("Not allowed to re-render a ThemeElement that doesn't belong to the master Theme");
            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                return parent::deletedEntityError('Datatype');

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                return parent::deletedEntityError('Group');
            if ($group->getPurpose() !== '')
                throw new \Exception('Unable to modify permissions for a default Group');


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
                    return parent::permissionDeniedError();
            }
            else {
                return parent::permissionDeniedError();
            }
            // --------------------

            $datatype_id = null;
            $return['d'] = array(
                'theme_element_id' => $theme_element_id,
                'html' => self::GetDisplayData($group, $source_datatype_id, 'theme_element', $theme_element_id, $request),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x792133260' . $e->getMessage();
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

        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        // Always bypass cache if in dev mode?
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;

        // Going to need this a lot...
        $datatree_array = parent::getDatatreeArray($em, $bypass_cache);


        // Load permissions for the specified group
        $permissions = parent::getRedisData(($redis->get($redis_prefix.'.group_'.$group->getId().'_permissions')));
        if ( $bypass_cache || $permissions == false ) {
            $permissions = parent::rebuildGroupPermissionsArray($em, $group->getId());
            $redis->set($redis_prefix.'.group_'.$group->getId().'_permissions', gzcompress(serialize($permissions)));
        }
//print '<pre>'.print_r($permissions, true).'</pre>';  exit();

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
                $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype->getId());

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
                $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
            }
        }


        // ----------------------------------------
        // Determine which datatypes/childtypes to load from the cache
        $include_links = false;
        $associated_datatypes = parent::getAssociatedDatatypes($em, array($datatype->getId()), $include_links);

//print '<pre>'.print_r($associated_datatypes, true).'</pre>'; exit();

        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            $datatype_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$dt_id)));
            if ($bypass_cache || $datatype_data == null)
                $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }

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
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                return parent::deletedEntityError('Group');
            if ($group->getPurpose() !== '')
                throw new \Exception('Unable to change Datatype permissions for a default group');

            /** @var GroupDatatypePermissions $gdtp */
            $gdtp = $em->getRepository('ODRAdminBundle:GroupDatatypePermissions')->findOneBy( array('group' => $group->getId(), 'dataType' => $datatype->getId()) );
            if ($gdtp == null)
                return parent::deletedEntityError('Permissions Entity');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $admin_permissions = parent::getUserPermissionsArray($em, $admin_user->getId());
            $datatype_permissions = $admin_permissions['datatypes'];

            if ( !$admin_user->hasRole('ROLE_ADMIN') )
                return parent::permissionDeniedError();
            if ( !(isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_admin'])) )
                return parent::permissionDeniedError();
            // --------------------


            // The 'can_view_datatype' permission should remain true for a top-level datatype...there's no point to the group if not having this permission means they can't view the datatype
            $top_level_datatypes = parent::getTopLevelDatatypes();
            if ($permission == 'dt_view' && in_array($datatype_id, $top_level_datatypes) )
                throw new \Exception('Unable to change the "can_view_datatype" permission on a top-level datatype');


            // Make the requested change to this group's permissions
            $properties = array();
            $cache_update = array();

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
                case 'dt_admin':
                    $properties['is_datatype_admin'] = $value;
                    break;
            }

            if ($permission == 'dt_admin' && $value == 1) {
                // If the group received the 'is_datatype_admin' permission, then make sure all other permissions are also active
                $properties = array(
                    'can_view_datatype' => 1,
                    'can_view_datarecord' => 1,
                    'can_add_datarecord' => 1,
                    'can_delete_datarecord' => 1,
                    'is_datatype_admin' => 1,
                );

                // TODO - Set all datafields of this datatype to can-view/can-edit?  Not doing it at the moment because...lazy?
            }
            else if ($value == 1) {
                // If some other permission got received, then ensure the group has the "can_view_datatype" permission as well
                $properties['can_view_datatype'] = 1;
            }
            else if ($permission == 'dt_view' && $value == 0) {
                // If the 'can_view_datatype' permission got removed, then remove all other permissions as well?
                $properties = array(
                    'can_view_datatype' => 0,
                    'can_view_datarecord' => 0,
                    'can_add_datarecord' => 0,
                    'can_delete_datarecord' => 0,
                    'is_datatype_admin' => 0,
                );

                // TODO - Set all datafields of this datatype to no-view/no-edit?  Not doing it at the moment because lack of 'dt_view' should theoretically prevent editing as well...
            }

            parent::ODR_copyGroupDatatypePermission($em, $admin_user, $gdtp, $properties);


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
            $group_permissions = self::getRedisData(($redis->get($redis_prefix.'.group_'.$group->getId().'_permissions')));
            if ($group_permissions != false) {
                $group_permissions['datatypes'][$datatype->getId()] = $cache_update;
                $redis->set($redis_prefix.'.group_'.$group->getId().'_permissions', gzcompress(serialize($group_permissions)));
            }

            // Clear cached version of permissions for all users of this group
            // Not updating cached entry because it's a combination of all group permissions, and would take as much work to figure out what all to change as it would to just rebuild it
            foreach ($user_list as $user_id)
                $redis->del($redis_prefix.'.user_'.$user_id.'_permissions');

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x88817522 ' . $e->getMessage();
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
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                return parent::deletedEntityError('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                return parent::deletedEntityError('Datatype');
            $datatype_id = $datatype->getId();

            /** @var Group $group */
            $group = $em->getRepository('ODRAdminBundle:Group')->find($group_id);
            if ($group == null)
                return parent::deletedEntityError('Group');
            if ($group->getPurpose() !== '')
                throw new \Exception('Unable to change Datafield permissions for a default group');

            /** @var GroupDatafieldPermissions $gdfp */
            $gdfp = $em->getRepository('ODRAdminBundle:GroupDatafieldPermissions')->findOneBy( array('group' => $group->getId(), 'dataField' => $datafield->getId()) );
            if ($gdfp == null)
                return parent::deletedEntityError('Permissions Entity');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $admin_permissions = parent::getUserPermissionsArray($em, $admin_user->getId());
            $datatype_permissions = $admin_permissions['datatypes'];

            if ( !$admin_user->hasRole('ROLE_ADMIN') )
                return parent::permissionDeniedError();
            if ( !(isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_admin'])) )
                return parent::permissionDeniedError();
            // --------------------


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
            $group_permissions = self::getRedisData(($redis->get($redis_prefix.'.group_'.$group->getId().'_permissions')));
            if ($group_permissions != false) {
                if ( !isset($group_permissions['datafields'][$datatype_id]) )
                    $group_permissions['datafields'][$datatype_id] = array();

                $group_permissions['datafields'][$datatype_id][$datafield->getId()] = $cache_update;
                $redis->set($redis_prefix.'.group_'.$group->getId().'_permissions', gzcompress(serialize($group_permissions)));
            }

            // Clear cached version of permissions for all users of this group
            // Not updating cached entry because it's a combination of all group permissions, and would take as much work to figure out what all to change as it would to just rebuild it
            foreach ($user_list as $user_id)
                $redis->del($redis_prefix.'.user_'.$user_id.'_permissions');

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x648233742 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
