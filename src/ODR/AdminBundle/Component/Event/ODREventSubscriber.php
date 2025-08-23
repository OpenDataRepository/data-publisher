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
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Doctrine\DBAL\Connection as DBALConnection;
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
     * @var DatatreeInfoService
     */
    private $datatree_info_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var boolean
     */
    private $debug;


    /**
     * ODREventSubscriber constructor.
     *
     * @param string $environment
     * @param ContainerInterface $container
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatatreeInfoService $datatree_info_service
     * @param SearchService $search_service
     * @param Logger $logger
     */
    public function __construct(
        string $environment,
        ContainerInterface $container,
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatatreeInfoService $datatree_info_service,
        SearchService $search_service,
        Logger $logger
    ) {
        $this->env = $environment;
        $this->container = $container;
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->datatree_info_service = $datatree_info_service;
        $this->search_service = $search_service;
        $this->logger = $logger;

//        $this->debug = false;
        $this->debug = true;
    }


    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            // Datatype
            DatatypeCreatedEvent::NAME => 'onDatatypeCreated',
            DatatypeImportedEvent::NAME => 'onDatatypeImport',
            DatatypeModifiedEvent::NAME => 'onDatatypeModified',
            DatatypeDeletedEvent::NAME => 'onDatatypeDeleted',
            DatatypePublicStatusChangedEvent::NAME => 'onDatatypePublicStatusChanged',
            DatatypeLinkStatusChangedEvent::NAME => 'onDatatypeLinkStatusChanged',
            // Datarecord
            DatarecordCreatedEvent::NAME => 'onDatarecordCreated',
            DatarecordModifiedEvent::NAME => 'onDatarecordModified',
            DatarecordDeletedEvent::NAME => 'onDatarecordDeleted',
            DatarecordPublicStatusChangedEvent::NAME => 'onDatarecordPublicStatusChanged',
            DatarecordLinkStatusChangedEvent::NAME => 'onDatarecordLinkStatusChanged',
            // Datafield
//            DatafieldCreatedEvent::NAME => 'onDatafieldCreated',
            DatafieldModifiedEvent::NAME => 'onDatafieldModified',
