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

    public function onKernelControllerPre(FilterControllerEvent $event)
    {
        $controller_array = $event->getController();
        $controller = $controller_array[0];
        $controller->integrated_user = '';

        // Check for Wordpress Integration
        if($this->container->getParameter('odr_wordpress_integrated')) {
            $odr_wordpress_user = getenv("WORDPRESS_USER");
            if ($odr_wordpress_user) {
                // print $odr_wordpress_user . ' ';
                /** @var ODRUser $user */
                $user = $this->user_manager->findUserByEmail($odr_wordpress_user);
                $controller->integrated_user = $user;

                // $user = $this->container->get('security.token_storage')->getToken()->getUser();

                $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
                $this->container->get('security.token_storage')->setToken($token);

                // $retrieved_token = $this->container->get('security.token_storage')->getToken();
                // $this->container->get('security.login_manager')->loginUser('main', $user);
            }
        }
    }

    public function onKernelControllerPost(FilterControllerEvent $event)
    {
        // ...
    }

}