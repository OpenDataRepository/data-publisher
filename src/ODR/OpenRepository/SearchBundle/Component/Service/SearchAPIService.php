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
use ODR\AdminBundle\Entity\RenderPlugin;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\OpenRepository\GraphBundle\Plugins\SearchOverrideInterface;
// Symfony
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;


class SearchAPIService
{
    // When determining whether a datarecord actually matches a search, it can be in a handful of
    //  different states...

    /**
     * The user is not allowed to view this record...this is completely different from a record that
     * doesn't match the search.  The presence (or lack thereof) of child/linked datarecords that
     * the user can't view MUST NOT affect whether the parent datarecord in question matches the
     * search or not.
     */
    const CANT_VIEW = 0b1000;

    /**
     * The record has a datafield being searched on, and it will be excluded from the final search
     * result list unless it matches.  This is not set for datafields that are only being searched
     * on because of "general search"
     */
    const MUST_MATCH = 0b0100;
    /**
     * When a search has both "advanced" and "general" terms, a record with this value ended up
     * matching all of the "advanced" search terms
     */
    const MATCHES_ADV = 0b0010;
    /**
     * When a search has both "advanced" and "general" terms, a record with this value ended up
     * matching all of the "general" search terms
     */
    const MATCHES_GEN = 0b0001;
    /**
     * The record matches both "advanced" and "general" search, and will be in the final search
     * results list...unless its parent gets excluded, or all child/linked records of a specific
     * child/linked datatype don't match the search.
     *
     * This value also gets used when the search has either "advanced" or "general" terms, but not both
     */
    const MATCHES_BOTH = 0b0011;

    /**
     * The record isn't being searched on (or it doesn't match the "general search" terms)...it could
     * still end up in the final search results list, if its parents or its children match the search
     */
    const DOESNT_MATTER = 0b0000;

    /**
     * There are also situations where a datarecord needs to be set to not match a search
     */
    const DISABLE_MATCHES_MASK = 0b1100;


    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatatreeInfoService
     */
    private $datatree_info_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var SearchKeyService
     */
    private $search_key_service;

    /**
     * @var SortService
     */
    private $sort_service;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * SearchAPIService constructor.
     *
     * @param ContainerInterface $container
     * @param EntityManager $entity_manager
     * @param DatatreeInfoService $datatree_info_service
     * @param SearchService $search_service
     * @param SearchKeyService $search_key_service
     * @param SortService $sort_service
     * @param CacheService $cache_service
     * @param Logger $logger
     */
    public function __construct(
        ContainerInterface $container,
        EntityManager $entity_manager,
        DatatreeInfoService $datatree_info_service,
        SearchService $search_service,
        SearchKeyService $search_key_service,
        SortService $sort_service,
        CacheService $cache_service,
        Logger $logger
    ) {
        $this->container = $container;
        $this->em = $entity_manager;
        $this->datatree_info_service = $datatree_info_service;
        $this->search_service = $search_service;
        $this->search_key_service = $search_key_service;
        $this->sort_service = $sort_service;
        $this->cache_service = $cache_service;
        $this->logger = $logger;
    }


    /**
     * Returns an array of searchable datafield ids, filtered to what the user can see, and
     * organized by their datatype id.
     *
     * @param int[] $top_level_datatype_ids
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     * @param bool $inverse If false, then the array contains the searchable datafields from descendant
     *                      datatypes...if true, then it comes from the ancestor datatypes instead
     *
     * @return array
     */
    public function getSearchableDatafieldsForUser($top_level_datatype_ids, $user_permissions, $search_as_super_admin = false, $inverse = false)
    {
        // Going to need to filter the resulting list based on the user's permissions
        $datatype_permissions = array();
        $datafield_permissions = array();
        if ( isset($user_permissions['datatypes']) )
            $datatype_permissions = $user_permissions['datatypes'];
        if ( isset($user_permissions['datafields']) )
            $datafield_permissions = $user_permissions['datafields'];


        $all_searchable_datafields = array();
        foreach ($top_level_datatype_ids as $num => $top_level_datatype_id) {
            // Get all possible datafields that can be searched on for this datatype
            $searchable_datafields = $this->search_service->getSearchableDatafields($top_level_datatype_id, $inverse);
            foreach ($searchable_datafields as $dt_id => $datatype_data) {
                $is_public = true;
                if ( $datatype_data['dt_public_date'] === '2200-01-01' )
                    $is_public = false;

                $can_view_dt = false;
                if ($search_as_super_admin)
                    $can_view_dt = true;
                else if ( isset($datatype_permissions[$dt_id]['dt_view']) )
                    $can_view_dt = true;

                if (!$is_public && !$can_view_dt) {
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
                        else if ( isset($datafield_permissions[$df_id]['view']) )
                            $can_view_df = true;

                        // If user can view datafield, move it out of the non_public section
                        if ($can_view_df)
                            $searchable_datafields[$dt_id]['datafields'][$df_id] = $datafield_data;
                    }

                    // Get rid of the non_public section
                    unset( $searchable_datafields[$dt_id]['datafields']['non_public'] );

                    // Only want the array of datafield ids that the user can see
                    $searchable_datafields[$dt_id] = $searchable_datafields[$dt_id]['datafields'];
                }
            }

            foreach ($searchable_datafields as $dt_id => $data)
                $all_searchable_datafields[$dt_id] = $data;
        }

