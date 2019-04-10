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
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class SearchCacheService
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
     * SearchCacheService constructor.
     *
     * @param EntityManager $entityManager
     * @param CacheService $cacheService
     * @param SearchService $searchService
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
    }


    // TODO - no real need to clear search cache entries on datatype create?  None of the cache entries would exist beforehand...

    // Don't need an onDatatypeModify() function...at the moment, none of the properties of a
    //  datatype, other than public status, have any effect on the results of a search


    /**
     * When a datatype is deleted, it also deletes a mess of datarecords, datafields, and could
     * also end up deleting a pile of other child datatypes...so anything related to the datatype
     * being deleted needs to be cleared.
     *
     * @param DataType $datatype
     */
    public function onDatatypeDelete($datatype)
    {
        $grandparent_datatype = $datatype->getGrandparent();

        // ----------------------------------------
        $related_datatypes = $this->search_service->getRelatedDatatypes($grandparent_datatype->getId());
        foreach ($related_datatypes as $num => $dt_id) {
            // Most likely, 'cached_search_dt_'.$dt_id.'_dr_parents' is the only entry that actually
            //  needs deleting, and then only when linked datatypes are involved...but being
            //  thorough won't hurt.
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_datafields');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_public_status');

            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_dr_parents');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_linked_dr_parents');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_created');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_createdBy');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_modified');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_modifiedBy');
        }


        // TODO - figure out whether there needs to be restrictions on when datatypes related to templates can be deleted
        if ( !is_null($grandparent_datatype->getMasterDataType()) ) {
            // If this datatype was derived from a master template...
            $master_datatype = $datatype->getGrandparent()->getMasterDataType();

            // ...then the cache entries that reference this datatype's datafields need to get cleared
            $related_datatypes = $this->search_service->getRelatedTemplateDatatypes($master_datatype->getUniqueId());
            foreach ($related_datatypes as $num => $dt_uuid)
                $this->cache_service->delete('cached_search_template_dt_'.$dt_uuid.'_datafields');
        }
        else if ( $grandparent_datatype->getIsMasterType() ) {
            // If the datatype being deleted is a master template, then delete the cache entries
            //  that store the datafields for all datatypes derived from this template
            $related_datatypes = $this->search_service->getRelatedTemplateDatatypes($grandparent_datatype->getUniqueId());
            foreach ($related_datatypes as $num => $dt_uuid)
                $this->cache_service->delete('cached_search_template_dt_'.$dt_uuid.'_datafields');
        }


        // ----------------------------------------
        // Don't have any convenient cache entry, so need to run a query to locate all master
        //  datatypes of the datatypes that are going to be deleted
        $query = $this->em->createQuery(
           'SELECT mdt.unique_id
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:DataType AS mdt WITH dt.masterDataType = mdt
            WHERE dt.id IN (:datatype_ids)
            AND dt.deletedAt IS NULL AND mdt.deletedAt IS NULL'
        ) ->setParameters(
            array(
                'datatype_ids' => $related_datatypes
            )
        );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result)
                $this->cache_service->delete('cached_search_template_dt_'.$result['unique_id'].'_dr_list');
        }


        // ----------------------------------------
        // Delete all cached search entries for all datafields of these datatypes
        $query = $this->em->createQuery(
           'SELECT df.id AS df_id, df.templateFieldUuid AS template_field_uuid
            FROM ODRAdminBundle:DataFields AS df
            WHERE df.dataType IN (:datatype_ids)
            AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $related_datatypes) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $df_id = $result['df_id'];
                $template_df_uuid = $result['template_field_uuid'];

                $this->cache_service->delete('cached_search_df_'.$df_id);
                $this->cache_service->delete('cached_search_df_'.$df_id.'_ordering');

                if ( !is_null($template_df_uuid) ) {
                    $this->cache_service->delete('cached_search_template_df_'.$template_df_uuid);
                    $this->cache_service->delete('cached_search_template_df_'.$template_df_uuid.'_ordering');
                    $this->cache_service->delete('cached_search_template_df_'.$template_df_uuid.'_fieldstats');
                }
            }
        }

        // Delete all cached search entries for all radio options in these datatypes
        $query = $this->em->createQuery(
           'SELECT ro.id AS ro_id, ro.radioOptionUuid AS ro_uuid
            FROM ODRAdminBundle:RadioOptions AS ro
            JOIN ODRAdminBundle:DataFields AS df WITH ro.dataField = df
            WHERE df.dataType IN (:datatype_ids)
            AND ro.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $related_datatypes) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $ro_id = $result['ro_id'];
                $ro_uuid = $result['ro_uuid'];

                $this->cache_service->delete('cached_search_ro_'.$ro_id);

                if ( !is_null($ro_uuid) )
                    $this->cache_service->delete('cached_search_template_ro_'.$ro_uuid);
            }
        }

        // Delete all cached search entries for all tags in these datatypes
        $this->cache_service->delete('cached_tag_tree_'.$grandparent_datatype->getId());
        $this->cache_service->delete('cached_template_tag_tree_'.$grandparent_datatype->getId());

        $query = $this->em->createQuery(
           'SELECT t.id AS t_id, t.tagUuid AS t_uuid
            FROM ODRAdminBundle:Tags AS t
            JOIN ODRAdminBundle:DataFields AS df WITH t.dataField = df
            WHERE df.dataType IN (:datatype_ids)
            AND t.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $related_datatypes) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $t_id = $result['t_id'];
                $t_uuid = $result['t_uuid'];

                $this->cache_service->delete('cached_search_tag_'.$t_id);

                if ( !is_null($t_uuid) )
                    $this->cache_service->delete('cached_search_template_tag_'.$t_uuid);
            }
        }
    }


    /**
     * There's no telling exactly what happens when a CSV Import is run on a datatype, so a whole
     * pile of search cache entries should be deleted upon completion.
     *
     * @param DataType $datatype
     */
    public function onDatatypeImport($datatype)
    {
        // Both deletion of a datatype and importing into a datatype typically require deletion of
        //  every single search cache entry that is related to a datatype
        self::onDatatypeDelete($datatype);

        // cached_search_dt_'.$dt_id.'_datafields and 'cached_search_dt_'.$dt_id.'_public_status'
        //  probably don't need to be deleted, but rebuilding them is fast enough for how
        //  infrequently this'll be called
    }


    /**
     * Deletes relevant search cache entries when a datatype's public status is changed.
     *
     * @param DataType $datatype
     */
    public function onDatatypePublicStatusChange($datatype)
    {
        // This entry has the datatype's public date in it, so it should be cleared
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_datafields');
        $this->cache_service->delete('cached_search_template_dt_'.$datatype->getUniqueId().'_datafields');
    }


    /**
     * Deletes relevant search cache entries when a datafield is created.
     *
     * @param DataFields $datafield
     */
    public function onDatafieldCreate($datafield)
    {
        $datatype = $datafield->getDataType();

        // Need to delete these entries...
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_datafields');
        $this->cache_service->delete('cached_search_template_dt_'.$datatype->getUniqueId().'_datafields');
    }


    /**
     * Deletes relevant search cache entries when a datafield has its value changed, or has its
     * fieldtype changed.
     *
     * @param DataFields $datafield
     */
    public function onDatafieldModify($datafield)
    {
        // While it's technically possible to selectively delete portions of the cached entry, it's
        //  really not worthwhile
        $this->cache_service->delete('cached_search_df_'.$datafield->getId());
        $this->cache_service->delete('cached_search_df_'.$datafield->getId().'_ordering');

        if ( !is_null($datafield->getMasterDataField()) ) {
            $master_df_uuid = $datafield->getMasterDataField()->getFieldUuid();
            $this->cache_service->delete('cached_search_template_df_'.$master_df_uuid);
            $this->cache_service->delete('cached_search_template_df_'.$master_df_uuid.'_ordering');
            $this->cache_service->delete('cached_search_template_df_'.$master_df_uuid.'_fieldstats');
        }

        // If the datafield is a radio options datafield, then any change should also delete all of
        //  the cached radio options associated with this datafield
        if ($datafield->getFieldType()->getTypeClass() === 'Radio') {
            $query = $this->em->createQuery(
               'SELECT ro.id AS ro_id, ro.radioOptionUuid AS ro_uuid
                FROM ODRAdminBundle:RadioOptions AS ro
                WHERE ro.dataField = :datafield_id
                AND ro.deletedAt IS NULL'
            )->setParameters( array('datafield_id' => $datafield->getId()) );
            $results = $query->getArrayResult();

            if ( is_array($results) ) {
                foreach ($results as $result) {
                    $ro_id = $result['ro_id'];
                    $ro_uuid = $result['ro_uuid'];

                    $this->cache_service->delete('cached_search_ro_'.$ro_id);

                    if ( !is_null($ro_uuid) )
                        $this->cache_service->delete('cached_search_template_ro_'.$ro_uuid);
                }
            }
        }
        // Do the same if it's a tag datafield
        else if ($datafield->getFieldType()->getTypeClass() === 'Tag') {
            $query = $this->em->createQuery(
               'SELECT t.id AS t_id, t.tagUuid AS t_uuid
                FROM ODRAdminBundle:Tags AS t
                WHERE t.dataField = :datafield_id
                AND t.deletedAt IS NULL'
            )->setParameters( array('datafield_id' => $datafield->getId()) );
            $results = $query->getArrayResult();

            if ( is_array($results) ) {
                foreach ($results as $result) {
                    $t_id = $result['t_id'];
                    $t_uuid = $result['t_uuid'];

                    $this->cache_service->delete('cached_search_tag_'.$t_id);

                    if ( !is_null($t_uuid) )
                        $this->cache_service->delete('cached_search_template_tag_'.$t_uuid);
                }
            }
        }
    }


    /**
     * Deletes relevant search cache entries when a datafield is deleted.
     *
     * @param Datafields $datafield
     */
    public function onDatafieldDelete($datafield)
    {
        // The cache entries deleted by this function also need to be deleting when a datafield
        //  is modified
        self::onDatafieldModify($datafield);

        // Also need to delete this entry
        $datatype = $datafield->getDataType();
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_datafields');
        $this->cache_service->delete('cached_search_template_dt_'.$datatype->getUniqueId().'_datafields');
    }


    /**
     * Deletes relevant search cache entries when a datafield has its public status changed.
     *
     * @param DataFields $datafield
     */
    public function onDatafieldPublicStatusChange($datafield)
    {
        $datatype = $datafield->getDataType();

        // Need to delete these entries...
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_datafields');

        // "cached_search_template_dt_<template_uuid>_datafields"  does not store public dates, so
        //  it doesn't need to be cleared
    }


    /**
     * Deletes relevant search cache entries when a datarecord is created in the given datatype.
     *
     * @param DataType $datatype
     */
    public function onDatarecordCreate($datatype)
    {
        // ----------------------------------------
        // If a datarecord was created, then this needs to be rebuilt
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_dr_parents');
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_linked_dr_parents');

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
        $query = $this->em->createQuery(
           'SELECT df.id AS df_id, df.templateFieldUuid AS template_field_uuid
            FROM ODRAdminBundle:DataFields AS df
            WHERE df.dataType = :datatype_id
            AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $df_id = $result['df_id'];
                $template_df_uuid = $result['template_field_uuid'];

                $this->cache_service->delete('cached_search_df_'.$df_id);
                $this->cache_service->delete('cached_search_df_'.$df_id.'_ordering');

                if ( !is_null($template_df_uuid) ) {
                    $this->cache_service->delete('cached_search_template_df_'.$template_df_uuid);
                    $this->cache_service->delete('cached_search_template_df_'.$template_df_uuid.'_ordering');
                }
            }
        }

        // Technically only need to delete all "unselected" entries from the cached radio option
        //  entries for this datatype, but it takes as much effort to rebuild the "unselected"
        //  section as it does to rebuild both "unselected" and "selected"
        $query = $this->em->createQuery(
           'SELECT ro.id AS ro_id, ro.radioOptionUuid AS ro_uuid
            FROM ODRAdminBundle:RadioOptions AS ro
            JOIN ODRAdminBundle:DataFields AS df WITH ro.dataField = df
            WHERE df.dataType = :datatype_id
            AND ro.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $ro_id = $result['ro_id'];
                $ro_uuid = $result['ro_uuid'];

                $this->cache_service->delete('cached_search_ro_'.$ro_id);

                if ( !is_null($ro_uuid) )
                    $this->cache_service->delete('cached_search_template_ro_'.$ro_uuid);
            }
        }

        // Same deal for tag datafields
        $query = $this->em->createQuery(
           'SELECT t.id AS t_id, t.tagUuid AS t_uuid
            FROM ODRAdminBundle:Tags AS t
            JOIN ODRAdminBundle:DataFields AS df WITH t.dataField = df
            WHERE df.dataType = :datatype_id
            AND t.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $t_id = $result['t_id'];
                $t_uuid = $result['t_uuid'];

                $this->cache_service->delete('cached_search_tag_'.$t_id);

                if ( !is_null($t_uuid) )
                    $this->cache_service->delete('cached_search_template_tag_'.$t_uuid);
            }
        }
    }


    /**
     * Deletes relevant search cache entries when a datarecord is modified.
     *
     * @param DataRecord $datarecord
     */
    public function onDatarecordModify($datarecord)
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
     * Deletes relevant search cache entries when a datarecord is deleted.
     *
     * @param DataType $datatype
     */
    public function onDatarecordDelete($datatype)
    {
        // ----------------------------------------
        // If a datarecord was deleted, then these need to be rebuilt
        $related_datatypes = $this->search_service->getRelatedDatatypes($datatype->getId());

        foreach ($related_datatypes as $num => $dt_id) {
            // Would have to search through each of these entries to see whether they matched the
            //  deleted datarecord...faster to just wipe all of them
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_dr_parents');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_linked_dr_parents');
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
        $query = $this->em->createQuery(
           'SELECT df.id AS df_id, df.templateFieldUuid AS template_field_uuid
            FROM ODRAdminBundle:DataFields AS df
            WHERE df.dataType IN (:datatype_ids)
            AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $related_datatypes) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $df_id = $result['df_id'];
                $template_df_uuid = $result['template_field_uuid'];

                $this->cache_service->delete('cached_search_df_'.$df_id);
                $this->cache_service->delete('cached_search_df_'.$df_id.'_ordering');

                if ( !is_null($template_df_uuid) ) {
                    $this->cache_service->delete('cached_search_template_df_'.$template_df_uuid);
                    $this->cache_service->delete('cached_search_template_df_'.$template_df_uuid.'_ordering');
                }
            }
        }

        // Technically only need to delete all "unselected" entries from the cached radio option
        //  entries for this datatype, but it takes as much effort to rebuild the "unselected"
        //  section as it does to rebuild both "unselected" and "selected"
        $query = $this->em->createQuery(
           'SELECT ro.id AS ro_id, ro.radioOptionUuid AS ro_uuid
            FROM ODRAdminBundle:RadioOptions AS ro
            JOIN ODRAdminBundle:DataFields AS df WITH ro.dataField = df
            WHERE df.dataType IN (:datatype_ids)
            AND ro.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $related_datatypes) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $ro_id = $result['ro_id'];
                $ro_uuid = $result['ro_uuid'];

                $this->cache_service->delete('cached_search_ro_'.$ro_id);

                if ( !is_null($ro_uuid) )
                    $this->cache_service->delete('cached_search_template_ro_'.$ro_uuid);
            }
        }

        // Same theory for tag datafields
        $query = $this->em->createQuery(
           'SELECT t.id AS t_id, t.tagUuid AS t_uuid
            FROM ODRAdminBundle:Tags AS t
            JOIN ODRAdminBundle:DataFields AS df WITH t.dataField = df
            WHERE df.dataType = :datatype_id
            AND t.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $t_id = $result['t_id'];
                $t_uuid = $result['t_uuid'];

                $this->cache_service->delete('cached_search_tag_'.$t_id);

                if ( !is_null($t_uuid) )
                    $this->cache_service->delete('cached_search_template_tag_'.$t_uuid);
            }
        }
    }


    /**
     * Deletes relevant search cache entries when a datarecord has its public status changed.
     *
     * @param DataRecord $datarecord
     */
    public function onDatarecordPublicStatusChange($datarecord)
    {
        // Just alias this to onDatarecordModify() for right now
        self::onDatarecordModify($datarecord);
    }


    /**
     * Deletes relevant search cache entries when a datarecord (or a datatype) is linked to or
     * unlinked from.
     *
     * @param DataType $descendant_datatype
     */
    public function onLinkStatusChange($descendant_datatype)
    {
        // ----------------------------------------
        // If something now (or no longer) links to $descendant_datatype, then these cache entries
        //  need to be deleted
        $this->cache_service->delete('cached_search_dt_'.$descendant_datatype->getId().'_linked_dr_parents');

        // Don't need to clear the 'cached_search_template_dt_'.$master_dt_uuid.'_dr_list' entry here
        // It doesn't contain any information about linking
    }
}
