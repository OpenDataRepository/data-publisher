<?php

/**
* Open Data Repository Data Publisher
* User Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The user controller handles everything related to users, profiles,
* and their permissions.  Will eventually handle user groups too.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\ODRUserExtend;
use ODR\AdminBundle\Entity\UserPermissions;
use ODR\AdminBundle\Entity\UserFieldPermissions;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ODRUserController extends ODRCustomController
{

    /**
     * Returns the profile editing HTML for a non-admin user
     * 
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function selfeditAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab the specified user
            $user = $this->container->get('security.context')->getToken()->getUser();
            $repo_user_extend = $this->getDoctrine()->getRepository('ODRAdminBundle:ODRUserExtend');
            $user_extend = $repo_user_extend->findOneBy( array('user' => $user) );

            // Do stuff for token? no idea
            $token = '';

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:profile.html.twig',
                    array(
                        'user' => $user,
                        'user_extend' => $user_extend,
                        'self_edit' => 'true',
                        'token' => $token
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x678877532 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns the HTML for an admin user to modify profile information for any other user
     * 
     * @param integer $user_id The database id of the user to edit.
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function editAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {

            // --------------------
            // Ensure user has permissions to be doing this
            $admin = $this->container->get('security.context')->getToken()->getUser();

            // Bypass all this sillyness if the user is a super admin, or doing this action to his own profile for some reason
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') || $admin->getId() == $user_id ) {

                // If user lacks super admin and admin roles, not allowed to do this
                if ( !$admin->hasRole('ROLE_ADMIN') )
                    return parent::permissionDeniedError();

                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin->getId(), $request, false);
                $user_permissions = parent::getPermissionsArray($user_id, $request, false);

                $allow = false;
                foreach ($admin_permissions as $datatype_id => $permission) {
                    if ( isset($permission['admin']) && $permission['admin'] == 1 ) {
                        // If $user_id is 0, this is a new user...allow if user has admin permissions for a datatype
                        if ($user_id === '0') {
                            $allow = true;
                            break;
                        }

                        // allow this profile edit if the admin user has an "is_type_admin" permission and the target user has a "can_view_type" for the same datatype
                        if ( isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id]['view']) && $user_permissions[$datatype_id]['view'] == 1 ) {
                            $allow = true;
                            break;
                        }
                    }
                }

                // If not allowed, block access
                if (!$allow)
                    return parent::permissionDeniedError();
            }
            // --------------------

            // Grab the specified user
            $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');
            $user = $repo_user->find($user_id);
            if ($user_id !== '0' && $user == null)
                return parent::deletedEntityError('User');

            $repo_user_extend = $this->getDoctrine()->getRepository('ODRAdminBundle:ODRUserExtend');
            $user_extend = $repo_user_extend->findOneBy( array('user' => $user) );

            // Do stuff for token? no idea
            $token = '';

            $self_edit = 'false';
            if ($admin->getid() == $user_id)
                $self_edit = 'true';

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:profile.html.twig',
                    array(
                        'user' => $user,
                        'user_extend' => $user_extend,
                        'self_edit' => $self_edit,
                        'token' => $token
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x678877532 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Wrapper function that allows a non-admin user to save changes to his own profile.
     * TODO: change to use symfony forms
     * 
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function selfsaveAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab the specified user
            $user = $this->container->get('security.context')->getToken()->getUser();

            if ( isset($_POST['odr_profile_form']) && $user->getId() == $_POST['odr_profile_form']['user_id']) {
                $post = $_POST['odr_profile_form'];
                $return = self::saveProfile($post);
            }
            else {
                $return['r'] = 2;
                $return['d'] = array( 'error' => 'bad form' );
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x678773129 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Wrapper function that allows an admin user to save changes to any user's profile
     * TODO: change to use symfony forms?
     * 
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function saveAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure a form got posted...
            $post = array();
            if ( isset($_POST['odr_profile_form']) )
                $post = $_POST['odr_profile_form'];
            else
                return parent::permissionDeniedError();

            // Need to get the user id out of the form to check permissions...
            $user_id = 0;
            if ( isset($post['user_id']) )
                $user_id = intval($post['user_id']);

            // --------------------
            // Ensure user has permissions to be doing this
            $admin = $this->container->get('security.context')->getToken()->getUser();

            // Bypass all this sillyness if the user is a super admin, or doing this action to his own profile for some reason
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') || $admin->getId() == $user_id ) {

                // If user lacks super admin and admin roles, not allowed to do this
                if ( !$admin->hasRole('ROLE_ADMIN') )
                    return parent::permissionDeniedError();
                
                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin->getId(), $request, false);
                $user_permissions = parent::getPermissionsArray($user_id, $request, false);

                $allow = false;
                foreach ($admin_permissions as $datatype_id => $permission) {
                    if ( isset($permission['admin']) && $permission['admin'] == 1 ) { 
                        // If $user_id is 0, this is a new user...allow if user has admin permissions for a datatype
                        if ($user_id == '0') {
                            $allow = true;
                            break;
                        }

                        // allow this profile edit if the admin user has an "is_type_admin" permission and the target user has a "can_view_type" for the same datatype
                        if ( isset($user_permissions[$datatype_id]) && isset($user_permissions[$datatype_id]['view']) && $user_permissions[$datatype_id]['view'] == 1 ) { 
                            $allow = true;
                            break;
                        }   
                    }   
                }   

                // If not allowed, block access
                if (!$allow)
                    return parent::permissionDeniedError();
            }
            // --------------------

            // Actually save the profile
            $return = self::saveProfile($post);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x678773129 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves modifications to user profiles to the database.
     * TODO: update to use symfony forms?
     * 
     * @param array $post
     * 
     * @return TODO
     */
    private function saveProfile($post)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        // Error reporting
        $fail = false;
        $error = '';
        $message = '';
        $redirect = false;
        $url = '';

        // Going to need these
        $em = $this->getDoctrine()->getManager();
        $user_manager = $this->container->get('fos_user.user_manager');
        $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');
        $repo_user_extend = $em->getRepository('ODRAdminBundle:ODRUserExtend');

        $admin_user = $this->container->get('security.context')->getToken()->getUser();

        $username = '';
        $firstname = '';
        $lastname = '';
        $email = '';
        $first_password = '';
        $second_password = '';
        $token = '';
        $user_id = 0;

        // Read the pieces of information
        if ( isset($post['email']) && isset($post['plainPassword']) && isset($post['plainPassword']['first']) && isset($post['plainPassword']['second']) && isset($post['_token']) && isset($post['firstname']) && isset($post['lastname']) ) {
            $email = $post['email'];
            $first_password = $post['plainPassword']['first'];
            $second_password = $post['plainPassword']['second'];
            $token = $post['_token'];
            $firstname = $post['firstname'];
            $lastname = $post['lastname'];

            if ( isset($post['username']) )
                $username = $post['username'];
            if ( isset($post['user_id']) )
                $user_id = intval($post['user_id']);

            if ($email == '' || $first_password == '' || $second_password == '') {
                $fail = true;
                $error = "bad form";
            }
        }
        else {
           $fail = true;
           $error = "bad form";
        }

        if ($first_password !== $second_password) {
            $fail = true;
            $error = "passwords don't match";
        }
        else if ( $user_id == 0 && $user_manager->findUserByUsername($username) !== null) {
            $target_user = $user_manager->findUserByUsername($username);

            if ( $target_user->hasRole('ROLE_SUPER_ADMIN') ) {
                $fail = true;
                $error = 'User already exists';
            }
            else {
                $fail = true;
                $redirect = true;
                $message = 'User already exists';

                // redirect to the manage permissions page for the target user so the admin can change the target user's permissions
                $router = $this->container->get('router');
                $url = $router->generate('odr_manage_user_permissions', array('user_id' => $target_user->getId()));
            }
        }

        if (!$fail) {
            // Everything is valid?
            $user = null;
            $user_extend = null;
            if ($user_id != 0) {
                $user = $repo_user->find($user_id);
                if ($user == null)
                    return parent::deletedEntityError('User');

                $user_extend = $repo_user_extend->findOneBy( array('user' => $user) );
                if ($user_extend == null) {
                    $user_extend = new ODRUserExtend();
                    $user_extend->setUser($user);
                    $user->setUserExtend($user_extend);
                }
            }
            else {
                $user = $user_manager->createUser();
                $user_extend = new ODRUserExtend();
                $user_extend->setUser($user);
                $user->setUserExtend($user_extend);
            }

            $user->setEmail($email);
            if ($first_password != '') {
                // Need to use this function to set salt/password
                $user->setPlainPassword($first_password);
            }

            if ($user_id == 0) {
                $user->setUsername($username);
                $user->setEnabled(true);
                $user->addRole('ROLE_USER');
            }

            $user_extend->setFirstName($firstname);
            $user_extend->setLastName($lastname);

            $em->persist($user_extend);
            $user_manager->updateUser($user);
            $em->flush();

            // Create user_permission entries for the new user
            if ($user_id == 0) {
                // Reload user to make symfony happy...
                $em->refresh($user);

                // Need to grab all top-level datatypes...
                $datatypes = null;
                $datatrees = $em->getRepository('ODRAdminBundle:DataTree')->findAll();
                $tmp_datatypes = $em->getRepository('ODRAdminBundle:DataType')->findAll();

                // TODO - parent::getTopLevelDatatypes() returns array if integers, want array of DataTypes...
                // Locate the IDs of all datatypes that are descended from another datatype
                $descendants = array();
                foreach ($datatrees as $datatree) {
                    if ($datatree->getIsLink() == 0)
                        $descendants[] = $datatree->getDescendant()->getId();
                }

                // Only save the datatypes that aren't descended from another datatype
                foreach ($tmp_datatypes as $tmp_datatype) {
                    if ( !in_array($tmp_datatype->getId(), $descendants) )
                        $datatypes[] = $tmp_datatype;
                }

                // Create default permissions for this user for each of the datatypes
                foreach ($datatypes as $datatype) {
                    self::permissionsExistence($user, $admin_user, $datatype, null);  // user is going to need permission entities eventually, upon user create is as good a place as any for them
                }

                $redirect = true;
                $message = 'User created';
                // redirect to the manage permissions page for the target user so the admin can change the target user's permissions
                $router = $this->container->get('router');
                $url = $router->generate('odr_manage_user_permissions', array('user_id' => $user->getId()));
            }
        }

        if ($redirect) {
            $return['r'] = 3;
            $return['d'] = array('message' => $message, 'url' => $url);
        }
        else if ($fail) {
            // Grab the specified user
//            $user = $repo_user->find($user_id);

            // Do stuff for token? no idea
            $token = '';

            // Return the error
            $return['r'] = 2;
            $return['d'] = array(
                'error' => $error,
            );
        }

        return $return;
    }


    /**
     * Renders and returns a list of all registered users.
     * 
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function listAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            $admin_user = $this->container->get('security.context')->getToken()->getUser();

            $em = $this->getDoctrine()->getManager();
            $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');
//            $user_permissions = $repo_user_permissions->findBy( array('user_id' => $admin_user) );
            $user_permissions = parent::getPermissionsArray($admin_user->getId(), $request);

            // User needs at least one "is_admin" permission to access this
            $admin_permissions = array();
            foreach ($user_permissions as $datatype_id => $up) {
                if ( isset($up['admin']) && $up['admin'] == '1' ) {
                    $admin_permissions[ $datatype_id ] = $up;
                }
            }

            if ( count($admin_permissions) == 0 )
                return parent::permissionDeniedError();
            // --------------------

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            $user_list = $user_manager->findUsers();

            // Reduce user list to those that possess a view permission to datatypes this user has admin permissions for, excluding super admins
            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') ) {
                $tmp = array();
                foreach ($user_list as $user) {
                    // Don't add super admins to this list
                    if (!$user->hasRole('ROLE_SUPER_ADMIN')) {
                        $user_permissions = parent::getPermissionsArray($user->getId(), $request, false);

                        foreach ($user_permissions as $datatype_id => $up) {
                            if ( isset($up['view']) && $up['view'] == '1' && isset($admin_permissions[$datatype_id]) ) {
                                $tmp[] = $user;
                                break;
                            }
                        }
                    }
                }

                $user_list = $tmp;
            }

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:list.html.twig',
                    array(
                        'users' => $user_list,
                        'admin_user' => $admin_user
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x68871752 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns HTML for modifying security roles (USER, DESIGNER, ADMIN) for a user.
     * 
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function managerolesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            $admin = $this->container->get('security.context')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            $users = $user_manager->findUsers();

            // Prevent the admin from modifying his own role (potentially removing his own admin role)
            $admin_user = $this->container->get('security.context')->getToken()->getUser();

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:manage_roles.html.twig',
                    array(
                        'users' => $users,
                        'admin_user' => $admin_user
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x48871752 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }

    /**
     * Saves changes made to a user's role (user/editor/designer/admin)
     * 
     * @param integer $user_id The database id of the user being modified.
     * @param string $role     Which role to grant the user.
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function setroleAction($user_id, $role, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            $admin = $this->container->get('security.context')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------

            // Grab all the users
            $em = $this->getDoctrine()->getManager();
            $user_manager = $this->container->get('fos_user.user_manager');
            $users = $user_manager->findUsers();

            // Prevent the admin from modifying his own role (potentially removing his own admin role)
            $admin_user = $this->container->get('security.context')->getToken()->getUser();

            // Locate the user being changed
            foreach ($users as $user) {
                if (($user->getId() == $user_id) && ($user !== $admin_user)) {

                    // Grab all permissions for this user
                    $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');
                    $repo_user_field_permissions = $em->getRepository('ODRAdminBundle:UserFieldPermissions');
                    $user_permissions = $repo_user_permissions->findBy( array('user_id' => $user) );
                    $user_field_permissions = $repo_user_field_permissions->findBy( array('user_id' => $user) );

                    if ($role == 'user') {
                        $user->addRole('ROLE_USER');
                        $user->removeRole('ROLE_ADMIN');
                        $user->removeRole('ROLE_SUPER_ADMIN');
/*
                        // Firewall should block access, but delete all Design permissions for this user to be on the safe side
                        foreach ($user_permissions as $user_permission) {
                            $user_permission->setCanDesignType(0);
                            $em->persist($user_permission);
                        }
                        $em->flush();
*/
                    }
                    else if ($role == 'admin') {
                        $user->addRole('ROLE_USER');
                        $user->addRole('ROLE_ADMIN');
                        $user->removeRole('ROLE_SUPER_ADMIN');
                    }
                    else if ($role == 'sadmin') {
                        $user->addRole('ROLE_USER');
                        $user->addRole('ROLE_ADMIN');
                        $user->addRole('ROLE_SUPER_ADMIN');

                        // Enable all datatype permissions for this (now admin) user
                        foreach ($user_permissions as $user_permission) {
                            $user_permission->setCanEditRecord(1);
                            $user_permission->setCanAddRecord(1);
                            $user_permission->setCanDeleteRecord(1);
                            $user_permission->setCanViewType(1);
                            $user_permission->setCanDesignType(1);
                            $user_permission->setIsTypeAdmin(1);
                            $em->persist($user_permission);
                        }
                        // Enable all datafield permissions for this (now admin) user
                        foreach ($user_field_permissions as $user_field_permission) {
                            $user_field_permission->setCanViewField(1);
                            $user_field_permission->setCanEditField(1);
                            $em->persist($user_field_permission);
                        }

                        $em->flush();
                    }

                    $user_manager->updateUser($user);
                    break;
                }
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x48781756 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }

    /**
     * Debug function to ensure all super-admin users have all permissions
     */
    public function permissioncheckAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

        $user_manager = $this->container->get('fos_user.user_manager');
        $users = $user_manager->findUsers();

        $admin_users = array();
        foreach ($users as $user) {
            if ($user->hasRole('ROLE_SUPER_ADMIN'))
                $admin_users[] = $user;
        }

        $top_level_datatypes = parent::getTopLevelDatatypes();
        foreach ($top_level_datatypes as $num => $datatype_id) {
            $datatype = $repo_datatype->find($datatype_id);

            foreach ($admin_users as $user)
                self::permissionsExistence($user, $user, $datatype, null);
        }
    }


    /**
     * Ensures the user permissions table has rows linking the given user and datatype...TODO
     * 
     * @param User $user               The User receiving the permissions
     * @param User $admin              The admin User which triggered this function
     * @param DataType $datatype       Which DataType these permissions are for
     * @param mixed $parent_permission null if $datatype is top-level, otherwise the $user's UserPermissions object for this $datatype's parent
     * 
     * @return none
     */
    private function permissionsExistence($user, $admin, $datatype, $parent_permission)
    {
        // Grab required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
        $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');

        // Look up the user's permissions for this datatype
        $user_permission = $repo_user_permissions->findOneBy( array('user_id' => $user, 'dataType' => $datatype) );

        // Verify a permissions object exists for this user/datatype
        if ($user_permission === null) {
            $user_permission = new UserPermissions();
            $user_permission->setUserId($user);
            $user_permission->setDataType($datatype);
            $user_permission->setCreatedBy($admin);

            $default = 0;
            if ($user->hasRole('ROLE_SUPER_ADMIN'))
                $default = 1;   // SuperAdmins can edit/add/delete/design everything, no exceptions

            if ($parent_permission === null) {
                // If this is a top-level datatype, use the defaults
                $user_permission->setCanEditRecord($default);
                $user_permission->setCanAddRecord($default);
                $user_permission->setCanDeleteRecord($default);
                $user_permission->setCanViewType($default);
                $user_permission->setCanDesignType($default);
                $user_permission->setIsTypeAdmin($default);
            }
            else {
                // If this is a childtype, use the parent's permissions as defaults
                $user_permission->setCanEditRecord( $parent_permission->getCanEditRecord() );
                $user_permission->setCanAddRecord( $parent_permission->getCanAddRecord() );
                $user_permission->setCanDeleteRecord( $parent_permission->getCanDeleteRecord() );
                $user_permission->setCanViewType( $parent_permission->getCanViewType() );
                $user_permission->setCanDesignType( $parent_permission->getCanDesignType() );
                $user_permission->setIsTypeAdmin( 0 );      // DON'T set admin permissions on childtype
            }

            $em->persist($user_permission);
        }

        // Locate any children of this datatype
        $datatrees = $repo_datatree->findBy( array('ancestor' => $datatype->getId(), 'is_link' => 0) );
        foreach ($datatrees as $datatree) {
            // Ensure the user has permission objects for them as well
            self::permissionsExistence($user, $admin, $datatree->getDescendant(), $user_permission);
        }

        // TODO - if a top-level datatype has childtypes, this flush will end up executing multiple times
        // Commit all the changes
        $em->flush();
    }


    /**
     * Renders and returns HTML listing all permissions for all datatypes for the selected user.
     * 
     * @param integer $user_id The database id of the user to modify.
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function managepermissionsAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            $admin_user = $this->container->get('security.context')->getToken()->getUser();

            $em = $this->getDoctrine()->getManager();
            $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');
            $user_permissions = $repo_user_permissions->findBy( array('user_id' => $admin_user) );

            // User needs at least one "is_admin" permission to access this
            $admin_permissions = array();
            foreach ($user_permissions as $user_permission) {
                $datatype = $user_permission->getDataType();
                if ($user_permission->getIsTypeAdmin() == '1') {
                    $admin_permissions[ $datatype->getId() ] = $user_permission;
//print $datatype->getId()."\n";
                }
            }

            if ( count($admin_permissions) == 0 )
                return parent::permissionDeniedError();
            // --------------------


            // Grab the specified user
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');
            $user = $repo_user->find($user_id);

            // Don't allow modifying of ROLE_SUPER_ADMIN
            if ($user->hasRole('ROLE_SUPER_ADMIN'))
                return parent::permissionDeniedError();

            // Don't allow those with just ROLE_USER to modify those with ROLE_ADMIN
            if ( !$admin_user->hasRole('ROLE_ADMIN') && $user->hasRole('ROLE_ADMIN') )
                return parent::permissionDeniedError();

            // Build the list of top-level datatypes
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');


            // Need all datatypes, organized into top-level and child datatype arrays
            $datatypes = array();
            $childtypes = array();
            $datatree_array = parent::getDatatreeArray($em);

            $tmp_datatypes = $repo_datatype->findAll();
            foreach ($tmp_datatypes as $tmp_datatype) {
                $dt_id = $tmp_datatype->getId();

                if ( !isset($datatree_array['descendant_of'][$dt_id]) || $datatree_array['descendant_of'][$dt_id] == '' ) {
                    // top-level datatype
                    $datatypes[] = $tmp_datatype;

                    if ( !isset($childtypes[$dt_id]) )
                        $childtypes[$dt_id] = null;
                }
                else {
                    // child datatype
                    $ancestor_id = $datatree_array['descendant_of'][$dt_id];
                    if ( !isset($childtypes[$ancestor_id]) || $childtypes[$ancestor_id] == null )
                        $childtypes[$ancestor_id] = array();

                    $childtypes[$ancestor_id][] = $tmp_datatype;
                }
            }


            // Ensure the user has permission objects for all the datatypes
            foreach ($datatypes as $datatype)
                self::permissionsExistence($user, $admin_user, $datatype, null);     // due to requiring individual permissions...

            // Reload all the permissions for this user
            $repo_user_permissions = $this->getDoctrine()->getRepository('ODRAdminBundle:UserPermissions');
            $user_permissions = $repo_user_permissions->findBy( array('user_id' => $user) );

            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:manage_permissions.html.twig',
                    array(
                        'admin_user' => $admin_user,
                        'admin_permissions' => $admin_permissions,

                        'user' => $user,
                        'permissions' => $user_permissions,
                        'datatypes' => $datatypes,
                        'childtypes' => $childtypes
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x8089132 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns HTML allowing the admin to set a particular permission for a specific DataType for ALL users at once.
     * 
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function quickpermissionsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
//$start = microtime(true);

            // --------------------
            // Ensure user has permissions to be doing this
            $admin = $this->container->get('security.context')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------

            // Grab the admin doing the permissions modification
            $admin = $this->container->get('security.context')->getToken()->getUser();

            // Build the list of top-level datatypes
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');

            // Need all datatypes, organized into top-level and child datatype arrays
            $datatypes = array();
            $childtypes = array();
            $datatree_array = parent::getDatatreeArray($em);

            $tmp_datatypes = $repo_datatype->findAll();
            foreach ($tmp_datatypes as $tmp_datatype) {
                $dt_id = $tmp_datatype->getId();

                if ( !isset($datatree_array['descendant_of'][$dt_id]) || $datatree_array['descendant_of'][$dt_id] == '' ) {
                    // top-level datatype
                    $datatypes[] = $tmp_datatype;

                    if ( !isset($childtypes[$dt_id]) )
                        $childtypes[$dt_id] = null;
                }
                else {
                    // child datatype
                    $ancestor_id = $datatree_array['descendant_of'][$dt_id];
                    if ( !isset($childtypes[$ancestor_id]) || $childtypes[$ancestor_id] == null )
                        $childtypes[$ancestor_id] = array();

                    $childtypes[$ancestor_id][] = $tmp_datatype;
                }
            }


//print 'build datatype arrays in '.(microtime(true) - $start)."\n";

            // Verify all the users have permission objects available for each datatype
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');
            $users = $repo_user->findAll();
/*
            foreach ($users as $user) {
                // Ensure all the datatypes have permission objects
                foreach ($datatypes as $datatype)
                    self::permissionsExistence($user, $admin, $datatype, null);
            }
*/


            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:quick_permissions.html.twig',
                    array(
                        'datatypes' => $datatypes,
                        'childtypes' => $childtypes
                    )
                )
            );

