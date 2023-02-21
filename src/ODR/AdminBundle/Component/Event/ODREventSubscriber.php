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
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
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
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var SearchService
     */
    private $search_service;

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
     * @param CacheService $cacheService
     * @param SearchService $search_service
     * @param Logger $logger
     */
    public function __construct(
        string $environment,
        ContainerInterface $container,
        EntityManager $entity_manager,
        CacheService $cache_service,
        SearchService $search_service,
        Logger $logger
    ) {
        $this->env = $environment;
        $this->container = $container;
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->search_service = $search_service;
        $this->logger = $logger;
    }


    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            // Datatype
            DatatypeCreatedEvent::NAME => 'onDatatypeCreated',
//            DatatypeImportedEvent::NAME => 'onDatatypeImport',
            DatatypeModifiedEvent::NAME => 'onDatatypeModified',
            DatatypeDeletedEvent::NAME => 'onDatatypeDeleted',
            DatatypePublicStatusChangedEvent::NAME => 'onDatatypePublicStatusChanged',
            // Datarecord
            DatarecordCreatedEvent::NAME => 'onDatarecordCreated',
            DatarecordModifiedEvent::NAME => 'onDatarecordModified',
            DatarecordDeletedEvent::NAME => 'onDatarecordDeleted',
            DatarecordPublicStatusChangedEvent::NAME => 'onDatarecordPublicStatusChanged',
            // Datafield
