<?php

// file: src/Acme/DemoBundle/Listener/DoctrineExtensionListener.php

namespace ODR\AdminBundle\Listener;

use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class DoctrineExtensionListener implements ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * TODO: short description.
     * 
     * @param ContainerInterface $container Optional, defaults to null. 
     * 
     * @return TODO
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * TODO: short description.
     * 
     * @param GetResponseEvent $event 
     * 
     * @return TODO
     */
    public function onLateKernelRequest(GetResponseEvent $event)
    {
        $translatable = $this->container->get('gedmo.listener.translatable');
        $translatable->setTranslatableLocale($event->getRequest()->getLocale());
    }

    /**
     * TODO: short description.
     * 
     * @param GetResponseEvent $event 
     * 
     * @return TODO
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
//        $securityContext = $this->container->get('security.context', ContainerInterface::NULL_ON_INVALID_REFERENCE);
//        if (null !== $securityContext && null !== $securityContext->getToken() && $securityContext->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
//            $loggable = $this->container->get('gedmo.listener.loggable');
//            $loggable->setUsername($securityContext->getToken()->getUsername());
//        }
    }
}