//print 'rendered html in '.(microtime(true) - $start)."\n";

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x876089132 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Updates a UserPermission object in the database.
     * 
     * @param User $user         The User that is getting their permissions modified.
     * @param DataType $datatype The DataType that the User's permissions are being modified for.
     * @param string $permission Which type of permission is being set.
     * @param integer $value     0 if the permission is being revoked, 1 if the permission is being granted
     * 
     * @return none
     */
    private function savePermission($user, $datatype, $permission, $value)
    {
        // If the user is an admin, they always have all permissions...don't change anything
        if (!$user->hasRole('ROLE_SUPER_ADMIN')) {
            // Going to need the entity manager...
            $em = $this->getDoctrine()->getManager();

            // Don't allow admin permissions to be set on a non-top-level datatype...
            $top_level_datatypes = parent::getTopLevelDatatypes();
            if ( $permission == 'admin' && !in_array($datatype->getId(), $top_level_datatypes) )
//throw new \Exception('admin 1');
                return;

            // Grab the user's permissions for this datatype
            $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');
            $user_permission = $repo_user_permissions->findOneBy( array('user_id' => $user, 'dataType' => $datatype) );
            if ( $user_permission == null ) {
                self::permissionsExistence($user, $user, $datatype, null);      // need to ensure a permission object exists before saving to it, obviously...
            }

            // Ensure childtypes do not have the admin permission...shouldn't happen, but make doubly sure
            if ( !in_array($datatype->getId(), $top_level_datatypes) ) {
                $user_permission->setIsTypeAdmin('0');
                $em->persist($user_permission);
                $em->flush($user_permission);
                $em->refresh($user_permission);
            }

            // Don't change other permissions if user has admin permission for datatype
            if ( $permission !== 'admin' && $user_permission->getIsTypeAdmin() == '1' )
//throw new \Exception('admin 2');
                return;

            // Modify their permissions based on the path
            switch($permission) {
                case 'edit':
                    $user_permission->setCanEditRecord($value);
                    break;
                case 'add':
                    $user_permission->setCanAddRecord($value);
                    break;
                case 'delete':
                    $user_permission->setCanDeleteRecord($value);
                    break;
                case 'view':
                    $user_permission->setCanViewType($value);
                    break;
                case 'design':
                    $user_permission->setCanDesignType($value);
                    break;
                case 'admin':
                    $user_permission->setIsTypeAdmin($value);
                    break;
            }

            $reset_can_edit_datafield = false;

            if ($permission == 'edit') {
                // If the user had their edit permissions changedfor this datatype, reset the edit permission on all datafields of this datatype
                $reset_can_edit_datafield = true;
            }
            else if ($permission == 'view' && $value == '0') {
                // If user no longer has view permissions for this datatype, disable all other permissions as well
                $user_permission->setCanEditRecord('0');
                $user_permission->setCanAddRecord('0');
                $user_permission->setCanDeleteRecord('0');
                $user_permission->setCanDesignType('0');
                $user_permission->setIsTypeAdmin('0');

                $reset_can_edit_datafield = true;
            }
            else if ($permission == 'admin' && $value == '1') {
                // If user got admin permissions for this datatype, enable all other permissions as well
                $user_permission->setCanViewType('1');
                $user_permission->setCanEditRecord('1');
                $user_permission->setCanAddRecord('1');
                $user_permission->setCanDeleteRecord('1');
                $user_permission->setCanDesignType('1');
            }
            else {
                if ($value == '1') {
                    // If user gets some permission for the datatype, ensure they have have view permissions as well
                    if ($user_permission->getCanViewType() == '0')
                        $user_permission->setCanViewType('1');
                }
            }
            // Save changes to the datatype permission
            $em->persist($user_permission);

            // If necessary to reset datafield edit permissions for this datatype...
            if ($reset_can_edit_datafield) {
                $repo_user_field_permissions = $em->getRepository('ODRAdminBundle:UserFieldPermissions');
                $datafield_permissions = $repo_user_field_permissions->findBy( array('user_id' => $user->getId(), 'dataType' => $datatype->getId()) );

                foreach ($datafield_permissions as $permission) {
                    if ($value == '1') {
                        // user was given edit permissions for this datatype...grant edit permissions for this datafield unless they're already restricted from viewing the datafield
                        if ($permission->getCanViewField() == '1')
                            $permission->setCanEditField('1');
                    }
                    else {
                        // user had his edit permissions revoked for this datatype...revoke edit permissions for this datafield
                        $permission->setCanEditField('0');
                    }

                    // Save changes to the datafield permission
                    $em->persist($permission);
                }
            }

            // Commit all changes
            $em->flush();
        }
    }


    /**
     * Updates a user's permissions for a given DataType.
     * 
     * @param integer $user_id     The database id of the User being modified.
     * @param integer $datatype_id The database id of the DataType these permissions are for.
     * @param integer $value       0 if permission is being revoked, 1 if permission being granted
     * @param string $permission   The permission being modified.
     * @param Request $request
     * 
     * @return TODO
     */
    public function togglepermissionAction($user_id, $datatype_id, $value, $permission, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            $admin_user = $this->container->get('security.context')->getToken()->getUser();

            $em = $this->getDoctrine()->getManager();
            $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');
            $user_permissions = $repo_user_permissions->findBy( array('user_id' => $admin_user) );

            // User needs at least one "is_admin" permission to access this
            $admin_permissions = array();
            foreach ($user_permissions as $user_permission) {
                $datatype = $user_permission->getDataType();
                if ($user_permission->getIsTypeAdmin() == '1') {
                    $admin_permissions[ $datatype->getId() ] = $user_permission;
//print $datatype->getId()."\n";
                }
            }

            if ( count($admin_permissions) == 0 )
                return parent::permissionDeniedError();
            // --------------------


            // Grab the user from their id
            $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');
            $user = $repo_user->find($user_id);

            // Don't allow modifying of ROLE_SUPER_ADMIN
            if ($user->hasRole('ROLE_SUPER_ADMIN'))
                return parent::permissionDeniedError();

            // Don't allow those with just ROLE_USER to modify those with ROLE_ADMIN
            if ( !$admin_user->hasRole('ROLE_ADMIN') && $user->hasRole('ROLE_ADMIN') )
                return parent::permissionDeniedError();


            // Grab the datatype that's being modified
            $repo_datatype = $this->getDoctrine()->getRepository('ODRAdminBundle:DataType');
            $datatype = $repo_datatype->find($datatype_id);

            // Modify the user's permission for that datatype and any children it has
            self::savePermission($user, $datatype, $permission, $value);
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
     * Grants or revokes a specific permission of a DataType for all users.
     * 
     * @param integer $datatype_id The database id of the DataType being modified.
     * @param integer $value       0 if the permission is being revoked, 1 if the permission is being granted
     * @param string $permission   Which permission is being modified.
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred
     */
    public function togglequickpermissionAction($datatype_id, $value, $permission, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            $admin = $this->container->get('security.context')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            $users = $user_manager->findUsers();

            // Grab the datatype that's being modified
            $repo_datatype = $this->getDoctrine()->getRepository('ODRAdminBundle:DataType');
            $datatype = $repo_datatype->find($datatype_id);

            // Modify that permission for all the users
            foreach ($users as $user)
                self::savePermission($user, $datatype, $permission, $value);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x883817922 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a User.
     * 
     * @param integer $user_id The database id of the user being deleted.
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function deleteAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            $admin = $this->container->get('security.context')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------

            // TODO - have to go through $user_manager to delete a user??
            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            $users = $user_manager->findUsers();

            // Prevent the admin from deleting himself
            $admin_user = $this->container->get('security.context')->getToken()->getUser();

            // Locate the user being changed
            foreach ($users as $user) {
                if (($user->getId() == $user_id) && ($user !== $admin_user)) {
                    // This may not be the right way to do it...
                    $user->setEnabled(0);
                    $user_manager->updateUser($user);
                    break;
                }
            }

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:list.html.twig',
                    array(
                        'users' => $users,
                        'admin_user' => $admin_user
                    )
                )
            );

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
     * Undeletes a User.
     * 
     * @param integer $user_id The database id of the user being reinstated.
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function undeleteAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            $admin = $this->container->get('security.context')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------

            // TODO - have to go through $user_manager to undelete a user??
            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            $users = $user_manager->findUsers();

            // Needed for the twig file
            $admin_user = $this->container->get('security.context')->getToken()->getUser();

            // Locate the user being changed
            foreach ($users as $user) {
                if ($user->getId() == $user_id) {
                    // This may not be the right way to do it...
                    $user->setEnabled(1);
                    $user_manager->updateUser($user);
                    break;
                }
            }

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:list.html.twig',
                    array(
                        'users' => $users,
                        'admin_user' => $admin_user
                    )
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x88817523 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Updates the number of results on a page used by ShortResults
     *
     * @param integer $length  How many ShortResult datarecords to show on a page.
     * @param Request $request
     *
     * @return none
     */
    public function pagelengthAction($length, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $session = $request->getSession();
            $session->set('shortresults_page_length', intval($length));
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x648812732 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns an interface for datafield-level permissions.
     *
     * @param integer $user_id     The database id of the User having their permissions modified
     * @param integer $datatype_id The database id of the DataType being modified
     * @param Request $request
     *
     * @return a Symfony JSON response containing HTML
     */
    public function datafieldpermissionsAction($user_id, $datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Grab the user from their id
            $em = $this->getDoctrine()->getManager();
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');
            $user = $repo_user->find($user_id);

            // Ensure user has permissions to be doing this
            $admin_user = $this->container->get('security.context')->getToken()->getUser();

            // Require admin user to have at least admin role to do this...
            if ( $admin_user->hasRole('ROLE_ADMIN') ) {

                // If target user is super admin, not allowed to do this
                if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                    return parent::permissionDeniedError();

                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin_user->getId(), $request, false);
                $user_permissions = parent::getPermissionsArray($user->getId(), $request, false);

                $allow = false;
                foreach ($admin_permissions as $dt_id => $permission) {
                    if ( isset($permission['admin']) && $permission['admin'] == 1 ) {
                        // allow this permissions change if the admin user has an "is_type_admin" permission and the target user has a "can_view_type" for the same datatype
                        if ( isset($user_permissions[$dt_id]) && isset($user_permissions[$dt_id]['view']) && $user_permissions[$dt_id]['view'] == 1 ) {
                            $allow = true;
                            break;
                        }
                    }
                }

                // If not allowed, block access
                if (!$allow)
                    return parent::permissionDeniedError();
            }
            else {
                return parent::permissionDeniedError();
            }
            // --------------------

            // Grab the datatype that's being modified
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');


            // Build the tree used for rendering the datatype
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);
            $theme_element = null;  // only used for reloading theme_element
            $is_link = 0;
            $top_level = 1;
            $short_form = 0;
            $indent = 0;
