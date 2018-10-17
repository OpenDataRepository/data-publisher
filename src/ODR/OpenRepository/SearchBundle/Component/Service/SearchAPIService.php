<?php

/**
 * Open Data Repository Data Publisher
 * Search API Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This holds the endpoint functions required to setup and run a full-fledged ODR search.
 *
 */

namespace ODR\OpenRepository\SearchBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
// Services
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class SearchAPIService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var SearchCacheService
     */
    private $search_cache_service;

    /**
     * @var SearchKeyService
     */
    private $search_key_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * SearchAPIService constructor.
     *
     * @param EntityManager $entityManager
     * @param DatatypeInfoService $datatypeInfoService
     * @param SearchService $searchService
     * @param SearchCacheService $searchCacheService
     * @param SearchKeyService $searchKeyService
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entityManager,
        DatatypeInfoService $datatypeInfoService,
        SearchService $searchService,
        SearchCacheService $searchCacheService,
        SearchKeyService $searchKeyService,
        Logger $logger
    ) {
        $this->em = $entityManager;
        $this->dti_service = $datatypeInfoService;
        $this->search_service = $searchService;
        $this->search_cache_service = $searchCacheService;
        $this->search_key_service = $searchKeyService;
        $this->logger = $logger;
    }


    /**
     * Returns an array of searchable datafield ids, filtered to what the user can see, and
     * organized by their datatype id.
     *
     * @param int $top_level_datatype_id
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     *
     * @return array
     */
    public function getSearchableDatafieldsForUser($top_level_datatype_id, $user_permissions, $search_as_super_admin = false)
    {
        // Going to need to filter the resulting list based on the user's permissions
        $datatype_permissions = array();
        $datafield_permissions = array();
        if ( isset($user_permissions['datatypes']) )
            $datatype_permissions = $user_permissions['datatypes'];
        if ( isset($user_permissions['datafields']) )
            $datafield_permissions = $user_permissions['datafields'];


        // Get all possible datafields that can be searched on for this datatype
        $searchable_datafields = $this->search_service->getSearchableDatafields($top_level_datatype_id);
        foreach ($searchable_datafields as $dt_id => $datatype_data) {
            $is_public = true;
            if ( $datatype_data['dt_public_date'] === '2200-01-01' )
                $is_public = false;

            $can_view_dt = false;
            if ($search_as_super_admin)
                $can_view_dt = true;
            else if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dt_view']) )
                $can_view_dt = true;

            if ( !$is_public && !$can_view_dt ) {
                // User can't view datatype, filter it out
                unset( $searchable_datafields[$dt_id] );
            }
            else {
                // User can view datatype, filter datafields if needed...public datafields are always
                //  visible when the datatype can be viewed
                foreach ($datatype_data['datafields']['non_public'] as $df_id => $datafield_data) {
                    $can_view_df = false;
                    if ($search_as_super_admin)
                        $can_view_df = true;
                    else if ( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['view']) )
                        $can_view_df = true;

                    // If user can view datafield, move it out of the non_public section
                    if ( $can_view_df )
                        $searchable_datafields[$dt_id]['datafields'][$df_id] = $datafield_data;
                }

                // Get rid of the non_public section
                unset( $searchable_datafields[$dt_id]['datafields']['non_public'] );

                // Only want the array of datafield ids that the user can see
                $searchable_datafields[$dt_id] = $searchable_datafields[$dt_id]['datafields'];
            }
        }

        // Return the final list
        return $searchable_datafields;
    }


    /**
     * Returns a search key that is filtered to what the user can see...at the moment, ODR will
     * forcibly redirect the user to the filtered search key if it's different than what they
     * originally attempted to access, but this could change to be more refined in the future...
     *
     * @param DataType $datatype
     * @param string $search_key
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     *
     * @return string
     */
    public function filterSearchKeyForUser($datatype, $search_key, $user_permissions, $search_as_super_admin = false)
    {
        // Convert the search key into array format...
        $search_params = $this->search_key_service->decodeSearchKey($search_key);
        $filtered_search_params = array();

        // Get all the datatypes/datafields the user is allowed to search on...
        $searchable_datafields = self::getSearchableDatafieldsForUser($datatype->getId(), $user_permissions, $search_as_super_admin);

        foreach ($search_params as $key => $value) {
            if ($key === 'dt_id' || $key === 'gen') {
                // Don't need to do anything special with these keys
                $filtered_search_params[$key] = $value;
            }
            else if ( is_numeric($key) ) {
                // This is a datafield entry...
                $df_id = intval($key);

                foreach ($searchable_datafields as $dt_id => $datafields) {
                    if ( isset($datafields[$df_id]) ) {
                        // User can search on this datafield
                        $filtered_search_params[$key] = $value;
                    }
                }
            }
            else {
                $pieces = explode('_', $key);

                if ( is_numeric($pieces[0]) && count($pieces) === 2 ) {
                    // This is a DatetimeValue field...
                    $df_id = intval($pieces[0]);

                    foreach ($searchable_datafields as $dt_id => $datafields) {
                        if ( isset($datafields[$df_id]) ) {
                            // User can search on this datafield
                            $filtered_search_params[$key] = $value;
                        }
                    }
                }
                else {
                    // $key is one of the modified/created/modifiedBy/createdBy/publicStatus entries
                    $dt_id = intval($pieces[1]);

                    if ( isset($searchable_datafields[$dt_id]) ) {
                        // User can search on this datatype
                        $filtered_search_params[$key] = $value;
                    }
                }
            }
        }

        // Convert the filtered set of search parameters back into a search key and return it
        $filtered_search_key = $this->search_key_service->encodeSearchKey($filtered_search_params);
        return $filtered_search_key;
    }


    /**
     * Runs a search specified by the given $search_key.  The contents of the search key are
     * silently tweaked based on the user's permissions.
     *
     * @param DataType $datatype
     * @param string $search_key
     * @param array $user_permissions     The permissions of the user doing the search, or an empty
     *                                    array when not logged in
     * @param int $sort_df_id             The id of the datafield to sort by, or 0 to sort by
     *                                    whatever is default for the datatype
     * @param bool $sort_ascending        If true, sort ascending...if false, sort descending
     *                                    instead
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     *
     * @return array
     */
    public function performSearch($datatype, $search_key, $user_permissions, $sort_df_id = 0, $sort_ascending = true, $search_as_super_admin = false)
    {
        // ----------------------------------------
        // Convert the search key into a format suitable for searching
        $searchable_datafields = self::getSearchableDatafieldsForUser($datatype->getId(), $user_permissions, $search_as_super_admin);
        $criteria = $this->search_key_service->convertSearchKeyToCriteria($search_key, $searchable_datafields);

        // Need to grab hydrated versions of the datafields/datatypes being searched on
        $hydrated_entities = self::hydrateCriteria($criteria);

        // Each datatype being searched on (or the datatype of a datafield being search on) needs
        //  to be initialized to "-1" (does not match) before the results of each facet search
        //  are merged together into the final array
        $affected_datatypes = $criteria['affected_datatypes'];
        unset( $criteria['affected_datatypes'] );

        // Also don't want the list of all datatypes anymore either
        unset( $criteria['all_datatypes'] );


        // ----------------------------------------
        // Get the base information needed so getSearchArrays() can properly setup the search arrays
        $search_permissions = self::getSearchPermissionsArray($hydrated_entities['datatype'], $affected_datatypes, $user_permissions, $search_as_super_admin);

        // Going to need these two arrays to be able to accurately determine which datarecords
        //  end up matching the query
        $search_arrays = self::getSearchArrays($datatype->getId(), $search_permissions);
        $flattened_list = $search_arrays['flattened'];
        $inflated_list = $search_arrays['inflated'];


        // Need to keep track of the result list for each facet separately...they end up merged
        //  together after all facets are searched on
        $facet_dr_list = array();
        foreach ($criteria as $facet => $facet_data) {
            // Need to keep track of the matches for each facet individually
            $facet_dr_list[$facet] = null;
            $merge_type = $facet_data['merge_type'];
            $search_terms = $facet_data['search_terms'];

            // For each search term within this facet...
            foreach ($search_terms as $key => $search_term) {
                // ...extract the entity for this search term
                $entity_type = $search_term['entity_type'];
                $entity_id = $search_term['entity_id'];
                /** @var DataType|DataFields $entity */
                $entity = $hydrated_entities[$entity_type][$entity_id];

                // Run/load the desired query based on the criteria
                $dr_list = array();
                if ($key === 'created')
                    $dr_list = $this->search_service->searchCreatedDate($entity, $search_term['before'], $search_term['after']);
                else if ($key === 'createdBy')
                    $dr_list = $this->search_service->searchCreatedBy($entity, $search_term['user']);
                else if ($key === 'modified')
                    $dr_list = $this->search_service->searchModifiedDate($entity, $search_term['before'], $search_term['after']);
                else if ($key === 'modifiedBy')
                    $dr_list = $this->search_service->searchModifiedBy($entity, $search_term['user']);
                else if ($key === 'publicStatus')
                    $dr_list = $this->search_service->searchPublicStatus($entity, $search_term['value']);
                else {
                    // Datafield search depends on the typeclass of the field
                    $typeclass = $entity->getFieldType()->getTypeClass();

                    if ($typeclass === 'Boolean') {
                        // Only split from the text/number searches to avoid parameter confusion
                        $dr_list = $this->search_service->searchBooleanDatafield($entity, $search_term['value']);
                    }
                    else if ($typeclass === 'Radio' && $facet === 'general') {
                        // General search only provides a string, and only wants selected radio options
                        $dr_list = $this->search_service->searchForSelectedRadioOptions($entity, $search_term['value']);
                    }
                    else if ($typeclass === 'Radio' && $facet !== 'general') {
                        // The more specific version of searching a radio datafield provides an array of selected/deselected options
                        $dr_list = $this->search_service->searchRadioDatafield($entity, $search_term['selections'], $search_term['combine_by_OR']);
                    }
                    else if ($typeclass === 'File' || $typeclass === 'Image') {
                        // Searches on Files/Images are effectively interchangable
                        $dr_list = $this->search_service->searchFileOrImageDatafield($entity, $search_term['filename'], $search_term['has_files']);
                    }
                    else if ($typeclass === 'DatetimeValue') {
                        // DatetimeValue needs to worry about before/after...
                        $dr_list = $this->search_service->searchDatetimeDatafield($entity, $search_term['before'], $search_term['after']);
                    }
                    else {
                        // Short/Medium/LongVarchar, Paragraph Text, Integer/DecimalValue, and DatetimeValue
                        $dr_list = $this->search_service->searchTextOrNumberDatafield($entity, $search_term['value']);
                    }
                }


                // ----------------------------------------
                // Need to merge this result with the existing matches for this facet
                if ($merge_type === 'OR') {
                    if ( is_null($facet_dr_list[$facet]) )
                        $facet_dr_list[$facet] = array();

                    // Merging by 'OR' criteria...every datarecord returned from the search matches
                    foreach ($dr_list['records'] as $dr_id => $num)
                        $facet_dr_list[$facet][$dr_id] = $num;
                }
                else {
                    // Merging by 'AND' criteria...if this is the first (or only) criteria...
                    if ( is_null($facet_dr_list[$facet]) ) {
                        // ...use the datarecord list returned by the first search
                        $facet_dr_list[$facet] = $dr_list['records'];
                    }
                    else {
                        // Otherwise, intersect the list returned by the search with the existing list
                        $facet_dr_list[$facet] = array_intersect_key($facet_dr_list[$facet], $dr_list['records']);
                    }
                }
            }
        }


        // ----------------------------------------
        // Perform the final merge, getting all facets down into a single list of matching datarecords
        $final_dr_list = null;
        foreach ($facet_dr_list as $facet => $dr_list) {
            if ( is_null($final_dr_list) )
                $final_dr_list = $dr_list;
            else
                $final_dr_list = array_intersect_key($final_dr_list, $dr_list);
        }

        // Need to transfer the values from $facet_dr_list into $flattened_list...
        if ( !is_null($final_dr_list) ) {
            foreach ($final_dr_list as $dr_id => $num) {
                // ...but only if they're not excluded because of public status
                if ($flattened_list[$dr_id] >= -1)
                    $flattened_list[$dr_id] = 1;
            }
        }
        else if ( count($criteria) === 0 ) {
            // If a search was run without criteria, then everything that the user can see
            //  matches the search
            foreach ($flattened_list as $dr_id => $num) {
                if ($num >= -1)
                    $flattened_list[$dr_id] = 1;
            }
        }


        // ----------------------------------------
        // Need to transfer the values from $flattened_list into the tree structure of $inflated_list
        self::mergeSearchArrays($flattened_list, $inflated_list);

        // Traverse $inflated_list to get the final set of datarecords that match the search
        $datarecord_ids = self::getMatchingDatarecords($flattened_list, $inflated_list);
        $datarecord_ids = array_keys($datarecord_ids);

        // Traverse the top-level of $inflated_list to get the grandparent datarecords that match
        //  the search
        $grandparent_ids = array();
        foreach ($inflated_list[$datatype->getId()] as $gp_id => $data) {
            if ($flattened_list[$gp_id] == 1)
                $grandparent_ids[] = $gp_id;
        }


        // Sort the resulting array
        $sorted_datarecord_list = array();
        if ($sort_df_id === 0)
            $sorted_datarecord_list = $this->dti_service->getSortedDatarecordList($datatype->getId(), implode(',', $grandparent_ids));
        else
            $sorted_datarecord_list = $this->dti_service->sortDatarecordsByDatafield($sort_df_id, $sort_ascending, implode(',', $grandparent_ids));

        // Convert from ($dr_id => $sort_value) into ($num => $dr_id)
        $sorted_datarecord_list = array_keys($sorted_datarecord_list);


        // ----------------------------------------
        // Save/return the end result
        $search_result = array(
            'complete_datarecord_list' => $datarecord_ids,
            'grandparent_datarecord_list' => $sorted_datarecord_list,
        );

        // There's not really any need or point to caching the end result
        return $search_result;
    }


    /**
     * Extracts all datafield/datatype entities listed in $criteria, and returns them as hydrated
     * objects in an array.
     *
     * @param array $criteria
     *
     * @return array
     */
    private function hydrateCriteria($criteria)
    {
        // ----------------------------------------
        // Want to find all datafield entities listed in the criteria array
        $datafield_ids = array();
        foreach ($criteria as $facet => $data) {
            // Only bother with keys that have search data
            if ( isset($data['search_terms']) ) {
                foreach ($data['search_terms'] as $key => $search_params) {
                    // Extract the entity from the criteria array
                    $entity_type = $search_params['entity_type'];
                    $entity_id = $search_params['entity_id'];

                    if ($entity_type === 'datafield')
                        $datafield_ids[$entity_id] = 1;
                }
            }
        }
        $datafield_ids = array_keys($datafield_ids);


        // ----------------------------------------
        // Need to hydrate all of the datafields/datatypes so the search functions work
        $datafields = array();
        $datatypes = array();

        if ( !empty($datafield_ids) ) {
            $query = $this->em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                WHERE df.id IN (:datafield_ids)
                AND df.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters( array('datafield_ids' => $datafield_ids) );
            $results = $query->getResult();

            /** @var DataFields $df */
            foreach ($results as $df)
                $datafields[ $df->getId() ] = $df;
        }

        // Because of permissions, need to hydrate all datatypes...
        $query = $this->em->createQuery(
           'SELECT dt
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.id IN (:datatype_ids)
            AND dt.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $criteria['all_datatypes']) );
        $results = $query->getResult();

        /** @var DataType $dt */
        foreach ($results as $dt)
            $datatypes[ $dt->getId() ] = $dt;


        // ----------------------------------------
        // Return the finished array
        return array(
            'datafield' => $datafields,
            'datatype' => $datatypes
        );
    }


    /**
     * It's easier for performSearch() when getSearchArrays() returns arrays that already contain
     * the  user's permissions and which datatypes are being searched on...this utility function
     * gathers that required info in a single spot.
     *
     * @param DataType[] $hydrated_datatypes
     * @param int[] $affected_datatypes
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     *
     * @return array
     */
    private function getSearchPermissionsArray($hydrated_datatypes, $affected_datatypes, $user_permissions, $search_as_super_admin = false)
    {
        // Going to need to filter based on the user's permissions
        $datatype_permissions = array();
        if ( isset($user_permissions['datatypes']) )
            $datatype_permissions = $user_permissions['datatypes'];

        $search_permissions = array();
        foreach ($hydrated_datatypes as $dt_id => $dt) {
            // User needs to be able to view the datatype in order for them to search on it...
            $can_view_datatype = false;
            if ($search_as_super_admin)
                $can_view_datatype = true;
            else if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dt_view']) )
                $can_view_datatype = true;
            else if ($dt->isPublic())
                $can_view_datatype = true;


            if ( !$can_view_datatype ) {
                // If the user can't view this datatype, then there's no point checking other
                //  permissions or gathering various lists of datarecords
                $search_permissions[$dt_id] = array(
                    'can_view_datatype' => $can_view_datatype
                );
            }
            else {
                // If user can't view non-public datarecords, then need to get a list of them so
                //  they can be properly excluded from the search results
                $can_view_datarecord = false;
                if ($search_as_super_admin)
                    $can_view_datarecord = true;
                else if ( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dr_view']) )
                    $can_view_datarecord = true;


                $non_public_datarecords = array();
                if (!$can_view_datarecord) {
                    $ret = $this->search_service->searchPublicStatus($dt, false);
                    $non_public_datarecords = $ret['records'];
                }

                $search_permissions[$dt_id] = array(
                    'datatype' => $dt,
                    'can_view_datatype' => $can_view_datatype,
                    'can_view_datarecord' => $can_view_datarecord,
                    'non_public_datarecords' => $non_public_datarecords,
                );

                // Also, store whether this datatype is being searched on
                if (in_array($dt_id, $affected_datatypes))
                    $search_permissions[$dt_id]['affected'] = true;
                else
                    $search_permissions[$dt_id]['affected'] = false;
            }
        }

        return $search_permissions;
    }


    /**
     * Returns two arrays that are required for determining which datarecords match a search.
     * Technically a search run on a top-level datatype doesn't need all this, but any search that
     * involves a child/linked datatype does.
     *
     * The first array contains <datarecord_id> => <num> pairs, where num is one of four values...
     *  - num == -2 -- this datarecord is excluded because the user can't view it
     *  - num == -1 -- this datarecord is currently excluded...it must match the search being run
     *  - num ==  0 -- this datarecord is not being searched on
     *  - num ==  1 -- set in performSearch(), indicates this datarecord matches the search being run
     * This array is used so performSearch() doesn't have to deal with recursion while collating
     * search results.
     *
     * The second array is an "inflated" version of all datarecords that could potentially match
     * a search being run on $top_level_datatype_id, assuming the user has permissions to see
     * everything.  This array is recursively traversed by mergeSearchArrays() to determine all the
     * datarecords which matched the search.
     *
     * @param int $top_level_datatype_id
     * @param array $permissions_array @see self::getSearchPermissionsArray()
     *
     * @return array
     */
    private function getSearchArrays($top_level_datatype_id, $permissions_array)
    {

        // ----------------------------------------
        // Intentionally not caching the results of this function for two reasons
        // 1) these arrays need to be initialized based on the search being run, and the
        //     permissions of the user running the search
        // 2) these arrays contain ids of datarecords across all datatypes related to the datatype
        //     being searched on...determining when to clear this entry, especially when linked
        //     datatypes are involved, would be nightmarish


        // ----------------------------------------
        // In order to properly build the search arrays, all child/linked datatypes with some
        //  connection to this datatype need to be located first
        $datatree_array = $this->dti_service->getDatatreeArray();

        // Base setup for both arrays...
        $flattened_list = array();
        $inflated_list = array(
            0 => array(
                $top_level_datatype_id => array()
            )
        );


        // ----------------------------------------
        foreach ($permissions_array as $dt_id => $permissions) {
            // Ensure that the user is allowed to view this datatype before doing anything with it
            if ( !$permissions['can_view_datatype'] )
                continue;


            // If the datatype is linked...then the backend query to rebuild the cache entry is
            //  different, as is the insertion of the resulting datarecords into the "inflated" list
            $is_linked_type = false;
            if ( isset($datatree_array['linked_from'][$dt_id]) )
                $is_linked_type = true;

            // Attempt to load this datatype's datarecords and their parents from the cache...
            $list = $this->search_service->getCachedSearchDatarecordList($dt_id);


            // Storing the datarecord ids in the flattened list is easy...
            foreach ($list as $dr_id => $value) {
                if ( isset($permissions['non_public_datarecords'][$dr_id]) )
                    $flattened_list[$dr_id] = -2;
                else if ( $permissions['affected'] === true )
                    $flattened_list[$dr_id] = -1;
                else
                    $flattened_list[$dr_id] = 0;
            }


            // Inserting into $inflated_list depends on what type of datatype this is...
            // @see self::buildDatarecordTree() for the eventual structure
            if ( $dt_id === $top_level_datatype_id ) {
                // These are top-level datarecords for a top-level datatype...the 0 is in there
                //  to make recursion in buildDatarecordTree() easier
                foreach ($list as $dr_id => $value)
                    $inflated_list[0][$dt_id][$dr_id] = '';
            }
            else if (!$is_linked_type) {
                // These datarecords are for a child datatype
                foreach ($list as $dr_id => $parent_dr_id) {
                    if ( !isset($inflated_list[$parent_dr_id]) )
                        $inflated_list[$parent_dr_id] = array();
                    if ( !isset($inflated_list[$parent_dr_id][$dt_id]) )
                        $inflated_list[$parent_dr_id][$dt_id] = array();

                    $inflated_list[$parent_dr_id][$dt_id][$dr_id] = '';
                }
            }
            else {
                // These datarecords are for a linked datatype
                foreach ($list as $dr_id => $parents) {
                    foreach ($parents as $parent_dr_id => $value) {
                        if ( !isset($inflated_list[$parent_dr_id]) )
                            $inflated_list[$parent_dr_id] = array();
                        if ( !isset($inflated_list[$parent_dr_id][$dt_id]) )
                            $inflated_list[$parent_dr_id][$dt_id] = array();

                        $inflated_list[$parent_dr_id][$dt_id][$dr_id] = '';
                    }
                }
            }
        }


        // ----------------------------------------
        // Sort the flattened list for easier debugging
        ksort($flattened_list);

        // Actually inflate the "inflated" list...
        $inflated_list = self::buildDatarecordTree($inflated_list, 0);

        // ...and then return the end result
        return array(
            'flattened' => $flattened_list,
            'inflated' => $inflated_list,
        );
    }


    /**
     * Turns the originally flattened $descendants_of_datarecord array into a recursive tree
     *  structure of the form...
     *
     * parent_datarecord_id => array(
     *     child_datatype_1_id => array(
     *         child_datarecord_1_id of child_datatype_1 => '',
     *         child_datarecord_2_id of child_datatype_1 => '',
     *         ...
     *     ),
     *     child_datatype_2_id => array(
     *         child_datarecord_1_id of child_datatype_2 => '',
     *         child_datarecord_2_id of child_datatype_2 => '',
     *         ...
     *     ),
     *     ...
     * )
     *
     * If child_datarecord_X_id has children of its own, then it is also a parent datarecord, and
     *  it points to another recursive tree structure of this type instead of an empty string.
     * Linked datatypes/datarecords are handled identically to child datatypes/datarecords.
     *
     * The tree's root looks like...
     *
     * 0 => array(
     *     target_datatype_id => array(
     *         top_level_datarecord_1_id => ...
     *         top_level_datarecord_2_id => ...
     *         ...
     *     )
     * )
     *
     * @param array $descendants_of_datarecord
     * @param string|integer $current_datarecord_id
     *
     * @return string|array
     */
    private function buildDatarecordTree($descendants_of_datarecord, $current_datarecord_id)
    {
        if ( !isset($descendants_of_datarecord[$current_datarecord_id]) ) {
            // $current_datarecord_id has no children...intentionally returning empty string
            //  because of recursive assignment
            return '';
        }
        else {
            // $current_datarecord_id has children
            $result = array();

            // For every child datatype this datarecord has...
            foreach ($descendants_of_datarecord[$current_datarecord_id] as $dt_id => $datarecords) {
                // For every child datarecord of this child datatype...
                foreach ($datarecords as $dr_id => $tmp) {
                    // NOTE - doing it this way to cut out recursive calls that just return ''
                    if ( isset($descendants_of_datarecord[$dr_id]) ) {
                        // ...get all children of this child datarecord and store them
                        $result[$dt_id][$dr_id] = self::buildDatarecordTree($descendants_of_datarecord, $dr_id);
                    }
                    else {
                        // ...the child datarecord has no children of its own
                        $result[$dt_id][$dr_id] = '';
                    }
                }
            }

            return $result;
        }
    }


    /**
     * Recursively traverses the datarecord tree and deletes all datarecords that either didn't
     * match the search that was just run, or were excluded because the user can't see them.
     *
     * @param array $flattened_list
     * @param array $inflated_list
     */
    private function mergeSearchArrays(&$flattened_list, &$inflated_list)
    {
        foreach ($inflated_list as $top_level_dt_id => $top_level_datarecords) {
            foreach ($top_level_datarecords as $dr_id => $child_dt_list) {

                if ( !is_array($child_dt_list) ) {
                    // No child datarecords...only save if it matched the search result
                    if ( $flattened_list[$dr_id] !== 1 )
                        unset( $inflated_list[$top_level_dt_id][$dr_id] );
                }
                else {
                    $votes = array();
                    foreach ($child_dt_list as $child_dt_id => $child_dr_list) {
                        //
                        $vote = self::mergeSearchArrays_worker($flattened_list, $child_dr_list);
                        if ($vote === -1) {
                            // None of the child datarecords of this child datatype match...so by
                            //  definition, this datarecord doesn't match either
                            $flattened_list[$dr_id] = -1;
                            break;
                        }
                        else {
                            // At least one of the child datarecords of this child datatype have a
                            //  value other than -1...so whether this datarecord ends up matching
                            //  or not depends on other factors
                            $votes[$child_dt_id] = $vote;
                        }
                    }

                    // This datarecord wasn't excluded due to its children not matching...but in
                    //  order to actually match the search, at least one of its children must have
                    //  matched the search
                    foreach ($votes as $child_dt_id => $vote) {
                        if ($vote === 1) {
                            $flattened_list[$dr_id] = 1;
                            break;
                        }
                    }
                }
            }
        }
    }


    /**
     * After searching is done, $flattened_list will be in a ($dr_id => $vote) format.  There are
     * four possible values for $vote...
     *
     * -2: This datarecord is excluded because the user can't view it...this has no effect on
     *      whether its parent datarecord is included or not, but all of its children are immediately
     *      excluded
     * -1: This datarecord did not match the search...this datarecord and its children are excluded
     *      from the search, and if all datarecords of this datatype also "don't match", then this
     *      datarecord's parent will be excluded as well
     *  0: This datarecord was not searched on...it'll be included in the search results if its
     *      parents aren't excluded somehow, and its grandparent datarecord ends up matching the
     *      search
     *  1: This datarecord matched the search...it'll be included in the search results if it's not
     *      somehow excluded by the negative values overriding it
     *
     * -2 is intentionally different from -1...the presence (or lack thereof) of child datarecords
     * that the user can't view MUST NOT affect whether the parent datarecord in question matches
     * the search or not.
     *
     * @param array $flattened_list
     * @param array $dr_list
     *
     * @return int
     */
    private function mergeSearchArrays_worker(&$flattened_list, &$dr_list)
    {
        $include = false;
        $exclude = false;
        foreach ($dr_list as $dr_id => $child_dt_list) {

            if ( $flattened_list[$dr_id] === -2 ) {
                // This datarecord is non-public, doesn't matter if it has child datarecords
                // This is different from
            }
            else if ($flattened_list[$dr_id] === -1 ) {
                // This datarecord didn't match the search...doesn't matter if it has children
                $exclude = true;
            }
            else {
                //
                if ( !is_array($child_dt_list) ) {
                    // If has no children, then this datarecord is included if it matched the search
                    if ( $flattened_list[$dr_id] === 1 )
                        $include = true;
                }
                else {
                    $votes = array();
                    foreach ($child_dt_list as $child_dt_id => $child_dr_list) {
                        //
                        $vote = self::mergeSearchArrays_worker($flattened_list, $child_dr_list);
                        if ($vote === -1) {
                            // None of the child datarecords of this child datatype match...so by
                            //  definition, this datarecord doesn't match either
                            $flattened_list[$dr_id] = -1;
                            $exclude = true;
                            break;
                        }
                        else {
                            // At least one of the child datarecords of this child datatype have a
                            //  value other than -1...so whether this datarecord ends up matching
                            //  or not depends on other factors
                            $votes[$child_dt_id] = $vote;
                        }
                    }

                    // This datarecord wasn't excluded due to its children not matching...but in
                    //  order to actually match the search, at least one of its children must have
                    //  matched the search
                    foreach ($votes as $child_dt_id => $vote) {
                        if ($vote === 1) {
                            $flattened_list[$dr_id] = 1;
                            $include = true;
                            break;
                        }
                    }
                }
            }
        }

        if ($include) {
            // At least one datarecord matches the search, so the parent datarecord could possibly
            //  be considered to match the search as well
            return 1;
        }
        else if ($exclude) {
            // All the child datarecords of at least one child datatype didn't match the search
            // Therefore, the parent datarecord should be excluded as well
            return -1;
        }
        else {
            // Otherwise, the results from this child datatype doesn't matter
            return 0;
        }
    }


    /**
     * In order for a top-level datarecord to match the search being run, the datarecord itself, or
     * at least one of its child datarecords, must also match the search.
     *
     * The recursion looks a little strange in order to reduce the number of recursive calls made.
     *
     * @param array $flattened_list
     * @param array $inflated_list
     *
     * @return array
     */
    private function getMatchingDatarecords($flattened_list, $inflated_list)
    {
        $matching_datarecords = array();
        foreach ($inflated_list as $top_level_dt_id => $top_level_datarecords) {
            foreach ($top_level_datarecords as $dr_id => $child_dt_list) {
                // Only care about this top-level datarecord when it either matches the search, or
                //  has a child datarecord that matched the search
                if ( $flattened_list[$dr_id] === 1 ) {
                    $matching_datarecords[$dr_id] = 1;

                    if ( is_array($child_dt_list) ) {
                        //
                        $matching_children = self::getMatchingDatarecords_worker($flattened_list, $child_dt_list);
                        foreach ($matching_children as $child_dr_id => $tmp)
                            $matching_datarecords[$child_dr_id] = 1;
                    }
                }
            }
        }

        return $matching_datarecords;
    }


    /**
     * This just recursively relays whether any of this datarecord's child datarecords ended up
     * matching the search.
     *
     * The recursion looks a little strange in order to reduce the number of recursive calls made.
     *
     * @param array $flattened_list
     * @param array $dt_list
     *
     * @return array
     */
    private function getMatchingDatarecords_worker($flattened_list, $dt_list)
    {
        $matching_datarecords = array();
        foreach ($dt_list as $dt_id => $dr_list) {
            foreach ($dr_list as $dr_id => $child_dt_list) {
                //
                if ( $flattened_list[$dr_id] >= 0 ) {
                    $matching_datarecords[$dr_id] = 1;

                    if ( is_array($child_dt_list) ) {
                        $matching_children = self::getMatchingDatarecords_worker($flattened_list, $child_dt_list);
                        foreach ($matching_children as $child_dr_id => $tmp)
                            $matching_datarecords[$child_dr_id] = 1;
                    }
                }
            }
        }

        return $matching_datarecords;
    }
}