//            DatafieldDeletedEvent::NAME => 'onDatafieldDeleted',

            // TODO - Nate is also going to eventually need events for Layout changes

            // Files/Images
            FileDeletedEvent::NAME => 'onFileDeleted',
            FilePostEncryptEvent::NAME => 'onFilePostEncrypt',
            FilePreEncryptEvent::NAME => 'onFilePreEncrypt',
            FilePublicStatusChangedEvent::NAME => 'onFilePublicStatusChanged',
            // Other storage entities
            PostUpdateEvent::NAME => 'onPostUpdate',

            // Generic
            MassEditTriggerEvent::NAME => 'onMassEditTrigger',
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
     * @param string|null $plugin_classname If provided, then only check the given plugin
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
                // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
                //  intercept it before it interfered with anything, and it also wouldn't reach ODR
                $base_info = array(self::class);
                $event_info = $event->getErrorInfo();
                $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
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
                $this->cache_service->delete('top_level_datatype_names');
                $this->cache_service->delete('top_level_themes');
            }
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
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
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onDatatypeDeleted()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datatype_id = $event->getDatatypeId();
//            $datatype_uuid = $event->getDatatypeUUID();
            $was_top_level = $event->getWasTopLevel();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }

            // ----------------------------------------
            // Always need to delete this entry
            $this->cache_service->delete('cached_datatree_array');
            // Might need to delete these entries too
            if ( $was_top_level ) {
                $this->cache_service->delete('top_level_datatypes');
                $this->cache_service->delete('top_level_datatype_names');
                $this->cache_service->delete('top_level_themes');
            }

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

                    $this->cache_service->delete('associated_datarecords_for_'.$dr_id);
                    $this->cache_service->delete('cached_datarecord_'.$dr_id);
                    $this->cache_service->delete('cached_table_data_'.$dr_id);
                    $this->cache_service->delete('json_record_'.$dr_uuid);
                }
            }

            // Child records don't have their own cache entries, so there's no point doing this if
            // the deleted datatype was not top-level
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
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
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
        }
    }


    /**
     * Handles dispatched DatatypeLinkStatusChanged events.
     *
     * @param DatatypeLinkStatusChangedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatatypeLinkStatusChanged(DatatypeLinkStatusChangedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onDatatypeLinkStatusChanged()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $ancestor_datatype = $event->getAncestorDatatype();
//            $new_descendant_datatype = $event->getNewDescendantDatatype();
//            $previous_descendant_datatype = $event->getPreviousDescendantDatatype();
//            $user = $event->getUser();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }


            // ----------------------------------------
            // Since a link between datatypes got created/deleted, delete the cached datatree array
            $this->cache_service->delete('cached_datatree_array');
            // The datatree array needs to be rebuilt with the new link data anyways
            $datatree_array = $this->datatree_info_service->getDatatreeArray();


            // Locate all datatypes that end up needing to load cache entries for the datatypes in
            //  $datatype_ids...
            $datatype_ids = array($ancestor_datatype->getId());
            $all_linked_ancestors = $this->datatree_info_service->getLinkedAncestors($datatype_ids, $datatree_array, true);
            $all_linked_descendants = $this->datatree_info_service->getLinkedDescendants($datatype_ids, $datatree_array, true);

            // Ensure the datatype that were originally passed in get the cache entry cleared
            foreach ($datatype_ids as $num => $dt_id) {
                $all_linked_ancestors[] = $dt_id;
                $all_linked_descendants[] = $dt_id;
            }

            // Clearing these cache entries is required
            foreach ($all_linked_ancestors as $num => $dt_id) {
                $this->cache_service->delete('associated_datatypes_for_'.$dt_id);

                // Going to use native SQL to get around doctrine's soft-deleteable filter...I'm not
                //  confident it works properly when dealing with potentially async situations
                $conn = $this->em->getConnection();
                $query =
                   'SELECT dr.id AS dr_id, dr.unique_id AS dr_uuid
                    FROM odr_data_record AS dr
                    WHERE dr.data_type_id = '.$dt_id;
                $results = $conn->fetchAll($query);

                foreach ($results as $result) {
                    $dr_id = $result['dr_id'];
                    $this->cache_service->delete('associated_datarecords_for_'.$dr_id);
                }
            }
            foreach ($all_linked_descendants as $num => $dt_id)
                $this->cache_service->delete('inverse_associated_datatypes_for_'.$dt_id);


            // ----------------------------------------
            // Don't need to clear 'datatype_<$dt_id>_record_names' or 'datatype_<$dt_id>_record_order'
            // ...that happens inside DatarecordLinkStatusChanged, if LinkController realizes that
            //  it needs to happen
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
        }
    }


    /**
     * The DatatypeImportedEvent is usually fired when there's a massive changeset to a datatype,
     * such as with MassEdit or CSVImport...instead of dispatching hundreds of more granular events,
     * this one is used instead.
     *
     * @param DatatypeImportedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatatypeImport(DatatypeImportedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onDatatypeImport()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datatype = $event->getDatatype();
            $clear_datarecord_caches = $event->getClearDatarecordCaches();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }


            // ----------------------------------------
            // Should ensure that these cache entries are cleared
            $this->cache_service->delete('cached_datatype_'.$datatype->getId());
            $this->cache_service->delete('associated_datatypes_for_'.$datatype->getId());
            $this->cache_service->delete('inverse_associated_datatypes_for_'.$datatype->getId());

            // Instead of firing off DatafieldModified events for every single datafield that
            //  might've been changed...locate all other datatypes that use this datatype's fields
            //  as a sort field...
            $query = $this->em->createQuery(
               'SELECT DISTINCT(l_dt.id) AS dt_id
                FROM ODRAdminBundle:DataFields AS df
                LEFT JOIN ODRAdminBundle:DataTypeSpecialFields AS dtsf WITH dtsf.dataField = df
                LEFT JOIN ODRAdminBundle:DataType AS l_dt WITH dtsf.dataType = l_dt
                WHERE df.dataType IN (:datatype_id)
                AND df.deletedAt IS NULL AND dtsf.deletedAt IS NULL AND l_dt.deletedAt IS NULL'
            )->setParameters(
                array( 'datatype_id' => array( $datatype->getId() ) )
            );
            $results = $query->getArrayResult();

            $datatypes_to_reset_order = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $datatypes_to_reset_order[] = $dt_id;
            }

            // ...and then reset these cache entries for each of the other datatypes
            foreach ($datatypes_to_reset_order as $dt_id) {
                // Could be more precise with clearing these two entries, but this event is rare
                $this->cache_service->delete('datatype_'.$dt_id.'_record_names');
                $this->cache_service->delete('datatype_'.$dt_id.'_record_order');
            }

            if ($clear_datarecord_caches) {
                // ...also need to delete the datarecord entries here...usually due to modifying
                //  external id/name/sort fields, or changing radio/tag names, but there are others
                $dr_list = $this->search_service->getCachedSearchDatarecordList($datatype->getGrandparent()->getId());
                foreach ($dr_list as $dr_id => $parent_dr_id) {
                    $this->cache_service->delete('associated_datarecords_for_'.$dr_id);
                    $this->cache_service->delete('cached_datarecord_'.$dr_id);
                    $this->cache_service->delete('cached_table_data_'.$dr_id);
                }

                $dr_list = $this->search_service->getCachedDatarecordUUIDList($datatype->getGrandparent()->getId());
                foreach ($dr_list as $dr_id => $dr_uuid)
                    $this->cache_service->delete('json_record_'.$dr_uuid);
            }
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onDatarecordCreated()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datarecord = $event->getDatarecord();
            $datatype = $datarecord->getDataType();

            // Because the DatarecordCreateEvent needs to have both datatype and datafield plugins,
            //  the default isEventRelevant() won't work
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);

            $event_name = get_class($event);
            $event_name = substr($event_name, strrpos($event_name, "\\") + 1);

            // Need to have an extra join in there to pick up the cases where the DatarecordCreatedEvent
            //  is attached to a datafield
            $query =
               'SELECT DISTINCT(rp.plugin_class_name) AS pluginClassName, rpe.event_callable
                FROM odr_render_plugin_events rpe
                JOIN odr_render_plugin rp ON rpe.render_plugin_id = rp.id
                LEFT JOIN odr_render_plugin_instance rpi ON rpi.render_plugin_id = rp.id
                LEFT JOIN odr_data_fields rpi_df ON rpi.data_field_id = rpi_df.id
                WHERE rpe.event_name = :event_name
                AND (rpi.data_type_id = :datatype_id OR rpi_df.data_type_id = :datatype_id)
                AND rp.deletedAt IS NULL AND rpe.deletedAt IS NULL
                AND rpi.deletedAt IS NULL AND rpi_df.deletedAt IS NULL';
            $params =  array(
                'event_name' => $event_name,
                'datatype_id' => $datatype->getId()
            );
            $conn = $this->em->getConnection();
            $results = $conn->executeQuery($query, $params);

            $relevant_plugins = array();
            foreach ($results as $result) {
                $plugin_classname = $result['pluginClassName'];
                $event_callable = $result['event_callable'];

                $relevant_plugins[$plugin_classname] = $event_callable;
            }

            if ( !empty($relevant_plugins) ) {
                // If so, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }


            // ----------------------------------------
            // Delete the related dashboard entry
            $this->cache_service->delete('dashboard_'.$datatype->getGrandparent()->getId());

            // Delete these two cache entries as well
            $this->cache_service->delete('datatype_'.$datatype->getId().'_record_names');
            $this->cache_service->delete('datatype_'.$datatype->getId().'_record_order');

            // DatabaseInfoService originally always reset cached graphs when a datarecord was
            //  created...that doesn't actually need to happen, since cached graphs change should
            //  only change on file events (or datarecord deletions), and a new record shouldn't
            //  have a file uploaded
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
        }
    }


    /**
     * Originally, changes made to a datarecord would also change that datarecord's updated property,
     * as well as the the updated property of that datarecord's parent...repeated until it hit the
     * datarecord's grandparent.
     *
     * After external applications started checking the updated property to determine when stuff
     * changed, it became apparent that the scope needed to expand to include every single possible
     * ancestor of the datarecord...otherwise, the external applications had to recheck basically
     * everything.
     *
     * This "every possible ancestor" logic needs to be applied to multiple places, and is easier
     * anyways when it's off in its own function.
     *
     * @param int[] $datarecords_to_process
     * @return int[]
     */
    private function findAllAncestors($datarecords_to_process)
    {
        $conn = $this->em->getConnection();

        $all_datarecord_ids = array();
        foreach ($datarecords_to_process as $num => $dr_id)
            $all_datarecord_ids[$dr_id] = 0;

        while ( !empty($datarecords_to_process) ) {
            $query =
               'SELECT ddr.id AS ddr_id, ddr.parent_id AS parent_id, adr.id AS linked_ancestor_id
                FROM odr_data_record ddr
                LEFT JOIN odr_linked_data_tree ldt ON ldt.descendant_id = ddr.id AND ldt.deletedAt IS NULL
                LEFT JOIN odr_data_record adr ON ldt.ancestor_id = adr.id
                WHERE ddr.id IN (?)
                AND ddr.deletedAt IS NULL';
            $parameters = array(1 => $datarecords_to_process);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query, $parameters, $types);

            $datarecords_to_process = array();
            foreach ($results as $result) {
                $ddr_id = intval($result['ddr_id']);
                $all_datarecord_ids[$ddr_id] = 0;

                // Store this datarecord's ancestor regardless of whether it was a child or a link
                if ( !is_null($result['parent_id']) ) {
                    $parent_id = intval($result['parent_id']);
                    if (  $ddr_id !== $parent_id ) {
                        $datarecords_to_process[$parent_id] = 0;
                        $all_datarecord_ids[$parent_id] = 0;
                    }
                }
                if ( !is_null($result['linked_ancestor_id']) ) {
                    $linked_ancestor_id = intval($result['linked_ancestor_id']);
                    $datarecords_to_process[$linked_ancestor_id] = 0;
                    $all_datarecord_ids[$linked_ancestor_id] = 0;
                }
            }

            // The loop requires the datarecord ids to be values, not keys
            $datarecords_to_process = array_keys($datarecords_to_process);
        }

        // The resulting list of datarecords should also be returned as values, not keys
        $all_datarecord_ids = array_keys($all_datarecord_ids);
        return $all_datarecord_ids;
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
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onDatarecordModified()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $user = $event->getUser();
            $datarecord = $event->getDatarecord();
