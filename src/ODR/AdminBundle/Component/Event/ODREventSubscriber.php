<?php

/**
 * Open Data Repository Data Publisher
 * ODR Event Subscriber
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Rather than have render plugins implement their own event subscription and listener classes, it
 * makes more sense for ODR to listen for any of its dispatched events and then relay them to the
 * render plugin implementations if they should be doing something.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


class ODREventSubscriber implements EventSubscriberInterface
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
     * @var EntityManager
     */
    private $em;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * ODREventSubscriber constructor.
     *
     * @param string $environment
     * @param ContainerInterface $container
     * @param EntityManager $entity_manager
     * @param Logger $logger
     */
    public function __construct(
        string $environment,
        ContainerInterface $container,
        EntityManager $entity_manager,
        Logger $logger
    ) {
        $this->env = $environment;
        $this->container = $container;
        $this->em = $entity_manager;
        $this->logger = $logger;
    }


    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            DatarecordCreatedEvent::NAME => 'onDatarecordCreated',
            FileDeletedEvent::NAME => 'onFileDeleted',
            FilePreEncryptEvent::NAME => 'onFilePreEncrypt',
            PluginAttachEvent::NAME => 'onPluginAttach',
            PluginOptionsChangedEvent::NAME => 'onPluginOptionsChanged',
            PluginPreRemoveEvent::NAME => 'onPluginPreRemove',
            PostUpdateEvent::NAME => 'onPostUpdate',
        );
    }


    /**
     * Determines whether any render plugins need to respond to the given event, and determines
     * which function to call inside the render plugin definition file if so.
     *
     * Don't really need to cache this, it seems.
     *
     * @param string $event_name
     * @param DataType|null $datatype
     * @param DataFields|null $datafield
     * @param string|null $plugin_classname
     *
     * @return string[]
     */
    private function isEventRelevant($event_name, $datatype, $datafield, $plugin_classname = null)
    {
        // TODO - cache this?  it would only need to change when a renderPluginInstance gets added/removed

        // Need to cut the namespace out of $event_name, or it won't match the database
        $event_name = substr($event_name, strrpos($event_name, "\\") + 1);

        // Either of these could be null...
        $datatype_id = 0;
        if ( !is_null($datatype) )
            $datatype_id = $datatype->getId();
        $datafield_id = 0;
        if ( !is_null($datafield) )
            $datafield_id = $datafield->getId();

        $query = null;
        if ( is_null($plugin_classname) ) {
            // Need to determine which of the render plugins currently in use listen to the given event
            $query = $this->em->createQuery(
               'SELECT DISTINCT(rp.pluginClassName) AS pluginClassName, rpe.eventCallable
                FROM ODRAdminBundle:RenderPluginEvents rpe
                JOIN ODRAdminBundle:RenderPlugin rp WITH rpe.renderPlugin = rp
                LEFT JOIN ODRAdminBundle:RenderPluginInstance rpi WITH rpi.renderPlugin = rp
                WHERE rpe.eventName = :event_name
                AND (rpi.dataType = :datatype_id OR rpi.dataField = :datafield_id)
                AND rp.deletedAt IS NULL AND rpe.deletedAt IS NULL AND rpi.deletedAt IS NULL'
            )->setParameters(
                array(
                    'event_name' => $event_name,
                    'datatype_id' => $datatype_id,
                    'datafield_id' => $datafield_id,
                )
            );
        }
        else {
            // If a plugin classname was passed in, then the event should only be run on that
            //  specific plugin...provided that it's actually in use
            $query = $this->em->createQuery(
               'SELECT DISTINCT(rp.pluginClassName) AS pluginClassName, rpe.eventCallable
                FROM ODRAdminBundle:RenderPluginEvents rpe
                JOIN ODRAdminBundle:RenderPlugin rp WITH rpe.renderPlugin = rp
                LEFT JOIN ODRAdminBundle:RenderPluginInstance rpi WITH rpi.renderPlugin = rp
                WHERE rpe.eventName = :event_name AND rp.pluginClassName = :plugin_classname
                AND (rpi.dataType = :datatype_id OR rpi.dataField = :datafield_id)
                AND rp.deletedAt IS NULL AND rpe.deletedAt IS NULL AND rpi.deletedAt IS NULL'
            )->setParameters(
                array(
                    'event_name' => $event_name,
                    'plugin_classname' => $plugin_classname,
                    'datatype_id' => $datatype_id,
                    'datafield_id' => $datafield_id,
                )
            );
        }
        $results = $query->getArrayResult();


        // Need two pieces of info from the previous query...which plugins listen to the event, and
        //  which function to call for each of those plugins
        $ret = array();
        foreach ($results as $result)
            $ret[ $result['pluginClassName'] ] = $result['eventCallable'];

        return $ret;
    }


    /**
     * Loads render plugins that need to respond to the given event, and executes the relevant
     * function in those render plugins.
     *
     * @param array $relevant_plugins @see self::isEventRelevant()
     * @param ODREventInterface $event
     */
    private function relayEvent($relevant_plugins, $event)
    {
        foreach ($relevant_plugins as $plugin_classname => $event_callable) {
            // Need to load the plugin via the container...
            $render_plugin = $this->container->get($plugin_classname);
            $listener = array(
                0 => $render_plugin,
                1 => $event_callable
            );

            try {
                // ...so the relevant function in the plugin can get called
                \call_user_func($listener, $event, $event::NAME, $this);

                // These calls are each wrapped inside their own try/catch block so that an error
                //  thrown by one render plugin hopefully won't block another render plugin from
                //  also responding to the event
            }
            catch (\Throwable $e) {
                if ( $this->env !== 'dev' ) {
                    // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
                    //  any additional subscribers won't run either
                    $base_info = array(self::class, $plugin_classname, $event_callable);
                    $event_info = $event->getErrorInfo();
                    $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
                }
                else {
                    // ...don't particularly want to rethrow the error since it'll interrupt
                    //  everything downstream of the event (such as file encryption...), but having
                    //  the error disappear is less ideal on the dev environment...
                    throw new ODRException($plugin_classname.' handling '.$event->getEventName().': '.$e->getMessage(), 500, 0x03e8b958);
                }
            }
        }
    }


    /**
     * Handles dispatched DatarecordCreated events
     *
     * @param DatarecordCreatedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatarecordCreated(DatarecordCreatedEvent $event)
    {
        try {
            // Determine whether any render plugins should run something in response to this event
            $datarecord = $event->getDatarecord();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datarecord->getDataType(), null);
            if ( !empty($relevant_plugins) ) {
                // If so, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }
        }
        catch (\Throwable $e) {
            if ( $this->env !== 'dev' ) {
                // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
                //  any additional subscribers won't run either
                $base_info = array(self::class);
                $event_info = $event->getErrorInfo();
                $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
            }
            else {
                // ...don't particularly want to rethrow the error since it'll interrupt everything
                //  downstream of the event (such as file encryption...), but having the error
                //  disappear is less ideal on the dev environment...
                throw $e;
            }
        }
    }


    /**
     * Handles dispached FileDeleted events
     *
     * @param FileDeletedEvent $event
     *
     * @throws \Throwable
     */
    public function onFileDeleted(FileDeletedEvent $event)
    {
        try {
            // Determine whether any render plugins should run something in response to this event
            $datafield = $event->getDatafield();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datafield->getDataType(), $datafield);
            if ( !empty($relevant_plugins) ) {
                // If so, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }
        }
        catch (\Throwable $e) {
            if ( $this->env !== 'dev' ) {
                // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
                //  any additional subscribers won't run either
                $base_info = array(self::class);
                $event_info = $event->getErrorInfo();
                $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
            }
            else {
                // ...don't particularly want to rethrow the error since it'll interrupt everything
                //  downstream of the event (such as file encryption...), but having the error
                //  disappear is less ideal on the dev environment...
                throw $e;
            }
        }
    }


    /**
     * Handles dispatched FilePreEncrypt events
     *
     * @param FilePreEncryptEvent $event
     *
     * @throws \Throwable
     */
    public function onFilePreEncrypt(FilePreEncryptEvent $event)
    {
        try {
            // Determine whether any render plugins should run something in response to this event
            $datafield = $event->getDatafield();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datafield->getDataType(), $datafield);
            if ( !empty($relevant_plugins) ) {
                // If so, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }
        }
        catch (\Throwable $e) {
            if ( $this->env !== 'dev' ) {
                // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
                //  any additional subscribers won't run either
                $base_info = array(self::class);
                $event_info = $event->getErrorInfo();
                $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
            }
            else {
                // ...don't particularly want to rethrow the error since it'll interrupt everything
                //  downstream of the event (such as file encryption...), but having the error
                //  disappear is less ideal on the dev environment...
                throw $e;
            }
        }
    }


    /**
     * Handles dispatched PluginAttach events
     *
     * @param PluginAttachEvent $event
     *
     * @throws \Throwable
     */
    public function onPluginAttach(PluginAttachEvent $event)
    {
        try {
            // Determine whether any render plugins should run something in response to this event
            $rpi = $event->getRenderPluginInstance();
            $rp = $rpi->getRenderPlugin();
            $datafield = $rpi->getDataField();
            $datatype = $rpi->getDataType();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, $datafield, $rp->getPluginClassName());
            if ( !empty($relevant_plugins) ) {
                // If any plugins remain, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }
        }
        catch (\Throwable $e) {
            if ( $this->env !== 'dev' ) {
                // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
                //  any additional subscribers won't run either
                $base_info = array(self::class);
                $event_info = $event->getErrorInfo();
                $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
            }
            else {
                // ...don't particularly want to rethrow the error since it'll interrupt everything
                //  downstream of the event (such as file encryption...), but having the error
                //  disappear is less ideal on the dev environment...
                throw $e;
            }
        }
    }


    /**
     * Handles dispatched PluginOptionsChanged events
     *
     * @param PluginOptionsChangedEvent $event
     *
     * @throws \Throwable
     */
    public function onPluginOptionsChanged(PluginOptionsChangedEvent $event)
    {
        try {
            // Determine whether any render plugins should run something in response to this event
            $rpi = $event->getRenderPluginInstance();
            $rp = $rpi->getRenderPlugin();
            $datafield = $rpi->getDataField();
            $datatype = $rpi->getDataType();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, $datafield, $rp->getPluginClassName());
            if ( !empty($relevant_plugins) ) {
                // If any plugins remain, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }
        }
        catch (\Throwable $e) {
            if ( $this->env !== 'dev' ) {
                // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
                //  any additional subscribers won't run either
                $base_info = array(self::class);
                $event_info = $event->getErrorInfo();
                $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
            }
            else {
                // ...don't particularly want to rethrow the error since it'll interrupt everything
                //  downstream of the event (such as file encryption...), but having the error
                //  disappear is less ideal on the dev environment...
                throw $e;
            }
        }
    }


    /**
     * Handles dispatched PluginPreRemove events
     *
     * @param PluginPreRemoveEvent $event
     *
     * @throws \Throwable
     */
    public function onPluginPreRemove(PluginPreRemoveEvent $event)
    {
        try {
            // Determine whether any render plugins should run something in response to this event
            $rpi = $event->getRenderPluginInstance();
            $rp = $rpi->getRenderPlugin();
            $datafield = $rpi->getDataField();
            $datatype = $rpi->getDataType();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, $datafield, $rp->getPluginClassName());
            if ( !empty($relevant_plugins) ) {
                // If any plugins remain, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }
        }
        catch (\Throwable $e) {
            if ( $this->env !== 'dev' ) {
                // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
                //  any additional subscribers won't run either
                $base_info = array(self::class);
                $event_info = $event->getErrorInfo();
                $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
            }
            else {
                // ...don't particularly want to rethrow the error since it'll interrupt everything
                //  downstream of the event (such as file encryption...), but having the error
                //  disappear is less ideal on the dev environment...
                throw $e;
            }
        }
    }


    /**
     * Handles dispatched PostUpdate events
     *
     * @param PostUpdateEvent $event
     *
     * @throws \Throwable
     */
    public function onPostUpdate(PostUpdateEvent $event)
    {
        try {
            // TODO - technically, there's a chance for infinite recursion...a change to datafield A
            // TODO -  triggers a change to datafield B, which can trigger a change to datafield A

            // TODO - is there any method to completely prevent this recursion from inside the event?

            // Determine whether any render plugins should run something in response to this event
            $storage_entity = $event->getStorageEntity();
            $datafield = $storage_entity->getDataField();
            $datatype = $datafield->getDataType();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, $datafield);
            if ( !empty($relevant_plugins) ) {
                // If any plugins remain, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }
        }
        catch (\Throwable $e) {
            if ( $this->env !== 'dev' ) {
                // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
                //  any additional subscribers won't run either
                $base_info = array(self::class);
                $event_info = $event->getErrorInfo();
                $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
            }
            else {
                // ...don't particularly want to rethrow the error since it'll interrupt everything
                //  downstream of the event (such as file encryption...), but having the error
                //  disappear is less ideal on the dev environment...
                throw $e;
            }
        }
    }
}