        // Return the final list
        return $all_searchable_datafields;
    }


    /**
     * This function fulfills a purpose similar to {@link getSearchableDatafieldsForUser()}...both
     * a "regular" search and a "template" search need to know which datafields the user is allowed
     * to view...but a "template" search may easily involve hundreds of datatypes that are derived
     * from the template being searched on.
     *
     * As such, the strategy used by {@link getSearchableDatafieldsForUser()} of loading info for one
     * datatype at a time is unviable...this function instead loads the relevant data for every single
     * relevant derived datatypes/datafields at once.  Caching this data is unfeasible, unfortunately,
     * which is why it only gets used for template searches.
     *
     * Additionally, the array returned by {@link getSearchableDatafieldsForUser()} contains each
     * datafield's typeclass and searchable status, but the array returned by this function does not.
     *
     * @param string[] $datafield_uuids The uuids of the datafields being searched on
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     *
     * @return array
     */
    public function getSearchableTemplateDatafieldsForUser($datafield_uuids, $user_permissions, $search_as_super_admin = false)
    {
        // Going to need to filter the resulting list based on the user's permissions
        $datatype_permissions = array();
        $datafield_permissions = array();
        if ( isset($user_permissions['datatypes']) )
            $datatype_permissions = $user_permissions['datatypes'];
        if ( isset($user_permissions['datafields']) )
            $datafield_permissions = $user_permissions['datafields'];


        // The "non-template" search can get away with doing things a datatype at a time, but a
        //  template could easily have hundreds of datatypes that are derived from it...therefore,
        //  a different method of loading the relevant data must be used
        $query = $this->em->createQuery(
           'SELECT dt.id AS dt_id, dtm.publicDate AS dt_public_date, df.id AS df_id, dfm.publicDate AS df_public_date
            FROM ODRAdminBundle:DataFields mdf
            JOIN ODRAdminBundle:DataFields df WITH df.masterDataField = mdf
            LEFT JOIN ODRAdminBundle:DataFieldsMeta dfm WITH dfm.dataField = df
            JOIN ODRAdminBundle:DataType dt WITH df.dataType = dt
            LEFT JOIN ODRAdminBundle:DataTypeMeta dtm WITH dtm.dataType = dt
            WHERE mdf.fieldUuid IN (:field_uuids)
            AND mdf.deletedAt IS NULL AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
        )->setParameters( array('field_uuids' => $datafield_uuids) );
        $results = $query->getArrayResult();

        $dt_lookup = array();
        $searchable_datafields = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $dt_public_date = $result['dt_public_date'];
            $df_id = $result['df_id'];
            $df_public_date = $result['df_public_date'];

            // The results from the query will contain duplicate datatype information, so ensure that
            //  the user's permissions for this datatype are only checked once
            if ( !isset($dt_lookup[$dt_id]) ) {
                $dt_is_public = true;
                if ($dt_public_date === '2200-01-01')
                    $dt_is_public = false;

                $can_view_datatype = false;
                if ( $search_as_super_admin || $dt_is_public || isset($datatype_permissions[$dt_id]['dt_view']) )
                    $can_view_datatype = true;

                $dt_lookup[$dt_id] = $can_view_datatype;
            }
            $can_view_datatype = $dt_lookup[$dt_id];

            if ( $can_view_datatype ) {
                // If the user can view this datatype, then the user's permissions for its datafields
                //  also need to be checked
                if ( !isset($searchable_datafields[$dt_id]) )
                    $searchable_datafields[$dt_id] = array();

                $df_is_public = true;
                if ($df_public_date === '2200-01-01')
                    $df_is_public = false;

                $can_view_datafield = false;
                if ( $search_as_super_admin || $df_is_public || isset($datafield_permissions[$df_id]['view']) )
                    $can_view_datafield = true;

                // If the user can view the datafield, then store its id
                if ( $can_view_datafield )
                    $searchable_datafields[$dt_id][$df_id] = 1;
            }
        }

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

        // Get all the datatypes/datafields the user can view...
        $inverse = false;
        if ( isset($search_params['inverse']) )
            $inverse = true;
        $searchable_datafields = self::getSearchableDatafieldsForUser(array($datatype->getId()), $user_permissions, $search_as_super_admin, $inverse);

        // Prior to inline searching, $searchable_datafields only had datafields that the user could
        //  view and weren't marked as DataFields::NOT_SEARCHABLE...but because of inline search's
        //  requirements, it now contains every single datafield related to this datatype that the
        //  user is allowed to view

        foreach ($search_params as $key => $value) {
            if ( $key === 'dt_id' || $key === 'gen' || $key === 'gen_all' || $key === 'inverse' ) {
                // Don't need to do anything special with these keys
                $filtered_search_params[$key] = $value;
            }
            else if ($key === 'sort_by') {
                // TODO - eventually need sort by created/modified date

                // The values for the "sort_by" key are allowed to either be an object...
                // e.g. {"dt_id":"3","sort_by":{"sort_df_id":"18","sort_dir":"asc"}}
                // ...or it's allowed to be an array of objects...
                // e.g. {"dt_id":"3","sort_by":[{"sort_df_id":"18","sort_dir":"asc"}]}

                // Since we want multi-datafield sorting to be a thing, convert the first form into
                //  the second form if needed
                if ( count($value) === 2 && isset($value['sort_df_id']) && isset($value['sort_dir']) ) {
                    $tmp = $value;
                    $value = array($tmp);
                }

                // Sorting happens regardless of whether the user can see the relevant datafield...
                //  so don't need to look anything up in $searchable_datafields
                $filtered_search_params['sort_by'] = $value;
            }
            else if ( is_numeric($key) ) {
                // Most of the fieldtypes provide search data like this...
                $df_id = intval($key);

                // Determine if the user can view the datafield...
                foreach ($searchable_datafields as $dt_id => $datafields) {
                    if ( isset($datafields[$df_id]) && $datafields[$df_id]['searchable'] !== DataFields::NOT_SEARCHABLE ) {
                        // User can both view and search this datafield
                        $filtered_search_params[$key] = $value;
                        break;
                    }
                }
            }
            else {
                $pieces = explode('_', $key);

                if ( is_numeric($pieces[0]) && count($pieces) === 2 ) {
                    // This is a DatetimeValue or the public_status/quality of a File/Image field...
                    $df_id = intval($pieces[0]);

                    $is_valid_field = false;
                    foreach ($searchable_datafields as $dt_id => $datafields) {
                        if ( isset($datafields[$df_id]) && $datafields[$df_id]['searchable'] !== DataFields::NOT_SEARCHABLE ) {
                            // User can both view and search this datafield...
                            $is_valid_field = true;
                            break;
                        }
                    }

                    // Searching on public status of files/images needs an additional check...
                    if ( $pieces[1] === 'pub' ) {
                        $dt_id = $datatype->getId();
                        if ( !isset($user_permissions['datatypes'][$dt_id]['dr_view'])
                            || !isset($user_permissions['datafields'][$df_id]['view'])
                        ) {
                            // ...because a user without the ability to see non-public files should
                            //  not be able to search on this criteria
                            $is_valid_field = false;
                        }
                    }
                    if ( $search_as_super_admin )
                        $is_valid_field = true;

                    if ( $is_valid_field )
                        $filtered_search_params[$key] = $value;
                }
                else {
                    // $key is one of the modified/created/modifiedBy/createdBy/publicStatus entries
                    $dt_id = intval($pieces[1]);

                    if ( isset($user_permissions['datatypes'][$dt_id]) ) {
                        // User needs to be able to either edit, create new, or delete existing
                        //  datarecords in order to be able to search these entries
                        $dt_permissions = $user_permissions['datatypes'][$dt_id];
                        if ( isset($dt_permissions['dr_edit'])
                            || isset($dt_permissions['dr_add'])
                            || isset($dt_permissions['dr_delete'])
                        ) {
                            if ( isset($searchable_datafields[$dt_id]) ) {
                                // User can search on this datatype
                                $filtered_search_params[$key] = $value;
                            }
                        }
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
     * @param DataType|null $datatype Preferably not null, but can parse $search_key if so
     * @param string $search_key
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in
     * @param bool $return_complete_list If false, then returns a sorted list of grandparent
     *                                   datarecord ids...if true, then returns an unsorted list of
     *                                   the grandparent datarecords and all their descendents that
     *                                   match the search
     * @param int[] $sort_datafields An ordered list of the datafields to sort by, or an empty
     *                               array to sort by whatever is default for the datatype
     * @param string[] $sort_directions An ordered list of which direction to sort each datafield by
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     *
     * @param bool $return_as_list If true, then returns a list of records with internal id and
     *                             unique id instead
     *
     * @return array
     */
    public function performSearch(
        $datatype,
        $search_key,
        $user_permissions,
        $return_complete_list = false,
        $sort_datafields = array(),
        $sort_directions = array(),
        $search_as_super_admin = false,
        $return_as_list = false
    ) {
        // ----------------------------------------
        $search_params = $this->search_key_service->decodeSearchKey($search_key);

        // This really shouldn't be null, but just in case...
        if ( is_null($datatype) )
            $datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find( $search_params['dt_id'] );


        // ----------------------------------------
        // Convert the search key into a format suitable for searching
        $inverse = false;
        if ( isset($search_params['inverse']) )
            $inverse = true;
        $searchable_datafields = self::getSearchableDatafieldsForUser(array($datatype->getId()), $user_permissions, $search_as_super_admin, $inverse);
        $criteria = $this->search_key_service->convertSearchKeyToCriteria($search_key, $searchable_datafields, $user_permissions, $search_as_super_admin);

        // Need to grab hydrated versions of the datafields/datatypes being searched on
        $hydrated_entities = self::hydrateCriteria($criteria);

        // Each datatype being searched on (or the datatype of a datafield being search on) needs
        //  to have its records be initialized to MUST_MATCH before the results of each facet search
        //  are merged together into the final array
        $affected_datatypes = $criteria['affected_datatypes'];
        unset( $criteria['affected_datatypes'] );

        // Also don't want the list of all datatypes anymore either
        unset( $criteria['all_datatypes'] );
        // ...or what type of search this is
        unset( $criteria['search_type'] );


        // ----------------------------------------
        // Get the base information needed so getSearchArrays() can properly setup the search arrays
        $search_permissions = self::getSearchPermissionsArray($hydrated_entities['datatype'], $affected_datatypes, $user_permissions, $search_as_super_admin);

        // Going to need three arrays so mergeSearchResults() can correctly determine which records
        //  end up matching the search
        $search_arrays = self::getSearchArrays( array($datatype->getId()), $search_permissions, $inverse );
        $flattened_list = $search_arrays['flattened'];
        $inflated_list = $search_arrays['inflated'];
        $search_datatree = $search_arrays['search_datatree'];

        // An "empty" search run with no criteria needs to return all top-level datarecord ids
        $return_all_results = true;

        // Need to keep track of the result list for each facet separately...they end up merged
        //  together after all facets are searched on
        $facet_dr_list = array();
        foreach ($criteria as $dt_id => $facet_list) {
            // Need to keep track of the matches for each datatype individually...
            $facet_dr_list[$dt_id] = array();

            foreach ($facet_list as $facet_num => $facet) {
                // ...and also keep track of the matches for each facet within this datatype individually
                $facet_dr_list[$dt_id][$facet_num] = null;

                $facet_type = $facet['facet_type'];
                $merge_type = $facet['merge_type'];
                $search_terms = $facet['search_terms'];

                // For each search term within this facet...
                foreach ($search_terms as $key => $search_term) {
                    // Don't return all top-level datarecord ids at the end
                    $return_all_results = false;

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
                    else if ( isset($hydrated_entities['renderPlugin'][$entity_id]) ) {
                        // The render plugin is already loaded, stored by the id of the datafield
                        //  that is using it
                        $tmp = $hydrated_entities['renderPlugin'][$entity_id];
                        /** @var SearchOverrideInterface $rp */
                        $rp = $tmp['renderPlugin'];
                        $rpf_list = $tmp['renderPluginFields'];
                        $rpo = $tmp['renderPluginOptions'];

                        // The plugin will return the same format that the regular searches do
                        $dr_list = $rp->searchOverriddenField($entity, $search_term, $rpf_list, $rpo);

                        // If this search involved the empty string...
                        $involves_empty_string = $dr_list['guard'];
                        if ($involves_empty_string) {
                            // ...then insert an entry into the criteria array so that the later
                            //  call of self::mergeSearchResults() can properly compensate
                            $criteria[$dt_id][$facet_num]['search_terms'][$key]['guard'] = true;
                        }
                    }
                    else {
                        // Datafield search depends on the typeclass of the field
                        $typeclass = $entity->getFieldType()->getTypeClass();

                        if ($typeclass === 'Boolean') {
                            // Only split from the text/number searches to avoid parameter confusion
                            $dr_list = $this->search_service->searchBooleanDatafield($entity, $search_term['value']);
                        }
                        else if ($typeclass === 'Radio' && $facet_type === 'general') {
                            // General search only provides a string, and only wants selected radio options
                            $dr_list = $this->search_service->searchForSelectedRadioOptions($entity, $search_term['value']);
                        }
                        else if ($typeclass === 'Radio' && $facet_type !== 'general') {
                            // The more specific version of searching a radio datafield provides an array of selected/deselected options
                            $dr_list = $this->search_service->searchRadioDatafield($entity, $search_term['selections']);
                        }
                        else if ($typeclass === 'Tag' && $facet_type === 'general') {
                            // General search only provides a string, and only wants selected tags
                            $dr_list = $this->search_service->searchForSelectedTags($entity, $search_term['value']);
                        }
                        else if ($typeclass === 'Tag' && $facet_type !== 'general') {
                            // The more specific version of searching a tag datafield provides an array of selected/deselected options
                            $dr_list = $this->search_service->searchTagDatafield($entity, $search_term['selections']);
                        }
                        else if ($typeclass === 'File' || $typeclass === 'Image') {
                            // Searches on Files/Images are effectively interchangable
                            $dr_list = $this->search_service->searchFileOrImageDatafield($entity, $search_term);    // There could be three different terms in there, actually

                            // If this search involved the empty string...
                            $involves_empty_string = $dr_list['guard'];
                            if ($involves_empty_string) {
                                // ...then insert an entry into the criteria array so that the later
                                //  call of self::mergeSearchResults() can properly compensate
                                $criteria[$dt_id][$facet_num]['search_terms'][$key]['guard'] = true;
                            }
                        }
                        else if ($typeclass === 'DatetimeValue') {
                            // DatetimeValue needs to worry about before/after...
                            $dr_list = $this->search_service->searchDatetimeDatafield($entity, $search_term['before'], $search_term['after']);
                        }
                        else {
                            // Short/Medium/LongVarchar, Paragraph Text, and Integer/DecimalValue
                            $dr_list = $this->search_service->searchTextOrNumberDatafield($entity, $search_term['value']);

                            // If this search involved the empty string...
                            $involves_empty_string = $dr_list['guard'];
                            if ($involves_empty_string) {
                                // ...then insert an entry into the criteria array so that the later
                                //  call of self::mergeSearchResults() can properly compensate
                                $criteria[$dt_id][$facet_num]['search_terms'][$key]['guard'] = true;
                            }
                        }
                    }


                    // ----------------------------------------
                    // Need to merge this result with the existing matches for this facet
                    if ($merge_type === 'OR') {
                        if ( is_null($facet_dr_list[$dt_id][$facet_num]) )
                            $facet_dr_list[$dt_id][$facet_num] = array();

                        // When merging by 'OR', every datarecord returned by the SearchService
                        //  functions ends up matching
                        foreach ($dr_list['records'] as $dr_id => $num)
                            $facet_dr_list[$dt_id][$facet_num][$dr_id] = $num;
                    }
                    else {
                        // When merging by 'AND'...if this is the first (or only) facet of criteria...
                        if ( is_null($facet_dr_list[$dt_id][$facet_num]) ) {
                            // ...use the datarecord list returned by the first SearchService call
                            $facet_dr_list[$dt_id][$facet_num] = $dr_list['records'];
                        }
                        else {
                            // ...otherwise, intersect the list returned by the search with the
                            //  currently stored list
                            $facet_dr_list[$dt_id][$facet_num] = array_intersect_key($facet_dr_list[$dt_id][$facet_num], $dr_list['records']);
                        }
                    }
                }
            }
        }


        // ----------------------------------------
        // Now that the individual search queries have been run...
        if ( $return_all_results ) {
            // When no search criteria is specified, then every datarecord that the user can see
            //  needs to be marked as "matching" the search
            foreach ($flattened_list as $dr_id => $num) {
                if ( !($num & SearchAPIService::CANT_VIEW) )
                    $flattened_list[$dr_id] |= SearchAPIService::MATCHES_BOTH;
            }
        }
        else {
            // Determine whether a 'general' search was executed...
            $was_general_seach = false;
            if ( isset($facet_dr_list['general']) )
                $was_general_seach = true;

            // Determine whether an 'advanced' search was executed...
            $was_advanced_search = false;
            foreach ($facet_dr_list as $key => $facet_list) {
                // ...then just need to save that an advanced search was run
                if ( is_numeric($key) && !empty($facet_list) ) {
                    $was_advanced_search = true;
                    break;
                }
            }

            // ...if both types of searches were executed, then the merging algorithm needs to
            //  separately track which type of search each record matched (they could match both too)
            $differentiate_search_types = $was_general_seach && $was_advanced_search;
            self::mergeSearchResults($criteria, true, $datatype->getId(), $search_datatree[$datatype->getId()], $facet_dr_list, $flattened_list, $differentiate_search_types);
        }


        // ----------------------------------------
        // If the user needs a list of datarecords that includes child/linked descendants...
        if ( $return_complete_list ) {
            // ...then traverse $inflated_list to get the final set of datarecords that match the search
            $datarecord_ids = self::getMatchingDatarecords($flattened_list, $inflated_list);
            $datarecord_ids = array_keys($datarecord_ids);

            // There's no correct method to sort this list, so might as well return immediately
            return $datarecord_ids;
        }


        // Otherwise, the user only wanted a list of the grandparent datarecords that matched the
        //  search...can traverse the top-level of $inflated list for that
        $grandparent_ids = array();
        if ( isset($inflated_list[$datatype->getId()]) ) {
            foreach ($inflated_list[$datatype->getId()] as $gp_id => $data) {
                if ( ($flattened_list[$gp_id] & SearchAPIService::MATCHES_BOTH) === SearchAPIService::MATCHES_BOTH )
                    $grandparent_ids[] = $gp_id;
            }
        }


        // Sort the resulting array if any results were found
        $sorted_datarecord_list = array();
        if ( !empty($grandparent_ids) ) {
            $source_dt_id = $datatype->getId();
            $grandparent_ids_for_sorting = implode(',', $grandparent_ids);

            // Want to use SortService::getSortedDatarecordList() unless the provided sort datafields
            //  or directions are different from the datatype's default sort order
            $has_sortfields = false;
            $is_default_sort_order = true;
            foreach ($sort_directions as $num => $dir) {
                if ( $dir !== 'asc' )
                    $is_default_sort_order = false;
            }
            foreach ($datatype->getSortFields() as $display_order => $df) {
                $has_sortfields = true;
                if ( !isset($sort_datafields[$display_order]) || $df->getId() !== $sort_datafields[$display_order] )
                    $is_default_sort_order = false;
            }
            if ( $has_sortfields && $is_default_sort_order )
                $sort_datafields = $sort_directions = array();

            // ----------------------------------------
            if ( empty($sort_datafields) ) {
                // No sort datafields defined for this request, use the datatype's default ordering
                $sorted_datarecord_list = $this->sort_service->getSortedDatarecordList($source_dt_id, $grandparent_ids_for_sorting);
            }
            else if ( count($sort_datafields) === 1 ) {
                // If the user wants to only use one datafield for sorting, then it's better to call
                //  the relevant functions in SortService directly
                $sort_df_id = $sort_datafields[0];
                $sort_dir = $sort_directions[0];

                if ( isset($searchable_datafields[$source_dt_id][$sort_df_id]) ) {
                    // The sort datafield belongs to the datatype being searched on
                    $sorted_datarecord_list = $this->sort_service->sortDatarecordsByDatafield($sort_df_id, $sort_dir, $grandparent_ids_for_sorting);
                }
                else {
                    // The sort datafield belongs to some linked datatype TODO - ...or child, eventually?
                    $sorted_datarecord_list = $this->sort_service->sortDatarecordsByLinkedDatafield($source_dt_id, $sort_df_id, $sort_dir, $grandparent_ids_for_sorting);
                }
            }
            else {
                // If more than one datafield is needed for sorting, then multisort has to be used
                $linked_datafields = array();
                $numeric_datafields = array();

                foreach ($sort_datafields as $display_order => $sort_df_id) {
                    // It's easier to determine whether this is a linked field or not here instead
                    //  of inside the multisort function
                    if ( isset($searchable_datafields[$source_dt_id][$sort_df_id]) )
                        $linked_datafields[$display_order] = false;
                    else
                        $linked_datafields[$display_order] = true;

                    // Same deal with whether the datafield is an integer/decimal field or not
                    foreach ($searchable_datafields as $sort_df_dt_id => $fields) {
                        // The field may not belong to $source_dt_id...
                        if ( isset($fields[$sort_df_id]) ) {
                            $typeclass = $fields[$sort_df_id]['typeclass'];
                            if ( $typeclass === 'IntegerValue' || $typeclass === 'DecimalValue' )
                                $numeric_datafields[$display_order] = true;
                            else
                                $numeric_datafields[$display_order] = false;

                            // Don't continue looking for this field
                            break;
                        }
                    }
                }

                $sorted_datarecord_list = $this->sort_service->multisortDatarecordList($source_dt_id, $sort_datafields, $sort_directions, $linked_datafields, $numeric_datafields, $grandparent_ids_for_sorting);
            }

            // Query to get
            // Convert from ($dr_id => $sort_value) into ($num => $dr_id)
            $sorted_datarecord_list = array_keys($sorted_datarecord_list);
        }

        $dr_list = array();
        if($return_as_list) {
            $str =
                'SELECT dr.id AS dr_id, dr.unique_id AS dr_uuid
                FROM ODRAdminBundle:DataRecord AS dr
                LEFT JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                WHERE dr.id IN (:datatype_ids)
                AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL';

            $params = array('datatype_ids' => $sorted_datarecord_list);

            $query = $this->em->createQuery($str)->setParameters($params);
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $dr_uuid = $result['dr_uuid'];

                $dr_list[$dr_id] = array(
                    'internal_id' => $dr_id,
                    'unique_id' => $dr_uuid,
                    'external_id' => '',
                    'record_name' => '',
                );
            }
        }
        else {
            $dr_list = $sorted_datarecord_list;
        }

        // ----------------------------------------
        // There's no point to caching the end result...it depends heavily on the user's permissions
        return $dr_list;
    }


    /**
     * Extracts all datafield/datatype entities listed in $criteria, and returns them as hydrated
     * objects in an array.
     *
     * @param array $criteria
     *
     * @return array
     */
    public function hydrateCriteria($criteria)
    {
        // ----------------------------------------
        // Searching is *just* different enough between datatypes and templates to be a pain...
        $search_type = $criteria['search_type'];
        unset( $criteria['search_type'] );

        // Want to find all datafield entities listed in the criteria array
        $datafield_ids = array();
        foreach ($criteria as $dt_id => $facet_list) {
            // Only look for datafields inside facet lists
            if ( !is_numeric($dt_id) && $dt_id !== 'general' )
                continue;

            foreach ($facet_list as $facet_num => $facet) {
                // Only bother with facets that have search data
                if ( isset($facet['search_terms']) ) {
                    foreach ($facet['search_terms'] as $key => $search_params) {
                        // Extract the entity from the criteria array
                        $entity_type = $search_params['entity_type'];
                        $entity_id = $search_params['entity_id'];

                        if ($entity_type === 'datafield')
                            $datafield_ids[$entity_id] = 1;
                    }
                }
            }
        }
        $datafield_ids = array_keys($datafield_ids);


        // ----------------------------------------
        // Need to hydrate all of the datafields/datatypes so the search functions work
        $datafields = array();
        $render_plugins = array();
        if ( !empty($datafield_ids) ) {
            $datafields = self::hydrateDatafields($search_type, $datafield_ids);
            $render_plugins = self::hydrateRenderPlugins($search_type, $datafield_ids);
        }


        // Because of permissions, need to hydrate all datatypes...
        $datatypes = array();
        if ( $search_type === 'datatype' )
            $datatypes = self::hydrateDatatypes($search_type, $criteria['all_datatypes']);
        else
            $datatypes = self::hydrateDatatypes($search_type, $criteria['all_templates']);


        // ----------------------------------------
        // Return the hydrated arrays
        return array(
            'datafield' => $datafields,
            'renderPlugin' => $render_plugins,
            'datatype' => $datatypes,
        );
    }


    /**
     * The hydration requirements are slightly different between "regular" searches and "template"
     * searches...
     *
     * TODO - is hydration actually required?
     *
     * @param string $search_type
     * @param int[] $datafield_ids
     *
     * @return DataFields[]
     */
    private function hydrateDatafields($search_type, $datafield_ids)
    {
        $datafields = array();

        if ($search_type === 'datatype') {
            // For a regular search, need to hydrate all datafields being searched on
            $params = array(
                'datafield_ids' => $datafield_ids
            );

            $query = $this->em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                WHERE df.id IN (:datafield_ids)
                AND df.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters($params);
            $results = $query->getResult();

            /** @var DataFields[] $results */
            foreach ($results as $df)
                $datafields[ $df->getId() ] = $df;
        }
        else {
            // For a template search, only want to hydrate the master template fields...otherwise
            //  we would have to hydrate every single datafield that uses the searched template
            //  fields as their master datafields.
            // Not really a good idea, especially since the actual searching functions can just
            //  have the database queries return a datafield id for permissions purposes
            $params = array(
                'field_uuids' => $datafield_ids
            );

            $query = $this->em->createQuery(
               'SELECT df
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                WHERE df.fieldUuid IN (:field_uuids) AND df.is_master_field = 1
                AND df.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters($params);
            $results = $query->getResult();

            /** @var DataFields[] $results */
            foreach ($results as $df)
                $datafields[ $df->getFieldUuid() ] = $df;
        }

        return $datafields;
    }


    /**
     * Loads render plugins that affect search results prior to use.
     *
     * @param string $search_type
     * @param array $datafield_ids
     *
     * @return array
     */
    private function hydrateRenderPlugins($search_type, $datafield_ids)
    {
        $render_plugins = array();

        if ( $search_type === 'datatype' ) {
            // For a regular search, need to hydrate all datafields being searched on
            $params = array(
                'datafield_ids' => $datafield_ids,
                'override_search' => true
            );

            // Because both datafield and datatype plugins can use search_override, it's easier if
            //  two dql queries are used. The first determines whether the datafields being searched
            //  on belong to a plugin that (possibly) wants to override searching...
            $query = $this->em->createQuery(
               'SELECT df.id AS df_id, rp.id AS rp_id, rpi.id AS rpi_id, rp.pluginClassName AS plugin_classname, rpf.fieldName
                FROM ODRAdminBundle:RenderPlugin AS rp
                JOIN ODRAdminBundle:RenderPluginInstance AS rpi WITH rpi.renderPlugin = rp
                JOIN ODRAdminBundle:RenderPluginMap AS rpm WITH rpm.renderPluginInstance = rpi
                JOIN ODRAdminBundle:RenderPluginFields AS rpf WITH rpm.renderPluginFields = rpf
                JOIN ODRAdminBundle:DataFields AS df WITH rpm.dataField = df
                WHERE rp.overrideSearch = :override_search AND rp.active = 1 AND df.id IN (:datafield_ids)
                AND rp.deletedAt IS NULL AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL
                AND rpf.deletedAt IS NULL AND df.deletedAt IS NULL'
            )->setParameters($params);
            $results = $query->getArrayResult();

            // Only want to load each render plugin once...
            $render_plugins_cache = array();
            $render_plugins_overrides = array();
            foreach ($results as $result) {
                $rp_id = $result['rp_id'];
                $plugin_classname = $result['plugin_classname'];
                $rpi_id = $result['rpi_id'];

                if ( !isset($render_plugins_cache[$plugin_classname]) ) {
                    /** @var SearchOverrideInterface $plugin */
                    $plugin = $this->container->get($plugin_classname);
                    $render_plugins_cache[$plugin_classname] = array('id' => $rp_id, 'plugin' => $plugin);
                }

                // Also need to organize these datafields by the render plugin instance that they're
                //  attached to
                if ( !isset($render_plugins_overrides[$plugin_classname]) )
                    $render_plugins_overrides[$plugin_classname] = array();
                if ( !isset($render_plugins_overrides[$plugin_classname][$rpi_id]) )
                    $render_plugins_overrides[$plugin_classname][$rpi_id] = array();

                $rpf_name = $result['fieldName'];
                $df_id = $result['df_id'];
                $render_plugins_overrides[$plugin_classname][$rpi_id][$rpf_name] = $df_id;

                // NOTE: $render_plugins_overrides could have more fields than the plugin actually
                //  wants to override
            }

            // Now that the fields have been grouped, determine whether the render plugin wants to
            //  override the search routine for each set of fields
            foreach ($render_plugins_cache as $plugin_classname => $plugin_data) {
                $plugin = $plugin_data['plugin'];

                // ...I don't think it's strictly necessary to check each render plugin instance, but
                //  maybe it will be in the future.  Dunno.
                foreach ($render_plugins_overrides[$plugin_classname] as $rpi_id => $df_list) {
                    $ret = $plugin->getSearchOverrideFields($df_list);

                    if ( !empty($ret) ) {
                        // ...the plugin wants to override at least one field
                        foreach ($ret as $rpf_name => $df_id) {
                            // It's easier on SearchAPIService if each datafield gets a reference to
                            //  the plugin (and eventually to the plugin's options)
                            $render_plugins[$df_id] = array(
                                'renderPlugin' => $plugin_data,
                            );
                        }
                    }
                    else {
                        // ...the plugin doesn't want to override any fields
                        unset( $render_plugins_overrides[$plugin_classname][$rpi_id] );
                    }
                }
            }

            // If a plugin wants to override searching for a field...
            $render_plugin_instance_ids = array();
            foreach ($render_plugins_overrides as $plugin_classname => $rpi_list) {
                // ...then we need to also load the related set of options for each render plugin
                //  instance
                foreach ($rpi_list as $rpi_id => $df_list)
                    $render_plugin_instance_ids[] = $rpi_id;
            }

            $render_plugin_options = array();
            $render_plugin_fields = array();    // NOTE: different than $render_plugin_fields
            if ( !empty($render_plugin_instance_ids) ) {
                $query = $this->em->createQuery(
                   'SELECT rpi.id AS rpi_id, rpom.value AS rpom_value, rpod.name AS rpod_name
                    FROM ODRAdminBundle:RenderPluginInstance rpi
                    LEFT JOIN ODRAdminBundle:RenderPluginOptionsMap AS rpom WITH rpom.renderPluginInstance = rpi
                    LEFT JOIN ODRAdminBundle:RenderPluginOptionsDef AS rpod WITH rpom.renderPluginOptionsDef = rpod
                    WHERE rpi.id IN (:render_plugin_instance_ids)
                    AND rpi.deletedAt IS NULL AND rpom.deletedAt IS NULL AND rpod.deletedAt IS NULL'
                )->setParameters( array('render_plugin_instance_ids' => $render_plugin_instance_ids) );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $rpi_id = $result['rpi_id'];
                    $option_name = $result['rpod_name'];
                    $option_value = $result['rpom_value'];

                    if ( !is_null($option_name) ) {
                        if ( !isset($render_plugin_options[$rpi_id]) )
                            $render_plugin_options[$rpi_id] = array();
                        $render_plugin_options[$rpi_id][$option_name] = $option_value;
                    }
                }

                // When overriding datatype plugins, it's useful to have the renderPluginField list...
                $query = $this->em->createQuery(
                   'SELECT rpi.id AS rpi_id, rpm_df.id AS df_id, rpf.fieldName AS rpf_name
                    FROM ODRAdminBundle:RenderPluginInstance AS rpi
                    LEFT JOIN ODRAdminBundle:RenderPluginMap AS rpm WITH rpm.renderPluginInstance = rpi
                    LEFT JOIN ODRAdminBundle:RenderPluginFields AS rpf WITH rpm.renderPluginFields = rpf
                    LEFT JOIN ODRAdminBundle:DataFields AS rpm_df WITH rpm.dataField = rpm_df
                    WHERE rpi.id IN (:render_plugin_instance_ids)
                    AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL
                    AND rpf.deletedAt IS NULL AND rpm_df.deletedAt IS NULL'
                )->setParameters( array('render_plugin_instance_ids' => $render_plugin_instance_ids) );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $rpi_id = $result['rpi_id'];
                    $df_id = $result['df_id'];
                    $rpf_name = $result['rpf_name'];

                    if ( !isset($render_plugin_fields[$rpi_id]) )
                        $render_plugin_fields[$rpi_id] = array();
                    $render_plugin_fields[$rpi_id][$rpf_name] = $df_id;
                }
            }

            // Need to map any existing render plugin options to each datafield they're related to
            foreach ($render_plugins_overrides as $plugin_classname => $rpi_list) {
                $plugin = $render_plugins_cache[$plugin_classname]['plugin'];

                foreach ($rpi_list as $rpi_id => $df_list) {
                    foreach ($df_list as $rpf_name => $df_id) {
                        // Since $render_plugins_overrides could refer to more fields than the plugin
                        //  actually wants to override...
                        if ( isset($render_plugins[$df_id]) ) {
                            // ...only continue processing on entries that already exist

                            // Easier to replace the existing array entry for this datafield...
                            $render_plugins[$df_id] = array(
                                'renderPlugin' => $plugin,
                                'renderPluginOptions' => array()
                            );
                            // ...and attach any render plugin options if they exist
                            if ( isset($render_plugin_options[$rpi_id]) )
                                $render_plugins[$df_id]['renderPluginOptions'] = $render_plugin_options[$rpi_id];
                            // ...same for the render plugin field list
                            if ( isset($render_plugin_fields[$rpi_id]) )
                                $render_plugins[$df_id]['renderPluginFields'] = $render_plugin_fields[$rpi_id];
                        }
                    }
                }
            }
        }
        else {
            throw new ODRNotImplementedException('unable to run search plugins across templates...');
        }

        return $render_plugins;
    }


    /**
     * The hydration requirements are slightly different between "regular" searches and "template"
     * searches...
     *
     * TODO - is hydration actually required?
     *
     * @param string $search_type
     * @param int[]|string $datatype_ids
     *
     * @return DataType[]
     */
    private function hydrateDatatypes($search_type, $datatype_ids)
    {
        $results = array();
        if ($search_type === 'datatype') {
            // For a regular search, need to hydrate all datatypes that could be searched on
            // Otherwise, we can't deal with permissions properly
            $params = array(
                'datatype_ids' => $datatype_ids
            );

            $query = $this->em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataType AS dt
                WHERE dt.id IN (:datatype_ids)
                AND dt.deletedAt IS NULL'
            )->setParameters($params);
            $results = $query->getResult();
        }
        else {
            // For a template search, we still need to hydrate all the non-template datatypes that
            //  are being searched on...otherwise, we can't deal with permissions properly
            $params = array(
                'template_uuids' => $datatype_ids
            );

            $query = $this->em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataType AS mdt
                JOIN ODRAdminBundle:DataType AS dt WITH dt.masterDataType = mdt
                JOIN ODRAdminBundle:DataType AS gp WITH dt.grandparent = gp
                WHERE mdt.unique_id IN (:template_uuids)
                AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL AND gp.deletedAt IS NULL'
            )->setParameters($params);
            $results = $query->getResult();
        }

        /** @var DataType[] $results */
        $datatypes = array();
        foreach ($results as $dt)
            $datatypes[ $dt->getId() ] = $dt;

        return $datatypes;
    }


    /**
     * Runs a cross-template search specified by the given $search_key.  The end result is filtered
     * based on the user's permissions.
     *
     * @param string $search_key
     * @param array $user_permissions
     * @param bool $search_as_super_admin If true, bypass all permissions checking
     *
     * @return array
     */
    public function performTemplateSearch($search_key, $user_permissions, $search_as_super_admin = false)
    {
        // ----------------------------------------
        // "Template" searching is roughly similar to "regular" searching, but it ends up taking a
        //  different path to reach the search result due to a template potentially having hundreds
        //  of datatypes that are derived from it

        // Need to get the template_uuid from the search key before the criteria step...
        $params = $this->search_key_service->decodeSearchKey($search_key);
        $template_uuid = $params['template_uuid'];

        // ...because it's needed in order to locate
        $template_structure = $this->search_service->getSearchableTemplateDatafields($template_uuid);
        $criteria = $this->search_key_service->convertSearchKeyToTemplateCriteria($search_key, $template_structure);
        // Don't need the template_uuid
        unset( $criteria['template_uuid'] );

        // Need the list of datatypes that are being searched on via advanced search, but need to
        //  reference them by regular ids, instead of their uuids
        $affected_datatypes = array();
        foreach ($criteria['affected_datatypes'] as $num => $dt_uuid) {
            $dt_id = $template_structure[$dt_uuid]['dt_id'];
            $affected_datatypes[$dt_id] = 1;
        }

        // No longer need this entry
        unset( $criteria['affected_datatypes'] );

        /** @var DataType $template_datatype */
        $template_datatype = $this->em->getRepository('ODRAdminBundle:DataType')->findOneBy(
            array('unique_id' => $template_uuid)
        );
        if ( $template_datatype == null )
            throw new ODRNotFoundException('Template Datatype');
        if ( $template_datatype->getId() !== $template_datatype->getGrandparent()->getId() )
            throw new ODRException('Unable to directly search on a child template');


        // Extract sort information from the search key if it exists
        $sort_df_uuid = null;
        $sort_ascending = true;
        if ( isset($criteria['sort_by']) ) {
            $sort_df_uuid = $criteria['sort_by']['sort_df_uuid'];

            if ( $criteria['sort_by']['sort_dir'] === 'desc' )
                $sort_ascending = false;

            // Sort criteria extracted, get rid of it so the search isn't messed up
            unset( $criteria['sort_by'] );
        }

        // No longer need what type of search this is
        unset( $criteria['search_type'] );

        // Dig through the criteria array to determine which template datafields are being searched
        //  on...
        $affected_datafields = array();
        foreach ($criteria as $facet => $facet_data) {
            foreach ($facet_data as $num => $data) {
                foreach ($data['search_terms'] as $field_uuid => $df_data)
                    $affected_datafields[$field_uuid] = 1;
            }
        }
        $affected_datafields = array_keys($affected_datafields);

        // ...because each of these template datafields needs to be hydrated for the search to work
        $query = $this->em->createQuery(
           'SELECT df
            FROM ODRAdminBundle:DataFields AS df
            WHERE df.fieldUuid IN (:field_uuids)
            AND df.deletedAt IS NULL'
        )->setParameters( array('field_uuids' => $affected_datafields) );
        $results = $query->getResult();

        $hydrated_datafields = array();
        foreach ($results as $df) {
            /** @var DataFields $df */
            $hydrated_datafields[ $df->getFieldUuid() ] = $df;
        }


        // In order to perform a template search in a reasonable amount of time, performTemplateSearch()
        //  is going to "pretend" that each template datatype "owns" the datarecords belonging to
        //  all of its derived datatypes.
        // However, correct application of user permissions requires a list of the ids of the derived
        //  datatypes and datafields
        $searchable_datafields = self::getSearchableTemplateDatafieldsForUser($affected_datafields, $user_permissions, $search_as_super_admin);


        // ----------------------------------------
        // Need to have a list of all top-level datatypes that derive themselves from the template
        //  being searched on...can't just use $searchable_datafields, because the search isn't
        //  guaranteed to be on a top-level datatype
        $query = $this->em->createQuery(
           'SELECT dt.id AS dt_id
            FROM ODRAdminBundle:DataType AS mdt
            JOIN ODRAdminBundle:DataType AS dt WITH dt.masterDataType = mdt
            WHERE mdt.unique_id = :template_uuid AND dt = dt.grandparent
            AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters( array('template_uuid' => $template_uuid) );
        $results = $query->getArrayResult();

        $top_level_datatype_ids = array();
        foreach ($results as $result)
            $top_level_datatype_ids[ $result['dt_id'] ] = 1;


        // Going to need three arrays so mergeSearchResults() can correctly determine which records
        //  end up matching the search
        $search_arrays = self::getTemplateSearchArrays(
            $template_uuid,
            $top_level_datatype_ids,
            $affected_datatypes,
            $user_permissions,
            $search_as_super_admin
        );
        $flattened_list = $search_arrays['flattened'];
        $inflated_list = $search_arrays['inflated'];
        $search_datatree = $search_arrays['search_datatree'];

        // An "empty" search run with no criteria needs to return all top-level datarecord ids
        $return_all_results = true;

        // Need to keep track of the result list for each facet separately...they end up merged
        //  together after all facets are searched on
        $facet_dr_list = array();
        foreach ($criteria as $dt_uuid => $facet_list) {
            // The criteria array is using template_uuids, but it's easier for the search result
            //  merging when the facet list uses the actual ids of the template datatypes
            $template_dt_id = 'general';
            if ( $dt_uuid !== 'general' && $dt_uuid !== 'field_stats' )
                $template_dt_id = $template_structure[$dt_uuid]['dt_id'];

            // Need to keep track of the matches for each datatype individually...
            $facet_dr_list[$template_dt_id] = array();

            foreach ($facet_list as $facet_num => $facet) {
                // ...and also keep track of the matches for each facet within this datatype individually
                $facet_dr_list[$template_dt_id][$facet_num] = null;

                $facet_type = $facet['facet_type'];
                $merge_type = $facet['merge_type'];
                $search_terms = $facet['search_terms'];

                // For each search term within this facet...
                foreach ($search_terms as $df_uuid => $search_term) {
                    // Don't return all top-level datarecord ids at the end
                    $return_all_results = false;

                    // ...extract the entity for this search term
//                    $entity_type = $search_term['entity_type'];    // TODO - search on datatype metadata?
                    $entity_id = $search_term['entity_id'];
                    /** @var DataFields $entity */
                    $entity = $hydrated_datafields[$entity_id];

                    // Datafield search depends on the typeclass of the field
                    $typeclass = $entity->getFieldType()->getTypeClass();

                    // Run/load the desired query based on the criteria
                    $results = array();
                    if ($typeclass === 'Boolean') {
                        // Only split from the text/number searches to avoid parameter confusion
                        $results = $this->search_service->searchBooleanTemplateDatafield($entity, $search_term['value']);
                    }
                    else if ($typeclass === 'Radio' && $facet_type === 'general') {
                        // General search only provides a string, and only wants selected radio options
                        $results = $this->search_service->searchForSelectedTemplateRadioOptions($entity, $search_term['value']);
                    }
                    else if ($typeclass === 'Radio' && $facet_type === 'field_stats') {
                        // The fieldstats controller action is only interested in which radio options
                        //  are selected and how many datarecords they're selected in...
                        $results = $this->search_service->searchTemplateRadioOptionFieldStats($entity);

                        // Apply the permissions filter and return without executing the rest of
                        //  the search routine
                        return self::getfieldstatsFilter($results['records'], $results['labels'], $searchable_datafields, $flattened_list);
                    }
                    else if ($typeclass === 'Radio') {
                        // The more specific version of searching a radio datafield provides an array of selected/deselected options
                        $results = $this->search_service->searchRadioTemplateDatafield($entity, $search_term['selections'], $search_term['combine_by_OR']);
                    }
                    else if ($typeclass === 'Tag' && $facet_type === 'general') {
                        // General search only provides a string, and only wants selected tags
                        $results = $this->search_service->searchForSelectedTemplateTags($entity, $search_term['value']);
                    }
                    else if ($typeclass === 'Tag' && $facet_type === 'field_stats') {
                        // The fieldstats controller action is only interested in which tags are
                        //  selected and how many datarecords they're selected in...
                        $results = $this->search_service->searchTemplateTagFieldStats($entity);

                        // Apply the permissions filter and return without executing the rest of
                        //  the search routine
                        return self::getfieldstatsFilter($results['records'], $results['labels'], $searchable_datafields, $flattened_list);
                    }
                    else if ($typeclass === 'Tag') {
                        // The more specific version of searching a tag datafield provides an array of selected/deselected options
                        $results = $this->search_service->searchTagTemplateDatafield($entity, $search_term['selections'], $search_term['combine_by_OR']);
                    }
                    else if ($typeclass === 'File' || $typeclass === 'Image') {
                        // Searches on Files/Images are effectively interchangable
                        $results = $this->search_service->searchFileOrImageTemplateDatafield($entity, $search_term);    // There could be three different terms in there, actually
                    }
                    else if ($typeclass === 'DatetimeValue') {
                        // DatetimeValue needs to worry about before/after...
                        $results = $this->search_service->searchDatetimeTemplateDatafield($entity, $search_term['before'], $search_term['after']);
                    }
                    else {
                        // Short/Medium/LongVarchar, Paragraph Text, and Integer/DecimalValue
                        $results = $this->search_service->searchTextOrNumberTemplateDatafield($entity, $search_term['value']);
                    }


                    // ----------------------------------------
                    // The template search functions return a list of all records that matched the
                    //  search, regardless of whether the user is allowed to view the datatypes and
                    //  datafields in question...it's easier to filter those out here
                    $tmp_dr_list = array();
                    foreach ($results as $dt_id => $df_list) {
                        if ( isset($searchable_datafields[$dt_id]) ) {
                            foreach ($df_list as $df_id => $dr_list) {
                                if ( isset($searchable_datafields[$dt_id][$df_id]) ) {
                                    foreach ($dr_list as $dr_id => $num)
                                        $tmp_dr_list[$dr_id] = 1;
                                }
                            }
                        }
                    }
                    // ...after the filtering is done, the datatype/datafield info can be discarded
                    $dr_list = array(
                        'records' => $tmp_dr_list
                    );

                    // Need to merge this result with the existing matches for this facet
                    if ($merge_type === 'OR') {
                        if ( is_null($facet_dr_list[$template_dt_id][$facet_num]) )
                            $facet_dr_list[$template_dt_id][$facet_num] = array();

                        // Merging by 'OR' criteria...every datarecord returned from the search matches
                        foreach ($dr_list['records'] as $dr_id => $num)
                            $facet_dr_list[$template_dt_id][$facet_num][$dr_id] = $num;
                    }
                    else {
                        // Merging by 'AND' criteria...if this is the first (or only) criteria...
                        if ( is_null($facet_dr_list[$template_dt_id][$facet_num]) ) {
                            // ...use the datarecord list returned by the first search
                            $facet_dr_list[$template_dt_id][$facet_num] = $dr_list['records'];
                        }
                        else {
                            // Otherwise, intersect the list returned by the search with the existing list
                            $facet_dr_list[$template_dt_id][$facet_num] = array_intersect_key($facet_dr_list[$template_dt_id][$facet_num], $dr_list['records']);
                        }
                    }
                }
            }
        }


        // ----------------------------------------
        // Now that the individual search queries have been run...
        if ( $return_all_results ) {
            // When no search criteria is specified, then every datarecord that the user can see
            //  needs to be marked as "matching" the search
            foreach ($flattened_list as $dr_id => $num) {
                if ( !($num & SearchAPIService::CANT_VIEW) )
                    $flattened_list[$dr_id] |= SearchAPIService::MATCHES_BOTH;
            }
        }
        else {
            // Determine whether a 'general' search was executed...
            $was_general_seach = false;
            if ( isset($facet_dr_list['general']) )
                $was_general_seach = true;

            // Determine whether an 'advanced' search was executed...
            $was_advanced_search = false;
            foreach ($facet_dr_list as $key => $facet_list) {
                // ...then just need to save that an advanced search was run
                if ( is_numeric($key) && !empty($facet_list) ) {
                    $was_advanced_search = true;
                    break;
                }
            }

            // ...if both types of searches were executed, then the merging algorithm needs to keep
            //  track of which type of search each record matched
            $differentiate_search_types = $was_general_seach && $was_advanced_search;
            self::mergeSearchResults($criteria, true, $template_datatype->getId(), $search_datatree[$template_datatype->getId()], $facet_dr_list, $flattened_list, $differentiate_search_types);
        }


        // ----------------------------------------
        // Traverse $inflated_list to get the final set of datarecords that match the search
        // TODO - only run this when starting a MassEdit/CSVExport job?
//        $datarecord_ids = self::getMatchingDatarecords($flattened_list, $inflated_list);
//        $datarecord_ids = array_keys($datarecord_ids);

        // Traverse the top-level of $inflated_list to get the grandparent datarecords that match
        //  the search
        $grandparent_ids = array();
        foreach ($inflated_list as $dt_id => $dr_list) {
            foreach ($dr_list as $gp_id => $something) {
                if ( ($flattened_list[$gp_id] & SearchAPIService::MATCHES_BOTH) === SearchAPIService::MATCHES_BOTH )
                    $grandparent_ids[] = $gp_id;
            }
        }


        // Sort the resulting array
        $sorted_datarecord_list = array();
        if ( !is_null($sort_df_uuid) ) {
            $sorted_datarecord_list = $this->sort_service->sortDatarecordsByTemplateDatafield($sort_df_uuid, $sort_ascending, implode(',', $grandparent_ids));

            // Convert from ($dr_id => $sort_value) into ($num => $dr_id)
            $sorted_datarecord_list = array_keys($sorted_datarecord_list);
        }
        else {
            // list is already in ($num => $dr_id) format
            $sorted_datarecord_list = $grandparent_ids;
        }


        // ----------------------------------------
        // Save/return the end result
        $search_result = array(
//            'complete_datarecord_list' => $datarecord_ids,
            'grandparent_datarecord_list' => $sorted_datarecord_list,
        );

        // There's no point to caching the end result...it depends heavily on the user's permissions
        return $search_result;
    }


    /**
     * {@link APIController::getfieldstatsAction()} needs to return a count of how many datarecords
     * have a specific radio option or tag selected across all instances of a template datafield.
     * This function filters the raw search results by the user's permissions before the APIController
     * action gets it.
     *
     * @param array $records
     * @param array $labels
     * @param array $searchable_datafields {@link getSearchableDatafieldsForUser()}
     * @param array $flattened_list {@link getSearchArrays()}
     *
     * @return array
     */
    private function getfieldstatsFilter($records, $labels, $searchable_datafields, $flattened_list)
    {
        foreach ($records as $dt_id => $df_list) {
            // Filter out datatypes the user can't see...
            if ( !isset($searchable_datafields[$dt_id]) ) {
                unset( $records[$dt_id] );
            }
            else {
                foreach ($df_list as $df_id => $dr_list) {
                    // Filter out datafields the user can't see...
                    if ( !isset($searchable_datafields[$dt_id][$df_id]) ) {
                        unset( $records[$dt_id][$df_id] );
                    }
                    else {
                        // Filter out non-public datarecords the user can't see
                        foreach ($dr_list as $dr_id => $ro_list) {
                            if ( ($flattened_list[$dr_id] & SearchAPIService::CANT_VIEW) === SearchAPIService::CANT_VIEW )
                                unset( $records[$dt_id][$df_id][$dr_id] );
                        }
                    }
                }
            }
        }

        // Return the filtered list back to the APIController
        return array(
            'labels' => $labels,
            'records' => $records
        );
    }


    /**
     * It's easier for {@link performSearch()} when {@link getSearchArrays()} returns arrays that
     * already contain the user's permissions and which datatypes are being searched on...this
     * utility function gathers that required info in a single spot.
     *
     * @param DataType[] $hydrated_datatypes
     * @param int[] $affected_datatypes {@link SearchKeyService::convertSearchKeyToCriteria()}
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     *
     * @return array
     */
    public function getSearchPermissionsArray($hydrated_datatypes, $affected_datatypes, $user_permissions, $search_as_super_admin = false)
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
            else if ( isset($datatype_permissions[$dt_id]['dt_view']) )
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
                else if ( isset($datatype_permissions[$dt_id]['dr_view']) )
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
     * Returns three arrays that are required for determining which datarecords match a search.
     * Technically a search run on a top-level datatype doesn't need all this, but any search that
     *  involves a child/linked datatype does.
     *
     * The first array is a "flattened" list of all records that could end up matching the search,
     *  and is primarily utilized so the rest of the searching doesn't have to deal with recursion
     *  when computing search results.  It consists of an array of <datarecord_id> => <num> pairs,
     *  where <num> is some composite of the various binary flags defined at the top of the SearchAPIService.
     *
     * The second array is an "inflated" array of all records and their descendants, because the
     *  hierarchy of "ancestor" -> "descendant" is critical to determining which "ancestors" end up
     *  matching the search. See {@link buildDatarecordTree()}.  When $inverse is true, then the
     *  linked relations are inverted, while the parent/child relations remain the same.
     *
     * The third array {@link buildSearchDatatree()} is used as a guide for merging the various
     * facets of records that matched the search. {@link mergeSearchResults()}
     *
     * @param int[] $top_level_datatype_ids
     * @param array $permissions_array {@link getSearchPermissionsArray()}
     * @param bool $inverse
     *
     * @return array
     */
    public function getSearchArrays($top_level_datatype_ids, $permissions_array, $inverse = false)
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
        $datatree_array = $this->datatree_info_service->getDatatreeArray();

        // Base setup for both arrays...
        $flattened_list = array();
        $inflated_list = array(0 => array());
        foreach ($top_level_datatype_ids as $num => $dt_id)
            $inflated_list[0][$dt_id] = array();

        // Flip this array so isset() can be used instead of in_array() later on
        $top_level_datatype_ids = array_flip($top_level_datatype_ids);

        // @see self::buildSearchDatatree()
        $search_datatree = self::buildSearchDatatree($datatree_array, $top_level_datatype_ids, $inverse);

        // Actually build the flattened and inflated lists
        self::getSearchArrays_Worker($datatree_array, $permissions_array, $top_level_datatype_ids, $flattened_list, $inflated_list, $inverse);


        // ----------------------------------------
        // Sort the flattened list for easier debugging
        ksort($flattened_list);

        // Actually inflate the "inflated" list...
        $inflated_list = self::buildDatarecordTree($inflated_list, 0);

        // ...and then return the end result
        return array(
            'flattened' => $flattened_list,
            'inflated' => $inflated_list,

            'search_datatree' => $search_datatree,
        );
    }


    /**
     * This is split off from {@link getSearchArrays()} for readability reasons.
     *
     * @param array $datatree_array
     * @param array $permissions_array
     * @param array $top_level_datatype_ids
     * @param array &$flattened_list
     * @param array &$inflated_list
     * @param boolean $inverse
     *
     * @return void
     */
    private function getSearchArrays_Worker($datatree_array, $permissions_array, $top_level_datatype_ids, &$flattened_list, &$inflated_list, $inverse)
    {
        foreach ($permissions_array as $dt_id => $permissions) {
            // Ensure that the user is allowed to view this datatype before doing anything with it
            if (!$permissions['can_view_datatype'])
                continue;


            // If the datatype is linked...then the backend query to rebuild the cache entry is
            //  different, as is the insertion of the resulting datarecords into the "inflated" list
            $is_linked_type = false;
            if ( isset($datatree_array['linked_from'][$dt_id]) )
                $is_linked_type = true;

            // If this is the datatype being searched on (or one of the datatypes directly derived
            //  from the template being searched on), then $is_linked_type needs to be false, so
            //  getCachedSearchDatarecordList() will return all datarecords...otherwise, it'll only
            //  return those that are linked to from somewhere (which is usually desired when
            //  searching a linked datatype)
            if ( isset($top_level_datatype_ids[$dt_id]) )
                $is_linked_type = false;

            // Attempt to load this datatype's datarecords and their parents from the cache...
            if ( !$inverse )
                $list = $this->search_service->getCachedSearchDatarecordList($dt_id, $is_linked_type);
            else
                $list = $this->search_service->getInverseSearchDatarecordList($dt_id, $is_linked_type);

            // Both calls return datarecord lists in the same format...the keys are always the ids
            //  of the records belonging to $dt_id.  When $inverse is false the values are always the
            //  parents...either
            //  1) the id of its parent when it's not a top-level datarecord, or
            //  2) an array of the ids of records that link to it

            // When $inverse is true, then #2 changes to provide an array of the ids this record
            //  links to instead...this effectively inverts the "ancestor" -> "descendant" relation,
            //  and fortunately most of the rest of the searching logic doesn't care


            // ----------------------------------------
            // Each datarecord in the flattened list needs to start out with one of three values...
            // - CANT_VIEW: this user can't see this datarecord, so it needs to be ignored
            // - MUST_MATCH: this datarecord has a datafield that's part of "advanced" search...
            //               ...it must end up matching the search to be included in the results
            // - DOESNT_MATTER: this datarecord is not part of an "advanced" search, so it will
            //                   have no effect on the final search result
            foreach ($list as $dr_id => $value) {
                if ( isset($permissions['non_public_datarecords'][$dr_id]) )
                    $flattened_list[$dr_id] = SearchAPIService::CANT_VIEW;
                else if ( $permissions['affected'] === true )
                    $flattened_list[$dr_id] = SearchAPIService::MUST_MATCH;
                else
                    $flattened_list[$dr_id] = SearchAPIService::DOESNT_MATTER;
            }


            // Inserting into $inflated_list depends on what type of datatype this is...
            // @see self::buildDatarecordTree() for the eventual structure
            if ( isset($top_level_datatype_ids[$dt_id]) ) {
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
    }


    /**
     * Recursively builds an array of the following form for {@link mergeSearchResults()} to use:
     * <pre>
     * <datatype_id> => array(
     *     'dr_list' => <datarecord_list>,
     *     'children' => array(...),
     *     'links' => array(...),
     * )
     * </pre>
     * ...where the array structure is recursively repeated inside 'children' or 'links', depending
     *  on whether the descendant is a child or a linked datatype.
     *
     * The <datarecord_list> stores whatever {@link SearchService::getCachedSearchDatarecordList()}
     *  returns for the current datatype.
     *
     * @param array $datatree_array
     * @param array $top_level_datatype_ids
     * @param bool $inverse If false, then the array contains the searchable datafields from descendant
     *                       datatypes...if true, then it comes from the ancestor datatypes instead
     * @param bool $is_linked_type
     *
     * @return array
     */
    private function buildSearchDatatree($datatree_array, $top_level_datatype_ids, $inverse = false, $is_linked_type = false)
    {
        $tmp = array();

        foreach ($top_level_datatype_ids as $dt_id => $num) {
            $tmp[$dt_id] = array(
                'dr_list' => array(),
                'children' => array(),
                'links' => array(),
            );

            // Need to store all datarecords of this datatype...
            if ( !$inverse )
                $tmp[$dt_id]['dr_list'] = $this->search_service->getCachedSearchDatarecordList($dt_id, $is_linked_type);
            else
                $tmp[$dt_id]['dr_list'] = $this->search_service->getInverseSearchDatarecordList($dt_id, $is_linked_type);

            // Always want any children of the top-level datatype...
            $children = array();
            foreach ($datatree_array['descendant_of'] as $child_dt_id => $parent_dt_id) {
                if ( $dt_id === $parent_dt_id )
                    $children[$child_dt_id] = 1;
            }
            if ( !empty($children) )
                $tmp[$dt_id]['children'] = self::buildSearchDatatree($datatree_array, $children, $inverse);

            // ...but which links to get depend on the value of $inverse
            $links = array();
            if ( !$inverse ) {
                // When $inverse is false, then need to recursively dig through linked descendants
                foreach ($datatree_array['linked_from'] as $descendant_id => $ancestors) {
                    if ( in_array($dt_id, $ancestors) )
                        $links[$descendant_id] = 1;
                }
            }
            else {
                // When $inverse is true, then need to recursively dig through linked ancestors
                if ( isset($datatree_array['linked_from'][$dt_id]) ) {
                    foreach ($datatree_array['linked_from'][$dt_id] as $num => $ancestor_dt_id)
                        $links[$ancestor_dt_id] = 1;
                }
            }

            // Regardless of which set of datatypes could be in $links...
            if ( !empty($links) ) {
                // ...if it has something, then recursively continue digging for related datatypes
                $tmp[$dt_id]['links'] = self::buildSearchDatatree($datatree_array, $links, $inverse, true);
            }
        }

        return $tmp;
    }


    /**
     * Turns the originally flattened $descendants_of_datarecord array into a recursive tree
     *  structure of the form...
     * <pre>
     * <parent_datarecord_id> => array(
     *     <child_datatype_1_id> => array(
     *         <child_datarecord_1_id of child_datatype_1> => '',
     *         <child_datarecord_2_id of child_datatype_1> => '',
     *         ...
     *     ),
     *     <child_datatype_2_id> => array(
     *         <child_datarecord_1_id of child_datatype_2> => '',
     *         <child_datarecord_2_id of child_datatype_2> => '',
     *         ...
     *     ),
     *     ...
     * )
     * </pre>
     *
     * If child_datarecord_X_id has children of its own, then it is also a parent datarecord, and
     *  it points to another recursive tree structure of this type instead of an empty string.
     * Linked datatypes/datarecords are handled identically to child datatypes/datarecords.
     *
     * The tree's root looks like...
     * <pre>
     * 0 => array(
     *     <target_datatype_id> => array(
     *         <top_level_datarecord_1_id> => ...
     *         <top_level_datarecord_2_id> => ...
     *         ...
     *     )
     * )
     * </pre>
     * TODO - now that $inflated_list isn't used to perform search logic, are the datatype ids still useful?
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
     * The "template" analog of {@link getSearchArrays()} does mostly the same thing, but it doesn't
     * attempt to get the list of records from cached data...instead, it uses a handful of specific
     * queries to load all records that the user is allowed to see, with as little overhead as
     * possible. {@link getSearchArrays()}
     *
     * @param string $template_uuid
     * @param array $top_level_datatype_ids An array where the keys are top-level datatype ids that
     *                                      are derived from the template datatype
     * @param array $affected_datatypes An array where the keys are datatypes with datafields that
     *                                  are being searched on
     * @param array $user_permissions
     * @param bool $search_as_super_admin If true, then user is allowed to see everything
     * @return array
     */
    public function getTemplateSearchArrays($template_uuid, $top_level_datatype_ids, $affected_datatypes, $user_permissions, $search_as_super_admin)
    {
        // ----------------------------------------
        // Intentionally not caching the results of this function for two reasons
        // 1) these arrays need to be initialized based on the search being run, and the
        //     permissions of the user running the search
        // 2) these arrays contain ids of datarecords across all datatypes related to the datatype
        //     being searched on...determining when to clear this entry, especially when linked
        //     datatypes are involved, would be nightmarish


        // ----------------------------------------
        // Instead of building the search_datatree from every single datatype that is derived from
        //  the template datatype...we're going to instead "collapse" all (potentially hundreds) of
        //  them into the template datatype's structure, based on which of the template datatype's
        //  descendants they're actually derived from...
        $query = $this->em->createQuery(
           'SELECT dt.id
            FROM ODRAdminBundle:DataType dt
            WHERE dt.unique_id = :template_uuid
            AND dt.deletedAt IS NULL'
        )->setParameters( array('template_uuid' => $template_uuid) );
        $results = $query->getArrayResult();
        $template_dt_id = $results[0]['id'];

        // @see self::buildSearchDatatree()
        $datatree_array = $this->datatree_info_service->getDatatreeArray();
        $search_datatree = self::buildSearchDatatree($datatree_array, array($template_dt_id => 1));


        // ----------------------------------------
        // Going to use native SQL to get the rest of the information, since there could be hundreds
        //  of datatypes derived from the given template and doctrine tends to require unecessary joins
        $conn = $this->em->getConnection();

        // Need to first get information on the top-level datatypes that are derived from the template
        $query =
           'SELECT dtm.data_type_id AS dt_id, dtm.public_date
            FROM odr_data_type_meta dtm
            WHERE dtm.data_type_id IN (?)
            AND dtm.deletedAt IS NULL';
        $params = array(1 => array_keys($top_level_datatype_ids));    // Need the datatype ids to be values, not keys
        $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
        $results = $conn->fetchAll($query, $params, $types);

        $ancestor_ids = array();
        $all_datatypes = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $public_date = $result['public_date'];

            // Need to ignore any of the derived datatypes (and their descendants) that the user
            //  isn't allowed to view
            $is_public = true;
            if ( $public_date === "2200-01-01 00:00:00" )
                $is_public = false;

            $can_view_datatype = false;
            if ( $search_as_super_admin || $is_public || isset($datatype_permissions[$dt_id]['dt_view']) )
                $can_view_datatype = true;

            // If the user can view this derived datatype...
            if ( $can_view_datatype ) {
                // ...then all of its descendants need to be located
                $ancestor_ids[] = $dt_id;

                // We're going to eventually need to locate all datatypes which were derived from a
                //  specific template datatype
                $all_datatypes[$dt_id] = $template_dt_id;
            }
        }

        // Next, need to locate all child/linked datatypes that are descended from these top-level
        //  derived datatypes...since we also need the public date of each of these descendants, we
        //  have to perform several mysql queries in a row to get all the data
        while (true) {
            $query =
               'SELECT descendant.id AS dt_id, mdt.id AS template_id, descendant_meta.public_date
                FROM odr_data_tree dt
                LEFT JOIN odr_data_type descendant ON dt.descendant_id = descendant.id
                LEFT JOIN odr_data_type_meta descendant_meta ON descendant_meta.data_type_id = descendant.id
                LEFT JOIN odr_data_type mdt ON descendant.master_datatype_id = mdt.id
                WHERE dt.ancestor_id IN (?)
                AND dt.deletedAt IS NULL AND mdt.deletedAt IS NULL
                AND descendant.deletedAt IS NULL AND descendant_meta.deletedAt IS NULL';
            $params = array(1 => $ancestor_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query, $params, $types);

            // If the set of ancestor datatypes has no descendants, then there's nothing left to do
            if ( empty($results) )
                break;

            $ancestor_ids = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $template_id = intval($result['template_id']);
                $public_date = $result['public_date'];

                // Need to ignore any of these datatypes (and their descendants) that the user isn't
                //  allowed to view
                $is_public = true;
                if ( $public_date === "2200-01-01 00:00:00" )
                    $is_public = false;

                $can_view_datatype = false;
                if ( $search_as_super_admin || $is_public || isset($datatype_permissions[$dt_id]['dt_view']) )
                    $can_view_datatype = true;

                // If the user can view this datatype...
                if ( $can_view_datatype ) {
                    // ...then we need to locate all of its descendants...
                    $ancestor_ids[] = $dt_id;

                    // We're going to eventually need to locate all datatypes which were derived
                    //  from a specific template datatype
                    $all_datatypes[$dt_id] = $template_id;
                }
            }
        }
        // Sort this for easier debugging
//        ksort($all_datatypes);


        // ----------------------------------------
        // Now that there's a list of every single datatype that can claim some relation to the
        //  original template datatype, the next step is to find all datarecords of those datatypes...
        $query =
           'SELECT dr.id AS dr_id, dr.data_type_id AS dt_id, dr.parent_id, drm.public_date
            FROM odr_data_record dr
            LEFT JOIN odr_data_record_meta drm ON drm.data_record_id = dr.id
            WHERE dr.data_type_id IN (?)
            AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL';
        $params = array(1 => array_keys($all_datatypes));
        $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
        $results = $conn->fetchAll($query, $params, $types);

        $all_datarecords = array();
        $possible_linked_descendants = array();
        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $dt_id = $result['dt_id'];
            $parent_id = intval($result['parent_id']);
            $public_date = $result['public_date'];

            // In order to properly set up $flattened_list later, we need to keep check whether the
            //  user is allowed to view every single datarecord
            $is_public = true;
            if ( $public_date === "2200-01-01 00:00:00" )
                $is_public = false;

            if ( !isset($all_datarecords[$dt_id]) )
                $all_datarecords[$dt_id] = array();
            $all_datarecords[$dt_id][$dr_id] = array(
                'parent' => $parent_id,
                'public' => $is_public
            );

            // Going to need to check whether these datarecords are linked to later on
            $possible_linked_descendants[$dr_id] = 1;
        }

        // In order for mergeSearchResults() to work correctly later on, we also need to determine
        //  whether any of these records are linked to from somewhere else.  In theory, ODR doesn't
        //  allow linking to child records...which makes this a lot easier
        $query =
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
            FROM odr_data_record descendant
            LEFT JOIN odr_linked_data_tree ldt ON ldt.descendant_id = descendant.id
            LEFT JOIN odr_data_record ancestor ON ldt.ancestor_id = ancestor.id
            WHERE descendant.id IN (?)
            AND descendant.deletedAt IS NULL AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL';
        $params = array(1 => array_keys($possible_linked_descendants));
        $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
        $results = $conn->fetchAll($query, $params, $types);

        $linked_ancestors = array();
        foreach ($results as $result) {
            $ancestor_id = $result['ancestor_id'];
            $descendant_id = $result['descendant_id'];

            if ( is_null($ancestor_id) )
                continue;

            if ( !isset($linked_ancestors[$descendant_id]) )
                $linked_ancestors[$descendant_id] = array();
            $linked_ancestors[$descendant_id][$ancestor_id] = 1;
        }

        // The final step here is to convert each linked record in $all_datarecords to list which
        //  records link to it, instead of listing its parent record id (which is useless since it's
        //  a top-level record itself)
        foreach ($all_datarecords as $dt_id => $dr_list) {
            foreach ($dr_list as $dr_id => $data) {
                // ...though the record isn't necessarily guaranteed to be linked to
                if ( isset($linked_ancestors[$dr_id]) )
                    $all_datarecords[$dt_id][$dr_id]['parent'] = $linked_ancestors[$dr_id];
            }
        }
        // Sort this list for easier debugging