//            $datatype = $datarecord->getDataType();
            $update_database = $event->getUpdateDatabase();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }

            // ----------------------------------------
            // Whenever an edit is made to a datarecord, then its ancestors need to be marked as
            //  updated...originally this only went up to the datarecord's grandparent, but that
            //  requirement got extended to include every single possible ancestor
            if ( $update_database ) {
                $dr_list = self::findAllAncestors( array($datarecord->getId()) );
                if ( $this->debug )
                    $this->logger->debug('ODREventSubscriber::onDatarecordModified() for datarecord '.$datarecord->getId().'...updated datarecord ids: '.implode(', ', $dr_list));

                $query_str =
                   'UPDATE odr_data_record AS dr
                    SET dr.updated = NOW(), dr.updatedBy = '.$user->getId().'
                    WHERE dr.id IN (?) AND dr.deletedAt IS NULL';
                $parameters = array(1 => $dr_list);
                $types = array(1 => DBALConnection::PARAM_INT_ARRAY);

                $conn = $this->em->getConnection();
                $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);
            }

            // ----------------------------------------
            // Only need to delete the cache entries for the datarecord's grandparent, however
            $grandparent = $datarecord->getGrandparent();
            $this->cache_service->delete('cached_datarecord_'.$grandparent->getId());
            $this->cache_service->delete('cached_table_data_'.$grandparent->getId());
            $this->cache_service->delete('json_record_' . $grandparent->getUniqueId());

            // Also delete the related dashboard entry
            $this->cache_service->delete('dashboard_'.$grandparent->getDataType()->getId());
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onDatarecordDeleted()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datarecord_id = $event->getDatarecordId();
            $datarecord_uuid = $event->getDatarecordUUID();
            $datatype = $event->getDataType();

            // NOTE: $dr_id and $dr_uuid will be arrays if this event was fired as a result of MassEdit
            //  doing a mass deletion

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }


            // ----------------------------------------
            // Delete the related dashboard entry
            $this->cache_service->delete('dashboard_'.$datatype->getGrandparent()->getId());

            // Delete these two cache entries as well
            $this->cache_service->delete('datatype_'.$datatype->getId().'_record_names');
            $this->cache_service->delete('datatype_'.$datatype->getId().'_record_order');

            // If the deleted datarecord (or datarecords) belongs to a top-level datatype, then
            //  probably should ensure several more cache entries are deleted...
            if ( $datatype->getId() == $datatype->getGrandparent()->getId() ) {
                if ( !is_array($datarecord_id) ) {
                    $this->cache_service->delete('associated_datarecords_for_'.$datarecord_id);
                    $this->cache_service->delete('cached_datarecord_'.$datarecord_id);
                    $this->cache_service->delete('cached_table_data_'.$datarecord_id);
                }
                else {
                    foreach ($datarecord_id as $dr_id) {
                        $this->cache_service->delete('associated_datarecords_for_'.$dr_id);
                        $this->cache_service->delete('cached_datarecord_'.$dr_id);
                        $this->cache_service->delete('cached_table_data_'.$dr_id);
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

            // There's no point deleting the above entries for a datarecord that isn't top-level
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onDatarecordPublicStatusChanged()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datarecord = $event->getDatarecord();
//            $datatype = $datarecord->getDataType();
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
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
        }
    }


    /**
     * Handles dispatched DatarecordLinkStatusChanged events.
     *
     * @param DatarecordLinkStatusChangedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatarecordLinkStatusChanged(DatarecordLinkStatusChangedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onDatarecordLinkStatusChanged()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datarecord_ids = $event->getDatarecordIds();
            $datatype = $event->getDescendantDatatype();
            $user = $event->getUser();
            $mark_as_updated = $event->getMarkAsUpdated();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }


            // ----------------------------------------
            // Force a rebuild of the cache entries for each datarecord that directly linked to the
            //  records mentioned in this event, so they no longer have a reference to them in the
            //  'children' section of their cache entries
            $query = $this->em->createQuery(
               'SELECT grandparent.unique_id
                FROM ODRAdminBundle:DataRecord dr
                JOIN ODRAdminBundle:DataRecord grandparent WITH dr.grandparent = grandparent
                WHERE dr.id IN (:datarecord_ids)
                AND grandparent.deletedAt IS NULL'
            )->setParameters( array('datarecord_ids' => $datarecord_ids) );
            $results = $query->getArrayResult();

            $datarecord_uuids = array();
            foreach ($results as $result)
                $datarecord_uuids[] = $result['unique_id'];

            foreach ($datarecord_ids as $dr_id) {
                $this->cache_service->delete('cached_datarecord_'.$dr_id);
                $this->cache_service->delete('cached_table_data_'.$dr_id);
            }
            foreach ($datarecord_uuids as $dr_uuid)
                $this->cache_service->delete('json_record_'.$dr_uuid);


            // Because ODR permits an arbitrarily deep hierarchy when it comes to linking datarecords...
            //  e.g.  A links to B links to C links to D links to...etc
            //  ...the cache entry 'associated_datarecords_for_<A>' will then mention (B, C, D, etc.),
            //  because they all need to be loaded via getDatarecordArray() in order to properly
            //  render A.  However, this means that linking/unlinking of datarecords between B/C,
            //  C/D, etc also affects which datarecords A needs to load...so any linking/unlinking
            //  needs to be propagated upwards...
            $records_to_clear = self::findAllAncestors($datarecord_ids);

            // Clearing this cache entry for each of the ancestor records found ensures that the
            //  newly linked/unlinked datarecords show up (or not) when they should
            foreach ($records_to_clear as $num => $dr_id)
                $this->cache_service->delete('associated_datarecords_for_'.$dr_id);

            // These particular ancestors don't need their 'cached_datarecord_<dr_id>' (and other)
            //  entries cleared, because those don't directly reference the records in this event

            // Additionally, if this event needs to partially shoulder the work usually done by the
            //  DatarecordModifiedEvent...
            if ( $mark_as_updated ) {
                // ...then also mark all of these records as updated
                if ( $this->debug )
                    $this->logger->debug('ODREventSubscriber::onDatarecordLinkStatusChanged()...updated datarecord ids: '.implode(', ', $records_to_clear));

                $query_str =
                   'UPDATE odr_data_record AS dr
                    SET dr.updated = NOW(), dr.updatedBy = '.$user->getId().'
                    WHERE dr.id IN (?) AND dr.deletedAt IS NULL';
                $parameters = array(1 => $records_to_clear);
                $types = array(1 => DBALConnection::PARAM_INT_ARRAY);

                $conn = $this->em->getConnection();
                $rowsAffected = $conn->executeUpdate($query_str, $parameters, $types);
            }


            // ----------------------------------------
            // If the descendant datatype has fields which are being used by an ancestor datatype for
            //  naming/sorting...
            $query = $this->em->createQuery(
               'SELECT DISTINCT(dt.id) AS dt_id
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataTypeSpecialFields AS dtsf WITH dtsf.dataField = df
                JOIN ODRAdminBundle:DataType dt WITH dtsf.dataType = dt
                JOIN ODRAdminBundle:DataRecord dr WITH dr.dataType = dt
                WHERE df.dataType = :descendant_datatype AND dr IN (:datarecord_ids)
                AND df.deletedAt IS NULL AND dtsf.deletedAt IS NULL
                AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters(
                array(
                    'descendant_datatype' => $datatype->getId(),
                    'datarecord_ids' => $datarecord_ids
                )
            );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                // ...then need to reset several cached entries for each ancestor datatype as a
                //  result of linking/unlinking
                $dt_id = $result['dt_id'];

                // Could be more precise with clearing these two entries, but this event is rare
                $this->cache_service->delete('datatype_'.$dt_id.'_record_names');
                $this->cache_service->delete('datatype_'.$dt_id.'_record_order');
            }
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
        }
    }


    /**
     * Handles dispached DatafieldModified events
     *
     * @param DatafieldModifiedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatafieldModified(DatafieldModifiedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onDatafieldModified()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datafield = $event->getDatafield();
//            $datatype = $datafield->getDataType();

            // This event currently isn't allowed to fire for render plugins
//            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, null);
//            if ( !empty($relevant_plugins) ) {
//                // If so, then load each plugin and call their required function
//                self::relayEvent($relevant_plugins, $event);
//            }

            // ----------------------------------------
            // Changes to the datafield should also clear these cache entries for any datatypes using
            //  it as a special field
            $name_datatypes = $datafield->getNameDatatypes();
            foreach ($name_datatypes as $dt)
                $this->cache_service->delete('datatype_'.$dt->getId().'_record_names');

            $sort_datatypes = $datafield->getSortDatatypes();
            foreach ($sort_datatypes as $dt)
                $this->cache_service->delete('datatype_'.$dt->getId().'_record_order');

        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onFileDeleted()', $event->getErrorInfo());

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
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
        }
    }


    /**
     * Handles dispatched FilePostEncrypt events
     *
     * @param FilePostEncryptEvent $event
     *
     * @throws \Throwable
     */
    public function onFilePostEncrypt(FilePostEncryptEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onFilePostEncrypt()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datafield = $event->getDatafield();
            $datatype = $datafield->getDataType();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, $datafield);
            if ( !empty($relevant_plugins) ) {
                // If so, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }

            // Don't want to clear any cache entries here...the upload process also fires off the
            //  datarecord/datafield modified events
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onFilePreEncrypt()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $datafield = $event->getDatafield();
            $datatype = $datafield->getDataType();
            $file = $event->getFile();
            $datarecord = $file->getDataRecord();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, $datafield);
            if ( !empty($relevant_plugins) ) {
                // If so, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }

            // In the off chance that the encryption job is not running or stalled, then deleting the
            //  cached version of the datarecord is slightly better than not doing so...that way a
            //  page reload will indicate that there's a file there
            $this->cache_service->delete('cached_datarecord_'.$datarecord->getId());

            // ...the other two are probably better off not being deleted, though
//            $this->cache_service->delete('cached_table_data_'.$datarecord->getId());
//            $this->cache_service->delete('json_record_' . $datarecord->getUniqueId());
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
        }
    }


    /**
     * Handles dispatched FilePublicStatusChangedEvent events
     *
     * @param FilePublicStatusChangedEvent $event
     *
     * @throws \Throwable
     */
    public function onFilePublicStatusChanged(FilePublicStatusChangedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onFilePublicStatusChanged()', $event->getErrorInfo());

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
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
        }
    }


    /**
     * Handles dispatched MassEditTrigger events
     *
     * @param MassEditTriggerEvent $event
     *
     * @throws \Throwable
     */
    public function onMassEditTrigger(MassEditTriggerEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onMassEditTrigger()', $event->getErrorInfo());

        try {
            // Determine whether any render plugins should run something in response to this event
            $drf = $event->getDataRecordFields();
            $datafield = $drf->getDataField();
            $datatype = $datafield->getDataType();

            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, $datafield, $event->getPluginClassName());
            if ( !empty($relevant_plugins) ) {
                // If any plugins remain, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onPluginAttach()', $event->getErrorInfo());

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
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onPluginOptionsChanged()', $event->getErrorInfo());

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
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onPluginPreRemove()', $event->getErrorInfo());

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
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
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
        if ( $this->debug )
            $this->logger->debug('ODREventSubscriber::onPostUpdate()', $event->getErrorInfo());

        try {
            // TODO - technically, there's a chance for infinite recursion...a change to datafield A
            // TODO -  triggers a change to datafield B, which can trigger a change to datafield A

            // TODO - is there any method to completely prevent this recursion from inside the event?
            $storage_entity = $event->getStorageEntity();
            $datafield = $storage_entity->getDataField();
            $datarecord = $storage_entity->getDataRecord();
            $datatype = $datafield->getDataType();

            // Determine whether the change to this drf should clear any namefield values that are
            //  cached inside datarecord entries...the Datafield/DatarecordModified events can't
            //  handle it because they're missing half of the info...
            $query =
               'SELECT adr.grandparent_id
                FROM odr_data_record ddr
                JOIN odr_linked_data_tree ldt ON ldt.descendant_id = ddr.grandparent_id
                JOIN odr_data_record adr ON ldt.ancestor_id = adr.id
                JOIN odr_data_type adt ON adr.data_type_id = adt.id
                JOIN odr_data_type_special_fields dtsf ON dtsf.data_type_id = adt.id
                WHERE ddr.id = '.$datarecord->getId().' AND dtsf.data_field_id = '.$datafield->getId().'
                AND dtsf.field_purpose = '.DataTypeSpecialFields::NAME_FIELD.'
                AND ddr.deletedAt IS NULL AND ldt.deletedAt IS NULL AND adr.deletedAt IS NULL
                AND adt.deletedAt IS NULL AND dtsf.deletedAt IS NULL';
            $conn = $this->em->getConnection();
            $results = $conn->fetchAll($query);

            foreach ($results as $result) {
                $dr_id = $result['grandparent_id'];
                $this->cache_service->delete('cached_datarecord_'.$dr_id);
            }


            // Determine whether any render plugins should run something in response to this event
            $relevant_plugins = self::isEventRelevant(get_class($event), $datatype, $datafield);
            if ( !empty($relevant_plugins) ) {
                // If any plugins remain, then load each plugin and call their required function
                self::relayEvent($relevant_plugins, $event);
            }
        }
        catch (\Throwable $e) {
            // Rethrowing the error is pretty much pointless...symfony's event dispatcher would
            //  intercept it before it interfered with anything, and it also wouldn't reach ODR
            $base_info = array(self::class);
            $event_info = $event->getErrorInfo();
            $this->logger->error($e->getMessage(), array_merge($base_info, $event_info));
        }
    }
}
