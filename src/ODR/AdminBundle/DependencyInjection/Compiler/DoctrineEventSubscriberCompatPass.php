<?php

/**
 * Open Data Repository Data Publisher
 * DoctrineEventSubscriberCompatPass
 *
 * Symfony 7's doctrine-bridge dropped support for the "doctrine.event_subscriber" tag (Doctrine
 * deprecated EventSubscriber); its RegisterEventListenersAndSubscribersPass now only wires
 * "doctrine.event_listener" tags. ODR's gedmo listeners (in doctrine_extensions.yml) are still
 * tagged as subscribers, so they stopped registering with the Doctrine EventManager -- which broke
 * every query (the enabled SoftDeleteable filter throws "Listener ... was not added").
 *
 * This pass bridges the gap: for every service still tagged "doctrine.event_subscriber", it reads
 * the listener's getSubscribedEvents() and adds an equivalent "doctrine.event_listener" tag per
 * event (preserving the connection), then drops the obsolete subscriber tag. gedmo listeners name
 * their methods after the events, so the event_listener wiring dispatches correctly.
 */

namespace ODR\AdminBundle\DependencyInjection\Compiler;

use Doctrine\Common\EventSubscriber;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class DoctrineEventSubscriberCompatPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('doctrine.event_subscriber') as $id => $tags) {
            $definition = $container->getDefinition($id);

            $class = $container->getParameterBag()->resolveValue($definition->getClass());
            if (!$class || !is_a($class, EventSubscriber::class, true)) {
                continue;
            }

            // getSubscribedEvents() is an instance method; gedmo listeners construct with no
            // required arguments, so a throwaway instance is safe to read the event list from.
            $events = (new $class())->getSubscribedEvents();

            foreach ($tags as $tagAttributes) {
                foreach ($events as $event) {
                    $listenerTag = ['event' => $event];
                    if (isset($tagAttributes['connection'])) {
                        $listenerTag['connection'] = $tagAttributes['connection'];
                    }
                    if (isset($tagAttributes['priority'])) {
                        $listenerTag['priority'] = $tagAttributes['priority'];
                    }
                    $definition->addTag('doctrine.event_listener', $listenerTag);
                }
            }

            $definition->clearTag('doctrine.event_subscriber');
        }
    }
}