//            DatafieldCreatedEvent::NAME => 'onDatafieldCreated',    // TODO - ignore datafields for now...
//            DatafieldModifiedEvent::NAME => 'onDatafieldModified',
//            DatafieldDeletedEvent::NAME => 'onDatafieldDeleted',
//            DatafieldPublicStatusChangedEvent::NAME => 'onDatafieldPublicStatusChanged',
            // TODO - Nate is also going to eventually need events for Layout changes

            // Files/Images
            FileDeletedEvent::NAME => 'onFileDeleted',
            FilePreEncryptEvent::NAME => 'onFilePreEncrypt',
            // Other storage entities
            PostUpdateEvent::NAME => 'onPostUpdate',
            PostMassEditEvent::NAME => 'onPostMassEdit',

            // Plugins
            PluginAttachEvent::NAME => 'onPluginAttach',
            PluginOptionsChangedEvent::NAME => 'onPluginOptionsChanged',
            PluginPreRemoveEvent::NAME => 'onPluginPreRemove',
        );
    }


    /**
     * Determines whether any render plugins need to respond to the given event, and determines
     * which function to call inside the render plugin definition file if so.
     *
     * Don't really need to cache this, it seems.    TODO - since events are going to be everywhere, it probably should be cached
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
     * Handles dispatched DatatypeCreated events
     *
     * @param DatatypeCreatedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatatypeCreated(DatatypeCreatedEvent $event)
    {
        $this->logger->debug('ODREventSubscriber::onDatatypeCreated()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datatype = $event->getDatatype();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }


            // ----------------------------------------
            // This cache entry always needs to be cleared
            $this->cache_service->delete('cached_datatree_array');

            // These cache entries should be cleared only if the new datatype is top-level
            if ( $datatype->getId() === $datatype->getParent()->getId() ) {
                $this->cache_service->delete('top_level_datatypes');
                $this->cache_service->delete('top_level_themes');
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
     * Handles dispatched DatatypeModified events
     *
     * @param DatatypeModifiedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatatypeModified(DatatypeModifiedEvent $event)
    {
        $this->logger->debug('ODREventSubscriber::onDatatypeModified()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datatype = $event->getDatatype();
            $user = $event->getUser();
            $clear_datarecord_caches = $event->getClearDatarecordCaches();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }


            // ----------------------------------------
            // Whenever an edit is made to a datatype, each of its parents (if it has any) also need
            //  to be marked as updated
            while ( $datatype->getId() !== $datatype->getParent()->getId() ) {
                // Mark this (non-top-level) datatype as updated by this user
                $datatype->setUpdatedBy($user);
                $datatype->setUpdated(new \DateTime());
                $this->em->persist($datatype);

                // Continue locating parent datatypes...
                $datatype = $datatype->getParent();
            }

            // $datatype is now guaranteed to be top-level
            $datatype->setUpdatedBy($user);
            $datatype->setUpdated(new \DateTime());
            $this->em->persist($datatype);

            // Save all changes made
            $this->em->flush();

            // Delete all regular cache entries that need to be rebuilt due to whatever change
            //  triggered this event
            $this->cache_service->delete('cached_datatype_'.$datatype->getId());

            if ($clear_datarecord_caches) {
                // ...also need to delete the datarecord entries here...usually due to modifying
                //  external id/name/sort fields, or changing radio/tag names, but there are others
                $dr_list = $this->search_service->getCachedSearchDatarecordList($datatype->getGrandparent()->getId());
                foreach ($dr_list as $dr_id => $parent_dr_id) {
                    $this->cache_service->delete('cached_datarecord_'.$dr_id);
                    $this->cache_service->delete('cached_table_data_'.$dr_id);
                }

                $dr_list = $this->search_service->getCachedDatarecordUUIDList($datatype->getGrandparent()->getId());
                foreach ($dr_list as $dr_id => $dr_uuid)
                    $this->cache_service->delete('json_record_'.$dr_uuid);
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
     * Handles dispatched DatatypeDeleted events
     *
     * @param DatatypeDeletedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatatypeDeleted(DatatypeDeletedEvent $event)
    {
        $this->logger->debug('ODREventSubscriber::onDatatypeDeleted()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datatype_id = $event->getDatatypeId();
            $datatype_uuid = $event->getDatatypeUUID();
            $was_top_level = $event->getWasTopLevel();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }

            // ----------------------------------------
            // While probably not strictly necessary, going to still delete the cached entries for
            //  datarecords belonging to the deleted datatype...only if it's top-level though
            if ( $was_top_level ) {
                // Going to use native SQL to get around doctrine's soft-deleteable filter...I'm not
                //  confident it works properly when dealing with potentially async situations
                $conn = $this->em->getConnection();
                $query =
                   'SELECT dr.id AS dr_id, dr.unique_id AS dr_uuid
                    FROM odr_data_record AS dr
                    WHERE dr.data_type_id = '.$datatype_id;
                $results = $conn->fetchAll($query);

                foreach ($results as $result) {
                    $dr_id = $result['dr_id'];
                    $dr_uuid = $result['dr_uuid'];

                    $this->cache_service->delete('cached_datarecord_'.$dr_id);
                    $this->cache_service->delete('cached_table_data_'.$dr_id);
                    $this->cache_service->delete('json_record_'.$dr_uuid);
                }
            }

            // Child records don't have their own cache entries, so there's no point doing this if
            // the deleted datatype was not top-level
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
     * Handles dispatched DatatypePublicStatusChanged events.  This also triggers the database
     * changes and cache clearing that a DatatypeModified event would, since we don't really
     * want to fire off both of these events at the same time...
     *
     * @param DatatypePublicStatusChangedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatatypePublicStatusChanged(DatatypePublicStatusChangedEvent $event)
    {
        $this->logger->debug('ODREventSubscriber::onDatatypePublicStatusChanged()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datatype = $event->getDatatype();
            $user = $event->getUser();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }


            // ----------------------------------------
            // Whenever an edit is made to a datatype, each of its parents (if it has any) also need
            //  to be marked as updated
            while ( $datatype->getId() !== $datatype->getParent()->getId() ) {
                // Mark this (non-top-level) datatype as updated by this user
                $datatype->setUpdatedBy($user);
                $datatype->setUpdated(new \DateTime());
                $this->em->persist($datatype);

                // Continue locating parent datatypes...
                $datatype = $datatype->getParent();
            }

            // $datatype is now guaranteed to be top-level
            $datatype->setUpdatedBy($user);
            $datatype->setUpdated(new \DateTime());
            $this->em->persist($datatype);

            // Save all changes made
            $this->em->flush();

            // Delete all regular cache entries that need to be rebuilt due to whatever change
            //  triggered this event
            $this->cache_service->delete('cached_datatype_'.$datatype->getId());
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
     * Handles dispatched DatarecordCreated events
     *
     * @param DatarecordCreatedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatarecordCreated(DatarecordCreatedEvent $event)
    {
        $this->logger->debug('ODREventSubscriber::onDatarecordCreated()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datarecord = $event->getDatarecord();
            $datatype = $datarecord->getDataType();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
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
     * Handles dispatched DatarecordModified events
     *
     * @param DatarecordModifiedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatarecordModified(DatarecordModifiedEvent $event)
    {
        $this->logger->debug('ODREventSubscriber::onDatarecordModified()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $user = $event->getUser();
            $datarecord = $event->getDatarecord();
//            $datatype = $datarecord->getDataType();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }

            // ----------------------------------------
            // Whenever an edit is made to a datarecord, each of its parents (if it has any) also need
            //  to be marked as updated
            $dr = $datarecord;
            while ($dr->getId() !== $dr->getParent()->getId()) {
                // Mark this (non-top-level) datarecord as updated by this user
                $dr->setUpdatedBy($user);
                $dr->setUpdated(new \DateTime());
                $this->em->persist($dr);

                // Continue locating parent datarecords...
                $dr = $dr->getParent();
            }

            // $dr is now the grandparent of $datarecord, save all changes made
            $dr->setUpdatedBy($user);
            $dr->setUpdated(new \DateTime());

            $this->em->persist($dr);
            $this->em->flush();

            // Delete all regular cache entries that need to be rebuilt due to whatever change
            //  triggered this event
            $this->cache_service->delete('cached_datarecord_'.$dr->getId());
            $this->cache_service->delete('cached_table_data_'.$dr->getId());
            $this->cache_service->delete('json_record_' . $dr->getUniqueId());
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
     * Handles dispatched DatarecordDeleted events
     *
     * @param DatarecordDeletedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatarecordDeleted(DatarecordDeletedEvent $event)
    {
        $this->logger->debug('ODREventSubscriber::onDatarecordDeleted()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datarecord_id = $event->getDatarecordId();
            $datarecord_uuid = $event->getDatarecordUUID();
//            $datatype = $event->getDataType();

            // NOTE: $dr_id and $dr_uuid will be arrays if this event was fired as a result of MassEdit
            //  doing a mass deletion

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }

            // While this isn't necessary if the deleted datarecord wasn't top-level, and technically
            //  shouldn't be necessary even if it is a top-level...might as well ensure no cache
            //  entries exist to reference the datarecord
            if ( !is_array($datarecord_id) ) {
                $this->cache_service->delete('cached_datarecord_'.$datarecord_id);
                $this->cache_service->delete('cached_table_data_'.$datarecord_id);

                $this->cache_service->delete('associated_datarecords_for_'.$datarecord_id);
            }
            else {
                foreach ($datarecord_id as $dr_id) {
                    $this->cache_service->delete('cached_datarecord_'.$dr_id);
                    $this->cache_service->delete('cached_table_data_'.$dr_id);

                    $this->cache_service->delete('associated_datarecords_for_'.$dr_id);
                }
            }

            if ( !is_array($datarecord_uuid) ) {
                $this->cache_service->delete('json_record_'.$datarecord_uuid);
            }
            else {
                foreach ($datarecord_uuid as $dr_uuid)
                    $this->cache_service->delete('json_record_'.$dr_uuid);
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
     * Handles dispatched DatarecordPublicStatusChanged events.  This also triggers the database
     * changes and cache clearing that a DatarecordModified event would, since we don't really
     * want to fire off both of these events at the same time...
     *
     * @param DatarecordPublicStatusChangedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatarecordPublicStatusChanged(DatarecordPublicStatusChangedEvent $event)
    {
        $this->logger->debug('ODREventSubscriber::onDatarecordPublicStatusChanged()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datarecord = $event->getDatarecord();
            $datatype = $datarecord->getDataType();
            $user = $event->getUser();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }


            // ----------------------------------------
            // Whenever an edit is made to a datarecord, each of its parents (if it has any) also need
            //  to be marked as updated
            $dr = $datarecord;
            while ($dr->getId() !== $dr->getParent()->getId()) {
                // Mark this (non-top-level) datarecord as updated by this user
                $dr->setUpdatedBy($user);
                $dr->setUpdated(new \DateTime());
                $this->em->persist($dr);

                // Continue locating parent datarecords...
                $dr = $dr->getParent();
            }

            // $dr is now the grandparent of $datarecord, save all changes made
            $dr->setUpdatedBy($user);
            $dr->setUpdated(new \DateTime());

            $this->em->persist($dr);
            $this->em->flush();

            // Delete all regular cache entries that need to be rebuilt due to whatever change
            //  triggered this event
            $this->cache_service->delete('cached_datarecord_'.$dr->getId());
            $this->cache_service->delete('cached_table_data_'.$dr->getId());
            $this->cache_service->delete('json_record_' . $dr->getUniqueId());
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
            $datatype = $datafield->getDataType();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, $datafield);
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
            $datatype = $datafield->getDataType();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, $datafield);
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
     * Handles dispatched PostMassEdit events
     *
     * @param PostMassEditEvent $event
     *
     * @throws \Throwable
     */
    public function onPostMassEdit(PostMassEditEvent $event)
    {
        try {
            // Determine whether any render plugins should run something in response to this event
            $drf = $event->getDataRecordFields();
            $datafield = $drf->getDataField();
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