//        ksort($all_datarecords);


        // ----------------------------------------
        // Now that all the datarecords have been loaded and are pointing to their relevant ancestor,
        //  the flattened_list and inflated_list can get created...
        $flattened_list = array();
        $inflated_list = array();
        foreach ($all_datarecords as $dt_id => $list) {
            // Store whether the user is allowed to view non-public records in this datatype
            $can_view_datarecords = false;
            if ( $search_as_super_admin )
                $can_view_datarecords = true;
            else if ( isset($user_permissions[$dt_id]['dr_view']) )
                $can_view_datarecords = true;

            // Each datarecord in the flattened list needs to start out with one of three values...
            // - CANT_VIEW: this user can't see this datarecord, so it needs to be ignored
            // - MUST_MATCH: this datarecord has a datafield that's part of "advanced" search...
            //               ...it must end up matching the search to be included in the results
            // - DOESNT_MATTER: this datarecord is not part of an "advanced" search, so it will
            //                   have no effect on the final search result
            foreach ($list as $dr_id => $data) {
                if ( !$can_view_datarecords && !$data['public'])
                    $flattened_list[$dr_id] = SearchAPIService::CANT_VIEW;
                else if ( isset($affected_datatypes[ $all_datatypes[$dt_id] ]) )    // Convert this record's datatype into its master template id
                    $flattened_list[$dr_id] = SearchAPIService::MUST_MATCH;
                else
                    $flattened_list[$dr_id] = SearchAPIService::DOESNT_MATTER;
            }

            // Unlike the "regular" searching, $flattened_list for a "template" search will likely
            //  contain at least some (but possibly quite a lot) of records that can't affect the
            //  search results

            // Unlike the regular searching, template searching (currently) never needs the "complete"
            //  list of datarecords that matched the search...and since it's slow to calculate, I'm
            //  more than happy to ignore it for the moment.  Still need $inflated_list to have the
            //  ids of the top-level datarecords though...
            if ( isset($top_level_datatype_ids[$dt_id]) ) {
                // ...so if this is a top-level datatype, build that list now
                $inflated_list[$dt_id] = array();
                foreach ($list as $dr_id => $data)
                    $inflated_list[$dt_id][$dr_id] = 1;
            }
        }
        // Sort the flattened list for easier debugging