$debug = true;
$debug = false;
if ($debug)
    print '<pre>';

            $tree = parent::buildDatatypeTree($user, $theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);

if ($debug)
    print '<pre>';

            // Grab datatype-level permissions for the specified user
            $datatype_permissions = parent::getPermissionsArray($user_id, $request, false);     // get permissions for $user_id, not the $admin calling the function
            // Grab the datafield-level permissions for this user
            $datafield_permissions = parent::getDatafieldPermissionsArray($user_id, $request);

            // Render the html for assigning datafield permissions
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:FieldPermissions:fieldpermissions_ajax.html.twig',
                    array(
                        'user' => $user,
                        'datatype_tree' => $tree,
                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,
                    )
                )
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
     * Saves a datafield permission change for a given user.
     *
     * @param integer $user_id      The database id of the User being modified.
     * @param integer $datafield_id The database id of the DataField being modified.
     * @param integer $permission   '2' => can view/can edit, '1' => can view/no edit, '0' => no view/no edit
     * @param Request $request
     *
     * @return an empty Symfony JSON response, unless an error occurred
     */
    public function savedatafieldpermissionAction($user_id, $datafield_id, $permission, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Grab the user from their id
            $em = $this->getDoctrine()->getManager();
            $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');
            $user = $repo_user->find($user_id);

            // Grab the datafield that's being modified
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $datafield = $repo_datafield->find($datafield_id);

            if ($datafield == null)
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');
            $datatype_id = $datatype->getId();

            // Ensure user has permissions to be doing this
            $admin_user = $this->container->get('security.context')->getToken()->getUser();

            // Require user to have at least admin role to do this...
            if ( $admin_user->hasRole('ROLE_ADMIN') ) {

                // If target user is super admin, not allowed to do this
                if ( $user->hasRole('ROLE_SUPER_ADMIN') )
                    return parent::permissionDeniedError();

                // Grab permissions of both target user and admin
                $admin_permissions = parent::getPermissionsArray($admin_user->getId(), $request, false);
                $user_permissions = parent::getPermissionsArray($user->getId(), $request, false);

                $allow = false;
                if ( isset($admin_permissions[$datatype_id]) && isset($admin_permissions[$datatype_id]['admin']) && $admin_permissions[$datatype_id]['admin'] == 1 ) {
                    if ( isset($admin_permissions[$datatype_id]) && isset($admin_permissions[$datatype_id]['view']) && $admin_permissions[$datatype_id]['view'] == 1 ) {
                        $allow = true;
                    }
                }

                // If not allowed, block access
                if (!$allow)
                    return parent::permissionDeniedError();
            }
            else {
                return parent::permissionDeniedError();
            }
            // --------------------

            // Grab the UserFieldPermission object that's being modified
            $repo_user_field_permissions = $em->getRepository('ODRAdminBundle:UserFieldPermissions');
            $user_field_permission = $repo_user_field_permissions->findOneBy( array('user_id' => $user->getId(), 'dataFields' => $datafield->getId()) );

            if ($permission == 2) {
                $user_field_permission->setCanViewField(1);
                $user_field_permission->setCanEditField(1);
            }
            else if ($permission == 1) {
                $user_field_permission->setCanViewField(1);
                $user_field_permission->setCanEditField(0);
            }
            else if ($permission == 0) {
                $user_field_permission->setCanViewField(0);
                $user_field_permission->setCanEditField(0);
            }

            // Save the permissions change
            $em->persist($user_field_permission);
            $em->flush();

            // TODO - invalidate datafield permissions across the server?
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
