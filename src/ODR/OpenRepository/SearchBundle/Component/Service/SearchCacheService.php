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
        EntityManager $entityManager,
        CacheService $cacheService,
        SearchService $searchService,
        Logger $logger
    )
    {
        $this->em = $entityManager;
        $this->cache_service = $cacheService;
        $this->search_service = $searchService;
        $this->logger = $logger;
    }


    // TODO - no real need to clear search cache entries on datatype create?  None of the cache entries would exist beforehand...

    // Don't need an onDatatypeModify() function...at the moment, none of the properties of a
    //  datatype, other than public status, have any effect on the results of a search


    /**
     * Most likely, 'cached_search_dt_'.$dt_id.'_dr_parents' is the only entry that would need
     * deleting, and then only when linked datatypes are involved...but being thorough won't hurt.
     *
     * @param DataType $datatype
     */
    public function onDatatypeDelete($datatype)
    {
        $related_datatypes = $this->search_service->getRelatedDatatypes($datatype->getGrandparent()->getId());

        foreach ($related_datatypes as $num => $dt_id) {
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_datafields');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_public_status');

            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_dr_parents');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_created');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_createdBy');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_modified');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_modifiedBy');
        }


        // ----------------------------------------
        // Delete all cached search entries for all datafields of these datatypes
        $query = $this->em->createQuery(
           'SELECT df.id
            FROM ODRAdminBundle:DataFields AS df
            WHERE df.dataType IN (:datatype_ids)
            AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $related_datatypes) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $this->cache_service->delete('cached_search_df_'.$result['id']);
                $this->cache_service->delete('cached_search_df_'.$result['id'].'_ordering');
            }
        }

        // Delete all cached search entries for all radio options in these datatypes
        $query = $this->em->createQuery(
           'SELECT ro.id
            FROM ODRAdminBundle:RadioOptions AS ro
            JOIN ODRAdminBundle:DataFields AS df WITH ro.dataField = df
            WHERE df.dataType IN (:datatype_ids)
            AND ro.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $related_datatypes) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result)
                $this->cache_service->delete('cached_search_ro_'.$result['id']);
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

        // If the datafield is a radio options datafield, then any change should also delete all of
        //  the cached radio options associated with this datafield
        if ($datafield->getFieldType()->getTypeClass() === 'Radio') {
            $query = $this->em->createQuery(
               'SELECT ro.id
                FROM ODRAdminBundle:RadioOptions AS ro
                WHERE ro.dataField = :datafield_id
                AND ro.deletedAt IS NULL'
            )->setParameters( array('datafield_id' => $datafield->getId()) );
            $results = $query->getArrayResult();

            if ( is_array($results) ) {
                foreach ($results as $result)
                    $this->cache_service->delete('cached_search_ro_'.$result['id']);
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
           'SELECT df.id
            FROM ODRAdminBundle:DataFields AS df
            WHERE df.dataType = :datatype_id
            AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $this->cache_service->delete('cached_search_df_'.$result['id']);
                $this->cache_service->delete('cached_search_df_'.$result['id'].'_ordering');
            }
        }

        // Technically only need to delete all "unselected" entries from the cached radio option
        //  entries for this datatype, but it takes as much effort to rebuild the "unselected"
        //  section as it does to rebuild both "unselected" and "selected"
        $query = $this->em->createQuery(
           'SELECT ro.id
            FROM ODRAdminBundle:RadioOptions AS ro
            JOIN ODRAdminBundle:DataFields AS df WITH ro.dataField = df
            WHERE df.dataType = :datatype_id
            AND ro.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype->getId()) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result)
                $this->cache_service->delete('cached_search_ro_'.$result['id']);
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
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_public_status');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_created');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_createdBy');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_modified');
            $this->cache_service->delete('cached_search_dt_'.$dt_id.'_modifiedBy');
        }


        // ----------------------------------------
        // Technically only need to delete datafield searches that involve the empty string
        // However, determining that takes too much effort...just delete all cached datafield
        //  entries for this datatype
        $query = $this->em->createQuery(
           'SELECT df.id
            FROM ODRAdminBundle:DataFields AS df
            WHERE df.dataType IN (:datatype_ids)
            AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $related_datatypes) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result) {
                $this->cache_service->delete('cached_search_df_'.$result['id']);
                $this->cache_service->delete('cached_search_df_'.$result['id'].'_ordering');
            }
        }

        // Technically only need to delete all "unselected" entries from the cached radio option
        //  entries for this datatype, but it takes as much effort to rebuild the "unselected"
        //  section as it does to rebuild both "unselected" and "selected"
        $query = $this->em->createQuery(
           'SELECT ro.id
            FROM ODRAdminBundle:RadioOptions AS ro
            JOIN ODRAdminBundle:DataFields AS df WITH ro.dataField = df
            WHERE df.dataType IN (:datatype_ids)
            AND ro.deletedAt IS NULL AND df.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $related_datatypes) );
        $results = $query->getArrayResult();

        if ( is_array($results) ) {
            foreach ($results as $result)
                $this->cache_service->delete('cached_search_ro_'.$result['id']);
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
        $this->cache_service->delete('cached_search_dt_'.$descendant_datatype->getId().'_dr_parents');
    }
}
