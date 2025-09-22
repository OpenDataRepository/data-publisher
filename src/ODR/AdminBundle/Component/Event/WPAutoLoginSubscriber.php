<?php

// src/EventSubscriber/TokenSubscriber.php
namespace ODR\AdminBundle\Component\Event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use FOS\UserBundle\Doctrine\UserManager;
// use FOS\UserBundle\Model\UserManagerInterface;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\AnonymousToken;

class WPAutoLoginSubscriber implements EventSubscriberInterface
{
    /**
     * @var string
     */
    private $env;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var UserManager
     */
    private $user_manager;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * ODREventSubscriber constructor.
     *
     * @param string $environment
     * @param ContainerInterface $container
     * @param UserManager $user_manager
     * @param Logger $logger
     */
    public function __construct(
        string $environment,
        ContainerInterface $container,
        UserManager $user_manager,
        Logger $logger
    ) {
        $this->env = $environment;
        $this->container = $container;
        $this->user_manager = $user_manager;
        $this->logger = $logger;
    }


    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::CONTROLLER => [
                ['onKernelControllerPre', 10],
                ['onKernelControllerPost', -10],
            ],
        );
    }

    /**
     * Handles the pre-controller event in the Symfony HTTP kernel lifecycle.
     * This method primarily facilitates integration with a WordPress instance,
     * enabling user-related actions such as synchronizing, creating, updating,
     * logging in, or logging out of users based on event data and environment
     * variables.
     *
     * @param FilterControllerEvent $event The event triggered before the controller is executed,
     *                                     carrying the current HTTP request and controller information.
     * @return void
     */
    public function onKernelControllerPre(FilterControllerEvent $event)
    {
        $controller_array = $event->getController();
        $controller = $controller_array[0];
        $controller->integrated_user = '';

        // print "WP ODR Kernel Event Handler (WOKEH) - Fired<br />";

        // Check for Wordpress Integration
        if($this->container->getParameter('odr_wordpress_integrated')) {

            // print "WOKEH - WP Integrated<br />";
            $is_admin_action = getenv("WORDPRESS_ODR_ADMIN_ACTION");
            $odr_wordpress_create_user = getenv("WORDPRESS_CREATE_USER");
            $odr_wordpress_update_user = getenv("WORDPRESS_UPDATE_USER");
            $odr_wordpress_user = getenv("WORDPRESS_USER");
            $odr_wordpress_user_old_email = getenv("WORDPRESS_USER_OLD_EMAIL");
            $odr_wordpress_user_first_name = getenv("WORDPRESS_USER_FIRST_NAME");
            $odr_wordpress_user_last_name = getenv("WORDPRESS_USER_LAST_NAME");

            // Must be designated an admin action to create a user
            if($is_admin_action == 'true') {
                // Update user meta (first name/last name)
                if($odr_wordpress_update_user == 'true') {
                    /** @var ODRUser $user */
                    $user = $this->user_manager->findUserByEmail($odr_wordpress_user);

                    if($user) {
                        $user->setFirstName($odr_wordpress_user_first_name);
                        $user->setLastName($odr_wordpress_user_last_name);
                        $this->user_manager->updateUser($user);

                        set_transient( 'odr-admin-notice-user-updated', true, 5 );
                    }
                    else {
                        set_transient( 'odr-admin-notice-error', true, 5 );
                    }
                }


                    // Creates a new user - should only be called when administrator
                // Utillize $odr_wordpress_user to create user
                if($odr_wordpress_create_user == 'true') {
                    // print "WOKEH - Create User<br />";

                    // Check if user exists
                    /** @var ODRUser $user */
                    $user = $this->user_manager->findUserByEmail($odr_wordpress_user);

                    if ($user) {
                        // A user with this email already exists.
                        // Prompt user to update permissions
                        set_transient( 'odr-admin-notice-user-found', true, 5 );
                    }
                    else {
                        // User not found - create user
                        // Create a new user with this email & set a random password

                        $user = $this->user_manager->createUser();
                        // $user->setUsername($odr_wordpress_user_first_name . ' ' . $odr_wordpress_user_last_name);
                        $user->setFirstName($odr_wordpress_user_first_name);
                        $user->setLastName($odr_wordpress_user_last_name);
                        $user->setEmail($odr_wordpress_user);
                        $user->setPlainPassword(random_bytes(8));
                        $user->setRoles(array('ROLE_USER'));
                        $user->setEnabled(1);
                        $this->user_manager->updateUser($user);

                        set_transient( 'odr-admin-notice-user-created', true, 5 );
                    }
                }
            }
            else {
                // User email updates only trigger when the user activates their email
                // TODO determine if this causes an issue for the admin
                if(!empty($odr_wordpress_user_old_email)) {
                    // Update user with new email
                    // print "WOKEH - Update User: " . $odr_wordpress_user_old_email . "<br />";
                    // Check if new email exists
                    /** @var ODRUser $user */
                    $existing_user = $this->user_manager->findUserByEmail($odr_wordpress_user_old_email);
                    $user = $this->user_manager->findUserByEmail($odr_wordpress_user);
                    if ($user) {
                        // Account already exists
                        // Ownership proven due to email confirmation
                        set_transient( 'odr-admin-notice-user-updated-email-exists', true, 5 );
                        // Do nothing
                    }
                    else if($existing_user && $existing_user->getEmail() != $odr_wordpress_user) {
                        // Update user
                        $existing_user->setEmail($odr_wordpress_user);
                        $this->user_manager->updateUser($existing_user);
                        // Show data synchronized success?
                        set_transient( 'odr-admin-notice-user-updated-email', true, 5 );
                    }
                    else {
                        set_transient( 'odr-admin-notice-error', true, 5 );
                    }
                }

                // Login or logout user (non-admin action)
                // If a change to email was made above, ann email should exist at this point
                if (!empty($odr_wordpress_user)) {
                    /** @var ODRUser $user */
                    $user = $this->user_manager->findUserByEmail($odr_wordpress_user);
                    if($user) {
                        // var_dump($user, true);exit();
                        $controller->integrated_user = $user;
                        $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
                        $this->container->get('security.token_storage')->setToken($token);
                    }
                }
                else {
                    // print "WOKEH - Logout User<br />";exit();
                    // TODO Auto-logout user if no odr_wordpress_user is passed...
                    // $this->container->get('security.token_storage')->setToken(null);
                    // $user = 'anon.';
                    // $token = new AnonymousToken($user, '', []);
                    // $token = new AnonymousToken($this->secret, new AnonymousUser(), array());
                    // $this->container->authenticate($token);
                    // $this->container->get('security.token_storage')->setToken($token);
                }
            }
        }
    }

    public function onKernelControllerPost(FilterControllerEvent $event)
    {
        // ...
    }

}