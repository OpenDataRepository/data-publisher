<?php

/**
 * Open Data Repository Data Publisher
 * ODRUser Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The user controller handles setting user roles/permissions, and
 * completely replaces the default FoS functionality for creating,
 * editing, and deleting users.
 *
 * Password resetting is handled by the ODR UserBundle by overriding
 * the relevant section of the FoSUserBundle.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// ODR
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
use ODR\OpenRepository\OAuthClientBundle\Entity\UserLink;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Forms
use ODR\AdminBundle\Form\ODRAdminChangePasswordForm;
use ODR\AdminBundle\Form\ODRUserProfileForm;
// OAuth
use HWI\Bundle\OAuthBundle\Security\OAuthUtils;
use ODR\OpenRepository\OAuthServerBundle\OAuth\ClientManager;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ODRUserController extends ODRCustomController
{

    /**
     * Renders a form to allow admins to create a new user
     *
     * @param Request $request
     *
     * @return Response
     */
    public function createnewuserAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($admin_user);

            $admin_permission_count = 0;
            foreach ($datatype_permissions as $dt_id => $dt_permission) {
                if ( isset($dt_permission['dt_admin']) && $dt_permission['dt_admin'] == 1 )
                    $admin_permission_count++;
            }

            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && $admin_permission_count == 0 )
                throw new ODRForbiddenException();
            // --------------------

            // Create the form that will be used
            $new_user = new ODRUser();
            $form = $this->createForm(ODRUserProfileForm::class, $new_user);

            // Render and return the form
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:create_user.html.twig',
                    array(
                        'profile_form' => $form->createView(),
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0xeaa9d56a;
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
     * Checks whether a given email address is already in use.
     * Returns 0 if the email is not in use, otherwise returns the ID of the user that owns the email address.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function checkemailexistenceAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure request is properly formed first
            $post = $request->request->all();
            if ( !isset($post['email']) )
                throw new ODRBadRequestException();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($admin_user);

            $admin_permission_count = 0;
            foreach ($datatype_permissions as $dt_id => $dt_permission) {
                if ( isset($dt_permission['dt_admin']) && $dt_permission['dt_admin'] == 1 )
                    $admin_permission_count++;
            }

            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && $admin_permission_count == 0 )
                throw new ODRForbiddenException();
            // --------------------

            // Attempt to find a user with this email address
            $email = $post['email'];
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser $user */
            $user = $user_manager->findUserByEmail($email);

            // If found, return their user id
            if ($user !== null) {
                if ( $user->getId() !== $admin_user->getId() && !$user->hasRole('ROLE_SUPER_ADMIN') )
                    $return['d'] = $user->getId();
                else
                    $return['d'] = -1;
            }
            else
                $return['d'] = 0;
        }
        catch (\Exception $e) {
            $source = 0x4a78400f;
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
     * Uses the provided form to create a new user
     *
     * @param Request $request
     *
     * @return Response
     */
    public function savenewuserAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure request is properly formed first
            $post = $request->request->all();
            if ( !isset($post['ODRUserProfileForm']) )
                throw new ODRBadRequestException();
            $post = $post['ODRUserProfileForm'];


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $user_manager = $this->container->get('fos_user.user_manager');
            $router = $this->get('router');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($admin_user);

            $admin_permission_count = 0;
            foreach ($datatype_permissions as $dt_id => $dt_permission) {
                if ( isset($dt_permission['dt_admin']) && $dt_permission['dt_admin'] == 1 )
                    $admin_permission_count++;
            }

            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') && $admin_permission_count == 0 )
                throw new ODRForbiddenException();
            // --------------------


            // Ensure a user with the specified email doesn't already exist...
            $email = $post['email'];
            /** @var ODRUser $user */
            $user = $user_manager->findUserByEmail($email);
            if ($user !== null) {
                // If user already exists, just return the url to their permissions page
                $url = $router->generate( 'odr_manage_user_groups', array('user_id' => $user->getId()) );
                $return['d'] = array('url' => $url);
            }
            else {
                // Create a new user and bind the form to it
                $new_user = new ODRUser();
                $form = $this->createForm(ODRUserProfileForm::class, $new_user);

                $form->handleRequest($request);

                if ($form->isSubmitted()) {

                    // Password fields matching is handled by the Symfony Form 'repeated' field
                    // Password length and complexity is handled by the isPasswordValid() callback function in ODR\OpenRepository\UserBundle\Entity\User

                    // TODO - check for additional errors to throw?

//$form->addError( new FormError("don't save form...") );

                    // If no errors...
                    if ($form->isValid()) {
                        // Enable the user and give default roles
                        $new_user->setEnabled(true);
                        $new_user->addRole('ROLE_USER');

                        // Save changes to the user
                        $user_manager->updateUser($new_user);

                        // Generate and return the URL to modify the new user's permissions
                        $em->refresh($new_user);

                        $url = $router->generate( 'odr_manage_user_groups', array('user_id' => $new_user->getId()) );
                        $return['d'] = array('url' => $url);
                    }
                    else {
                        // Form validation failed
                        $error_str = parent::ODR_getErrorMessages($form);
                        throw new ODRException($error_str);
                    }
                }
            }
        }
        catch (\Exception $e) {
            $source = 0xc5f96e25;
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
     * Returns the profile editing HTML for a non-admin user
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function selfeditprofileAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Grab the specified user
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Don't need to check permissions...this only returns the page to edit the user's own profile

            // User is doing this to his own profile, by definition
            $self_edit = true;


            // ----------------------------------------
            // Store whether any OAuth providers have been configured
            $connected_oauth_resources = array();
            $has_oauth_providers = false;

            // Users should only be able to see their own connected OAuth accounts, not those belonging to somebody else
            if ( $self_edit && $this->has('hwi_oauth.security.oauth_utils') ) {
                /** @var OAuthUtils $oauth_utils */
                $oauth_utils = $this->get('hwi_oauth.security.oauth_utils');
                $resource_owners = $oauth_utils->getResourceOwners();

                if (count($resource_owners) > 0) {
                    $has_oauth_providers = true;

                    // Attempt to figure out which OAuth providers the user is already connected to
                    foreach ($user->getUserLink() as $ul) {
                        /** @var UserLink $ul */
                        if ($ul->getProviderName() !== null && $ul->getProviderId() !== null)
                            $connected_oauth_resources[] = $ul->getProviderName();
                    }
                }
            }


            // ----------------------------------------
            // Determine whether the user owns any OAuth clients
            $has_oauth_clients = false;
            $owned_clients = array();
            $site_baseurl = $this->getParameter('site_baseurl');

            if ( $self_edit && $this->has('odr.oauth_server.client_manager') ) {
                $has_oauth_clients = true;

                /** @var ClientManager $client_manager */
                $client_manager = $this->get('odr.oauth_server.client_manager');
                $owned_clients = $client_manager->getOwnedClients($user);
            }


            // ----------------------------------------
            // Create a new form to edit the user
            $form = $this->createForm(ODRUserProfileForm::class, $user, array('target_user_id' => $user->getId()));

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:user_profile.html.twig',
                    array(
                        'profile_form' => $form->createView(),
                        'current_user' => $user,
                        'target_user' => $user,
                        'self_edit' => $self_edit,

                        'has_oauth_providers' => $has_oauth_providers,
                        'connected_oauth_resources' => $connected_oauth_resources,

                        'has_oauth_clients' => $has_oauth_clients,
                        'owned_clients' => $owned_clients,
                        'site_baseurl' => $site_baseurl,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0x97f688bd;
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
     * Returns the HTML for an admin user to modify profile information for any other user
     * 
     * @param integer $user_id The database id of the user to edit.
     * @param Request $request
     * 
     * @return Response
     */
    public function editprofileAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab the specified user
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($user == null || !$user->isEnabled())
                throw new ODRNotFoundException('User');

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // If the user is a super admin, or doing this action to his own profile for some reason...
            if ( $admin->hasRole('ROLE_SUPER_ADMIN') || $admin->getId() == $user_id ) {
                // ...then permissions aren't an issue
            }
            else {
                // If user lacks the admin role, then they're not allowed to do this
                if ( !$admin->hasRole('ROLE_ADMIN') )
                    throw new ODRForbiddenException();

                // Grab permissions of both target user and admin
                $admin_permissions = $pm_service->getDatatypePermissions($admin);
                $user_permissions = $pm_service->getDatatypePermissions($user);

                $allow = false;
                foreach ($admin_permissions as $dt_id => $permission) {
                    if ( isset($permission['dt_admin']) && $permission['dt_admin'] == 1 ) {
                        // Allow this profile edit if the admin user has the "is_datatype_admin" permission and the target user has the "can_view_datatype" for the same datatype
                        // TODO - this seems dangerous...
                        if ( isset($user_permissions[$dt_id]) && isset($user_permissions[$dt_id]['dt_view']) ) {
                            $allow = true;
                            break;
                        }
                    }
                }

                // If not allowed, block access
                if (!$allow)
                    throw new ODRForbiddenException();
            }
            // --------------------

            // Store whether the user is doing this to his own profile or not
            $self_edit = false;
            if ($admin->getId() == $user_id)
                $self_edit = true;


            // ----------------------------------------
            // Store whether any OAuth providers have been configured
            $connected_oauth_resources = array();
            $has_oauth_providers = false;

            // Users should only be able to see their own connected OAuth accounts, not those belonging to somebody else
            if ( $self_edit && $this->has('hwi_oauth.security.oauth_utils') ) {
                /** @var OAuthUtils $oauth_utils */
                $oauth_utils = $this->get('hwi_oauth.security.oauth_utils');
                $resource_owners = $oauth_utils->getResourceOwners();

                if (count($resource_owners) > 0) {
                    $has_oauth_providers = true;

                    // Attempt to figure out which OAuth providers the user is already connected to
                    foreach ($user->getUserLink() as $ul) {
                        /** @var UserLink $ul */
                        if ($ul->getProviderName() !== null && $ul->getProviderId() !== null)
                            $connected_oauth_resources[] = $ul->getProviderName();
                    }
                }
            }


            // ----------------------------------------
            // Determine whether the user owns any OAuth clients
            $has_oauth_clients = false;
            $owned_clients = array();
            $site_baseurl = $this->getParameter('site_baseurl');

            if ( $self_edit && $this->has('odr.oauth_server.client_manager') ) {
                $has_oauth_clients = true;

                /** @var ClientManager $client_manager */
                $client_manager = $this->get('odr.oauth_server.client_manager');
                $owned_clients = $client_manager->getOwnedClients($user);
            }


            // ----------------------------------------
            // Create a new form to edit the user
            $form = $this->createForm(ODRUserProfileForm::class, $user, array('target_user_id' => $user->getId()));

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:user_profile.html.twig',
                    array(
                        'profile_form' => $form->createView(),
                        'current_user' => $admin,
                        'target_user' => $user,
                        'self_edit' => $self_edit,

                        'has_oauth_providers' => $has_oauth_providers,
                        'connected_oauth_resources' => $connected_oauth_resources,

                        'has_oauth_clients' => $has_oauth_clients,
                        'owned_clients' => $owned_clients,
                        'site_baseurl' => $site_baseurl,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0xb6a03520;
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
     * Wrapper function that allows a non-admin user to save changes to his own profile.
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function selfsaveprofileAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Need to get the user id out of the form to check permissions...
            $post = $request->request->all();
            if ( !isset($post['ODRUserProfileForm']) )
                throw new ODRBadRequestException();
            if ( !isset($post['ODRUserProfileForm']['user_id']) )
                throw new ODRBadRequestException();

            $user_id = intval( $post['ODRUserProfileForm']['user_id'] );

            // Grab the current user
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Only allow this if the user is modifying their own profile
            if ($user->getId() !== $user_id)
                throw new ODRBadRequestException();

            self::saveProfile($user_id, $request);
        }
        catch (\Exception $e) {
            $source = 0x4c69f197;
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
     * Wrapper function that allows an admin user to save changes to any user's profile
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function saveprofileAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Need to get the user id out of the form to check permissions...
            $post = $request->request->all();
            if ( !isset($post['ODRUserProfileForm']) )
                throw new ODRBadRequestException();
            if ( !isset($post['ODRUserProfileForm']['user_id']) )
                throw new ODRBadRequestException();

            $user_id = intval( $post['ODRUserProfileForm']['user_id'] );

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($user == null || !$user->isEnabled())
                throw new ODRNotFoundException('User');

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // If the user is a super admin, or doing this action to his own profile for some reason...
            if ( $admin->hasRole('ROLE_SUPER_ADMIN') || $admin->getId() == $user_id ) {
                // ...then permissions aren't an issue
            }
            else {
                // If user lacks the admin role, then they're not allowed to do this
                if ( !$admin->hasRole('ROLE_ADMIN') )
                    throw new ODRForbiddenException();

                // Grab permissions of both target user and admin
                $admin_permissions = $pm_service->getDatatypePermissions($admin);
                $user_permissions = $pm_service->getDatatypePermissions($user);

                $allow = false;
                foreach ($admin_permissions as $dt_id => $permission) {
                    if ( isset($permission['dt_admin']) && $permission['dt_admin'] == 1 ) {
                        // Allow this profile edit if the admin user has the "is_datatype_admin" permission and the target user has the "can_view_datatype" for the same datatype
                        // TODO - this seems dangerous...
                        if ( isset($user_permissions[$dt_id]) && isset($user_permissions[$dt_id]['dt_view']) ) {
                            $allow = true;
                            break;
                        }
                    }
                }

                // If not allowed, block access
                if (!$allow)
                    throw new ODRForbiddenException();
            }
            // --------------------

            // Save any changes to the profile
            self::saveProfile($user_id, $request);
        }
        catch (\Exception $e) {
            $source = 0xc6125a86;
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
     * Saves modifications to user profiles to the database.
     *
     * @param integer $target_user_id
     * @param Request $request
     * 
     * @throws ODRException
     */
    private function saveProfile($target_user_id, Request $request)
    {
        // Get required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $user_manager = $this->container->get('fos_user.user_manager');
        $repo_user = $em->getRepository('ODROpenRepositoryUserBundle:User');

//        $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

        /** @var ODRUser $target_user */
        $target_user = $repo_user->find($target_user_id);
        if ($target_user == null)
            throw new ODRNotFoundException('User');     // theoretically shouldn't happen

        $email = $target_user->getEmail();

        // Bind the request to a form
        $form = $this->createForm(ODRUserProfileForm::class, $target_user, array('target_user_id' => $target_user->getId()));
        $form->handleRequest($request);

        if ($form->isSubmitted()) {

            // TODO - check for additional non-password errors to throw?
            //$form->addError( new FormError('do not save') );

            // If no errors...
            if ($form->isValid()) {
                // Save changes to the user
                $target_user->setEmail($email);     // as of right now, binding the form will clear the user's email/username because that field is disabled...set the email/username back to what it was originally
                $user_manager->updateUser($target_user);
            }
            else {
                // Form validation failed
                $error_str = parent::ODR_getErrorMessages($form);
                throw new ODRException($error_str);
            }
        }
    }


    /**
     * Returns the HTML for an admin user to change another user's password
     * TODO - this is bad practice...admins shouldn't be changing other user's passwords
     * 
     * @param integer $user_id The database id of the user to edit.
     * @param Request $request
     * 
     * @return Response
     */
    public function changepasswordAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab the specified user
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var ODRUser $target_user */
            $target_user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($target_user == null || !$target_user->isEnabled())
                throw new ODRNotFoundException('User');

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // If the user is a super admin, or doing this action to his own profile for some reason...
            if ( $admin->hasRole('ROLE_SUPER_ADMIN') || $admin->getId() == $user_id ) {
                // ...then permissions aren't an issue
            }
            else {
                // If user lacks the admin role, then they're not allowed to do this
                if ( !$admin->hasRole('ROLE_ADMIN') )
                    throw new ODRForbiddenException();

                // Grab permissions of both target user and admin
                $admin_permissions = $pm_service->getDatatypePermissions($admin);
                $user_permissions = $pm_service->getDatatypePermissions($target_user);

                $allow = false;
                foreach ($admin_permissions as $dt_id => $permission) {
                    if ( isset($permission['dt_admin']) && $permission['dt_admin'] == 1 ) {
                        // Allow this profile edit if the admin user has the "is_datatype_admin" permission and the target user has the "can_view_datatype" for the same datatype
                        // TODO - this seems dangerous...
                        if ( isset($user_permissions[$dt_id]) && isset($user_permissions[$dt_id]['dt_view']) ) {
                            $allow = true;
                            break;
                        }
                    }
                }

                // If not allowed, block access
                if (!$allow)
                    throw new ODRForbiddenException();
            }
            // --------------------

            // Create a new form to edit the user
            $form = $this->createForm(ODRAdminChangePasswordForm::class, $target_user, array('target_user_id' => $target_user->getId()));

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:change_password.html.twig',
                    array(
                        'form' => $form->createView(),
                        'current_user' => $admin,
                        'target_user' => $target_user,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0x6c1fc667;
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
     * Saves changes an admin makes to another user's password
     * TODO - this is bad practice...admins shouldn't be changing other user's passwords
     *
     * @param Request $request
     *
     * @return Response
     */
    public function savepasswordAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Need to get the user id out of the form to check permissions...
            $post = $request->request->all();
            if ( !isset($post['ODRAdminChangePasswordForm']) )
                throw new ODRBadRequestException();
            if ( !isset($post['ODRAdminChangePasswordForm']['user_id']) )
                throw new ODRBadRequestException();

            $target_user_id = intval( $post['ODRAdminChangePasswordForm']['user_id'] );


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $user_manager = $this->container->get('fos_user.user_manager');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var ODRUser $target_user */
            $target_user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($target_user_id);
            if ($target_user == null)
                throw new ODRNotFoundException('User');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // If the user is a super admin, or doing this action to his own profile for some reason...
            if ( $admin->hasRole('ROLE_SUPER_ADMIN') || $admin->getId() == $target_user_id ) {
                // ...then permissions aren't an issue
            }
            else {
                // If user lacks the admin role, then they're not allowed to do this
                if ( !$admin->hasRole('ROLE_ADMIN') )
                    throw new ODRForbiddenException();

                // Grab permissions of both target user and admin
                $admin_permissions = $pm_service->getDatatypePermissions($admin);
                $user_permissions = $pm_service->getDatatypePermissions($target_user);

                $allow = false;
                foreach ($admin_permissions as $dt_id => $permission) {
                    if ( isset($permission['dt_admin']) && $permission['dt_admin'] == 1 ) {
                        // Allow this profile edit if the admin user has the "is_datatype_admin" permission and the target user has the "can_view_datatype" for the same datatype
                        // TODO - this seems dangerous...
                        if ( isset($user_permissions[$dt_id]) && isset($user_permissions[$dt_id]['dt_view']) ) {
                            $allow = true;
                            break;
                        }
                    }
                }

                // If not allowed, block access
                if (!$allow)
                    throw new ODRForbiddenException();
            }
            // --------------------

            // Bind form to user
            $form = $this->createForm(ODRAdminChangePasswordForm::class, $target_user, array('target_user_id' => $target_user->getId()));
            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                // Password fields matching is handled by the Symfony Form 'repeated' field
                // Password length and complexity is handled by the isPasswordValid() callback function in ODR\OpenRepository\UserBundle\Entity\User

                // TODO - check for additional errors to throw?
                //$form->addError( new FormError('do not save') );

                // If no errors...
                if ($form->isValid()) {
                    // Save changes to the user
                    $user_manager->updateUser($target_user);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($form);
                    throw new ODRException($error_str);
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x482172cc;
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
     * Renders and returns a list of all registered users.
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function listusersAction(Request $request)
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


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($admin_user);

            $admin_permission_count = 0;
            foreach ($datatype_permissions as $dt_id => $dt_permission) {
                if ( isset($dt_permission['dt_admin']) && $dt_permission['dt_admin'] == 1 )
                    $admin_permission_count++;
            }

            // Deny access if user does not have any 'is_datatype_admin' permissions, or if user is not admin/super admin
            if ( !$admin_user->hasRole('ROLE_ADMIN') || $admin_permission_count == 0 )
                throw new ODRForbiddenException();
            // --------------------

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser[] $user_list */
            $user_list = $user_manager->findUsers();    // twig filters out disabled users if needed

            // If the user is not a super admin, then only show users that have the 'can_view_datatype' permission for datatypes that the calling user has 'is_datatype_admin'
            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') ) {
                $tmp = array();
                foreach ($user_list as $user) {
                    // Because the calling user is not a super admin, don't add super admins to the list of users
                    if ( !$user->hasRole('ROLE_SUPER_ADMIN') ) {
                        $user_permissions = $pm_service->getDatatypePermissions($user);

                        foreach ($user_permissions as $datatype_id => $up) {
                            if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dt_admin']) && isset($up['dt_view']) ) {
                                $tmp[] = $user;
                                break;
                            }
                        }
                    }
                }

                $user_list = $tmp;
            }

            // Render the list of users
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:user_list.html.twig',
                    array(
                        'users' => $user_list,
                        'admin_user' => $admin_user
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0x4f9fcf8c;
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
     * Renders and returns HTML for modifying security roles (USER, DESIGNER, ADMIN) for a user.
     * 
     * @param Request $request
     * 
     * @return Response
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
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // --------------------

            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            $users = $user_manager->findUsers();    // twig filters out disabled users if needed

            // Prevent the admin from modifying his own role (potentially removing his own admin role)
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Determine whether the Jupyterhub role needs to be displayed
            $using_jupyterhub = false;
            if ( $this->container->hasParameter('jupyterhub_config') ) {
                $jupyterhub_config = $this->container->getParameter('jupyterhub_config');

                $using_jupyterhub = $jupyterhub_config['use_jupyterhub'];
            }

            // Render them in a list
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:manage_roles.html.twig',
                    array(
                        'users' => $users,
                        'admin_user' => $admin_user,
                        'using_jupyterhub' => $using_jupyterhub,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0xaa351c38;
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
     * Saves changes made to a user's role (user/editor/designer/admin)
     *
     * @param integer $user_id The database id of the user being modified.
     * @param string $role     Which role to grant the user.
     * @param Request $request
     * 
     * @return Response
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
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // --------------------

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $user_manager = $this->container->get('fos_user.user_manager');

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($user == null || !$user->isEnabled())
                throw new ODRNotFoundException('User');

            if (!$user->isEnabled())
                throw new ODRException('Unable to change role of a deleted User');
            if ( $user->getId() == $admin_user->getId() && $role !== 'jupyterhub' )
                throw new ODRException('Unable to change own role');
//            if ( $user->hasRole('ROLE_SUPER_ADMIN') )
//                throw new ODRException('Unable to change role of another Super-Admin');


/*
            // ----------------------------------------
            // Determine whether the Jupyterhub role needs to be dealt with
            $using_jupyterhub = false;
            if ( $this->container->hasParameter('jupyterhub_config') ) {
                $jupyterhub_config = $this->container->getParameter('jupyterhub_config');

                $using_jupyterhub = $jupyterhub_config['use_jupyterhub'];
            }

            if ($using_jupyterhub && $role == 'jupyterhub') {
                // Unlike the other roles, this one is a toggle
                if ( $user->hasRole('ROLE_JUPYTERHUB_USER') )
                    $user->removeRole('ROLE_JUPYTERHUB_USER');
                else
                    $user->addRole('ROLE_JUPYTERHUB_USER');
            }
*/

            // ----------------------------------------
            // Users are only allowed to have one of the ODR-specific roles at once
            if ($role == 'user') {
                // User got demoted to the regular user group
                $user->addRole('ROLE_USER');
                $user->removeRole('ROLE_ADMIN');
                $user->removeRole('ROLE_SUPER_ADMIN');
            }
            else if ($role == 'admin') {
                // User got added to the admin group
                $user->addRole('ROLE_USER');
                $user->addRole('ROLE_ADMIN');
                $user->removeRole('ROLE_SUPER_ADMIN');
            }
            else if ($role == 'sadmin') {
                // User got added to the super-admin group
                $user->addRole('ROLE_USER');
                $user->addRole('ROLE_ADMIN');
                $user->addRole('ROLE_SUPER_ADMIN');

                // ----------------------------------------
                // Remove the user from all the non-admin groups they're currently a member of...
                // NOTE - doing it this way because doctrine doesn't support multi-table updates
                $query_str = '
                    UPDATE odr_user_group AS ug, odr_group AS g
                    SET ug.deletedAt = NOW(), ug.deletedBy = :admin_user_id
                    WHERE ug.group_id = g.id
                    AND ug.user_id = :user_id AND g.purpose IN ("", "edit_all", "view_all", "view_only")
                    AND ug.deletedAt IS NULL AND g.deletedAt IS NULL';
                $parameters = array('admin_user_id' => $admin_user->getId(), 'user_id' => $user->getId());

                $conn = $em->getConnection();
                $rowsAffected = $conn->executeUpdate($query_str, $parameters);

                // ...so they can be added to all existing "admin" default groups instead
                /** @var Group[] $admin_groups */
                $admin_groups = $em->getRepository('ODRAdminBundle:Group')->findBy( array('purpose' => 'admin') );
                if ( count($admin_groups) > 0 ) {
                    // Build a single INSERT INTO query to add this user to all existing "admin" default groups
                    // A unique constraint placed upon (user_id, group_id) in the database will prevent duplicate entries
                    $query_str = '
                        INSERT IGNORE INTO odr_user_group (user_id, group_id, created, createdBy)
                        VALUES ';

                    foreach ($admin_groups as $admin_group)
                        $query_str .= '("'.$user->getId().'", "'.$admin_group->getId().'", NOW(), "'.$admin_user->getId().'"),'."\n";
                    $query_str = substr($query_str, 0, -2).';';

                    $conn = $em->getConnection();
                    $rowsAffected = $conn->executeUpdate($query_str);
                }


                // ----------------------------------------
                // Delete any cached permissions for the affected user
                /** @var CacheService $cache_service*/
                $cache_service = $this->container->get('odr.cache_service');
                $cache_service->delete('user_'.$user_id.'_permissions');
            }

            $user_manager->updateUser($user);
        }
        catch (\Exception $e) {
            $source = 0xee335a24;
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
     * Deletes a User.
     * 
     * @param integer $user_id The database id of the user being deleted.
     * @param Request $request
     * 
     * @return Response
     */
    public function deleteuserAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // --------------------

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $user_manager = $this->container->get('fos_user.user_manager');

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($user == null || !$user->isEnabled())
                throw new ODRNotFoundException('User');

            // Prevent super-admins from being deleted?
//            if ( $user->hasRole('ROLE_SUPER_ADMIN') )
//                throw new ODRException('Unable to delete another Super-Admin user');

            // Remove user from all the groups they're currently a member of
            // NOTE - using $em->getConnection()->executeUpdate(...) because
            //  $em->createQuery(...)->execute() doesn't support multi-table updates
            $query_str =
               'UPDATE odr_user_group AS ug, odr_group AS g
                SET ug.deletedAt = NOW(), ug.deletedBy = :admin_user_id
                WHERE ug.group_id = g.id AND ug.user_id = :user_id
                AND ug.deletedAt IS NULL AND g.deletedAt IS NULL';
            $parameters = array('admin_user_id' => $admin_user->getId(), 'user_id' => $user->getId());

            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query_str, $parameters);

            // Demote the user to the regular user group
            $user->addRole('ROLE_USER');
            $user->removeRole('ROLE_ADMIN');
            $user->removeRole('ROLE_SUPER_ADMIN');

            // Delete the user's cached permissions
            $cache_service->delete('user_'.$user_id.'_permissions');


            // This may not be the right way to do it...
            $user->setEnabled(0);
            $user_manager->updateUser($user);


            // ----------------------------------------
            // Update the user list
            $users = $user_manager->findUsers();    // twig filters out disabled users if needed

            // Generate a redirect to the user list
            $router = $this->get('router');
            $return['d'] = array(
                'url' => $router->generate('odr_user_list')
            );
        }
        catch (\Exception $e) {
            $source = 0x750059fa;
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
     * Undeletes a User.
     * 
     * @param integer $user_id The database id of the user being reinstated.
     * @param Request $request
     * 
     * @return Response
     */
    public function undeleteuserAction($user_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$admin_user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // --------------------

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $user_manager = $this->container->get('fos_user.user_manager');

            /** @var ODRUser $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($user == null || $user->isEnabled())
                throw new ODRNotFoundException('User');

            // This may not be the right way to do it...
            $user->setEnabled(1);
            $user_manager->updateUser($user);


            // ----------------------------------------
            // Update the user list
            $users = $user_manager->findUsers();    // twig filters out disabled users if needed

            // Generate a redirect to the user list
            $router = $this->get('router');
            $return['d'] = array(
                'url' => $router->generate('odr_user_list')
            );
        }
        catch (\Exception $e) {
            $source = 0x79443334;
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
     * Changes the number of Datarecords displayed per ShortResults page.  This will also end up
     * changing the session variable the datatables plugin uses to store page length.
     *
     * A page currently using the datatables plugin uses TextResultsController::datatablesrowrequestAction()
     * to change its own length.
     *
     * @param integer $length  How many Datarecords to display on a page.
     * @param Request $request
     *
     * @return Response
     */
    public function pagelengthAction($length, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');

            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];

            if ($odr_tab_id !== '') {
                // Store the change to this tab's page_length in the session
                $odr_tab_service->setPageLength($odr_tab_id, $length);
            }
        }
        catch (\Exception $e) {
            $source = 0x1cfad2a4;
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
     * Typically, if a user has the permission to edit datarecords for a datatype, then they're
     * permitted to edit all datarecords they can view.  However, when a user has a datarecord
     * restriction, then usually there's a difference between the list of datarecords the user can
     * view and the list of datarecords they can edit.
     *
     * When a user with a datarecord restriction on a datatype does a search on that datatype, by
     * default ODR hides the datarecords they can't edit.  This controller action allows the user
     * to invert that setting, and store the preference in a browser cookie.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function toggleshoweditableAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $key = '';
        $value = '';

        try {
            // Pull the tab id from the current request
            $post = $request->request->all();
            if ( !isset($post['odr_tab_id']) || !isset($post['datatype_id']) )
                throw new ODRBadRequestException('invalid form');

            $odr_tab_id = $post['odr_tab_id'];
            $datatype_id = $post['datatype_id'];

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $cookies = $request->cookies;

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( is_null($datatype) )
                throw new ODRNotFoundException('Datatype');


            // Load the current value of the cookie
            $key = 'datatype_'.$datatype->getId().'_editable_only';

            // Can't use boolean here it seems
            $display = 1;
            if ( $cookies->has($key) )
                $display = intval( $cookies->get($key) );

            // Invert the value stored
            if ($display === 1)
                $value = 0;
            else
                $value = 1;

            // The value is stored back in the cookie after the response is created below
        }
        catch (\Exception $e) {
            $source = 0xbf591415;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->setCookie(new Cookie($key, $value));
        return $response;
    }


    /**
     * Loads the wrapper for selecting which theme the admin user wants to view through the eyes of the given user.
     *
     * @param integer $user_id
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function vieweffectivepermissionsAction($user_id, $datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var ODRUser $target_user */
            $target_user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($target_user == null || !$target_user->isEnabled())
                throw new ODRNotFoundException('User');

            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ( !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('Only available for top-level Datatypes');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require admin user to have at least admin role to do this...
            if ( $admin_user->hasRole('ROLE_ADMIN') ) {
                // Grab permissions of both target user and admin
                $datatype_permissions = $pm_service->getDatatypePermissions($admin_user);

                // If requesting user isn't an admin for this datatype, don't allow them to set datafield permissions for other users
                if ( !isset($datatype_permissions[$datatype_id]) || !isset($datatype_permissions[$datatype_id]['dt_admin']) )
                    throw new ODRForbiddenException();
            }
            else {
                throw new ODRForbiddenException();
            }
            // --------------------


            // Load permissions of target user
            $datatype_permissions = $pm_service->getDatatypePermissions($target_user);
//print '<pre>'.print_r($datatype_permissions, true).'</pre>'; exit();

            // Also want all themes for this datatype
            $theme_list = $datatype->getThemes();


            // Render and return the required HTML
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ODRUser:view_wrapper.html.twig',
                    array(
                        'target_user' => $target_user,
                        'datatype_permissions' => $datatype_permissions,

                        'datatype' => $datatype,
                        'theme_list' => $theme_list,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x8f12f6cb;
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
     * Loads the specified theme and filters by the specified user's permissions.
     *
     * @param integer $user_id
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function viewpermissionsresultAction($user_id, $theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var ODRUser $target_user */
            $target_user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
            if ($target_user == null || !$target_user->isEnabled())
                throw new ODRNotFoundException('User');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ( !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('Only available for top-level Datatypes');

            $top_level_themes = $theme_service->getTopLevelThemes();
            if ( !in_array($theme_id, $top_level_themes) )
                throw new ODRBadRequestException('Only available for top-level Themes');


            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require admin user to have at least admin role to do this...
            if ( !$admin_user->hasRole('ROLE_ADMIN') )
                throw new ODRForbiddenException();

            if ( !$pm_service->isDatatypeAdmin($admin_user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Render the datatype from the target user's point of view
            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->get('odr.render_service');
            $page_html = $odr_render_service->getViewAsUserHTML($admin_user, $target_user, $theme);

            $return['d'] = array(
                'html' => $page_html
            );
        }
        catch (\Exception $e) {
            $source = 0x1206f648;
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
     * Allows a user to set their session or default theme.
     *
     * @param integer $datatype_id
     * @param integer $theme_id
     * @param bool $persist If true, then save this choice to the database
     * @param Request $request
     *
     * @return Response
     */
    public function applythemeAction($datatype_id, $theme_id, $persist = false, Request $request)
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
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            if ($theme->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException('Theme does not match Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // If the user can't view the datatype, then they shouldn't be setting themes for it
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            if ($user === 'anon.') {
                // If the theme isn't shared, an anon user can't use it
                if ( !$theme->isShared() )
                    throw new ODRForbiddenException();

                // Otherwise, set it as the session theme
                $theme_service->setSessionTheme($datatype->getId(), $theme);

                // Silently ignore attempts to save this preference to the database
            }
            else {
                // If the theme isn't shared, or the user doesn't own the theme, then they can't use it
                if ( !$theme->isShared() && $theme->getCreatedBy()->getId() !== $user->getId() )
                    throw new ODRForbiddenException();

                // Otherwise, set it as the session theme
                $theme_service->setSessionTheme($datatype->getId(), $theme);

                // If the user indicated they wanted to save this as their default, do so
                if ($persist)
                    $theme_service->setUserDefaultTheme($user, $theme);
            }

        }
        catch (\Exception $e) {
            $source = 0x1aeac909;
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