//        ksort($flattened_list);


        // In order to building the search_datatree, it'll be more useful for $all_datatypes to be
        //  organized by template id first
        $tmp = array();
        foreach ($all_datatypes as $dt_id => $template_id) {
            if ( !isset($tmp[$template_id]) )
                $tmp[$template_id] = array();
            $tmp[$template_id][$dt_id] = 1;
        }
        $all_datatypes = $tmp;

        $search_datatree = self::buildTemplateSearchDatatree($search_datatree, $all_datatypes, $all_datarecords, false);


        // ----------------------------------------
        // Actually inflate the "inflated" list...
//        $inflated_list = self::buildDatarecordTree($inflated_list, 0);

        // ...and then return the end result
        return array(
            'flattened' => $flattened_list,
            'inflated' => $inflated_list,

            'search_datatree' => $search_datatree,
        );
    }


    /**
     * Recursively builds an array of the following form for self::mergeSearchResults() to use for
     * a template search:
     * <pre>
     * <datatype_id> => array(
     *     'dr_list' => <datarecord_list>,
     *     'children' => array(...),
     *     'links' => array(...),
     * )
     * </pre>
     * ...where the array structure is recursively repeated inside 'children' and 'links', depending
     * on whether the descendant is a child or a linked datatype.
     *
     * This function requires that self::buildSearchDatatree() is run on the template datatype first,
     * so it can modify that result to "pretend" that the template datatypes "own" all records from
     * all datatypes derived from their relevant templates. See {@link mergeSearchResults()}
     *
     * @param array $search_datatree {@link buildSearchDatatree()}
     * @param array $all_datatypes
     * @param array $all_datarecords
     * @param bool $is_link
     * @return array
     */
    private function buildTemplateSearchDatatree($search_datatree, $all_datatypes, $all_datarecords, $is_link)
    {
        $tmp = array();

        foreach ($search_datatree as $template_dt_id => $data) {
            $tmp[$template_dt_id] = array(
                'dr_list' => array(),
                'children' => array(),
                'links' => array(),
            );

            // In order to make the search_datatree useful for a template search, we need to first
            //  find all datatypes that are derived from this template...
            foreach ($all_datatypes[$template_dt_id] as $dt_id => $num) {
                // ...so we can then locate all datarecords of those datatypes...
                if ( !isset($all_datarecords[$dt_id]) )
                    continue;

                foreach ($all_datarecords[$dt_id] as $dr_id => $dr_data) {
                    // ...in order to copy each datarecord into the 'dr_list' of the search_datatree
                    //  for this datatype
                    if ( !$is_link ) {
                        // If this is a child (or top-level) datatype, then each record id should
                        //  point to the id of its parent record
                        $tmp[$template_dt_id]['dr_list'][$dr_id] = $dr_data['parent'];

                        // Because self::getTemplateSearchArrays() only attempts to find linked
                        //  information for descendants, a top-level datatype won't ever be in the
                        //  situation where $dr_data['parent'] points to an array.
                        // Additionally, since ODR doesn't allow linking to child records, this
                        //  won't ever accidentally overwrite their parent data either
                    }
                    else {
                        // If this is a linked datatype, then the parent of this record needs to be
                        //  an array to be included in the datarecord list...if the parent is still
                        //  an integer, then that means that nothing linked to this record, and
                        //  therefore it has no ancestor to affect
                        if ( is_array($dr_data['parent']) )
                            $tmp[$template_dt_id]['dr_list'][$dr_id] = $dr_data['parent'];
                    }
                }
            }

            // Now that the datarecords have been copied over, we need to do the same for any
            //  child/linked descendants this template datatype has
            if ( !empty($data['children']) ) {
                $child_tree = $data['children'];
                $tmp[$template_dt_id]['children'] = self::buildTemplateSearchDatatree($child_tree, $all_datatypes, $all_datarecords, false);
            }
            if ( !empty($data['links']) ) {
                $links_tree = $data['links'];
                $tmp[$template_dt_id]['links'] = self::buildTemplateSearchDatatree($links_tree, $all_datatypes, $all_datarecords, true);
            }
        }

        return $tmp;
    }


    /**
     * The primary difficulty with searching in ODR is that the datarecords/datatypes are all
     * effectively "in the same bucket"...there's no viable method to get useful table joins when
     * the backend database is content-agnostic, and when there can be an arbitrarily deep tree of
     * child/linked datatypes.  Additionally, MYSQL doesn't really like joining these "all in one"
     * tables together.
     *
     * Therefore, ODR has to explicitly perform much of the logic that a properly defined MYSQL
     * database will end up performing purely as a side effect of having a useful relational schema.
     * This is complicated by ODR requiring a "one size fits all" algorithm that has to work for
     * any database structure...even those MYSQL would need to use a UNION or run multiple queries for.
     *   e.g.  A links to B, B links to C, and A also links to C...which records in A match when
     *   searching on criteria from C?  MYSQL can't do it in one query.
     *
     * Further complicating this is ODR kludging two fundamentally different types of searches
     * together...an "advanced" search only checks a single datafield and can be visualized/combined
     * into a long chain of AND statements...but a "general" search is technically a shorthand for a
     * lot of individual searches that need their results combined using both OR and AND statements,
     * and therefore requires noticeably different logic for it to return the correct results.
     * {@link SearchKeyService::convertSearchKeyToCriteria()} for what a "general" search actually is
     *
     *
     * The majority of the that logic is in this function...using $search_datatree as a guide, the
     * lists of records that matched the actual search terms from $facet_dr_list are used to modify
     * the values in $flattened_list to indicate how each individual record relates to the search.
     *
     * This logic attempts to implement the idea of "child records that match the search might cause
     * their parents to also match the search.  This is the complete opposite of the previous version,
     * which was fixated on the idea of "a parent record will not match if all child records of a
     * childtype don't match"...if you're dying to know how the previous version worked for whatever
     * reason, then you can check out SearchAPIService::mergeSearchArrays() in commit 17df21c.
     *
     * @param array $criteria {@link SearchKeyService::convertSearchKeyToCriteria()}
     * @param bool $is_top_level
     * @param int $datatype_id
     * @param array $search_datatree {@link buildSearchDatatree()}
     * @param array $facet_dr_list
     * @param array $flattened_list {@link getSearchArrays()}
     * @param bool $differentiate_search_types
     *
     * @return array
     */
    private function mergeSearchResults($criteria, $is_top_level, $datatype_id, $search_datatree, $facet_dr_list, &$flattened_list, $differentiate_search_types)
    {
        // The advanced and general searches in ODR need to have their results combined separately
        $facets = array('adv' => array(), 'gen' => array());

        // Need to keep track of which of this datatype's records matched the search, so the parent
        //  datatype (if one exists) can determine whether any of its records match the search
        $matches = array(
            'records' => array('adv' => array(), 'gen' => array()),
            'dependencies' => array($datatype_id => 1),
        );


        // ----------------------------------------
        // It's not necessarily guaranteed that a field from this datatype was searched on...but if
        //  one was, then the resulting list of matching records will need to be combined with any
        //  lists of matching records from descendant datatypes.
        // This block currently resides here because it provides a "short-circuit" opportunity...
        //  if none of the records from this datatype matched the search, then none of their descendants
        //  will either...so there's no point recursing down into them.

        // The records in this datatype only "matter" if the datatype was searched on...
        $own_dr_list = $search_datatree['dr_list'];
        foreach ($own_dr_list as $dr_id => $parent_dr_id) {
            if ( !isset($flattened_list[$dr_id]) || ($flattened_list[$dr_id] & SearchAPIService::CANT_VIEW) ) {
                // User can't view this record, so don't know if the datatype was searched on...
                // Keep looking
            }
            else {
                // User can view this record...
                if ( ($flattened_list[$dr_id] & SearchAPIService::MUST_MATCH) === SearchAPIService::MUST_MATCH ) {
                    // ...and the datatype was searched on...copy the records that matched whatever
                    // search was performed
                    $facets['adv'][$datatype_id] = $facet_dr_list[$datatype_id][0];
                }
                // No need to continue looking
                break;
            }
        }

        // If this datatype was searched on, but none of the records matched...
        if ( isset($facets['adv'][$datatype_id]) && empty($facets['adv'][$datatype_id]) ) {
            // ...then there's no point checking any child/linked datatypes
            return $matches;
        }


        // ----------------------------------------
        // Need to determine which records from any descendant datatypes matched this search, since
        //  they will influence whether records from this datatype match or not.
        $descendants = array();

        foreach ($search_datatree['children'] as $child_dt_id => $child_data) {
            // Determine which records from this child datatype end up matching the search
            $tmp = self::mergeSearchResults($criteria, false, $child_dt_id, $child_data, $facet_dr_list, $flattened_list, $differentiate_search_types);

            // Store that this datatype's results depend on all of its descendants through this route
            if ( !isset($descendants[$child_dt_id]) )
                $descendants[$child_dt_id] = array();
            foreach ($tmp['dependencies'] as $descendant_dt_id => $num) {
                // Need to keep track of which datatypes could've influenced this child's result set
                $descendants[$child_dt_id][$descendant_dt_id] = 1;
                // ...but can also ensure the recursive part doesn't break here as well
                $matches['dependencies'][$descendant_dt_id] = 1;
            }

            // The recursive call returns two lists of descendant datarecords that matched...these
            //  descendants need to be converted into lists of records of this datatype
            foreach ($tmp['records']['adv'] as $descendant_dr_id => $parent_dr_id) {
                // Since this is a child datatype, there can only be one parent record
                if ( isset($own_dr_list[$parent_dr_id]) )
                    $facets['adv'][$child_dt_id][$parent_dr_id] = 1;
            }
            foreach ($tmp['records']['gen'] as $facet_num => $dr_list) {
                foreach ($dr_list as $descendant_dr_id => $parent_dr_id) {
                    // Since this is a child datatype, there can only be one parent record
                    if ( isset($own_dr_list[$parent_dr_id]) )
                        $facets['gen'][$facet_num][$parent_dr_id] = 1;
                }
            }

            // If the child datatype was searched on, but had no results...then create an empty array
            //  in $facets, otherwise the rest of the function will think the descendant wasn't searched on
            //  i.e. DOESN'T_MATTER
            if ( empty($tmp['records']['adv']) && !empty($criteria[$child_dt_id]) )
                $facets['adv'][$child_dt_id] = array();
            if ( empty($tmp['records']['gen']) && isset($criteria['general']) )
                $facets['gen'][$child_dt_id] = array();
        }

        foreach ($search_datatree['links'] as $linked_dt_id => $link_data) {
            // Determine which records from this linked datatype end up matching the search
            $tmp = self::mergeSearchResults($criteria, false, $linked_dt_id, $link_data, $facet_dr_list, $flattened_list, $differentiate_search_types);

            // Store that this datatype's results depend on all of its descendants through this route
            if ( !isset($descendants[$linked_dt_id]) )
                $descendants[$linked_dt_id] = array();
            foreach ($tmp['dependencies'] as $descendant_dt_id => $num) {
                // Need to keep track of which datatypes could've influenced this child's result set
                $descendants[$linked_dt_id][$descendant_dt_id] = 1;
                // ...but can also ensure the recursive part doesn't break here as well
                $matches['dependencies'][$descendant_dt_id] = 1;
            }

            // The recursive call returns two lists of descendant datarecords that matched...these
            //  descendants need to be converted into lists of records of this datatype
            foreach ($tmp['records']['adv'] as $descendant_dr_id => $parent_dr_ids) {
                // Since this is a linked datatype, there could be multiple parent records...
                foreach ($parent_dr_ids as $parent_dr_id => $str) {
                    // ...only interested in the records that belong to this datatype
                    if ( isset($own_dr_list[$parent_dr_id]) )
                        $facets['adv'][$linked_dt_id][$parent_dr_id] = 1;
                }
            }
            foreach ($tmp['records']['gen'] as $facet_num => $dr_list) {
                foreach ($dr_list as $dr_id => $parent_dr_ids) {
                    // Since this is a linked datatype, there could be multiple parent records...
                    foreach ($parent_dr_ids as $parent_dr_id => $str) {
                        // ...only interested in the records that belong to this datatype
                        if ( isset($own_dr_list[$parent_dr_id]) )
                            $facets['gen'][$facet_num][$parent_dr_id] = 1;
                    }
                }
            }

            // If the linked datatype was searched on, but had no results...then create an empty array
            //  in $facets, otherwise the rest of the function will think the descendant wasn't searched on
            //  i.e. DOESN'T_MATTER
            if ( empty($tmp['records']['adv']) && !empty($criteria[$linked_dt_id]) )
                $facets['adv'][$linked_dt_id] = array();
            if ( empty($tmp['records']['gen']) && isset($criteria['general']) )
                $facets['gen'][$linked_dt_id] = array();
        }


        // ----------------------------------------
        // There are two situations under which merging by OR needs to happen...

        // The more common instance is when a search term inside a descendant datatype could
        //  match the empty string...in this case, we also want records from the ancestor datatype
        //  that DO NOT have descendants to match the query
        $merge_ancestors_without_descendants = null;
        foreach ($descendants as $descendant_dt_id => $data) {
            if ( isset($criteria[$descendant_dt_id]) ) {
                foreach ($criteria[$descendant_dt_id] as $facet_num => $facet) {
                    foreach ($facet['search_terms'] as $df_id => $search_term) {
                        // If the 'guard' key exists in the array, then this search_term could've
                        //  matched the empty string...
                        if ( isset($search_term['guard']) ) {
                            // ...this currently can only happen as a result of a search on a
                            //  text/number field, or a file/image filename search
                            $merge_ancestors_without_descendants = $descendant_dt_id;
                            break 3;
                        }
                    }
                }
            }
        }

        if ( !is_null($merge_ancestors_without_descendants) ) {
            $relevant_descendant_dt_id = $merge_ancestors_without_descendants;

            // Going to be faster to determine which datarecords don't have descendants by
            //  digging through the $search_datatree array...
            $relevant_descendant_dr_list = null;
            if ( isset($search_datatree['children'][$relevant_descendant_dt_id]) )
                $relevant_descendant_dr_list = $search_datatree['children'][$relevant_descendant_dt_id]['dr_list'];
            else
                $relevant_descendant_dr_list = $search_datatree['links'][$relevant_descendant_dt_id]['dr_list'];

            // Copy the current list of ancestor datarecords...
            $current_ancestor_dr_list = $search_datatree['dr_list'];
            foreach ($relevant_descendant_dr_list as $descendant_dr_id => $ancestors) {
                if ( is_array($ancestors) ) {
                    // The descendant is a linked datatype, and therefore could have multiple ancestors
                    foreach ($ancestors as $ancestor_dr_id => $str) {
                        // ...then get rid of all entries that are ancestors of these descendant records
                        if ( isset($current_ancestor_dr_list[$ancestor_dr_id]) )
                            unset( $current_ancestor_dr_list[$ancestor_dr_id] );
                    }
                }
                else {
                    // The descendant is a child datatype, and therefore only has a single ancestor
                    if ( isset($current_ancestor_dr_list[$ancestors]) )
                        unset( $current_ancestor_dr_list[$ancestors] );
                }
            }

            // Each of the ancestors without descendants then need to be inserted into $facets...
            $ancestors_without_descendants = $current_ancestor_dr_list;
            foreach ($ancestors_without_descendants as $ancestor_dr_id => $ancestors_of_ancestor_dr) {
                // ...insert the ancestor records as if they directly matched the results of the
                //  descendant datatype...after the do/while loop, there's no guarantee of a relation
                //  between the datatype and datarecord ids in the array anyways
                $facets['adv'][$relevant_descendant_dt_id][$ancestor_dr_id] = 1;
            }
        }


        // ----------------------------------------
        // $facets['adv'] now contains two types of lists of records belonging that match the search
        //  terms provided by the user...
        do {
            // If this datatype has no descendants, then it never needs to merge_by_OR
            $merge_by_OR = false;
            if ( empty($descendants) )
                break;


            // The only time a datatype might need to merge search results by OR is when there are
            //  "multiple paths" to reach the same descendant datatype...
            // e.g. B is a descendant of A, and B links to D, and A also links to D
            // e.g. B and C are both descendants of A, and both B and C link to D
            // In both cases, D can be reached in more than one way, so D's ancestors may need to
            //  have their search results merged by OR (A/B in the first case, and B/C in the second)
            // TODO - Does this logic work for more complicated setups?  I'm struggling to think of something that makes sense
            // TODO - this current logic only really works because you can "get away with" not wanting the linked descendants to have separate searches...
            $descendants_counts = array();
            foreach ($descendants as $descendant_dt_id => $data) {
                // The fastest way to determine if this situation arises is to sum how many times
                //  each datatype appears inside the (local) $descendants array
                foreach ($data as $dt_id => $num) {
                    if ( !isset($descendants_counts[$dt_id]) )
                        $descendants_counts[$dt_id] = 0;
                    $descendants_counts[$dt_id]++;
                }
            }
            // Sort the resulting array so that datatypes that occur more than once are easy to find
            arsort($descendants_counts);

            // Datatypes that do occur more than once still aren't guaranteed to require results to
            //  be merged by OR...
            $descendant_dt_id = null;
            foreach ($descendants_counts as $descendant_dt_id => $count) {
                if ( $count > 1 ) {
                    // ...only do that if they were actually searched on
                    if ( !empty($facet_dr_list[$descendant_dt_id]) ) {
                        $merge_by_OR = true;
                        break;
                    }
                }
            }


            // ----------------------------------------
            if ( $merge_by_OR ) {
                // If merging by OR is required, then all the current datarecord lists and descendant
                //  data should get merged into a single logical facet
                $first_dt_id = null;

                foreach ($descendants as $dt_id => $data) {
                    if ( isset($data[$descendant_dt_id]) ) {
                        // This particular subset of child/linked descendants depends on a datatype
                        //  that is linked to more than once...this data need to be merged by OR, so
                        //  it all needs to be moved into a facet labeled by $first_dt_id
                        if ( is_null($first_dt_id) )
                            $first_dt_id = $dt_id;

                        if ( $first_dt_id === $dt_id ) {
                            // This facet will be where all relevant data gets combined into

                            // Don't need to copy facet data over, but should ensure a facet actually
                            //  exists to make the rest of the loop easier
                            if ( !isset($facets['adv'][$first_dt_id]) )
                                $facets['adv'][$first_dt_id] = array();

                            // Don't need to copy descendant data over, since this is the final
                            //  destination for that data anyways
                        }
                        else {
                            // Need to copy all relevant data from $dt_id into the facet labeled by
                            //  $first_dt_id
                            if ( isset($facets['adv'][$dt_id]) ) {
                                foreach ($facets['adv'][$dt_id] as $dr_id => $num)
                                    $facets['adv'][$first_dt_id][$dr_id] = 1;
                            }
                            foreach ($descendants[$dt_id] as $dependent_dt_id => $num)
                                $descendants[$first_dt_id][$dependent_dt_id] = 1;

                            // No longer need the facet or the descendant data for $dt_id, since it's
                            //  all stored under $first_dt_id now
                            unset( $facets['adv'][$dt_id] );
                            unset( $descendants[$dt_id] );
                        }
                    }
                }
            }
        } while ($merge_by_OR);


        // IMPORTANT: at this point, the $dt_id in the $facets array is no longer guaranteed to mean
        //  "this datatype matches these datarecords".  Since $facets is a local modification of
        //  $facet_dr_list and only has one further use in this function, this isn't a huge deal.


        // For an advanced search, these lists need to be merged by AND to generate the list of
        //  records from this datatype that end up matching all search terms for this datatype and
        //  its descendants.
        $final_dr_list = null;
        foreach ($facets['adv'] as $dt_id => $dr_list) {
            // Facets from advanced search are merged together by AND
            if ( is_null($final_dr_list) )
                $final_dr_list = $dr_list;
            else
                $final_dr_list = array_intersect_key($final_dr_list, $dr_list);
        }

        // The search isn't guaranteed to have had advanced search terms defined that affected this
        //  datatype or its descendants...
        if ( !is_null($final_dr_list) ) {
            // ...but if any such search terms were defined, then $flattened_list needs to be updated
            //  to indicate which records from this datatype "end up" matching the search

            foreach ($final_dr_list as $dr_id => $num) {
                // If the user is allowed to view this datarecord...
                if ( isset($flattened_list[$dr_id]) && !($flattened_list[$dr_id] & SearchAPIService::CANT_VIEW) ) {
                    // ...then mark it as matching the search
                    if ( $differentiate_search_types )
                        $flattened_list[$dr_id] |= SearchAPIService::MATCHES_ADV;
                    else
                        $flattened_list[$dr_id] |= SearchAPIService::MATCHES_BOTH;

                    // Since this record matched, it needs to be relayed to its parent datatype to
                    //  determine whether any of its parent records "end up" matching the search
                    if ( isset($own_dr_list[$dr_id]) )
                        $matches['records']['adv'][$dr_id] = $own_dr_list[$dr_id];
                }
            }
        }


        // ----------------------------------------
        // Determining which records match a general search is quite different than the same process
        //  for an advanced search, unfortunately.
        if ( isset($facet_dr_list['general']) ) {
            // Combine the records from this datatype that match the general search with the records
            //  that match because their descendants match the the general search...the quickest way
            //  to do this is to overwrite the local copy of $facet_dr_list, effectively causing a
            //  merge by OR, whereas advanced search merges by AND.
            foreach ($facets['gen'] as $facet_num => $dr_list) {
                foreach ($dr_list as $dr_id => $num) {
                    // Note that the facets themselves aren't merged together...this is done so that
                    //  a general search with multiple facets (e.g. "downs quartz") doesn't necessarily
                    //  require a record to have both "downs" and "quartz" in it at the same time...

                    // A top-level record should match that example search even when descendant A
                    //  only matches "downs", and descendant B only matches "quartz".  Obviously,
                    //  the behavior should be the same when A == B...and if a dataype doesn't have
                    //  descendants, then it obviously should still have to match both "downs" and
                    //  "quartz" itself...
                    $facet_dr_list['general'][$facet_num][$dr_id] = 1;
                }
            }

            if ( $is_top_level ) {
                // If this combination is taking place at the top-level datatype, then the facets
                //  do need to be merged together by AND at this point...a top-level record has no
                //  parents, so it needs to match all the facets of general search to be included
                $final_dr_list = $own_dr_list;
                foreach ($facet_dr_list['general'] as $facet_num => $dr_list)
                    $final_dr_list = array_intersect_key($final_dr_list, $dr_list);

                foreach ($final_dr_list as $dr_id => $num) {
                    // If the user is allowed to view this record...
                    if ( isset($flattened_list[$dr_id]) && !($flattened_list[$dr_id] & SearchAPIService::CANT_VIEW) ) {
                        // ...then mark it as matching the general search
                        if ( $differentiate_search_types )
                            $flattened_list[$dr_id] |= SearchAPIService::MATCHES_GEN;
                        else
                            $flattened_list[$dr_id] |= SearchAPIService::MATCHES_BOTH;

                        // No point storing/returning information for a parent datatype to use, since
                        //  this is already a top-level datatype
                    }
                }
            }
            else {
                // If this combination is not taking place at the top-level datatype, then the facets
                //  can't be combined yet...need to determine which set of records from this datatype
                //  match each facet of the general search, and then continue recursively "bubbling"
                foreach ($facet_dr_list['general'] as $facet_num => $dr_list) {
                    foreach ($dr_list as $dr_id => $num) {
                        // If this record in this facet belongs to the current datatype...
                        if ( isset($own_dr_list[$dr_id]) ) {
                            // ...and the user is allowed to view it...
                            if ( isset($flattened_list[$dr_id]) && !($flattened_list[$dr_id] & SearchAPIService::CANT_VIEW) ) {
                                // ...then mark it as matching the general search
                                if ( $differentiate_search_types )
                                    $flattened_list[$dr_id] |= SearchAPIService::MATCHES_GEN;
                                else
                                    $flattened_list[$dr_id] |= SearchAPIService::MATCHES_BOTH;

                                // Since this record matched, it needs to be relayed to its parent
                                //  datatype to determine whether any of its parent records "end up"
                                //  matching the search
                                $matches['records']['gen'][$facet_num][$dr_id] = $own_dr_list[$dr_id];
                            }
                        }
                    }
                }
            }
        }

        return $matches;
    }


    /**
     * Recursively cross-references the tree structure of $inflated_list with the values stored in
     * $flattened_list to determine all datarecords (top-level and descendants) that ended up
     * matching the search.
     *
     * The recursion looks a little strange in order to avoid recursing into an empty child array.
     *
     * @param array $flattened_list {@link getSearchArrays()}
     * @param array $inflated_list
     *
     * @return array
     */
    private function getMatchingDatarecords($flattened_list, $inflated_list)
    {
        $matching_datarecords = array();
        foreach ($inflated_list as $top_level_dt_id => $top_level_datarecords) {
            foreach ($top_level_datarecords as $dr_id => $child_dt_list) {
                // If this top-level datarecord "ended up" "matching the search", then it'll have a
                //  value of (0b0-11) in $flattened_list...self::mergeSearchResults() is responsible
                //  for that
                if ( ($flattened_list[$dr_id] & SearchAPIService::MATCHES_BOTH) === SearchAPIService::MATCHES_BOTH ) {
                    $matching_datarecords[$dr_id] = 1;

                    if ( is_array($child_dt_list) ) {
                        // If the top-level datarecord has any descendant datatypes, then they need
                        //  to be also checked to find which of the descendant datarecords matched
                        //  the search
                        $matching_children = self::getMatchingDatarecords_worker($flattened_list, $child_dt_list);
                        foreach ($matching_children as $child_dr_id => $num)
                            $matching_datarecords[$child_dr_id] = 1;
                    }
                }
            }
        }

        return $matching_datarecords;
    }


    /**
     * Recursively cross-references the tree structure of $inflated_list with the values stored in
     * $flattened_list to determine all datarecords (top-level and descendants) that ended up
     * matching the search.
     *
     * The recursion looks a little strange in order to avoid recursing into an empty child array.
     *
     * @param array $flattened_list {@link getSearchArrays()}
     * @param array $dt_list
     *
     * @return array
     */
    private function getMatchingDatarecords_worker($flattened_list, $dt_list)
    {
        $matching_datarecords = array();
        foreach ($dt_list as $dt_id => $dr_list) {
            foreach ($dr_list as $dr_id => $child_dt_list) {
                // The criteria for a child record to "match the search" is slightly different than
                //  that of a top-level record...primarily, whether the record matched the general
                //  search isn't actually relevant.  Since the top-level record does, any descendant
                //  record is assumed to match as long as it doesn't have a reason to be excluded.

                // The two reasons to exclude a descendant are if the user can't see it (0b1---),
                //  but that has effectively already been enforced by earlier logic
                // The other reason is the descendant doesn't match the advanced search (0b-10-)

                // Therefore, as long as the third bit is a 1 or the second bit is 0, this record
                //  ends up maching the search
                if ( $flattened_list[$dr_id] & SearchAPIService::MATCHES_ADV   // third bit set
                    || !($flattened_list[$dr_id] & SearchAPIService::MUST_MATCH)    // second bit not set
                ) {
                    $matching_datarecords[$dr_id] = 1;

                    if ( is_array($child_dt_list) ) {
                        // Continue checking all descendants of this datatype as well
                        $matching_children = self::getMatchingDatarecords_worker($flattened_list, $child_dt_list);
                        foreach ($matching_children as $child_dr_id => $num)
                            $matching_datarecords[$child_dr_id] = 1;
                    }
                }
            }
        }

        return $matching_datarecords;
    }
}
