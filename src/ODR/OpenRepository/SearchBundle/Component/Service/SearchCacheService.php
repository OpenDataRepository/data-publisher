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
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
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
     * @var Logger
     */
    private $logger;


    /**
     * SearchCacheService constructor.
     *
     * @param EntityManager $entityManager
     * @param CacheService $cache_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entityManager,
        CacheService $cache_service,
        Logger $logger
    )
    {
        $this->em = $entityManager;
        $this->cache_service = $cache_service;
        $this->logger = $logger;
    }


    /**
     * @deprecated use SearchKeyService::encodeSearchKey()
     * Converts an array of search parameters into a url-safe base64 string.
     *
     * @param array $search_params
     *
     * @return string
     */
    public function encodeSearchKey($search_params)
    {
        // Always sort the array to ensure it comes out the same
        ksort($search_params);
        // Encode the search string and strip any padding characters at the end
        $encoded = rtrim( base64_encode(json_encode($search_params)), '=' );

        // Replace all occurrences of the '+' character with '-', and the '/' character with '_'
        return strtr($encoded, '+/', '-_');
    }


    /**
     * @deprecated use SearchKeyService::decodeSearchKey()
     * Converts a search key back into an array of search parameters.
     *
     * @param string $search_key
     *
     * @return array
     */
    public function decodeSearchKey($search_key)
    {
        // Replace all occurrences of the '-' character with '+', and the '_' character with '/'
        $decoded = base64_decode( strtr($search_key, '-_', '+/') );

        // Return an array instead of an object
        $array = json_decode($decoded, true);
        ksort($array);
        if ( is_null($array) )
            throw new ODRException('Invalid JSON', 400, 0x6e1c96a1);
        else
            return $array;
    }


    /**
     * Deletes all search results that have been cached for the given datatype.  Usually needed
     * when sweeping changes are made to the datatype...imports, mass updates, datatype deletions.
     *
     * Also currently used in places such as creating/deleting/mass updating datarecords.  These
     * kinds of changes (among others) would require parsing the criteria for each search to
     * accurately determine which cached search results to delete...this is too irritating to do
     * without a proper filtering search system.
     *
     * @param int $datatype_id
     */
    public function clearByDatatypeId($datatype_id)
    {
        // Get all cached search results
        $cached_searches = $this->cache_service->get('cached_search_results');

        // If this datatype has cached search results, delete them
        if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
            unset ( $cached_searches[$datatype_id] );
            $this->cache_service->set('cached_search_results', $cached_searches);
        }
    }


    /**
     * Deletes all cached search results that involve the given datafield.  Usually needed after
     * some change is made to the contents of a datafield, or public status for files/images gets
     * changed.
     *
     * @param int $datafield_id
     */
    public function clearByDatafieldId($datafield_id)
    {
        // Get all cached search results
        $cached_searches = $this->cache_service->get('cached_search_results');

        if ($cached_searches === false)
            return;

        foreach ($cached_searches as $dt_id => $dt) {
            foreach ($dt as $search_checksum => $search_data) {
                $searched_datafields = $search_data['searched_datafields'];
                $searched_datafields = explode(',', $searched_datafields);

                if ( in_array($datafield_id, $searched_datafields) )
                    unset( $cached_searches[$dt_id][$search_checksum] );
            }
        }

        // Save any remaining cached search results
        $this->cache_service->set('cached_search_results', $cached_searches);
    }


    /**
     * @deprecated
     */
    public function clearByDatarecordId()
    {
        throw new ODRNotImplementedException();

        // See if any cached search results need to be deleted...
        $cached_searches = $this->cache_service->get('cached_search_results');

        if ( $cached_searches !== false && isset($cached_searches[$datatype_id]) ) {
            // Delete all cached search results for this datatype that contained this now-deleted datarecord
            foreach ($cached_searches[$datatype_id] as $search_checksum => $search_data) {
                $datarecord_list = explode(',', $search_data['datarecord_list']['all']);    // if found in the list of all grandparents matching a search, just delete the entire cached search
                if ( in_array($datarecord_id, $datarecord_list) )
                    unset ( $cached_searches[$datatype_id][$search_checksum] );
            }

            // Save the collection of cached searches back to memcached
            $this->cache_service->set('cached_search_results', $cached_searches);
        }
    }



    // ----------------------------------------
    // ----------------------------------------
    // ----------------------------------------


    // No real need to clear search cache entries on datatype create/modify/delete


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

            foreach ($results as $result)
                $this->cache_service->delete('cached_search_ro_'.$result['id']);
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

        foreach ($results as $result)
            $this->cache_service->delete('cached_search_df_'.$result['id']);

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

        foreach ($results as $result)
            $this->cache_service->delete('cached_search_ro_'.$result['id']);
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
     * @param DataRecord $datarecord
     */
    public function onDatarecordDelete($datarecord)
    {
        $datatype = $datarecord->getDataType();

        // ----------------------------------------
        // If a datarecord was deleted, then these need to be rebuilt
        $this->cache_service->delete('cached_search_dt_'.$datatype->getId().'_dr_parents');


        // ----------------------------------------
        // Would have to search through each of these entries to see whether they matched the
        //  deleted datarecord...faster to just wipe all of them
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

        foreach ($results as $result)
            $this->cache_service->delete('cached_search_df_'.$result['id']);

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

        foreach ($results as $result)
            $this->cache_service->delete('cached_search_ro_'.$result['id']);
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
