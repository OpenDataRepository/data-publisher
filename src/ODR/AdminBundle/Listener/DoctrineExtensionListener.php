<?php

// file: src/Acme/DemoBundle/Listener/DoctrineExtensionListener.php

namespace ODR\AdminBundle\Listener;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Listens for kernel.request to wire up the gedmo translatable locale + loggable username.
 *
 * Symfony 7 removed ContainerAwareInterface/Trait; this listener keeps its own setContainer() +
 * $container (the full container is injected explicitly via doctrine_extensions.yml), so it no
 * longer implements the removed interface.
 */
class DoctrineExtensionListener
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
    public function setContainer(?ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * TODO: short description.
     *
     * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
     *
     * @return TODO
     */
    public function onLateKernelRequest(\Symfony\Component\HttpKernel\Event\RequestEvent $event)
    {
        $translatable = $this->container->get('gedmo.listener.translatable');
        $translatable->setTranslatableLocale($event->getRequest()->getLocale());
    }

    /**
     * TODO: short description.
     *
     * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
     *
     * @return TODO
     */
    public function onKernelRequest(\Symfony\Component\HttpKernel\Event\RequestEvent $event)
    {
//        $securityContext = $this->container->get('security.context', ContainerInterface::NULL_ON_INVALID_REFERENCE);
//        if (null !== $securityContext && null !== $securityContext->getToken() && $securityContext->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
//            $loggable = $this->container->get('gedmo.listener.loggable');
//            $loggable->setUsername($securityContext->getToken()->getUsername());
//        }
    }
}
