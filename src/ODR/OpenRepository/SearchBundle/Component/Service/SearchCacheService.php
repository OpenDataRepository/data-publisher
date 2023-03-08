<?php

/**
 * Open Data Repository Data Publisher
 * Search Cache Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains the functions to delete relevant search cache entries, organized by actions performed
 * elsewhere in ODR.
 *
 */

namespace ODR\OpenRepository\SearchBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
// Events
use ODR\AdminBundle\Component\Event\DatafieldCreatedEvent;
use ODR\AdminBundle\Component\Event\DatafieldDeletedEvent;
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
use ODR\AdminBundle\Component\Event\DatarecordDeletedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordPublicStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatarecordLinkStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatatypeCreatedEvent;
use ODR\AdminBundle\Component\Event\DatatypeDeletedEvent;
use ODR\AdminBundle\Component\Event\DatatypeImportedEvent;
use ODR\AdminBundle\Component\Event\DatatypeModifiedEvent;
use ODR\AdminBundle\Component\Event\DatatypePublicStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatatypeLinkStatusChangedEvent;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
// Symfony
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


class SearchCacheService implements EventSubscriberInterface
{
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
     * @var boolean
     */
    private $debug;


    /**
     * SearchCacheService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param SearchService $search_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        SearchService $search_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->search_service = $search_service;
        $this->logger = $logger;


        $this->debug = false;
//        $this->debug = true;
    }


    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            // Datatype
//            DatatypeCreatedEvent::NAME => 'onDatatypeCreate',    // Don't have any search cache entries to clear for these two events
//            DatatypeModifiedEvent::NAME => 'onDatatypeModify',
            DatatypeImportedEvent::NAME => 'onDatatypeImport',
            DatatypeDeletedEvent::NAME => 'onDatatypeDelete',
            DatatypePublicStatusChangedEvent::NAME => 'onDatatypePublicStatusChange',
            DatatypeLinkStatusChangedEvent::NAME => 'onDatatypeLinkStatusChange',
            // Datarecord
            DatarecordCreatedEvent::NAME => 'onDatarecordCreate',
            DatarecordModifiedEvent::NAME => 'onDatarecordModify',
            DatarecordDeletedEvent::NAME => 'onDatarecordDelete',
            DatarecordPublicStatusChangedEvent::NAME => 'onDatarecordPublicStatusChange',
            DatarecordLinkStatusChangedEvent::NAME => 'onDatarecordLinkStatusChange',
            // Datafield
            DatafieldCreatedEvent::NAME => 'onDatafieldCreate',
            DatafieldModifiedEvent::NAME => 'onDatafieldModify',
            DatafieldDeletedEvent::NAME => 'onDatafieldDelete',

            // TODO - Nate is also going to eventually need events for Layout changes
        );
    }

    // Don't need an onDatatypeCreate() function...none of the relevant cache entries exist

    // Don't need an onDatatypeModify() function...at the moment, none of the properties of a
    //  datatype, other than public status, have any effect on the results of a search


    /**
     * Deletes search cache entries for all datafields belonging to the given datatypes
     *
     * @param array $datatype_ids
     * @param bool $include_deleted
     */
    private function clearCachedDatafieldsByDatatype($datatype_ids, $include_deleted = false)
    {
        // TODO - ...cache these queries required by the private functions?
        // Going to use native SQL to get around doctrine's soft-deleteable filter...I'm not
        //  confident it works properly when dealing with potentially async situations
        $conn = $this->em->getConnection();

        $query =
           'SELECT df.id AS df_id, df.template_field_uuid AS template_field_uuid
            FROM odr_data_fields AS df
            WHERE df.data_type_id IN (?)';
        if (!$include_deleted)
            $query .= ' AND df.deletedAt IS NULL';

        $parameters = array(1 => $datatype_ids);
        $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
        $results = $conn->fetchAll($query, $parameters, $types);

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $df_id = $result['df_id'];
                $template_df_uuid = $result['template_field_uuid'];

                $this->cache_service->delete('cached_search_df_'.$df_id);
                $this->cache_service->delete('cached_search_df_'.$df_id.'_ordering');

                if (!is_null($template_df_uuid)) {
                    $this->cache_service->delete('cached_search_template_df_'.$template_df_uuid);
                    $this->cache_service->delete('cached_search_template_df_'.$template_df_uuid.'_ordering');
                    $this->cache_service->delete('cached_search_template_df_'.$template_df_uuid.'_fieldstats');
                }
            }
        }
    }


    /**
     * Clears search cache entries for all radio options belonging to the given datatypes
     *
     * @param array $datatype_ids
     * @param bool $include_deleted
     */
    private function clearCachedRadioOptionsByDatatype($datatype_ids, $include_deleted = false)
    {
        // Going to use native SQL to get around doctrine's soft-deleteable filter...I'm not
        //  confident it works properly when dealing with potentially async situations
        $conn = $this->em->getConnection();

        $query =
           'SELECT ro.id AS ro_id, ro.radio_option_uuid AS ro_uuid
            FROM odr_radio_options AS ro
            JOIN odr_data_fields AS df ON ro.data_fields_id = df.id
            WHERE df.data_type_id IN (?)';
        if (!$include_deleted)
            $query .= ' AND ro.deletedAt IS NULL AND df.deletedAt IS NULL';

        $parameters = array(1 => $datatype_ids);
        $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
        $results = $conn->fetchAll($query, $parameters, $types);

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $ro_id = $result['ro_id'];
                $ro_uuid = $result['ro_uuid'];

                $this->cache_service->delete('cached_search_ro_'.$ro_id);

                if (!is_null($ro_uuid))
                    $this->cache_service->delete('cached_search_template_ro_'.$ro_uuid);
            }
        }
    }


    /**
     * Clears search cache entries for all tags belonging to the given datatypes
     *
     * @param array $datatype_ids
     * @param bool $include_deleted
     */
    private function clearCachedTagsByDatatype($datatype_ids, $include_deleted = false)
    {
        // Going to use native SQL to get around doctrine's soft-deleteable filter...I'm not
        //  confident it works properly when dealing with potentially async situations
        $conn = $this->em->getConnection();

        $query =
           'SELECT t.id AS t_id, t.tag_uuid AS t_uuid
            FROM odr_tags AS t
            JOIN odr_data_fields AS df ON t.data_fields_id = df.id
            WHERE df.data_type_id IN (?)';
        if (!$include_deleted)
            $query .= ' AND t.deletedAt IS NULL AND df.deletedAt IS NULL';

        $parameters = array(1 => $datatype_ids);
        $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
        $results = $conn->fetchAll($query, $parameters, $types);

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $t_id = $result['t_id'];
                $t_uuid = $result['t_uuid'];

                $this->cache_service->delete('cached_search_tag_'.$t_id);

                if (!is_null($t_uuid))
                    $this->cache_service->delete('cached_search_template_tag_'.$t_uuid);
            }
        }
    }


    /**
     * Clears search cache entries for all radio options belonging to the given datafields
     *
     * @param array $datafield_ids
     * @param bool $include_deleted
     */
    private function clearCachedRadioOptionsByDatafield($datafield_ids, $include_deleted = false)
    {
        // Going to use native SQL to get around doctrine's soft-deleteable filter...I'm not
        //  confident it works properly when dealing with potentially async situations
        $conn = $this->em->getConnection();

        $query =
           'SELECT ro.id AS ro_id, ro.radio_option_uuid AS ro_uuid
            FROM odr_radio_options AS ro
            WHERE ro.data_fields_id IN (?)';
        if (!$include_deleted)
            $query .= ' AND ro.deletedAt IS NULL';

        $parameters = array(1 => $datafield_ids);
        $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
        $results = $conn->fetchAll($query, $parameters, $types);

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $ro_id = $result['ro_id'];
                $ro_uuid = $result['ro_uuid'];

                $this->cache_service->delete('cached_search_ro_'.$ro_id);

                if (!is_null($ro_uuid))
                    $this->cache_service->delete('cached_search_template_ro_'.$ro_uuid);
            }
        }
    }


    /**
     * Clears search cache entries for all tags belonging to the given datatypes
     *
     * @param array $datatype_ids
     * @param bool $include_deleted
     */
    private function clearCachedTagsByDatafield($datafield_ids, $include_deleted = false)
    {
        // Going to use native SQL to get around doctrine's soft-deleteable filter...I'm not
        //  confident it works properly when dealing with potentially async situations
        $conn = $this->em->getConnection();

        $query =
           'SELECT t.id AS t_id, t.tag_uuid AS t_uuid
            FROM odr_tags AS t
            WHERE t.data_fields_id IN (?)';
        if (!$include_deleted)
            $query .= ' AND t.deletedAt IS NULL';

        $parameters = array(1 => $datafield_ids);
        $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
        $results = $conn->fetchAll($query, $parameters, $types);

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $t_id = $result['t_id'];
                $t_uuid = $result['t_uuid'];

                $this->cache_service->delete('cached_search_tag_'.$t_id);

                if (!is_null($t_uuid))
                    $this->cache_service->delete('cached_search_template_tag_'.$t_uuid);
            }
        }
    }


    /**
     * This event is fired when a datatype is deleted.  Most of the relevant search cache entries
     * will no longer be referenced, but there are several more esoteric ones that might...so might
     * as well clear as many of them as possible.
     *
     * @param DatatypeDeletedEvent $event
     */
    public function onDatatypeDelete(DatatypeDeletedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatatypeDelete()', $event->getErrorInfo());

        // Both deletion of a datatype and importing into a datatype typically require deletion of
        //  every single search cache entry that is related to a datatype
        $datatype_id = $event->getDatatypeId();

        self::clearDatatypeEntries($datatype_id);
    }


    /**
     * There's no telling exactly what happens when a CSV Import is run on a datatype, so a whole
     * pile of search cache entries should be deleted upon completion.
     *
     * @param DatatypeImportedEvent $event
     */
    public function onDatatypeImport(DatatypeImportedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatatypeImport()', $event->getErrorInfo());

        // Both deletion of a datatype and importing into a datatype typically require deletion of
        //  every single search cache entry that is related to a datatype
        $datatype = $event->getDatatype();

        self::clearDatatypeEntries($datatype->getId());

        // cached_search_dt_'.$dt_id.'_datafields and 'cached_search_dt_'.$dt_id.'_public_status'
        //  probably don't need to be deleted, but rebuilding them is fast enough for how
        //  infrequently this'll be called
    }


    /**
     * Does the work of clearing cached datatype entries when a datatype is deleted, or after a CSV
     * Import happens to it
     *
     * @param int $datatype_id
     */
    private function clearDatatypeEntries($datatype_id)
    {
        // ----------------------------------------
        // Going to use native SQL to get around doctrine's soft-deleteable filter...I'm not
        //  confident it works properly when dealing with potentially async situations
        $conn = $this->em->getConnection();

        // Want to do a pile of stuff based off the datatype that got deleted, as well as any of its
        //  child datatypes...but due to deletion, there are no convenient cache entries to use
        // Can't just use the grandparent_id property either, because the datatype that got deleted
        //  may not be top-level
        $query =
           'SELECT dt.id AS dt_id, dt.parent_id AS parent_dt_id, dt.grandparent_id AS grandparent_dt_id
            FROM odr_data_type AS o_dt
            LEFT JOIN odr_data_type AS grandparent ON o_dt.grandparent_id = grandparent.id
            LEFT JOIN odr_data_type as dt ON dt.grandparent_id = grandparent.id
            WHERE o_dt.id = '.$datatype_id;
        $results = $conn->fetchAll($query);

        $grandparent_datatype_id = null;
        $tmp_datatree_array = array();
        foreach ($results as $result) {
            $dt_id = intval($result['dt_id']);
            $parent_dt_id = intval($result['parent_dt_id']);

            // Going to need this for later
            if ( is_null($grandparent_datatype_id) )
                $grandparent_datatype_id = intval($result['grandparent_dt_id']);

            if ( $dt_id !== $parent_dt_id ) {
                if ( !isset($tmp_datatree_array[$parent_dt_id]) )
                    $tmp_datatree_array[$parent_dt_id] = array();
                $tmp_datatree_array[$parent_dt_id][] = $dt_id;
            }
        }

        // Need to determine all child datatypes that are descended from the deleted child datatype
        $deleted_datatype_ids = array($datatype_id => 0);

        // If the deleted datatype has children datatypes...
        if ( isset($tmp_datatree_array[$datatype_id]) ) {
            // ...then for each child datatype it has...
            $datatypes_to_check = $tmp_datatree_array[$datatype_id];
            while ( !empty($datatypes_to_check) ) {
                $tmp = array();
                foreach ($datatypes_to_check as $dt_id) {
                    // ...that child datatype also got deleted
                    $deleted_datatype_ids[$dt_id] = 0;

                    // ...and if that child datatype has children of its own...
                    if ( isset($tmp_datatree_array[$dt_id]) ) {
                        foreach ($tmp_datatree_array[$dt_id] as $num => $child_dt_id)
                            // ...check them as well
                            $tmp[] = $child_dt_id;
                    }
                }

                // Reset for next loop
                $datatypes_to_check = $tmp;
            }
        }
        $deleted_datatype_ids = array_keys($deleted_datatype_ids);


        // ----------------------------------------
        foreach ($deleted_datatype_ids as $dt_id) {
            // Most likely, 'cached_search_dt_'.$dt_id.'_dr_parents' is the only entry that actually
            //  needs deleting, and then only when linked datatypes are involved...but being
            //  thorough won't hurt.
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_datafields');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_public_status');

            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_dr_parents');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_linked_dr_parents');
            $this->cache_service->delete('cached_dt_'.$dt_id.'_dr_uuid_list');

            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_created');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_createdBy');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_modified');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_modifiedBy');
        }

        // Delete all cached search entries for all datafields of these datatypes
        self::clearCachedDatafieldsByDatatype($deleted_datatype_ids, true);

        // Delete all cached search entries for all radio options and tags in these datatypes
        self::clearCachedRadioOptionsByDatatype($deleted_datatype_ids, true);
        self::clearCachedTagsByDatatype($deleted_datatype_ids, true);

        // Also need to delete these cached search entries
        $this->cache_service->delete('cached_tag_tree_'.$grandparent_datatype_id);
        $this->cache_service->delete('cached_template_tag_tree_'.$grandparent_datatype_id);


        // ----------------------------------------
        // TODO - figure out whether there needs to be restrictions on when datatypes related to templates can be deleted
        // Need to determine whether any cached search entries for templates have to be deleted...
        $query =
           'SELECT grandparent.id AS grandparent_id, grandparent.is_master_type, mdt.id AS mdt_id
            FROM odr_data_type AS dt
            LEFT JOIN odr_data_type AS grandparent ON dt.grandparent_id = grandparent.id
            LEFT JOIN odr_data_type AS mdt ON grandparent.master_datatype_id = mdt.id
            WHERE dt.id = '.$datatype_id;
        $results = $conn->fetchAll($query);

        $template_datatype_id = null;
        foreach ($results as $result) {
            // Should only be one result here
            $grandparent_id = $result['grandparent_id'];
            $is_template = $result['is_master_type'];
            $mdt_id = $result['mdt_id'];

            if ( $is_template ) {
                // If the datatype that just got deleted was a template, then save its id
                $template_datatype_id = $grandparent_id;
            }
            else if ( !is_null($mdt_id) ) {
                // If the datatype that just got deleted was derived from a template, then save its
                //  template's id
                $template_datatype_id = $mdt_id;
            }
            // Otherwise, don't need to do anything here
        }

        if ( !is_null($template_datatype_id) ) {
            // Regardless of whether the datatype that just got deleted was the master template, or
            //  one of those derived from it...need to determine the ids and uuids of all datatypes
            //  which are children of the master template
            $query =
               'SELECT mdt.id AS mdt_id, mdt.unique_id AS mdt_uuid
                FROM odr_data_type AS mdt
                WHERE mdt.grandparent_id = '.$template_datatype_id;
            $results = $conn->fetchAll($query);

            if ( is_array($results) ) {
                foreach ($results as $result) {
                    $mdt_id = $result['mdt_id'];
                    $mdt_uuid = $result['mdt_uuid'];

                    $this->cache_service->delete('cached_search_template_dt_'.$mdt_id.'_datafields');
                    $this->cache_service->delete('cached_search_template_dt_'.$mdt_uuid.'_dr_list');
                }
            }
        }
    }


    /**
     * Deletes relevant search cache entries when a datatype's public status is changed.
     *
     * @param DatatypePublicStatusChangedEvent $event
     */
    public function onDatatypePublicStatusChange(DatatypePublicStatusChangedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatatypePublicStatusChange()', $event->getErrorInfo());

        $datatype = $event->getDatatype();

        // This entry has the datatype's public date in it, so it should be cleared
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_datafields');
        $this->cache_service->delete('cached_search_template_dt_'.$datatype->getUniqueId().'_datafields');
    }


    /**
     * Deletes relevant search cache entries when a datatype is linked to or unlinked from another
     * datatype.
     *
     * @param DatatypeLinkStatusChangedEvent $event
     */
    public function onDatatypeLinkStatusChange(DatatypeLinkStatusChangedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatatypeLinkStatusChange()', $event->getErrorInfo());

//        $ancestor_datatype = $event->getAncestorDatatype();
//        $new_descendant_datatype = $event->getNewDescendantDatatype();
        $previous_descendant_datatype = $event->getPreviousDescendantDatatype();

        // If the ancestor datatype was unlinked from a descendant datatype...
        if ( !is_null($previous_descendant_datatype) )
            // ...then need to clear this cache entry
            $this->cache_service->delete('cached_search_dt_'.$previous_descendant_datatype->getId().'_linked_dr_parents');

        // Don't need to clear anything for the new descendant datatype

        // Also don't need to clear the 'cached_search_template_dt_'.$master_dt_uuid.'_dr_list' entry
        //  here, since it doesn't contain any information about linking
    }


    /**
     * Deletes relevant search cache entries when a datafield is created.
     *
     * @param DatafieldCreatedEvent $event
     */
    public function onDatafieldCreate(DatafieldCreatedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatafieldCreate()', $event->getErrorInfo());

        $datatype = $event->getDatafield()->getDataType();

        // Need to delete these entries...
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_datafields');
        $this->cache_service->delete('cached_search_template_dt_'.$datatype->getUniqueId().'_datafields');
    }


    /**
     * Deletes relevant search cache entries when a datafield has its value changed, or has its
     * fieldtype changed.
     *
     * @param DatafieldModifiedEvent $event
     */
    public function onDatafieldModify(DatafieldModifiedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatafieldModify()', $event->getErrorInfo());

        $datafield = $event->getDatafield();
        $datafield_id = $datafield->getId();
        $datatype_id = $datafield->getDataType()->getId();
        $typeclass = $datafield->getFieldType()->getTypeClass();

        $master_datafield_uuid = null;
        if ( !is_null($datafield->getMasterDataField()) )
            $master_datafield_uuid = $datafield->getMasterDataField()->getFieldUuid();

        // Modifying and deleting a datafield requires the same clearing of search cache entries
        self::clearDatafieldEntries(
            $datafield_id,
            $datatype_id,
            $typeclass,
            $master_datafield_uuid
        );
    }


    /**
     * Deletes relevant search cache entries when a datafield is deleted.
     *
     * @param DatafieldDeletedEvent $event
     */
    public function onDatafieldDelete(DatafieldDeletedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatafieldDelete()', $event->getErrorInfo());

        $datafield_id = $event->getDatafieldId();
        $datatype_id = $event->getDatatype()->getId();

        // Going to use native SQL to get around doctrine's soft-deleteable filter...I'm not
        //  confident it works properly when dealing with potentially async situations
        $conn = $this->em->getConnection();
        $query =
           'SELECT ft.type_class, mdf.unique_id
            FROM odr_data_fields df
            LEFT JOIN odr_data_fields_meta dfm ON dfm.data_field_id = df.id
            LEFT JOIN odr_field_type ft ON dfm.field_type_id = ft.id
            LEFT JOIN odr_data_fields mdf ON df.master_datafield_id = mdf.id
            WHERE df.id = '.$datafield_id;
        $results = $conn->fetchAll($query);

        // Unfortunately, there are likely to be multiple unwanted datafieldMeta entries in here...
        $typeclass = null;
        $master_datafield_uuid = null;
        foreach ($results as $result) {
            $a = 1;
        }

        // Modifying and deleting a datafield requires the same clearing of search cache entries
        self::clearDatafieldEntries(
            $datafield_id,
            $datatype_id,
            $typeclass,
            $master_datafield_uuid
        );

        // Also need to delete these entries
        $this->cache_service->delete('cached_search_dt_'.$datatype_id.'_datafields');
        $this->cache_service->delete('cached_search_template_dt_'.$datatype_id.'_datafields');
    }


    /**
     * Does the work of clearing cached datatype entries when a datafield is modified or deleted.
     *
     * @param int $datafield_id
     * @param int $datatype_id
     * @param string $typeclass
     * @param string|null $master_datafield_uuid
     */
    private function clearDatafieldEntries($datafield_id, $datatype_id, $typeclass, $master_datafield_uuid = null)
    {
        // While it's technically possible to selectively delete portions of the cached entry, it's
        //  really not worthwhile
        $this->cache_service->delete('cached_search_df_'.$datafield_id);
        $this->cache_service->delete('cached_search_df_'.$datafield_id.'_ordering');
        $this->cache_service->delete('cached_search_dt_'.$datatype_id.'_datafields');

        if ( !is_null($master_datafield_uuid) ) {
            $this->cache_service->delete('cached_search_template_df_'.$master_datafield_uuid);
            $this->cache_service->delete('cached_search_template_df_'.$master_datafield_uuid.'_ordering');
            $this->cache_service->delete('cached_search_template_df_'.$master_datafield_uuid.'_fieldstats');
        }

        // If the datafield is a radio options or tag datafield, then any change should also delete
        //  all of the cached radio options or tags associated with this datafield
        if ($typeclass === 'Radio')
            self::clearCachedRadioOptionsByDatafield( array($datafield_id) );
        else if ($typeclass === 'Tag')
            self::clearCachedTagsByDatafield( array($datafield_id) );
    }


    /**
     * Deletes relevant search cache entries when a datarecord is created in the given datatype.
     *
     * @param DatarecordCreatedEvent $event
     */
    public function onDatarecordCreate(DatarecordCreatedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatarecordCreate()', $event->getErrorInfo());

        $datatype = $event->getDatarecord()->getDataType();

        // ----------------------------------------
        // If a datarecord was created, then this needs to be rebuilt
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_dr_parents');
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_linked_dr_parents');
        $this->cache_service->delete('cached_dt_'.$datatype->getId().'_dr_uuid_list');

        if ( !is_null($datatype->getMasterDataType()) ) {
            $master_dt_uuid = $datatype->getMasterDataType()->getUniqueId();
            $this->cache_service->delete('cached_search_template_dt_'.$master_dt_uuid.'_dr_list');
        }

        // These could be made more precise...but that's likely overkill except for public_status
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_created');
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_createdBy');
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_modified');
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_modifiedBy');
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_public_status');


        // ----------------------------------------
        // Technically only need to delete datafield searches that involve the empty string
        // However, determining that takes too much effort...just delete all cached datafield
        //  entries for this datatype
        self::clearCachedDatafieldsByDatatype( array($datatype->getId()) );

        // Technically only need to delete all "unselected" entries from the cached radio option
        //  entries for this datatype, but it takes as much effort to rebuild the "unselected"
        //  section as it does to rebuild both "unselected" and "selected"
        self::clearCachedRadioOptionsByDatatype( array($datatype->getId()) );

        // Same deal for tag datafields
        self::clearCachedTagsByDatatype( array($datatype->getId()) );
    }


    /**
     * Deletes relevant search cache entries when a datarecord is modified.
     *
     * @param DatarecordModifiedEvent $event
     */
    public function onDatarecordModify(DatarecordModifiedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatarecordModify()', $event->getErrorInfo());

        // DatarecordModified and DatarecordPublicStatusChanged events need to clear the same
        //  search cache entries
        $datarecord = $event->getDatarecord();
        self::clearCachedDatarecordEntries($datarecord);
    }


    /**
     * Deletes relevant search cache entries when a datarecord is deleted.
     *
     * @param DatarecordDeletedEvent $event
     */
    public function onDatarecordDelete(DatarecordDeletedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatarecordDelete()', $event->getErrorInfo());

        $datatype = $event->getDatatype();

        // ----------------------------------------
        // If a datarecord was deleted, then these need to be rebuilt
        $related_datatypes = $this->search_service->getRelatedDatatypes($datatype->getId());

        foreach ($related_datatypes as $num => $dt_id) {
            // Would have to search through each of these entries to see whether they matched the
            //  deleted datarecord...faster to just wipe all of them
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_dr_parents');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_linked_dr_parents');
            $this->cache_service->delete('cached_dt_'.$dt_id.'_dr_uuid_list');

            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_public_status');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_created');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_createdBy');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_modified');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_modifiedBy');
        }

        // Unlike onDatatypeDelete(), deleting a single datarecord only affects at most one of
        //  these cache entries
        if ( !is_null($datatype->getMasterDataType()) ) {
            $master_dt_uuid = $datatype->getMasterDataType()->getUniqueId();
            $this->cache_service->delete('cached_search_template_dt_'.$master_dt_uuid.'_dr_list');
        }


        // ----------------------------------------
        // Technically only need to delete datafield searches that involve the empty string
        // However, determining that takes too much effort...just delete all cached datafield
        //  entries for this datatype
        self::clearCachedDatafieldsByDatatype($related_datatypes);

        // Technically only need to delete all "unselected" entries from the cached radio option
        //  entries for this datatype, but it takes as much effort to rebuild the "unselected"
        //  section as it does to rebuild both "unselected" and "selected"
        self::clearCachedRadioOptionsByDatatype($related_datatypes);

        // Same theory for tag datafields
        self::clearCachedTagsByDatatype($related_datatypes);
    }


    /**
     * Deletes relevant search cache entries when a datarecord has its public status changed.
     *
     * @param DatarecordPublicStatusChangedEvent $event
     */
    public function onDatarecordPublicStatusChange(DatarecordPublicStatusChangedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatarecordPublicStatusChange()', $event->getErrorInfo());

        // DatarecordModified and DatarecordPublicStatusChanged events need to clear the same
        //  search cache entries
        $datarecord = $event->getDatarecord();
        self::clearCachedDatarecordEntries($datarecord);
    }


    /**
     * Does the work of clearing cached relevant datarecord entries when a datarecord is modified,
     * or its public status changes.
     *
     * @param DataRecord $datarecord
     */
    private function clearCachedDatarecordEntries($datarecord)
    {
        $datatype = $datarecord->getDataType();

        // ----------------------------------------
        // Would have to search through each cached search entry to be completely accurate with
        //  deletion...but that would take too long
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_modified');

        // Technically only need to delete two cached search entries in this...whoever modified it,
        //  and whoever modified it previously...but not worthwhile to do
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_modifiedBy');

        // Technically this should be in its own "event"-like function, but betting that it
        //  won't be common enough to have a performance penalty
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_public_status');


        // ----------------------------------------
        // Don't need to delete any cached search entries for datafields...if this modification was
        //  due to a change to a datafield, then that's handled elsewhere.  If not, then there's no
        //  need to delete anything datafield related.
    }


    /**
     * Handles dispatched DatatypeLinkStatusChanged events.
     *
     * @param DatarecordLinkStatusChangedEvent $event
     *
     * @throws \Throwable
     */
    public function onDatarecordLinkStatusChange(DatarecordLinkStatusChangedEvent $event)
    {
        if ( $this->debug )
            $this->logger->debug('SearchCacheService::onDatarecordLinkStatusChange()', $event->getErrorInfo());

        $datatype = $event->getDescendantDatatype();

        // If something now (or no longer) links to $descendant_datatype, then these cache entries
        //  need to be deleted
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_linked_dr_parents');

        // Don't need to clear the 'cached_search_template_dt_'.$master_dt_uuid.'_dr_list' entry here
        // It doesn't contain any information about linking
    }
}
