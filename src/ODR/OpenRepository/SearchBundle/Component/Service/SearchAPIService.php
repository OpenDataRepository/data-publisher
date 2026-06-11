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
     * @var string
     */
    private $search_key_char_limit;

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
     * @param string $search_key_char_limit
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
        string $search_key_char_limit,
        ContainerInterface $container,
        EntityManager $entity_manager,
        DatatreeInfoService $datatree_info_service,
        SearchService $search_service,
        SearchKeyService $search_key_service,
        SortService $sort_service,
        CacheService $cache_service,
        Logger $logger
    ) {
        $this->search_key_char_limit = $search_key_char_limit;
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
     *
     * @return array
     */
    public function getSearchableDatafieldsForUser($top_level_datatype_ids, $user_permissions, $search_as_super_admin = false)
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
            $searchable_datafields = $this->search_service->getSearchableDatafields($top_level_datatype_id);
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
     * @deprecated
     * This function fulfills a purpose similar to {@link self::getSearchableDatafieldsForUser()}...
     * both a "regular" search and a "template" search need to know which datafields the user is allowed
     * to view...but a "template" search may easily involve hundreds of datatypes that are derived
     * from the template being searched on.
     *
     * As such, the strategy used by {@link self::getSearchableDatafieldsForUser()} of loading info
     * for one datatype at a time is unviable...this function instead loads the relevant data for
     * every single relevant derived datatypes/datafields at once.  Caching this data is unfeasible,
     * unfortunately, which is why it only gets used for template searches.
     *
     * Additionally, the array returned by {@link self::getSearchableDatafieldsForUser()} contains each
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
     * Returns a search key that has been filtered to only contain what the user can see.
     *
     * ODR originally forcibly redirected the user to this filtered search key if it was different
     * than what they originally attempted to access, but that redirect was screwing up Wordpress.
     * So instead, everywhere in ODR that uses a search key has to filter it first...
     *
     * @param int $datatype_id Testing is easier if this is an int
     * @param string $search_key
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in
     * @param bool $search_as_super_admin If true, don't filter anything by permissions
     * @param bool $ignore_searchable If true, then don't filter out fields that aren't searchable.
     *                                Required for InlineLink.
     *
     * @return string
     */
    public function filterSearchKeyForUser($datatype_id, $search_key, $user_permissions, $search_as_super_admin = false, $ignore_searchable = false)
    {
        // Convert the search key into array format...
        $search_params = $this->search_key_service->decodeSearchKey($search_key);
        $filtered_search_params = array();

        // Extract the inverse target datatype, if it exists
        $inverse_target_datatype_id = null;
        if ( isset($search_params['inverse']) ) {
            $inverse_target_datatype_id = intval($search_params['inverse']);

            // values less than 1 disable this feature
            if ( $inverse_target_datatype_id < 1 ) {
                unset( $search_params['inverse'] );
                $inverse_target_datatype_id = null;
            }
        }

        // Get all the datatypes/datafields the user can view...which datatype to use depends on
        //  whether it's an "inverse" search or not
        $searchable_datafields = array();
        if ( is_null($inverse_target_datatype_id) )
            $searchable_datafields = self::getSearchableDatafieldsForUser(array($datatype_id), $user_permissions, $search_as_super_admin);
        else
            $searchable_datafields = self::getSearchableDatafieldsForUser(array($inverse_target_datatype_id), $user_permissions, $search_as_super_admin);

        // Prior to inline searching, $searchable_datafields only had datafields that the user could
        //  view and weren't marked as DataFields::NOT_SEARCHABLE...but because of inline search's
        //  requirements, it now contains every single datafield related to this datatype that the
        //  user is allowed to view

        $removed_criteria = false;
        foreach ($search_params as $key => $value) {
            if ( $key === 'dt_id' || $key === 'gen' || $key === 'gen_lim'
                || $key === 'inverse' || $key === 'ignore' || $key === 'merge' || $key === 'set'
            ) {
                // Don't need to filter/change these values
                $filtered_search_params[$key] = $value;
            }
            else if ( $key === 'sort_by' ) {
                // Don't want to allow the user to sort by fields that are non-public
                foreach ($value as $sort_num => $sort_criteria) {
                    $sort_df_id = $sort_criteria['sort_df_id'];
                    foreach ($searchable_datafields as $dt_id => $datafields) {
                        if ( isset($datafields[$sort_df_id]) &&
                            ($ignore_searchable || $datafields[$sort_df_id]['searchable'] !== DataFields::NOT_SEARCHABLE)
                        ) {
                            // User can both view and search this datafield
                            $filtered_search_params[$key][$sort_num] = array(
                                'sort_df_id' => $sort_criteria['sort_df_id'],
                                'sort_dir' => $sort_criteria['sort_dir'],
                            );
                            break;
                        }
                        else {
                            // User can't view/search this datafield
                            $removed_criteria = true;
                        }
                    }
                }
            }
            else if ( is_numeric($key) ) {
                // Most of the fieldtypes provide search data like this...
                $df_id = intval($key);

                // Determine if the user can view the datafield...
                foreach ($searchable_datafields as $dt_id => $datafields) {
                    if ( isset($datafields[$df_id]) &&
                        ($ignore_searchable || $datafields[$df_id]['searchable'] !== DataFields::NOT_SEARCHABLE)
                    ) {
                        // User can both view and search this datafield
                        $filtered_search_params[$key] = $value;
                        break;
                    }
                    else {
                        // User can't view/search this datafield
                        $removed_criteria = true;
                    }
                }
            }
            else {
                $pieces = explode('_', $key);

                if ( is_numeric($pieces[0]) && count($pieces) === 2 ) {
                    // This is a DatetimeValue or the public_status/quality of a File/Image field...
                    $df_id = intval($pieces[0]);
                    $df_dt_id = null;

                    $is_valid_field = false;
                    foreach ($searchable_datafields as $dt_id => $df_list) {
                        if ( isset($df_list[$df_id]) &&
                            ($ignore_searchable || $df_list[$df_id]['searchable'] !== DataFields::NOT_SEARCHABLE)
                        ) {
                            // User can both view and search this datafield...
                            $df_dt_id = $dt_id;
                            $is_valid_field = true;
                            break;
                        }
                    }

                    // Searching on public status of files/images needs an additional check...
                    if ( $is_valid_field && $pieces[1] === 'pub' ) {
                        if ( !isset($user_permissions['datatypes'][$df_dt_id]['dr_view'])
                            || !isset($user_permissions['datafields'][$df_id]['view'])
                        ) {
                            // ...because a user without the ability to see non-public files should
                            //  not be able to search on this criteria
                            $is_valid_field = false;
                        }
                    }
                    if ( $search_as_super_admin )
                        $is_valid_field = true;

                    if ( $is_valid_field ) {
                        // User can view/search this datafield
                        $filtered_search_params[$key] = $value;
                    }
                    else {
                        // User can't view/search this datafield
                        $removed_criteria = true;

                        // If they're searching for public files...
                        if ( $value == '1' ) {
                            if ( !isset($search_params[$df_id]) ) {
                                // ...and also not searching for a filename, then change the search
                                //  key to search for records with files
                                $filtered_search_params[$df_id] = '!""';
                            }
                        }

                        // An attempt to search for non-public files should be completely ignored
                    }

                    // Don't need additional checks for Datetime of XYZData fields
                }
                else {
                    // $key is one of the modified/created/modifiedBy/createdBy/publicStatus entries
                    $dt_id = intval($pieces[1]);

                    if ( $search_as_super_admin ) {
                        // Super admins can always search on datatypes
                        $filtered_search_params[$key] = $value;
                    }
                    else if ( isset($user_permissions['datatypes'][$dt_id]) ) {
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
                            else {
                                // User can't view/search this datatype
                                $removed_criteria = true;
                            }
                        }
                    }
                    else {
                        // User can't view/search this datatype
                        $removed_criteria = true;  // TODO - add test for this if possible
                    }
                }
            }
        }

        // Determine whether the user needs to get a new search key...
        if ( $removed_criteria ) {
            // If something got filtered out, then need to go through the trouble of creating
            //  another search key for them to use
            $filtered_search_key = $this->search_key_service->encodeSearchKey($filtered_search_params);

            // Return the modified search key
            return $filtered_search_key;
        }

        // Otherwise, no criteria got removed...return the originally provided search key
        return $search_key;
    }


    /**
     * On the surface, building a dependency graph between datatypes in ODR sounds simple...ODR's
     * concepts of "ancestor"/"descendant" datatypes/records create the prerequisite relationships
     * between databases, and even prevents cycles between datatypes that would require a programmer
     * to untangle.
     *
     * The information built by this function is effectively a duplicate of what could be acquired
     * via a traversal from "ancestor" to "descendant" in ODR...but this specific format makes it
     * possible to use {@see self::invertSearchDependencyGraph()} if the search wants to go in a
     * different direction...
     *
     * Surprisingly enough, datatypes that are theoretically related but can't be rendered together
     * actually have a different set of rules underpinning their relationship...
     *
     * The primary return is in the $graph variable:
     * <pre>
     * <ancestor_dt_id> => array(
     *     [<child_dt_id>] => 0,
     *     ...
     *     [<linked_dt_id>] => 1,
     *     ...
     * ),
     * [<ancestor_dt_id>] => array(...)
     * </pre>
     *
     * @param array $datatree_array {@see DatatreeInfoService::getDatatreeArray()}
     * @param array $related_datatypes Generally a flipped version of the deep return from
     *                                 {@see DatatreeInfoService::getAssociatedDatatypes()}, but
     *                                 with datatypes the user can't view already removed from it
     * @param array $ignored_prefixes Instructs the function to ignore specific paths to datatypes
     * @param int $current_datatype_id The datatype to find descendants for
     * @param string $current_prefix This should be an empty string when initially called
     * @param array &$graph This should be an empty array when initially called...the result is
     *                      an array(<ancestor_dt_id> => array(<descendant_dt_ids>,...),...)
     * @param array &$counts This should be an empty array when initially called...the result is
     *                       an array(<dt_id> => <# of paths>,...)
     * @return void
     */
    public function buildSearchDependencyGraph($datatree_array, $related_datatypes, $ignored_prefixes, $current_datatype_id, $current_prefix, &$graph, &$counts)
    {
        // Need to perform a bit of setup on the first entry...
        if ( empty($graph) )
            $counts = array($current_datatype_id => 1);
        if ( $current_prefix === '' )
            $current_prefix = strval($current_datatype_id);

        $datatypes_to_check = array();

        // Check the entire list of childtypes...
        foreach ($datatree_array['descendant_of'] as $descendant_dt_id => $ancestor_dt_id) {
            // ...if this childtype is related to the impending search, and hasn't been encountered
            //  before...
            if ( isset($related_datatypes[$descendant_dt_id])
                && isset($related_datatypes[$ancestor_dt_id])
                && !isset($graph[$ancestor_dt_id][$descendant_dt_id])
                && $current_datatype_id === $ancestor_dt_id
            ) {
                $new_prefix = $current_prefix.'_'.$descendant_dt_id;
                if ( !isset($ignored_prefixes[$new_prefix]) ) {
                    // ...then create an entry in the dependency graph for it
                    $graph[$current_datatype_id][$descendant_dt_id] = 0;

                    // Need to keep track of how many times a childtype is encountered
                    if ( !isset($counts[$descendant_dt_id]) ) {
                        // ...if this is the first time it was encountered, then need to also locate
                        //  its descendants
                        $datatypes_to_check[$descendant_dt_id] = 1;
                        $counts[$descendant_dt_id] = 0;
                    }
                    $counts[$descendant_dt_id]++;
                }
            }
        }

        // Check the entire list of linked types...
        foreach ($datatree_array['linked_from'] as $descendant_dt_id => $ancestor_dt_ids) {
            foreach ($ancestor_dt_ids as $ancestor_dt_id) {
                // ...if this linked descendant is related to the impending search, and hasn't been
                //  encountered before...
                if ( isset($related_datatypes[$descendant_dt_id])
                    && isset($related_datatypes[$ancestor_dt_id])
                    && !isset($graph[$ancestor_dt_id][$descendant_dt_id])
                    && $current_datatype_id === $ancestor_dt_id
                ) {
                    $new_prefix = $current_prefix.'_'.$descendant_dt_id;
                    if ( !isset($ignored_prefixes[$new_prefix]) ) {
                        // ...then create an entry in the dependency graph for it
                        $graph[$current_datatype_id][$descendant_dt_id] = 1;

                        // Need to keep track of how many times a childtype is encountered
                        if ( !isset($counts[$descendant_dt_id]) ) {
                            // ...if this is the first time it was encountered, then need to also
                            //  locate its descendants
                            $datatypes_to_check[$descendant_dt_id] = 1;
                            $counts[$descendant_dt_id] = 0;
                        }
                        $counts[$descendant_dt_id]++;
                    }
                }
            }
        }

        // If there are any child/linked descendants found as a result of this iteration...
        if ( !empty($datatypes_to_check) ) {
            foreach ($datatypes_to_check as $dt_id => $num) {
                // ...then assuming the user doesn't want to "ignore" said descendant...
                $new_prefix = $current_prefix.'_'.$dt_id;
                if ( !isset($ignored_prefixes[$new_prefix]) )
                    // ...continue recursively locating descendants
                    self::buildSearchDependencyGraph($datatree_array, $related_datatypes, $ignored_prefixes, $dt_id, $new_prefix, $graph, $counts);
            }
        }
    }


    /**
     * This function takes the result of {@link self::buildSearchDependencyGraph()}, and then performs
     * a series of relationship inversions so that a linked descendant datatype can instead be
     * considered the top-level datatype. The main trick is to avoid creating duplicates and/or cycles
     * while performing this inversion...those are impossible to untangle without programmer
     * intuition/intervention.
     *
     * Unfortunately, the lurking danger here is that the relationships between ancestors and linked
     * descendants reachable by "multiple paths" can change with this inversion.  If there's the
     * setup {A -> R, A -> AB -> R}, with R being a linked type and AB being a child of A...then that
     * semantically implies {(A with matching AB) AND (A with matching R OR A with any AB with matching R)}.
     * The naive inversion...{R -> A, R -> AB -> A}...semantically implies that R depends on AB, but
     * this is also the opposite of the original setup where AB only could have R.
     *
     * As a side note, original attempt at "inverse" searching was effectively too conservative
     * with its traversal of the graph, and would really only traverse back up to the original
     * top-level datatype...ignoring and/or being completely unaware of any other descendants the
     * original top-level datatype had.
     *
     * NOTE: unlike {@see self::buildSearchDependencyGraph()}, the values of $graph have NO meaning
     *
     * @param array $graph {@see self::buildSearchDependencyGraph()}
     * @param array $original_counts {@see self::buildSearchDependencyGraph()}
     * @param int $current_datatype_id The datatype to rebase $graph from
     * @return array An array to replace the $counts computed by buildSearchDependencyGraph()
     */
    public function invertSearchDependencyGraph(&$graph, $original_counts, $current_datatype_id)
    {
        // ----------------------------------------
        // Easier to do this iteratively because $graph is unstacked
        $datatypes_to_check = array($current_datatype_id => 1);

        while ( !empty($datatypes_to_check) ) {
            $tmp = array();

            // Because $graph is unstacked, we can check every single datatype in it...
            foreach ($datatypes_to_check as $dt_id => $num) {
                // ...with the goal of locating all entries where the datatype to check is a
                //  descendant
                foreach ($graph as $ancestor_dt_id => $descendant_dt_ids) {
                    // Verify that there is an ancestor -> descendant connection, and that it wasn't
                    //  created by this function
                    if ( isset($graph[$ancestor_dt_id][$dt_id]) && $graph[$ancestor_dt_id][$dt_id] < 2 ) {
                        // Create a new entry in the graph for this reversed descendant -> ancestor
                        //  connection
                        if ( !isset($graph[$dt_id]) )
                            $graph[$dt_id] = array();
                        // Set the value to 1 to prevent this function from thinking it needs to
                        //  continue "following the path" from it
                        $graph[$dt_id][$ancestor_dt_id] = 2;

                        // No longer want or need the ancestor -> descendant connection
                        unset( $graph[$ancestor_dt_id][$dt_id] );
                        if ( empty($graph[$ancestor_dt_id]) )
                            unset( $graph[$ancestor_dt_id] );

                        // Check whether the this (currently ancestor) datatype was a descendant
                        //  of anything else...and therefore, whether those connections need inverting
                        $tmp[$ancestor_dt_id] = 1;
                    }
                }
            }

            // Reset for the next loop
            $datatypes_to_check = $tmp;
        }

        // The $counts array that got filled out when creating the original dependency graph is no
        //  longer valid
        $counts = array($current_datatype_id => 1);
        foreach ($graph as $ancestor_dt_id => $descendant_dt_ids) {
            if ( empty($descendant_dt_ids) ) {
                unset( $graph[$ancestor_dt_id] );
            }
            else {
                foreach ($descendant_dt_ids as $dt_id => $num) {
                    // Just need to count how many times each "descendant" appears in the new graph
                    if ( !isset($counts[$dt_id]) )
                        $counts[$dt_id] = 0;
                    $counts[$dt_id]++;
                }
            }
        }

        return $counts;
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
     * It's easier for {@link self::performSearch()} when {@link self::getSearchArrays()} returns
     * arrays that already contain the user's permissions and which datatypes are being searched on...
     * this utility function gathers that required info in a single spot.
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
     * ODR can end up in situations with complicated database structures that are rather nighmarish
     * to search...to use a real-world examples:
     * <pre>
     * AMCSD (or RRUFF Samples)
     * |- RRUFF References (link)
     * |- IMA Mineral List (link)
     *     |- RRUFF References (link)
     *     |- Status Notes (child)
     *         |- RRUFF References (link)
     *      |- Reference A (child)
     *          |- RRUFF References (link)
     *      |- Reference B (child)
     *          |- RRUFF References (link)
     * </pre>
     *
     * In the case of RRUFF Samples, the user typically doesn't care which RRUFF Reference link
     * matches...but in the case of AMCSD, they very much do care, and usually don't want to involve
     * any descendants of the IMA Mineral List.  Until they do want to run that search.  =')
     *
     * Fortunately, because of the rules ODR follows when permitting links, each of them can be
     * uniquely identified by a "prefix".  This permits {@link self::getSearchArrays()} to "selectively
     * ignore" parts of the various arrays it would otherwise build...that lack of info ends up
     * causing {@link self::mergeSearchResults()} to behave as if those links don't exist.
     *
     * Due to differences in storing the prefixes and actually using them, it's easier to use a
     * function as an in-between.
     *
     * @param array $search_params
     * @param DataType $datatype
     *
     * @return array
     */
    public function getIgnoredPrefixes($search_params, $datatype)
    {
        $ignored_prefixes = array();

        if ( isset($search_params['ignore']) ) {
            // If this key is set, then use its contents
            $ignored_prefixes = array_flip($search_params['ignore']);
        }
        else {
            // Otherwise, fall back to whatever the default is for this datatype
            $ignored_prefixes = array();    // TODO
        }

        return $ignored_prefixes;
    }


    /**
     * Returns a "flattened" list of all records that could end up matching the search, which is
     * primarily utilized so the rest of the searching doesn't have to deal with recursion when
     * computing search results.  The array has the structure:
     * <pre>
     * array(
     *     <datatype_id> => array(
     *         <datarecord_id> => <num>,
     *         ...
     *     ),
     *     ...
     * )
     * </pre>
     *
     * The number is some combination of bitflags...this function only initializes the array with
     * {@link SearchAPIService::CANT_VIEW} and {@link SearchAPIService::DOESNT_MATTER}
     *
     * If $complete_datarecord_list is provided, then the datarecord ids contained within are set to
     * {@link SearchAPIService::MATCHES_BOTH}.  This is currently used by CSVExportHelperService,
     * because the complete datarecord list returned by {@link SearchAPIService::performSearch()}
     * almost always needs to be broken up as part of forcing it into beanstalk for CSVExport.
     *
     * @param array $permissions_array {@link self::getSearchPermissionsArray()}
     * @param array $complete_datarecord_list Should generally be empty
     *
     * @return array
     */
    public function getFlattenedList($permissions_array, $complete_datarecord_list = array())
    {
        // ----------------------------------------
        // Intentionally not caching the results of this function for two reasons
        // 1) these arrays need to be initialized based on the search being run, and the
        //     permissions of the user running the search
        // 2) these arrays contain ids of datarecords across all datatypes related to the datatype
        //     being searched on...determining when to clear this entry, especially when linked
        //     datatypes are involved, would be nightmarish

        $flattened_list = array();
        foreach ($permissions_array as $dt_id => $permissions) {
            // Ensure that the user is allowed to view this datatype before doing anything with it
            if ( !$permissions['can_view_datatype'] )
                continue;

            // Attempt to load this datatype's datarecords and their parents from the cache...
            $list = $this->search_service->getCachedDatarecordList($dt_id);


            // ----------------------------------------
            // Each datarecord in the flattened list needs to start out with one of three values...
            if ( !isset($flattened_list[$dt_id]) )
                $flattened_list[$dt_id] = array();

            // - CANT_VIEW: this user can't see this datarecord, so it needs to be ignored
            // - MUST_MATCH: this datarecord has a datafield that's part of "advanced" search...
            //               ...it must end up matching the search to be included in the results
            // - DOESNT_MATTER: this datarecord is not part of an "advanced" search, so it will
            //                   have no effect on the final search result
            foreach ($list as $dr_id => $value) {
                if ( isset($permissions['non_public_datarecords'][$dr_id]) )
                    $flattened_list[$dt_id][$dr_id] = SearchAPIService::CANT_VIEW;
//                else if ( $permissions['affected'] === true )
//                    $flattened_list[$dt_id][$dr_id] = SearchAPIService::MUST_MATCH;  // NOTE: can't fill this in here
                else if ( !empty($complete_datarecord_list) ) {
                    // NOTE: used by/for CSVExport
                    if ( isset($complete_datarecord_list[$dr_id]) )
                        $flattened_list[$dt_id][$dr_id] = SearchAPIService::MATCHES_BOTH;
                    else
                        $flattened_list[$dt_id][$dr_id] = SearchAPIService::MUST_MATCH;
                }
                else
                    $flattened_list[$dt_id][$dr_id] = SearchAPIService::DOESNT_MATTER;  // TODO - rename?
            }
        }

        // ----------------------------------------
//        // Sort the flattened list for easier debugging
//        foreach ($flattened_list as $dt_id => $dr_list) {
//            $tmp = $dr_list;
//            ksort($tmp);
//            $flattened_list[$dt_id] = $tmp;
//        }

        // ...and then return the end result
        return $flattened_list;
    }


    /**
     * Runs a search specified by the given $search_key.  The contents of the search key are
     * silently tweaked based on the user's permissions.
     *
     * @param DataType|null $datatype Preferably not null, but can parse $search_key if it is.
     * @param string $search_key
     * @param array $user_permissions The permissions of the user doing the search, or an empty
     *                                array when not logged in.
     * @param bool $return_complete_list If false, then returns a sorted list of grandparent
     *                                   datarecord ids...if true, then returns an unsorted list of
     *                                   the grandparent datarecords and all their descendents that
     *                                   match the search.
     * @param int[] $sort_datafields An ordered list of the datafields to sort by, or an empty
     *                               array to sort by whatever is default for the datatype.
     * @param string[] $sort_directions An ordered list of which datafields/directions to sort the
     *                                  results set with.
     *                                  IMPORTANT: the sort directives in the search key are ignored,
     *                                  because otherwise the search key would override any temporary
     *                                  sorting the user wants to perform.
     * @param bool $search_as_super_admin If true, don't filter anything by permissions.
     * @param bool $ignore_searchable If true, then don't filter out fields that aren't searchable.
     *                                Required for InlineLink.
     * @param bool $return_as_list If true, then returns a list of records with internal id and
     *                             unique id instead.
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
        $ignore_searchable = false,
        $return_as_list = false
    ) {

        // ----------------------------------------
        // This typically shouldn't be null, but phpunit testing is unable to provide a hydrated
        //  datatype entity
        if ( is_null($datatype) ) {
            $search_params = $this->search_key_service->decodeSearchKey($search_key);
            if ( !isset($search_params['dt_id']) )
                throw new ODRBadRequestException('SearchAPIService::performSearch() completely unable to find datatype');
            $datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find( $search_params['dt_id'] );
        }

        // Not going to check whether it's a child datatype...ODR would prefer it's a top-level
        //  datatype, but the search system doesn't really care one way or another


        // ----------------------------------------
        // Originally...ODR took the search keys it received, checked whether it contained anything
        //  the user couldn't see, and if so it then forcibly redirected to URL that had the filtered
        //  version of said search key
        // That behavior was convenient for ODR, but it was screwing up Wordpress...so now everywhere
        //  that wants to actually use the search key has to filter it prior to decoding it...
        $filtered_search_key = self::filterSearchKeyForUser($datatype->getId(), $search_key, $user_permissions, $search_as_super_admin, $ignore_searchable);
        $search_params = $this->search_key_service->decodeSearchKey($filtered_search_key);

        // It turns out that once you start transforming sets of records across a one-to-many
        //  relationship, there are effectively two different methods of doing it.
        // The first way is to "resolve now"...all criteria in a datatype gets evaluated to create
        //  a single list of records, which is then transformed as needed.  This has the advantage
        //  of explicitly identifying descendant records for MassEdit/CSVExport...but causes negation
        //  to behave a little wonky.
        //  e.g. Abelsonite matches both "reference author: downs" and "reference author: !downs"
        //  because it has references that match either criteria
        // The second way is to "resolve later"...each criteria for a datatype effectively creates
        //  its own list of records, and all those records get transformed into the top-level
        //  datatype...and then merged together.  This more or less inverts the "resolve now"
        //  advantages/disadvantages.
        $use_set_logic = false;
        if ( isset($search_params['set']) && intval($search_params['set']) == 1 )
            $use_set_logic = true;

        // Extract the inverse target datatype, if it exists
        $inverse_target_datatype_id = null;
        if ( isset($search_params['inverse']) ) {
            $inverse_target_datatype_id = intval($search_params['inverse']);

            // values less than 1 disable this feature
            if ( $inverse_target_datatype_id < 1 ) {
                unset( $search_params['inverse'] );
                $inverse_target_datatype_id = null;
            }
        }

        // Need the list of datafields (and their typeclasses) that can be searched on...
        $searchable_datafields = array();
        if ( is_null($inverse_target_datatype_id) )
            $searchable_datafields = self::getSearchableDatafieldsForUser(array($datatype->getId()), $user_permissions, $search_as_super_admin);
        else
            $searchable_datafields = self::getSearchableDatafieldsForUser(array($inverse_target_datatype_id), $user_permissions, $search_as_super_admin);

        // Once the filtering is completed, then the search key can be converted into a considerably
        //  more complicated array format that serves as a repository for the upcoming search
        $criteria = $this->search_key_service->convertSearchKeyToCriteria($filtered_search_key, $searchable_datafields, $user_permissions, $search_as_super_admin);

        // Need to grab hydrated versions of the datafields/datatypes being searched on
        $hydrated_entities = self::hydrateCriteria($criteria);

        // The process of building the criteria also created a number of other useful utility arrays
        $affected_datatypes = $criteria['affected_datatypes'];
        $datatypes_with_criteria = $criteria['datatypes_with_criteria'];
        $all_datatypes = $criteria['all_datatypes'];
        $default_merge_type = $criteria['default_merge_type'];

        // No longer want any of these keys in the criteria array
        unset( $criteria['search_type'] );
        unset( $criteria['affected_datatypes'] );
        unset( $criteria['datatypes_with_criteria'] );
        unset( $criteria['all_datatypes'] );
        unset( $criteria['default_merge_type'] );


        // ----------------------------------------
        // Get the base information needed so getSearchArrays() can properly setup the search arrays
        $search_permissions = self::getSearchPermissionsArray($hydrated_entities['datatype'], $affected_datatypes, $user_permissions, $search_as_super_admin);

        // Determine whether the search should completely ignore any descendants
        $ignored_prefixes = self::getIgnoredPrefixes($search_params, $datatype);  // TODO - ...i forget why the second parameter was in here

        // Going to need three arrays so mergeSearchResults() can correctly determine which records
        //  end up matching the search...if running a "regular" search, then they need to be based
        //  off the datatype being searched.  If an "inverse" search, then they need to instead be
        //  based off that targeted datatype...doing so greatly simplies logic later on
        $flattened_list = self::getFlattenedList($search_permissions);

        // An "empty" search run with no criteria needs to return all top-level datarecord ids
        $return_all_results = true;

        // Need to keep track of the result list for each facet separately...they end up merged
        //  together after all facets are searched on
        $facet_dr_list = array();
        foreach ($criteria as $dt_id => $facet_list) {
            // Need to keep track of the matches for each datatype individually...
            if ( $dt_id !== 'general' )
                $facet_dr_list[$dt_id] = array();

            foreach ($facet_list as $facet_num => $facet) {
                // Skip the top-level merge flag for general search
                if ( $dt_id === 'general' && $facet_num === 'merge_type' )
                    continue;

                $facet_type = $facet['facet_type'];
                $merge_type = $facet['merge_type'];
                $search_terms = $facet['search_terms'];
                if ( empty($search_terms) )
                    continue;

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

                        if ( $facet_type === 'general' && isset($facet['negated']) ) {
                            // In the hope that the render plugins don't actually need to deal with
                            //  this themselves...attempt to pre-emptively deal with any negation
                            //  required out here...
                            $search_term['value'] = substr($search_term['value'], 1);
                        }

                        // The plugin will return the same format that the regular searches do
                        $dr_list = $rp->searchOverriddenField($entity, $search_term, $rpf_list, $rpo, $use_set_logic);

                        // If this search involved the empty string...
                        $query_modified = $dr_list['modify'];
                        if ( $query_modified > SearchQueryService::NO_MODIFICATION ) {
                            // ...then insert an entry into the criteria array so that the later
                            //  call of self::mergeSearchResults() can properly compensate
                            $criteria[$dt_id][$facet_num]['search_terms'][$key]['modify'] = $query_modified;
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
                            // Pre-emptively deal with any negation required out here...the actual
                            //  negation will happen later
                            if ( isset($facet['negated']) )
                                $search_term['value'] = substr($search_term['value'], 1);

                            // General search only provides a string, and only wants selected radio options
                            $dr_list = $this->search_service->searchForSelectedRadioOptions($entity, $search_term['value']);
                        }
                        else if ($typeclass === 'Radio' && $facet_type !== 'general') {
                            // The more specific version of searching a radio datafield provides an
                            //  array of selected/deselected options...but it technically might not
                            //  have any due to what was required to implement the "default search
                            //  params" thing
                            if ( empty($search_term['selections']) )
                                continue;
                            $dr_list = $this->search_service->searchRadioDatafield($entity, $search_term['selections']);
                        }
                        else if ($typeclass === 'Tag' && $facet_type === 'general') {
                            // Pre-emptively deal with any negation required out here...the actual
                            //  negation will happen later
                            if ( isset($facet['negated']) )
                                $search_term['value'] = substr($search_term['value'], 1);

                            // General search only provides a string, and only wants selected tags
                            $dr_list = $this->search_service->searchForSelectedTags($entity, $search_term['value']);
                        }
                        else if ($typeclass === 'Tag' && $facet_type !== 'general') {
                            // The more specific version of searching a tag datafield provides an
                            //  array of selected/deselected tags...but it technically might not
                            //  have any due to what was required to implement the "default search
                            //  params" thing
                            if ( empty($search_term['selections']) )
                                continue;
                            $dr_list = $this->search_service->searchTagDatafield($entity, $search_term['selections']);
                        }
                        else if ($typeclass === 'File' || $typeclass === 'Image') {
                            $dr_list = null;
                            // Searches on Files/Images are effectively interchangable
                            if ( isset($search_term['filename']) ) {
                                $dr_list = $this->search_service->searchFileOrImageDatafield($entity, $search_term, $use_set_logic);

                                // If this search involved the empty string...
                                $query_modified = $dr_list['modify'];
                                if ( $query_modified > SearchQueryService::NO_MODIFICATION ) {
                                    // ...then insert an entry into the criteria array so that the later
                                    //  call of self::mergeSearchResults() can properly compensate
                                    $criteria[$dt_id][$facet_num]['search_terms'][$key]['modify'] = $query_modified;
                                }
                            }
                            if ( isset($search_term['public_status']) ) {
                                $dr_list_public_status = $this->search_service->searchFileOrImageDatafield_publicstatus($entity, $search_term);

                                // Public status can't be negated

                                // If not using set logic, then this list needs to be merged with
                                //  any other search term for this field
                                if ( is_null($dr_list) )
                                    $dr_list = $dr_list_public_status;
                                else
                                    $dr_list['records'] = array_intersect_key($dr_list['records'], $dr_list_public_status['records']);
                            }
                            if ( isset($search_term['quality']) ) {
                                $dr_list_quality = $this->search_service->searchFileOrImageDatafield_quality($entity, $search_term);

                                // quality can't be negated TODO?

                                // If not using set logic, then this list needs to be merged with
                                //  any other search term for this field
                                if ( is_null($dr_list) )
                                    $dr_list = $dr_list_quality;
                                else
                                    $dr_list['records'] = array_intersect_key($dr_list['records'], $dr_list_quality['records']);
                            }
                        }
                        else if ($typeclass === 'DatetimeValue') {
                            // DatetimeValue needs to worry about before/after or value...
                            $before = $after = $value = null;
                            if ( isset($search_term['before']) )
                                $before = $search_term['before'];
                            if ( isset($search_term['after']) )
                                $after = $search_term['after'];
                            if ( isset($search_term['value']) )
                                $value = $search_term['value'];

                            $dr_list = $this->search_service->searchDatetimeDatafield($entity, $before, $after, $value);
                        }
                        else if ($typeclass === 'XYZData') {
                            // XYZData requires a mess of processing
                            if ( isset($search_term['value']) ) {
                                // The "advanced" form of this field has its search term in a single value
                                $dr_list = $this->search_service->searchXYZDatafield($entity, $search_term['value']);
                            }
                            else {
                                // The "simple" form of this field could have up to three separate
                                //  search terms
                                $x_value = $y_value = $z_value = '';
                                if ( isset($search_term['x']) )
                                    $x_value = trim($search_term['x']);
                                if ( isset($search_term['y']) )
                                    $y_value = trim($search_term['y']);
                                if ( isset($search_term['z']) )
                                    $z_value = trim($search_term['z']);

                                if ( strpos($x_value, '&&') !== false || strpos($y_value, '&&') !== false || strpos($z_value, '&&') !== false ) {
                                    // If any of the terms include '&&', then this is kind of an
                                    //  "intermediate complexity" search.  Going to attempt to
                                    //  transform the given string into the "advanced" format...
                                    $transformed_str = self::transformXYZDatafieldSearchStr($x_value, $y_value, $z_value);

                                    // ...then run the "advanced" version of the search
                                    $dr_list = $this->search_service->searchXYZDatafield($entity, $transformed_str);
                                }
                                else {
                                    // Otherwise, just treat this as a "simple" XYZData search
                                    $dr_list = $this->search_service->searchXYZDatafield_simple($entity, $x_value, $y_value, $z_value);
                                }
                            }


                            // NOTE: not needed until negation is implemented
//                            // If this search involved the empty string...
//                            $involves_empty_string = $dr_list['guard'];
//                            if ($involves_empty_string) {
//                                // ...then insert an entry into the criteria array so that the later
//                                //  call of self::mergeSearchResults() can properly compensate
//                                $criteria[$dt_id][$facet_num]['search_terms'][$key]['guard'] = true;
//                            }
                        }
                        else if ($facet_type === 'general') {
                            // Short/Medium/LongVarchar, Paragraph Text, and Integer/DecimalValue
                            $dr_list = $this->search_service->searchTextOrNumberDatafieldGeneral($entity, $search_term['value'], $use_set_logic);

                            // Don't care whether SearchService::searchTextOrNumberDatafieldGeneral()
                            //  modified the query or not at this point...it's being kept track of
                            //  elsewhere
                        }
                        else {
                            // Short/Medium/LongVarchar, Paragraph Text, and Integer/DecimalValue
                            $dr_list = $this->search_service->searchTextOrNumberDatafield($entity, $search_term['value'], $use_set_logic);

                            // If this search involved the empty string...
                            $query_modified = $dr_list['modify'];
                            if ( $query_modified > SearchQueryService::NO_MODIFICATION ) {
                                // ...then insert an entry into the criteria array so that the later
                                //  call of self::mergeSearchResults() can properly compensate
                                $criteria[$dt_id][$facet_num]['search_terms'][$key]['modify'] = $query_modified;
                            }
                        }
                    }


                    // ----------------------------------------
                    // Need to merge this result with the existing matches for this facet...
                    $facet_dt_id = $search_term['datatype_id'];

                    if ( $dt_id === 'general' ) {
                        // ...a general search needs to separate the datarecord lists by datatype,
                        //  because they can't get negated properly if they're all smashed together
                        if ( !isset($facet_dr_list[$facet_dt_id]['general']) )
                            $facet_dr_list[$facet_dt_id]['general'] = array();
                        if ( !isset($facet_dr_list[$facet_dt_id]['general'][$facet_num]) )
                            $facet_dr_list[$facet_dt_id]['general'][$facet_num] = array();

                        // General search always merges by OR when inside a facet
                        foreach ($dr_list['records'] as $dr_id => $num)
                            $facet_dr_list[$facet_dt_id]['general'][$facet_num][$dr_id] = $num;
                    }
                    else {
                        // ...an advanced search should be able to smash all the datarecord lists for
                        //  a datatype together, because the user can't mix ANDs/ORs between datafields
                        if ( !isset($facet_dr_list[$facet_dt_id]['advanced']) )
                            $facet_dr_list[$facet_dt_id]['advanced'] = array();
                        if ( !isset($facet_dr_list[$facet_dt_id]['advanced'][$facet_num]) )
                            $facet_dr_list[$facet_dt_id]['advanced'][$facet_num] = null;  // NOTE: this is so merging by AND can distinguish between "no records yet" and "no records match"

                        // Advanced searches are a combination of records across all fields in the
                        //  datatype...
                        if ( is_null($facet_dr_list[$dt_id]['advanced'][$facet_num]) ) {
                            // ...if this is the first set of records for this facet, then don't need
                            //  to merge with anything
                            $facet_dr_list[$dt_id]['advanced'][$facet_num] = $dr_list['records'];
                        }
                        else {
                            // ...if this isn't the first set of records, then what to do depends on
                            //  the type of merge being performed...
                            if ( $merge_type === 'OR' ) {
                                // ...union the subsequent sets of records with the first
                                foreach ($dr_list['records'] as $dr_id => $num)
                                    $facet_dr_list[$facet_dt_id]['advanced'][$facet_num][$dr_id] = $num;
                            }
                            else {
                                // ...otherwise, intersect the list returned by the search with the
                                //  currently stored list
                                $facet_dr_list[$facet_dt_id]['advanced'][$facet_num] = array_intersect_key($facet_dr_list[$facet_dt_id]['advanced'][$facet_num], $dr_list['records']);
                            }

                            // NOTE: when using the 'set' logic, this won't really ever be entered
                        }
                    }
                }
            }
        }


        // ----------------------------------------
        // At this point, all the individual search queries have been run and there's usually some
        //  sort of datarecord set(s) in $facet_dr_list.  There are generally three situations the
        //  search system could be in at this point:
        // 1) no criteria provided for a regular search
        // 2) criteria provided for a regular search
        // 3) MassEdit/CSVExport wants a "complete" list of matching records
        $graph = $counts = array();
        $datatree_array = null;

        // The first situation is simple...
        if ( !$return_all_results || $return_complete_list ) {
            // ...but in order to complete the other two situations, the search system needs to
            //  figure out a graph from "ancestor" to "descendant" so the actual merging/collation
            //  can be handled correctly
            if ( is_null($datatree_array) )
                $datatree_array = $this->datatree_info_service->getDatatreeArray();

            // The initial graph needs to mirror how ODR's rendering systems sees the datatypes...
            $all_datatypes = array_flip($all_datatypes);
            if ( is_null($inverse_target_datatype_id) )
                self::buildSearchDependencyGraph($datatree_array, $all_datatypes, $ignored_prefixes, $datatype->getId(), '', $graph, $counts);
            else
                self::buildSearchDependencyGraph($datatree_array, $all_datatypes, $ignored_prefixes, $inverse_target_datatype_id, '', $graph, $counts);

            // ...but that graph needs to be "inverted" if the user wants the final set of results
            //  to belong to a datatype that isn't considered to be the ancestor by the rendering
            //  system
            if ( !is_null($inverse_target_datatype_id) && $datatype->getId() !== $inverse_target_datatype_id )
                $counts = self::invertSearchDependencyGraph($graph, $counts, $datatype->getId());
        }


        // ----------------------------------------
        // Now that the individual search queries have been run...
        if ( $return_all_results ) {
            // When no search criteria is specified, then every datarecord that the user can see
            //  needs to be marked as "matching" the search
            foreach ($flattened_list as $dt_id => $dr_list) {
                foreach ($dr_list as $dr_id => $num) {
                    if ( !($num & SearchAPIService::CANT_VIEW) )
                        $flattened_list[$dt_id][$dr_id] |= SearchAPIService::MATCHES_BOTH;
                }
            }
        }
        else {
            // If search criteria was specified, then ODR typically has to merge lists of datarecords
            //  together to get a final result...unlike the merging by AND/OR that was done earlier
            //  as the datafield criteria was being evaluated, this next set of merges have to follow
            //  a different, more complicated set of rules...

            // TODO - this doesn't feel complete at all
            $merge_types = array();
            if ( isset($criteria['general']['merge_type']) )
                $merge_types['general'] = $criteria['general']['merge_type'];
            if ( isset($criteria[$datatype->getId()][0]['merge_type']) )  // TODO - i don't think this means anything anymore
                $merge_types['advanced'] = $criteria[$datatype->getId()][0]['merge_type'];

            if ( !isset($merge_types['advanced']) )
                $merge_types['advanced'] = $default_merge_type;


            // Rather than pass the full $criteria array to performMerge(), it makes more sense to
            //  extract the facets which are negated/guarded beforehand
            $modified_facets = array('general' => array(), 'advanced' => array());
            foreach ($criteria as $dt_id => $facet_list) {
                foreach ($facet_list as $facet_num => $facet) {
                    if ( $dt_id === 'general' ) {
                        // General search is easy to deal with
                        if ( $facet_num === 'merge_type' )
                            continue;
                        if ( isset($facet['negated']) )
                            $modified_facets['general'][$facet_num] = SearchQueryService::NEGATED_QUERY;
                    }
                    else {
                        // Advanced search requires each individial facet get checked
                        if ( !empty($facet) ) {
                            foreach ($facet['search_terms'] as $df_id => $df_data) {
                                if ( isset($df_data['modify']) )
                                    $modified_facets['advanced'][$facet_num] = $df_data['modify'];
                            }
                        }
                    }
                }
            }

            // Actually perform the mess of opertaions to convert arbitrary sets of datarecord lists
            //  in $facet_dr_list into a set of datarecords of the top-level datatype, and simultaneously
            //  update $flattened_list so that it contains a final list of records
            self::performMerge($graph, $counts, $datatype->getId(), $datatypes_with_criteria, $merge_types, $facet_dr_list, $flattened_list, $modified_facets, $use_set_logic);
        }


        // ----------------------------------------
        // If the user needs a list of datarecords that includes child/linked descendants for the
        //  purposes of MassEdit/CSVExport...
        if ( $return_complete_list ) {
            // ...then that requires (a potentially large number of) transforms to get all matching
            //  descendants of the matching records of the requested datatype...
            if ( is_null($datatree_array) )
                $datatree_array = $this->datatree_info_service->getDatatreeArray();
            $datarecord_ids = self::getCompleteDatarecordList($datatree_array, $graph, $flattened_list, $datatype->getId());
            $datarecord_ids = array_keys($datarecord_ids);
//            sort($datarecord_ids);

            // There's no correct method to sort this list, so might as well return immediately
            return $datarecord_ids;
        }


        // Otherwise, the user only wanted a list of the grandparent datarecords that matched the
        //  search...
        $grandparent_ids = array();
        if ( isset($flattened_list[$datatype->getId()]) ) {
            foreach ($flattened_list[$datatype->getId()] as $dr_id => $val) {
                if ( ($val & SearchAPIService::MATCHES_BOTH) === SearchAPIService::MATCHES_BOTH )
                    $grandparent_ids[] = $dr_id;
            }
        }


        // ----------------------------------------
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
        if ($return_as_list) {
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
     * The sidebar UI for XYZData fields has two forms...an "advanced" form with its own UI, and a
     * "simple" form where it mostly behaves like a trio of regular text fields.  The catch is that,
     * by itself, the "simple" form can only a single-range search (e.g. ">4 <5") or handle multiple
     * ranges separated by ORs (e.g. ">4 <5, >6 <7", which is turned into (>4 <5) OR (>6 <7)).
     *
     * By itself, it can't handle multiple ranges separated by ANDs (e.g. (>4 <5) AND (>6 <7)),
     * because {@link SearchService::searchXYZDatafield_simple()} only runs a single mysql query...
     * and because you can't have a value that's less than 5 and greater than 6 at the same time, it
     * will return no results.
     *
     * Fortunately, ODR can mostly work around this by introducing a character sequence specifically
     * to coerce multiple ranges into a single string...if it receives ">4 <5 && >6 <7", then it can
     * be broken up into a format that works with {@link SearchService::searchXYZDatafield()} instead.
     *
     * @param string $x_value
     * @param string $y_value
     * @param string $z_value
     * @return string
     */
    private function transformXYZDatafieldSearchStr($x_value, $y_value, $z_value)
    {
        // The correct way to do this is to explode on '&&' for each given string...
        $x_pieces = explode('&&', $x_value);
        $y_pieces = explode('&&', $y_value);
        $z_pieces = explode('&&', $z_value);

        // ...but we also need to do a bit of processing to try to clean up the strings a bit
        foreach ($x_pieces as $num => $piece) {
            $x_pieces[$num] = trim($piece);
            if ( $x_pieces[$num] === '' )
                unset( $x_pieces[$num] );
            else if ( strpos($x_pieces[$num], ',') !== false )
                $x_pieces[$num] = str_replace(',', ' OR ', $x_pieces[$num]);
        }
        if ( empty($x_pieces) )
            $x_pieces[0] = '';

        foreach ($y_pieces as $num => $piece) {
            $y_pieces[$num] = trim($piece);
            if ( $y_pieces[$num] === '' )
                unset( $y_pieces[$num] );
            else if ( strpos($y_pieces[$num], ',') !== false )
                $y_pieces[$num] = str_replace(',', ' OR ', $y_pieces[$num]);
        }
        if ( empty($y_pieces) )
            $y_pieces[0] = '';

        foreach ($z_pieces as $num => $piece) {
            $z_pieces[$num] = trim($piece);
            if ( $z_pieces[$num] === '' )
                unset( $z_pieces[$num] );
            else if ( strpos($z_pieces[$num], ',') !== false )
                $z_pieces[$num] = str_replace(',', ' OR ', $z_pieces[$num]);
        }
        if ( empty($z_pieces) )
            $z_pieces[0] = '';

        // Unfortunately, we need every possible permutation of these pieces in order to create the
        //  multi-range query that SearchService::searchXYZDatafield() expects
        $permutations = array();
        foreach ($x_pieces as $x_num => $x_piece) {
            foreach ($y_pieces as $y_num => $y_piece) {
                foreach ($z_pieces as $z_num => $z_piece) {
                    if ( $x_piece !== '' || $y_piece !== '' || $z_piece !== '' )
                        $permutations[] = '('.$x_piece.','.$y_piece.','.$z_piece.')';
                }
            }
        }
        // Do note that if more than one string has multiple ranges, then this setup very quickly
        //  results in queries which are going to be unmatchable in practically every situation
        // There's not much choice though, because the is the easiest way to get multirange to work
        //  without starting from the "advanced" version

        // These permutations then need to get imploded back into a single string
        $transformed_str = implode('|', $permutations);
        return $transformed_str;
    }


    /**
     * @deprecated
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
                    else if ($typeclass === 'XYZData') {
                        // XYZData requires a mess of processing
                        $dr_list = $this->search_service->searchXYZTemplateDatafield($entity, $search_term['value']);
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
            $was_general_search = false;
            if ( isset($facet_dr_list['general']) )
                $was_general_search = true;

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
            $differentiate_search_types = $was_general_search && $was_advanced_search;
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
     * @deprecated
     * {@link APIController::getfieldstatsAction()} needs to return a count of how many datarecords
     * have a specific radio option or tag selected across all instances of a template datafield.
     * This function filters the raw search results by the user's permissions before the APIController
     * action gets it.
     *
     * @param array $records
     * @param array $labels
     * @param array $searchable_datafields {@link self::getSearchableDatafieldsForUser()}
     * @param array $flattened_list {@link self::getSearchArrays()}
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
     * @deprecated
     * Returns four arrays that are required for determining which datarecords match a search.
     * Technically a search run on a top-level datatype doesn't need all this, but any search that
     * involves a child/linked datatype does.
     *
     * The first array is a "flattened" list of all records that could end up matching the search,
     * and is primarily utilized so the rest of the searching doesn't have to deal with recursion
     * when computing search results.  It consists of an array of <datarecord_id> => <num> pairs,
     * where <num> is some composite of the various binary flags defined at the top of the SearchAPIService.
     *
     * The second array is an "inflated" array of all records and their descendants, because the
     * hierarchy of "ancestor" -> "descendant" is critical to determining which "ancestors" end up
     * matching the search. See {@link self::buildDatarecordTree()}.
     *
     * The third array is primarily used by general search when it encounters negated values...in
     * order to return the correct results, the queries have been run as if the search term wasn't
     * negated, and therefore the negation has to happen before the results get merged together.
     * {@link SearchKeyService::tokenizeGeneralSearch()}
     *
     * The fourth array {@link self::buildSearchDatatree()} is used as a guide for merging the various
     * facets of records that matched the search. {@link self::mergeSearchResults()}
     *
     * @param int $target_datatype_id
     * @param array $permissions_array {@link self::getSearchPermissionsArray()}
     * @param array $ignored_prefixes {@link self::getIgnoredPrefixes()}
     *
     * @return array
     */
    public function getSearchArrays($target_datatype_id, $permissions_array, $ignored_prefixes)
    {
        // ----------------------------------------
        // Intentionally not caching the results of this function for two reasons
        // 1) these arrays need to be initialized based on the search being run, and the
        //     permissions of the user running the search
        // 2) these arrays contain ids of datarecords across all datatypes related to the datatype
        //     being searched on...determining when to clear this entry, especially when linked
        //     datatypes are involved, would be nightmarish


        // ----------------------------------------
        // The usual search involves all child/linked descendants of the datatype $target_datatype_id
        $datatree_array = $this->datatree_info_service->getDatatreeArray();


        // ----------------------------------------
        // Base setup for both arrays...
        $datatype_dr_lists = array();
        $flattened_list = array();
        $inflated_list = array(0 => array());
        $inflated_list[0][$target_datatype_id] = array();

        $search_datatree = self::buildSearchDatatree($ignored_prefixes, '', $datatree_array, array($target_datatype_id => 0));

        // Actually build the flattened and inflated lists
        self::getSearchArrays_Worker($datatree_array, $permissions_array, array($target_datatype_id => 0), $datatype_dr_lists, $flattened_list, $inflated_list);


        // ----------------------------------------
        // Sort the flattened list for easier debugging
        ksort($flattened_list);

        // Actually inflate the "inflated" list...
        $inflated_list = self::buildDatarecordTree($ignored_prefixes, '', $inflated_list, 0);

        // ...and then return the end result
        return array(
            'flattened' => $flattened_list,
            'inflated' => $inflated_list,

            'datatype_dr_lists' => $datatype_dr_lists,
            'search_datatree' => $search_datatree,
        );
    }


    /**
     * @deprecated
     * This is split off from {@link self::getSearchArrays()} for readability reasons.
     *
     * @param array $datatype_dr_lists
     * @param array $datatree_array {@link DatatreeInfoService::getDatatreeArray()}
     * @param array $permissions_array {@link self::getSearchPermissionsArray()}
     * @param array $top_level_datatype_ids
     * @param array &$flattened_list
     * @param array &$inflated_list
     *
     * @return void
     */
    private function getSearchArrays_Worker($datatree_array, $permissions_array, $top_level_datatype_ids, &$datatype_dr_lists, &$flattened_list, &$inflated_list)
    {
        foreach ($permissions_array as $dt_id => $permissions) {
            // Ensure that the user is allowed to view this datatype before doing anything with it
            if ( !$permissions['can_view_datatype'] )
                continue;

            // If the datatype is linked...then the backend query to rebuild the cache entry is
            //  different, as is the insertion of the resulting datarecords into the "inflated" list
            $is_linked_type = false;

            // When run in a normal search, any datatype in both $permissions_array and the
            //  'linked_from' section
            if ( isset($datatree_array['linked_from'][$dt_id]) )
                $is_linked_type = true;


            // If this is the datatype being searched on (or one of the datatypes directly derived
            //  from the template being searched on), then $is_linked_type needs to be false, so
            //  getCachedDatarecordList() will return all datarecords...otherwise, it'll only
            //  return those that are linked to from somewhere (which is usually desired when
            //  searching a linked datatype)
            if ( isset($top_level_datatype_ids[$dt_id]) )
                $is_linked_type = false;

            // Attempt to load this datatype's datarecords and their parents from the cache...
            $list = $this->search_service->getCachedDatarecordList($dt_id, false, $is_linked_type);


            // ----------------------------------------
            // In order for negation to work correctly in general search, the pre-merging needs
            //  access to a list of datarecord ids for each datatype.  $search_datatree has this
            //  info too, but that's in a format that requires recursion to access...
            foreach ($list as $dr_id => $value)
                $datatype_dr_lists[$dt_id][$dr_id] = 1;


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
     * @deprecated
     * Recursively builds an array of the following form for {@link self::mergeSearchResults()} to use:
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
     * The <datarecord_list> stores whatever {@link SearchService::getCachedDatarecordList()}
     *  returns for the current datatype.
     *
     * @param array $ignored_prefixes {@link self::getIgnoredPrefixes()}
     * @param string $prev_prefix
     * @param array $datatree_array
     * @param array $top_level_datatype_ids
     * @param bool $is_linked_type
     *
     * @return array
     */
    private function buildSearchDatatree($ignored_prefixes, $prev_prefix, $datatree_array, $top_level_datatype_ids, $is_linked_type = false)
    {
        $tmp = array();

        foreach ($top_level_datatype_ids as $dt_id => $num) {
            // Going to store basic info in this array structure
            $tmp[$dt_id] = array(
                'dr_list' => array(),
                'children' => array(),
                'links' => array(),
            );

            // Determine the prefix of the datatype currently being looked at
            $current_prefix = '';
            if ( $prev_prefix === '' )
                $current_prefix = $dt_id;
            else
                $current_prefix = $prev_prefix.'_'.$dt_id;

            // If it matches one of the ignored prefixes, then don't gather any data about this
            //  datatype
            if ( isset($ignored_prefixes[$current_prefix]) )
                continue;


            // Need to store all datarecords of this datatype...
            $tmp[$dt_id]['dr_list'] = $this->search_service->getCachedDatarecordList($dt_id, false, $is_linked_type);

            // Always want any children of the top-level datatype...
            $children = array();
            foreach ($datatree_array['descendant_of'] as $child_dt_id => $parent_dt_id) {
                if ( $dt_id === $parent_dt_id )
                    $children[$child_dt_id] = 1;
            }
            if ( !empty($children) )
                $tmp[$dt_id]['children'] = self::buildSearchDatatree($ignored_prefixes, $current_prefix, $datatree_array, $children);



            $links = array();
            // Need to recursively dig through linked descendants
            foreach ($datatree_array['linked_from'] as $descendant_id => $ancestors) {
                if ( in_array($dt_id, $ancestors) )
                    $links[$descendant_id] = 1;
            }

            // Regardless of which set of datatypes could be in $links...
            if ( !empty($links) ) {
                // ...if it has something, then recursively continue digging for related datatypes
                $tmp[$dt_id]['links'] = self::buildSearchDatatree($ignored_prefixes, $current_prefix, $datatree_array, $links, true);
            }
        }

        return $tmp;
    }


    /**
     * @deprecated
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
     *
     * @param array $ignored_prefixes {@link self::getIgnoredPrefixes()}
     * @param string $prev_prefix
     * @param array $descendants_of_datarecord
     * @param string|integer $current_datarecord_id
     *
     * @return string|array
     */
    private function buildDatarecordTree($ignored_prefixes, $prev_prefix, $descendants_of_datarecord, $current_datarecord_id)
    {
        if ( !isset($descendants_of_datarecord[$current_datarecord_id]) ) {
            // $current_datarecord_id has no children...intentionally returning empty string
            //  because of recursive assignment
            return '';
        }
        else {
            // $current_datarecord_id has children
            $result = array();

            // For every descendant datatype this datarecord has...
            foreach ($descendants_of_datarecord[$current_datarecord_id] as $dt_id => $datarecords) {
                // ...verify that the "path" to this descendant isn't in the list of ignored prefixes
                //  before building its datarecord tree
                $current_prefix = '';
                if ( !empty($ignored_prefixes) ) {
                    if ( $prev_prefix === '' )
                        $current_prefix = $dt_id;
                    else
                        $current_prefix = $prev_prefix.'_'.$dt_id;
                    if ( isset($ignored_prefixes[$current_prefix]) )
                        continue;
                }

                // For every child datarecord of this child datatype...
                foreach ($datarecords as $dr_id => $tmp) {
                    // NOTE - doing it this way to cut out recursive calls that just return ''
                    if ( isset($descendants_of_datarecord[$dr_id]) ) {
                        // ...get all children of this child datarecord and store them
                        $result[$dt_id][$dr_id] = self::buildDatarecordTree($ignored_prefixes, $current_prefix, $descendants_of_datarecord, $dr_id);
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
     * @deprecated
     * The "template" analog of {@link self::getSearchArrays()} does mostly the same thing, but it
     * doesn't attempt to get the list of records from cached data...instead, it uses a handful of
     * specific queries to load all records that the user is allowed to see, with as little overhead
     * as possible.
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
        $ignored_prefixes = array();    // TODO
        $search_datatree = self::buildSearchDatatree($ignored_prefixes, '', $datatree_array, array($template_dt_id => 1));


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
     * @deprecated
     * Recursively builds an array of the following form for {@link self::mergeSearchResults()} to
     * use for a template search:
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
     * This function requires that {@link self::buildSearchDatatree()} is run on the template datatype
     * first, so it can modify that result to "pretend" that the template datatypes "own" all records
     * from all datatypes derived from their relevant templates. See {@link self::mergeSearchResults()}
     *
     * @param array $search_datatree
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
     * There's basically three parts to searches in ODR...the first is to convert incoming requests
     * into "criteria", the second is to get lists of datarecords that match the "criteria", and
     * the third is to somehow "merge" them together to get a final set of matching records.  The
     * third part is by far the most difficult.
     *
     * @param array $graph {@see self::buildSearchDependencyGraph()}
     * @param array $counts {@see self::buildSearchDependencyGraph()}
     * @param int $top_level_datatype_id The datatype the search will be returning records for. This
     *                                   is guaranteed to be a top-level datatype, but it might be
     *                                   a descendant from the perspective of the rendering system
     * @param array &$datatypes_with_criteria
     * @param array $merge_types
     * @param array $facet_dr_list
     * @param array &$flattened_list {@see self::getSearchArrays()}
     * @param array $modified_facets
     * @param bool $use_set_logic TODO
     */
    private function performMerge($graph, $counts, $top_level_datatype_id, &$datatypes_with_criteria, $merge_types, $facet_dr_list, &$flattened_list, $modified_facets, $use_set_logic)
    {
        // Unfortunately, there's a difference in merging depending on whether there are multiple
        //  paths to reach a datatype or not
        $single = array();
        $multiple = array();
        // ...and also a difference in how "general" searches are merged
        $general = array();
        // Can reduce the duplicates in the $single array by keeping track of visited datatypes
        $visited = array();
        // TODO - certain inverse configurations can create a lot of duplicates in $multiple too...

        // ...but all of them can be determined during the same depth-first traversal of $graph
        self::determineMergeOrder($datatypes_with_criteria, $top_level_datatype_id, strval($top_level_datatype_id), $graph, $counts, $single, $multiple, $general, $visited);

        // ----------------------------------------
        // The previous depth-first traversal also updated $datatypes_with_criteria so that the
        //  "ancestors" (from the point of view of the current possibly-inverted search) believe they
        //  have criteria when their "descendants" do.
        // This allows $flattened_list to get updated with datatypes that must match the criteria
        //  (but aren't being directly searched on) prior to actually merging anything
        foreach ($flattened_list as $dt_id => $dr_list) {
            if ( isset($datatypes_with_criteria[$dt_id]) && (($datatypes_with_criteria[$dt_id] & SearchAPIService::MATCHES_ADV) === SearchAPIService::MATCHES_ADV) ) {
                foreach ($dr_list as $dr_id => $num)
                    $flattened_list[$dt_id][$dr_id] |= SearchAPIService::MUST_MATCH;
            }
        }


        // ----------------------------------------
        // Probably going to need this
        $datatree_array = $this->datatree_info_service->getDatatreeArray();

        // The datatypes with only a "single" path to reach them should get merged first...
        if ( !empty($single) ) {
            // Order matters here
            for ($i = 0; $i < count($single); $i++) {
                // There's only ever going to be one source/target dt pair per array entry
                //  in $single, but don't know what they are
                foreach ($single[$i] as $source_dt_id => $target_dt_id) {
                    // Apparently this can be empty sometimes
                    if ( !isset($facet_dr_list[$source_dt_id]['advanced']) )
                        continue;

                    foreach ($facet_dr_list[$source_dt_id]['advanced'] as $facet_num => $dr_list) {
                        // Need to know whether to include target records that are not related to
                        //  source records
                        $include_unrelated_records = false;
                        if ( isset($modified_facets['advanced'][$facet_num])
                            && $modified_facets['advanced'][$facet_num] === SearchQueryService::NEED_UNRELATED_RECORDS
                        ) {
                            $include_unrelated_records = true;
                        }

                        // Transform the set of descendant records into a set of ancestor records
                        $ret = self::transformRecordsFromCriteria($datatree_array, $facet_dr_list, $flattened_list, $source_dt_id, $target_dt_id, 'advanced', $facet_num, $include_unrelated_records);

                        // If any results were returned...
                        if ( isset($ret[$facet_num]) ) {
                            // ...then the results are always with any existing records in the ancestor's
                            //  facet list
                            if ( !isset($facet_dr_list[$target_dt_id]['advanced'][$facet_num]) ) {
                                // ...no datarecords for this facet yet, so just use the returned list
                                $facet_dr_list[$target_dt_id]['advanced'][$facet_num] = $ret[$facet_num];
                            }
                            else {
                                if ( $merge_types['advanced'] === 'OR' ) {
                                    foreach ($ret[$facet_num] as $dr_id => $num)
                                        $facet_dr_list[$target_dt_id]['advanced'][$facet_num][$dr_id] = $num;
                                }
                                else {
                                    // ...otherwise, merge by AND
                                    $facet_dr_list[$target_dt_id]['advanced'][$facet_num] = array_intersect_key($facet_dr_list[$target_dt_id]['advanced'][$facet_num], $ret[$facet_num]);
                                }
                            }
                        }
                    }
                }
            }
        }
        // ...because that reduces the amount of records to process when dealing with datatypes
        //  that have "multiple" paths
        if ( !empty($multiple) ) {
            // Each datatype with "multiple" paths has its own entry in here
            foreach ($multiple as $multipath_dt_id => $merges) {
                // Need to handle each facet individually
                foreach ($facet_dr_list[$multipath_dt_id]['advanced'] as $facet_num => $dr_list) {
                    if ( strpos($facet_num, '*') !== false )
                        continue;

                    // The order of the individual paths doesn't matter
                    foreach ($merges as $postfix => $merge_order) {
                        // ...but the order of the datatypes does
                        for ($i = 0; $i < count($merge_order)-1; $i++) {
                            $source_dt_id = $merge_order[$i];
                            $target_dt_id = $merge_order[$i+1];

                            // Because each path needs to get merged together at the end, they
                            //  need to temporarily be stored in separate facets
                            $facet_num_target = $postfix.'*'.$facet_num;
                            // ...but the initial read depends on whether this is the first
                            //  step in the chain or not
                            $facet_num_src = $postfix.'*'.$facet_num;
                            if ($i === 0)
                                $facet_num_src = $facet_num;

                            // Need to know whether to include target records that are not related to
                            //  source records
                            $include_unrelated_records = false;
                            if ( isset($modified_facets['advanced'][$facet_num])
                                && $modified_facets['advanced'][$facet_num] === SearchQueryService::NEED_UNRELATED_RECORDS
                            ) {
                                $include_unrelated_records = true;
                            }

                            // Transform the set of descendant records into a set of ancestor records
                            $ret = self::transformRecordsFromCriteria($datatree_array, $facet_dr_list, $flattened_list, $source_dt_id, $target_dt_id, 'advanced', $facet_num_src, $include_unrelated_records);

                            // When doing these merges, the initial set of results are stored separately
                            //  from each other
                            if ( !isset($facet_dr_list[$target_dt_id]['advanced'][$facet_num_target]) ) {
                                $facet_dr_list[$target_dt_id]['advanced'][$facet_num_target] = array();

                                foreach ($ret as $ret_facet_num => $ret_dr_list) {
//                                  if ($facet_num === $facet_num_target) {  // NOTE: this seems like it should work, but doesn't
                                    foreach ($ret_dr_list as $dr_id => $num)
                                        $facet_dr_list[$target_dt_id]['advanced'][$facet_num_target][$dr_id] = $num;
//                                  }
                                }
                            }
                            else {
                                throw new ODRException('...apparently merging $multiple does require that other $facet_dr_list logic branch?');
//                              $facet_dr_list[$target_dt_id]['advanced'][$facet_num_target] = array_intersect_key($facet_dr_list[$target_dt_id][$facet_num_target], $ret);
                            }
                        }
                    }
                }
            }

            if ( !$use_set_logic ) {
                // When not using set logic, the various lists of datarecords should get merged
                //  earlier than when using set logic
                $merged_dr_lists = array();
                if ( !isset($facet_dr_list[$top_level_datatype_id]['advanced']) )
                    $facet_dr_list[$top_level_datatype_id]['advanced'] = array();

                foreach ($facet_dr_list[$top_level_datatype_id]['advanced'] as $facet_num => $dr_list) {
                    if ( !is_null($dr_list) && strpos($facet_num, '*') !== false && strpos($facet_num, '_') !== false ) {
                        // $facet_num should currently be <postfix>*0...but get the part after the
                        //  '*' character just in case it's different
                        $pieces = explode('*', $facet_num);
                        $facet_id = $pieces[1];

                        // Need to use the first datatype in the postfix to organize the lists of
                        //  datarecords
                        $pieces = explode('_', $pieces[0]);
                        $relevant_dt_id = $pieces[0];
                        if ( !isset($merged_dr_lists[$relevant_dt_id]) ) {
                            $merged_dr_lists[$relevant_dt_id] = $dr_list;
                        }
                        else {
                            // This needs to merge by OR, unless the query could match the empty
                            //  string...
                            $use_and = false;
                            if ( isset($modified_facets['advanced'][$facet_id]) && $modified_facets['advanced'][$facet_id] > SearchQueryService::NO_MODIFICATION )
                                $use_and = !$use_and;

                            if ( !$use_and ) {
                                foreach ($dr_list as $dr_id => $num)
                                    $merged_dr_lists[$relevant_dt_id][$dr_id] = $num;
                            }
                            else {
                                $merged_dr_lists[$relevant_dt_id] = array_intersect_key($merged_dr_lists[$relevant_dt_id], $dr_list);
                            }
                        }
                    }
                }

                // Each of the separate paths can now get merged by into a single list of records
                $merged_dr_list = null;
                foreach ($merged_dr_lists as $relevant_dt_id => $dr_list) {
                    if ( is_null($merged_dr_list) ) {
                        // ...no existing datarecord list yet, so just use the first one
                        $merged_dr_list = $dr_list;
                    }
                    else {
                        if ( $merge_types['advanced'] === 'OR') {
                            foreach ($dr_list as $dr_id => $num)
                                $merged_dr_list[$dr_id] = $num;
                        }
                        else {
                            // ...otherwise, merge by AND
                            $merged_dr_list = array_intersect_key($merged_dr_list, $dr_list);
                        }
                    }
                }

                // The resulting list of records needs to get merged by AND with any existing advanced
                //  criteria...
                if ( !is_null($merged_dr_list) ) {
                    if ( !isset($facet_dr_list[$top_level_datatype_id]['advanced'][0]) ) {
                        // ...no existing criteria, so just use this list
                        $facet_dr_list[$top_level_datatype_id]['advanced'][0] = $merged_dr_list;
                    }
                    else {
                        if ( $merge_types['advanced'] === 'OR' ) {
                            foreach ($merged_dr_list as $dr_id => $num)
                                $facet_dr_list[$top_level_datatype_id]['advanced'][0][$dr_id] = $num;
                        }
                        else {
                            // ...otherwise, merge by AND
                            $facet_dr_list[$top_level_datatype_id]['advanced'][0] = array_intersect_key($facet_dr_list[$top_level_datatype_id]['advanced'][0], $merged_dr_list);
                        }
                    }
                }
            }
            else {
                // When using set logic, merging at this point is simpler...
                foreach ($facet_dr_list as $dt_id => $facet_list) {
                    if ( isset($facet_list['advanced']) ) {
                        foreach ($facet_list['advanced'] as $facet_key => $dr_list) {
                            if ( !is_null($dr_list) && strpos($facet_key, '*') !== false ) {
                                $pieces = explode('*', $facet_key);
                                $facet_num = $pieces[1];
                                if ( !isset($facet_dr_list[$dt_id]['advanced'][$facet_num]) )
                                    $facet_dr_list[$dt_id]['advanced'][$facet_num] = array();

                                // NOTE: need to always merge by OR at this point
//                                if ( isset($modified_facets['advanced'][$facet_num]) || $merge_types['advanced'] === 'OR' ) {
                                    foreach ($dr_list as $dr_id => $num)
                                        $facet_dr_list[$dt_id]['advanced'][$facet_num][$dr_id] = $num;
//                                }
//                                else if ( $merge_types['advanced'] === 'AND' ) {
//                                    $facet_dr_list[$dt_id]['advanced'][$facet_num] = array_intersect_key($facet_dr_list[$dt_id]['advanced'][$facet_num], $dr_list);
//                                }
                            }
                        }
                    }
                }
            }
        }


        // ----------------------------------------
        // General search works slightly differently than the advanced searches...
        if ( !empty($general) ) {
            // Order matters here
            for ($i = 0; $i < count($general); $i++) {
                // There's only ever going to be one source/target dt pair per array entry
                //  in $general, but don't know what they are
                foreach ($general[$i] as $source_dt_id => $target_dt_id) {
                    // Do NOT need to deal with the possibility of a negated facet here...general
                    //  search is split apart and handled in such a way that any negated criteria
                    //  must be handled later

                    // Transform the set of descendant records into a set of ancestor records
                    $ret = self::transformRecordsFromCriteria($datatree_array, $facet_dr_list, $flattened_list, $source_dt_id, $target_dt_id, 'general'/*, $guard*/);

                    // $ret could have multiple facets in there, one for each 'token' of the general
                    //  search...they can't get merged together into one list of records until the
                    //  top-level datatype is reached
                    if ( !isset($facet_dr_list[$target_dt_id]['general']) )
                        $facet_dr_list[$target_dt_id]['general'] = array();

                    foreach ($ret as $facet_num => $dr_list) {
                        // ...however, the list of records for each individual "token" should get
                        //  merged by OR with any existing records for the same "token" in the
                        //  ancestor's list of records
                        if ( !isset($facet_dr_list[$target_dt_id]['general']) )
                            $facet_dr_list[$target_dt_id]['general'][$facet_num] = $dr_list;
                        else {
                            foreach ($dr_list as $dr_id => $num)
                                $facet_dr_list[$target_dt_id]['general'][$facet_num][$dr_id] = $num;
                        }
                    }
                }
            }
        }


        // ----------------------------------------
        // At this point, all sets of datarecord lists from the criteria have been successfully
        // transformed into lists of records of $top_level_datatype_id.

        // ----------------------------------------
        // There's still the issue of negated general search criteria to deal with, however...
        //  ...performing this requires lists of all datarecords in a datatype
        $full_dr_lists = array();
        if ( !empty($modified_facets['general']) ) {
            foreach ($modified_facets['general'] as $modified_facet_num => $modification) {
                // If the general search had at least one negated facet...
                foreach ($facet_dr_list as $dt_id => $facet_list) {
                    if ( isset($facet_list['general'][$modified_facet_num]) ) {
                        // ...then there should be a datarecord list for this facet in this datatype
                        // Get the list of records for this datatype
                        if ( !isset($full_dr_lists[$dt_id]) )
                            $full_dr_lists[$dt_id] = $this->search_service->getCachedDatarecordList($dt_id);
                        $tmp = $full_dr_lists[$dt_id];

                        // ...and then subtract the records that matched the "un-negated" search from it
                        foreach ($facet_list['general'][$modified_facet_num] as $dr_id => $num)
                            unset( $tmp[$dr_id] );

                        // Want to completely replace the existing list of records for this facet
                        $facet_dr_list[$dt_id]['general'][$modified_facet_num] = $tmp;
                    }
                }
            }
        }
        if ( !empty($modified_facets['advanced']) ) {
            foreach ($modified_facets['advanced'] as $modified_facet_num => $modification) {
                // If the advanced search had at least one negated facet...
                if ( $modification === SearchQueryService::NEGATED_QUERY ) {
                    foreach ($facet_dr_list as $dt_id => $facet_list) {
                        if ( isset($facet_list['advanced'][$modified_facet_num]) ) {
                            // ...then there should be a datarecord list for this facet in this datatype
                            // Get the list of records for this datatype
                            if ( !isset($full_dr_lists[$dt_id]) )
                                $full_dr_lists[$dt_id] = $this->search_service->getCachedDatarecordList($dt_id);
                            $tmp = $full_dr_lists[$dt_id];

                            // ...and then subtract the records that matched the "un-negated" search from it
                            foreach ($facet_list['advanced'][$modified_facet_num] as $dr_id => $num) {
                                unset( $tmp[$dr_id] );
                                // NOTE: this doesn't seem to be a good idea
//                                $flattened_list[$dt_id][$dr_id] |= SearchAPIService::NEGATED_MATCH;
                            }

                            // Want to completely replace the existing list of records for this facet
                            $facet_dr_list[$dt_id]['advanced'][$modified_facet_num] = $tmp;
                        }
                    }
                }
            }
        }


        // ----------------------------------------
        // At this point, the lists of records for 'general' searches in $facet_dr_list need to get
        //  compressed together to generate a final match for their respective datatypes
        if ( !empty($facet_dr_list[$top_level_datatype_id]['general']) ) {
            // Ensure the base facet exists...it should already, but I don't want shennanigans
            if ( !isset($facet_dr_list[$top_level_datatype_id]['general'][0]) )
                $facet_dr_list[$top_level_datatype_id]['general'][0] = array();

            // Each facet beyond the first needs to get merged back into the base facet
            foreach ($facet_dr_list[$top_level_datatype_id]['general'] as $facet_num => $dr_list) {
                if ( $facet_num === 0 )
                    continue;

                if ( $merge_types['general'] === 'AND' ) {
                    $facet_dr_list[$top_level_datatype_id]['general'][0] = array_intersect_key($facet_dr_list[$top_level_datatype_id]['general'][0], $dr_list);
                }
                else {  // merge_type === 'OR'
                    foreach ($dr_list as $dr_id => $num)
                        $facet_dr_list[$top_level_datatype_id]['general'][0][$dr_id] = $num;
                }

                // Getting rid of it helps with debugging....
                unset( $facet_dr_list[$top_level_datatype_id]['general'][$facet_num] );
            }
        }

        // IMPORTANT: this is required if and only if advanced search gets changed to resolve "later"
        //  instead of "now"
        if ( !empty($facet_dr_list[$top_level_datatype_id]['advanced']) ) {
            // Ensure the base facet exists...
            if ( !isset($facet_dr_list[$top_level_datatype_id]['advanced'][0]) )
                $facet_dr_list[$top_level_datatype_id]['advanced'][0] = null;

            // Each facet beyond the first needs to get merged back into the base facet
            foreach ($facet_dr_list[$top_level_datatype_id]['advanced'] as $facet_num => $dr_list) {
                if ( $facet_num === 0 || !is_numeric($facet_num) )
                    continue;

                if ( is_null($facet_dr_list[$top_level_datatype_id]['advanced'][0]) ) {
                    $facet_dr_list[$top_level_datatype_id]['advanced'][0] = $dr_list;
                }
                else {
                    if ( $merge_types['advanced'] === 'AND' ) {
                        $facet_dr_list[$top_level_datatype_id]['advanced'][0] = array_intersect_key($facet_dr_list[$top_level_datatype_id]['advanced'][0], $dr_list);
                    }
                    else {  // merge_type === 'OR'
                        foreach ($dr_list as $dr_id => $num)
                            $facet_dr_list[$top_level_datatype_id]['advanced'][0][$dr_id] = $num;
                    }
                }

                // Getting rid of it helps with debugging....
                unset( $facet_dr_list[$top_level_datatype_id]['advanced'][$facet_num] );
            }
        }

        // The penultimate step is to merge the general/advanced facets together into a single list,
        //  for just the top-level datatype
        $advanced_dr_list = null;
        if ( isset($facet_dr_list[$top_level_datatype_id]['advanced'][0]) )
            $advanced_dr_list = $facet_dr_list[$top_level_datatype_id]['advanced'][0];

        $general_dr_list = null;
        if ( isset($facet_dr_list[$top_level_datatype_id]['general'][0]) )
            $general_dr_list = $facet_dr_list[$top_level_datatype_id]['general'][0];

        // Replace the datarecord list of the top-level datatype with the final list of matching
        //  records
        if ( is_null($advanced_dr_list) && is_null($general_dr_list) )
            $facet_dr_list[$top_level_datatype_id]['advanced'][0] = array();
        else if ( is_null($advanced_dr_list) && !is_null($general_dr_list) )
            $facet_dr_list[$top_level_datatype_id]['advanced'][0] = $general_dr_list;
        else if ( !is_null($advanced_dr_list) && is_null($general_dr_list) )
            $facet_dr_list[$top_level_datatype_id]['advanced'][0] = $advanced_dr_list;
        else
            $facet_dr_list[$top_level_datatype_id]['advanced'][0] = array_intersect_key($advanced_dr_list, $general_dr_list);


        // ----------------------------------------
        // The final step is to update the flag in $flattened list for each of the advanced
        //  facets
        foreach ($facet_dr_list as $dt_id => $facets) {
            // IMPORTANT: this is effectively a kludge caused by advanced search resolving "now" and
            //  by the 'multiple' paths merging their results back into the advanced search facets
            //  much earlier in the function
            if ( isset($facets['advanced'][0]) ) {
                foreach ($facets['advanced'][0] as $dr_id => $num) {
                    if ( isset($flattened_list[$dt_id][$dr_id]) && $flattened_list[$dt_id][$dr_id] < SearchAPIService::CANT_VIEW )
                        $flattened_list[$dt_id][$dr_id] |= SearchAPIService::MATCHES_BOTH;
                }
            }
            else if ( isset($facets['advanced']) ) {
                $tmp_dr_list = null;
                foreach ($facets['advanced'] as $facet_num => $dr_list) {
                    if ( is_null($tmp_dr_list) )
                        $tmp_dr_list = $dr_list;
                    else if ( $merge_types['advanced'] === 'AND' )
                        $tmp_dr_list = array_intersect_key($tmp_dr_list, $dr_list);
                    else if ( $merge_types['advanced'] === 'OR' )
                        foreach ($dr_list as $dr_id => $num)
                            $tmp_dr_list[$dr_id] = $num;
                }
                if ( !is_null($tmp_dr_list) ) {
                    foreach ($tmp_dr_list as $dr_id => $num) {
                        if ( isset($flattened_list[$dt_id][$dr_id]) && $flattened_list[$dt_id][$dr_id] < SearchAPIService::CANT_VIEW )
                            $flattened_list[$dt_id][$dr_id] |= SearchAPIService::MATCHES_BOTH;
                    }
                }
            }
        }
    }


    /**
     * The primary problem with allowing arbitrary merge directions is that there still needs to be
     * a guarantee that criteria from descendants "makes it" to the top-level datatype. The catch is
     * that the order depends on whether there are "multiple paths" from the top-level datatype to
     * any given descendant...
     *
     * Datatypes with only a "single" path only require a chain of merge orders...a depth-first
     * traversal guarantees that all descendants get merged into their ancestors, before their
     * ancestors get merged into their ancestors, and so on.
     *
     * "Multiple" paths, however...e.g. R when {A -> R -> X, A -> AB -> R -> X, A -> AC -> R -> X}
     * ...in order for the final set of search results to be accurate, these merges need to be
     * handled differently.  Generally speaking, criteria from these datatypes needs to take every
     * possible path to the top-level datatype, and then get merged together by OR, and only then
     * get ANDed with criteria from the "single" paths.
     *
     * So for the above example, assuming that each of the five mentioned datatypes have criteria,
     * the ~optimal~ path is to merge [AB -> A, AC -> A, X -> R] in any order and save the results,
     * then perform [R -> A, R -> AB -> A, R -> AC -> A] and save the result, then finally merge both
     * sets of A together.
     *
     * @param array &$datatypes_with_criteria
     * @param int $current_datatype_id
     * @param string $current_postfix
     * @param array $graph
     * @param array $counts
     * @param array &$single
     * @param array &$multiple
     * @param array &$general
     * @param array &$visited
     * @return int bitmask...0 if no criteria, 1 if general search criteria, 2 if adv search criteria
     */
    private function determineMergeOrder(&$datatypes_with_criteria, $current_datatype_id, $current_postfix, $graph, $counts, &$single, &$multiple, &$general, &$visited)
    {
        // If this is entered, then the current datatype has child/linked descendants
        $current_has_criteria = 0;
        if ( isset($datatypes_with_criteria[$current_datatype_id]) )
            $current_has_criteria = $datatypes_with_criteria[$current_datatype_id];

        // If the current datatype has no child/linked descendants, then just return immediately
        if ( !isset($graph[$current_datatype_id]) )
            return $current_has_criteria;

        if ( !isset($datatypes_with_criteria[$current_datatype_id]) )
            $datatypes_with_criteria[$current_datatype_id] = $current_has_criteria;

        foreach ($graph[$current_datatype_id] as $descendant_dt_id => $num) {
            // Going to need this if there are multiple paths to reach a descendant
            $new_postfix = $descendant_dt_id.'_'.$current_postfix;

            $descendant_has_criteria = false;
            if ( !isset($graph[$descendant_dt_id]) ) {
                // This descendant has no descendants of its own...save a recursive step
                $descendant_has_criteria = 0;
                if ( isset($datatypes_with_criteria[$descendant_dt_id]) )
                    $descendant_has_criteria = $datatypes_with_criteria[$descendant_dt_id];
            }
            // NOTE: the following WILL NOT WORK...breaks generated paths for $multiple with the set:
            // {ML -> R, ML -> SN -> R, ML -> RA -> R, ML -> RB -> R, S -> R, S -> ML -> ...} when
            // the graph is inverted so that R is top-level
//            else if ( isset($visited[$descendant_dt_id]) ) {
//                $descendant_has_criteria = $visited[$descendant_dt_id];
//            }
            else {
                // Need to continue recursion to get this descendant's descendants
                $descendant_has_criteria = self::determineMergeOrder($datatypes_with_criteria, $descendant_dt_id, $new_postfix, $graph, $counts, $single, $multiple, $general, $visited);
//                $visited[$descendant_dt_id] = $descendant_has_criteria;
            }

            if ($descendant_has_criteria !== false)
                $datatypes_with_criteria[$current_datatype_id] |= $descendant_has_criteria;

            // Only care about the descendant if it has criteria...
            if ( $descendant_has_criteria > 0 ) {
                // Because general search is a very long chain of ORs, its merges don't need to
                //  consider the possibility of "multiple paths"...but the order is still relevant
                if ( ($descendant_has_criteria & SearchAPIService::MATCHES_GEN) === SearchAPIService::MATCHES_GEN ) {
//                    if ( !isset($visited[$new_postfix]) ) {  // NOTE: this also WILL NOT WORK
                    $general[] = array($descendant_dt_id => $current_datatype_id);
//                        $visited[$new_postfix] = $descendant_has_criteria;
//                    }

                    // Because the descendant has criteria, the current datatype also has criteria
                    $current_has_criteria |= $descendant_has_criteria;
                }

                // Advanced search, on the other hand, is generally a long chain of ANDs, which means
                //  descendants that can be reached by "multiple paths" will screw this up...
                if ( ($descendant_has_criteria & SearchAPIService::MATCHES_ADV) === SearchAPIService::MATCHES_ADV ) {
                    if ( $counts[$descendant_dt_id] === 1 ) {
                        // ...if there's only has one "path" to reach the top-level datatype, then
                        //  store the merge order directly
                        if ( !isset($visited[$descendant_dt_id]) ) {
                            // ...but only if this path hasn't been stored before
                            $visited[$descendant_dt_id] = $descendant_has_criteria;
                            $single[] = array($descendant_dt_id => $current_datatype_id);

                            // NOTE: this seems to work, unlike the earlier $visited stuff in this function
                        }

                        // Because the descendant has criteria, the current datatype also has criteria
                        $current_has_criteria |= $descendant_has_criteria;
                    }
                    else {
                        // ...if there's more than one "path", then we need to eventually collect
                        //  merge orders for all possible "paths" to this datatype
                        if ( !isset($multiple[$descendant_dt_id]) )
                            $multiple[$descendant_dt_id] = array();
                        $multiple[$descendant_dt_id][$new_postfix] = array();

                        $tmp = explode('_', $new_postfix);
                        foreach ($tmp as $dt_id)
                            $multiple[$descendant_dt_id][$new_postfix][] = intval($dt_id);

                        // Criteria from these datatypes should not be directly merged into their ancestors
                    }
                }
            }
        }

        return $current_has_criteria;
    }


    /**
     * Converts the datarecord list stored in $source_dt_id's criteria into a datarecord list of
     * $target_dt_id.
     *
     * This is the "smallest step" in the merging process...where arbitrary criteria for (nearly)
     * arbitrary datatypes get turned into a set of records for a (somehow related) top-level
     * datatype for searching purposes.  As such, it doesn't really know or care about the details
     * of how the source/target datatypes are related...just that they are.
     *
     * This place needs to deal with non-public records as part of the transformation...non-public
     * source records can't result in target records, and non-public target records also can't be
     * allowed to be returned.
     *
     * @param array $datatree_array {@see DatatreeInfoService::getDatatreeArray()}
     * @param array $facet_dr_list TODO
     * @param array $flattened_list {@see self::getSearchArrays()}
     * @param int $source_dt_id The datatype id of the given set of records
     * @param int $target_dt_id The id of the datatype the records should be transformed into
     * @param int $facet_type 'advanced' or 'general'
     * @param int $facet_num_src If provided, then the transform only looks at records from that
     *                           specific facet.  General search transforms should not use this.
     * @param bool $include_unrelated_records If true, then this triggers inclusion of target records
     *                                         that are not related to source records e.g. parents
     *                                         without children or ancestors without descendants
     * @return array The datarecord ids are keys
     */
    private function transformRecordsFromCriteria($datatree_array, $facet_dr_list, $flattened_list, $source_dt_id, $target_dt_id, $facet_type, $facet_num_src = null, $include_unrelated_records = false)
    {
        // In order to pull off the transform, you need to determine how the records are related to
        //  each other.  ODR has four different possible relations, and there are cache entries for
        //  ODR to use to expedite this...
        $source_is_child_descendant = $source_is_link_descendant = false;
        $target_is_child_descendant = $target_is_link_descendant = false;

        if ( isset($datatree_array['descendant_of'][$source_dt_id])
            && $datatree_array['descendant_of'][$source_dt_id] === $target_dt_id
        ) {
            $source_is_child_descendant = true;
        }
        else if ( isset($datatree_array['descendant_of'][$target_dt_id])
            && $datatree_array['descendant_of'][$target_dt_id] === $source_dt_id
        ) {
            $target_is_child_descendant = true;
        }
        else if ( isset($datatree_array['linked_from'][$source_dt_id])
            && in_array($target_dt_id, $datatree_array['linked_from'][$source_dt_id])
        ) {
            $source_is_link_descendant = true;
        }
        else if ( isset($datatree_array['linked_from'][$target_dt_id])
            && in_array($source_dt_id, $datatree_array['linked_from'][$target_dt_id])
        ) {
            $target_is_link_descendant = true;
        }

        // One of these has to be true to continue
        if ( !($source_is_child_descendant || $target_is_child_descendant || $source_is_link_descendant || $target_is_link_descendant) )
            throw new ODRBadRequestException('SearchAPIService::transformRecordsFromCriteria()  source dt '.$source_dt_id.' is not directly related to target dt '.$target_dt_id);


        // ----------------------------------------
        // Most of the cache entries have extra datarecords that aren't relevant to the search
        $source_dr_list = $flattened_list[$source_dt_id];
        $target_dr_list = $flattened_list[$target_dt_id];

        // The actual transformation depends on the structure of the cache entries...
        $transformed_records = array();
        if ($source_is_child_descendant) {
            // ...this gets an array where <child_dr_id> => <parent_record_id>
            $source_child_to_parent = $this->search_service->getCachedDatarecordList($source_dt_id, false, false);
            // The child records belong to $source_dt_id, and the parent records belong to $target_dt_id

            // For this transformation, need to take the child records...
            if ( isset($facet_dr_list[$source_dt_id][$facet_type]) ) {
                foreach ($facet_dr_list[$source_dt_id][$facet_type] as $facet_num => $dr_list) {
                    if ( !is_null($facet_num_src) && $facet_num !== $facet_num_src )
                        continue;
                    if ( !isset($transformed_records[$facet_num]) )
                        $transformed_records[$facet_num] = array();

                    foreach ($dr_list as $source_dr_id => $num) {
                        if ( isset($source_child_to_parent[$source_dr_id])
                            && $source_dr_list[$source_dr_id] < SearchAPIService::CANT_VIEW
                        ) {
                            // If the user can view the child record, then get its parent...
                            $parent_dr_id = $source_child_to_parent[$source_dr_id];
                            // ...and if the user can view the parent...
                            if ( $target_dr_list[$parent_dr_id] < SearchAPIService::CANT_VIEW ) {
                                // ...then save the parent record id
                                $transformed_records[$facet_num][$parent_dr_id] = 1;
                            }
                        }
                    }

                    // If the search criteria involves the empty string...
                    if ($include_unrelated_records) {
                        // ...then need to also determine which records in the parent datatype do not have
                        //  child records of this datatype
                        $unrelated_target_records = $target_dr_list;

                        foreach ($source_child_to_parent as $child_dr_id => $parent_dr_id) {
                            if ( $source_dr_list[$child_dr_id] < SearchAPIService::CANT_VIEW ) {
                                if ( isset($target_dr_list[$parent_dr_id])
                                    && $target_dr_list[$parent_dr_id] < SearchAPIService::CANT_VIEW
                                ) {
                                    unset( $unrelated_target_records[$parent_dr_id] );
                                }
                            }
                        }

                        foreach ($unrelated_target_records as $ancestor_dr_id => $num)
                            $transformed_records[$facet_num][$ancestor_dr_id] = 1;
                    }
                }
            }
        }
        else if ($target_is_child_descendant) {
            // ...this gets an array where <parent_dr_id> => array(<child_dr_1> => '', <child_dr_2> => '',...)
            $source_parent_to_child = $this->search_service->getCachedDatarecordList($source_dt_id, true, false);
            // The parent records belong to $source_dt_id, but the child records don't necessarily
            //  belong to $target_dt_id...

            // For this transformation, need to take the matching parent records...
            if ( isset($facet_dr_list[$source_dt_id][$facet_type]) ) {
                foreach ($facet_dr_list[$source_dt_id][$facet_type] as $facet_num => $dr_list) {
                    if ( !is_null($facet_num_src) && $facet_num !== $facet_num_src )
                        continue;
                    if ( !isset($transformed_records[$facet_num]) )
                        $transformed_records[$facet_num] = array();

                    foreach ($dr_list as $source_dr_id => $num) {
                        if ( !empty($source_parent_to_child[$source_dr_id])
                            && $source_dr_list[$source_dr_id] < SearchAPIService::CANT_VIEW
                        ) {
                            // ...if the parent record has child records, and the user can view the
                            //  parent record...
                            foreach ($source_parent_to_child[$source_dr_id] as $descendant_dr_id => $str) {
                                // ...then determine which child records belong to the target datatype...
                                if ( isset($target_dr_list[$descendant_dr_id])
                                    && $target_dr_list[$descendant_dr_id] < SearchAPIService::CANT_VIEW
                                ) {
                                    // ...and if the user can also view the child record, then save it
                                    $transformed_records[$facet_num][$descendant_dr_id] = 1;
                                }
                            }
                        }
                    }
                }
            }

            // Unlike the rest of these transformations, there's nothing to be done here when the
            //  empty string is involved...can't have child records without a parent record
        }
        else if ($source_is_link_descendant) {
            // ...this gets an array where <descendant_dr_id> => array(<ancestor_dr_1> => '', <ancestor_dr_2> => '', ...)
            $source_linked_to_by = $this->search_service->getCachedDatarecordList($source_dt_id, false, true);
            // The descendant belongs to $source_dt_id, but the ancestor records don't necessarily
            //  belong to $target_dt_id...

            // For this transformation, need to take the descendant records...
            if ( isset($facet_dr_list[$source_dt_id][$facet_type]) ) {
                foreach ($facet_dr_list[$source_dt_id][$facet_type] as $facet_num => $dr_list) {
                    if ( !is_null($facet_num_src) && $facet_num !== $facet_num_src )
                        continue;
                    if ( !isset($transformed_records[$facet_num]) )
                        $transformed_records[$facet_num] = array();

                    foreach ($dr_list as $source_dr_id => $num) {
                        if ( !empty($source_linked_to_by[$source_dr_id])
                            && $source_dr_list[$source_dr_id] < SearchAPIService::CANT_VIEW
                        ) {
                            // If the user can view the descendant record, then check its linked
                            //  ancestors...
                            foreach ($source_linked_to_by[$source_dr_id] as $ancestor_dr_id => $str) {
                                if ( isset($target_dr_list[$ancestor_dr_id])
                                    && $target_dr_list[$ancestor_dr_id] < SearchAPIService::CANT_VIEW
                                ) {
                                    // ...if the user can view the ancestor record and it also belongs
                                    //  to the correct datatype, then save it
                                    $transformed_records[$facet_num][$ancestor_dr_id] = 1;
                                }
                            }
                        }
                    }

                    // If the search criteria requires unrelated records from the target...
                    if ($include_unrelated_records) {
                        // ...then need to also determine which records in the ancestor datatype do
                        //  not have descendant records of this datatype
                        $unrelated_target_records = $target_dr_list;

                        foreach ($source_linked_to_by as $source_dr_id => $ancestor_dr_list) {
                            if ( !empty($ancestor_dr_list)
                                && $source_dr_list[$source_dr_id] < SearchAPIService::CANT_VIEW
                            ) {
                                foreach ($ancestor_dr_list as $ancestor_dr_id => $str) {
                                    if ( isset($target_dr_list[$ancestor_dr_id])
                                        && $target_dr_list[$ancestor_dr_id] < SearchAPIService::CANT_VIEW
                                    ) {
                                        unset( $unrelated_target_records[$ancestor_dr_id] );
                                    }
                                }
                            }
                        }

                        foreach ($unrelated_target_records as $ancestor_dr_id => $num)
                            $transformed_records[$facet_num][$ancestor_dr_id] = 1;
                    }
                }
            }
        }
        else if ($target_is_link_descendant) {
            // ...this gets an array where <ancestor_dr_id> => array(<descendant_dr_1> => '', <descendant_dr_2> => '', ...)
            $source_links_to = $this->search_service->getCachedDatarecordList($source_dt_id, true, true);

            // The ancestor belongs to $source_dt_id, but the descendant records don't necessarily
            //  belong to $target_dt_id...so for each matching ancestor record...
            if ( isset($facet_dr_list[$source_dt_id][$facet_type]) ) {
                foreach ($facet_dr_list[$source_dt_id][$facet_type] as $facet_num => $dr_list) {
                    if ( !is_null($facet_num_src) && $facet_num !== $facet_num_src )
                        continue;
                    if ( !isset($transformed_records[$facet_num]) )
                        $transformed_records[$facet_num] = array();

                    foreach ($dr_list as $source_dr_id => $num) {
                        // ...if it has descendants...
                        if ( !empty($source_links_to[$source_dr_id])
                            && $source_dr_list[$source_dr_id] < SearchAPIService::CANT_VIEW
                        ) {
                            // ...and the user can view it, then locate which of its linked descendants
                            //  belong to the target datatype...
                            foreach ($source_links_to[$source_dr_id] as $descendant_dr_id => $str) {
                                if ( isset($target_dr_list[$descendant_dr_id])
                                    && $target_dr_list[$descendant_dr_id] < SearchAPIService::CANT_VIEW
                                ) {
                                    // ...and save the descendant record if the user can view it
                                    $transformed_records[$facet_num][$descendant_dr_id] = 1;
                                }
                            }
                        }
                    }

                    // If the search criteria involves the empty string...
                    if ($include_unrelated_records) {
                        // ...then need to also determine which records in the descendant datatype are not
                        //  linked to by the ancestor datatype
                        $unrelated_target_records = $target_dr_list;

                        foreach ($source_links_to as $source_dr_id => $ancestor_dr_list) {
                            if ( !empty($ancestor_dr_list)
                                && $source_dr_list[$source_dr_id] < SearchAPIService::CANT_VIEW
                            ) {
                                foreach ($ancestor_dr_list as $ancestor_dr_id => $str) {
                                    if ( isset($target_dr_list[$ancestor_dr_id])
                                        && $target_dr_list[$ancestor_dr_id] < SearchAPIService::CANT_VIEW
                                    ) {
                                        unset( $unrelated_target_records[$ancestor_dr_id] );
                                    }
                                }
                            }
                        }

                        foreach ($unrelated_target_records as $ancestor_dr_id => $num)
                            $transformed_records[$facet_num][$ancestor_dr_id] = 1;
                    }
                }
            }
        }

        // Debugging is easier if the records are sorted here...
//        foreach ($transformed_records as $num => $dr_list) {
//            $tmp = $dr_list;
//            ksort($tmp);
//            $transformed_records[$num] = $tmp;
//        }
        return $transformed_records;
    }


    /**
     * @deprecated
     * The primary difficulty with searching in ODR is that the datarecords/datatypes are all
     * effectively "in the same bucket"...there's no viable method to get useful table joins when
     * the backend database is content-agnostic, and when there can be an arbitrarily deep tree of
     * child/linked datatypes.  Additionally, MYSQL really DOES NOT LIKE joining these "all in one"
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
     * {@link SearchKeyService::tokenizeGeneralSearch()} for what a "general" search actually is
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
     * @param array $search_datatree {@link self::buildSearchDatatree()}
     * @param array $facet_dr_list
     * @param array $flattened_list {@link self::getSearchArrays()}
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
            // ...then there's no point checking any child/linked datatypes if merging by AND
            if ( $criteria['default_merge_type'] === 'AND' )
                return $matches;

            // When merging by OR, then the descendants still need to be checked
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
            // TODO - If there's this database with the setup:
            // TODO - {IMA -> References, IMA -> Reference A -> References, IMA -> Reference B -> References, IMA -> Status Notes -> References}
            // TODO - ...this logic will only work when at most one "path" is selected and the empty string is involved
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
            // Need the ability to switch between merging types...
            $merge_type = $criteria['default_merge_type'];
            if ( isset($criteria[$dt_id][0]['merge_type']) )
                $merge_type = $criteria[$dt_id][0]['merge_type'];

            if ( is_null($final_dr_list) ) {
                // Use this list if no merging has happened yet
                $final_dr_list = $dr_list;
            }
            else if ( $merge_type === 'AND' ) {
                // When merging by AND, only records in every list end up matching
                $final_dr_list = array_intersect_key($final_dr_list, $dr_list);
            }
            else if ( $merge_type === 'OR' ) {
                // When merging by OR, any record in the lists ends up matching
                foreach ($dr_list as $dr_id => $num)
                    $final_dr_list[$dr_id] = $num;
            }
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
            // The records from this datatype that match the general search need to be merged with
            //  the records that match because their descendants match the the general search.
            // The catch is whether this is done depends on whether the search was negated or not...
            foreach ($facets['gen'] as $facet_num => $dr_list) {
                foreach ($dr_list as $dr_id => $num) {
                    // What to do here depends on whether negation is involved...
                    if ( !isset($criteria['general'][$facet_num]['negated']) ) {
                        // ...if it isn't, then a general search is purely "does this exist".  Any
                        //  records that are ancestors of those that match the search should be
                        //  treated as if they directly match the search too
                        $facet_dr_list['general'][$facet_num][$dr_id] = 1;
                    }
                    else {
                        // ...if it is, then a general search means "this can't exist".  Because
                        //  of how ODR's search system is torqued, the "correct" merge has effectively
                        //  already been performed on the list of records
                    }
                }

                // Note that the facets themselves aren't getting merged together yet...this is because
                //  a general search with multiple facets (e.g. "downs quartz") doesn't necessarily
                //  require a record to have both "downs" and "quartz" in it at the same time...
            }

            if ( $is_top_level ) {
                // ...but once we hit the top-level datatype, everything does need to be merged
                //  together into a single list of records
                $final_dr_list = array();
                if ( $criteria['general']['merge_type'] === 'AND' ) {
                    $final_dr_list = $own_dr_list;
                    foreach ($facet_dr_list['general'] as $facet_num => $dr_list)
                        $final_dr_list = array_intersect_key($final_dr_list, $dr_list);
                }
                else {
                    foreach ($facet_dr_list['general'] as $facet_num => $dr_list) {
                        foreach ($dr_list as $dr_id => $num)
                            $final_dr_list[$dr_id] = $num;
                    }
                }

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
     * @deprecated
     * Recursively cross-references the tree structure of $inflated_list with the values stored in
     * $flattened_list to determine all datarecords (top-level and descendants) that ended up
     * matching the search.
     *
     * The recursion looks a little strange in order to avoid recursing into an empty child array.
     *
     * @param array $flattened_list {@link self::getSearchArrays()}
     * @param array $inflated_list
     * @param integer $target_datatype_id The datatype id to begin saving all descendants of. Mostly
     *                                    required to deal with "inverse" searches, as the top-level
     *                                    of $inflated_list is not the correct starting point
     *
     * @return array
     */
    private function getMatchingDatarecords($flattened_list, $inflated_list, $target_datatype_id)
    {
        $matching_datarecords = array();
        foreach ($inflated_list as $top_level_dt_id => $top_level_datarecords) {
            // If this is a regular search, then this will immediately go to true...but if not, then
            //  need to go digging into the inflated array before finding the correct datatype
            $save_descendants = false;
            if ( $top_level_dt_id === $target_datatype_id )
                $save_descendants = true;

            foreach ($top_level_datarecords as $dr_id => $child_dt_list) {
                // If this top-level datarecord "ended up" "matching the search", then it'll have a
                //  value of (0b0-11) in $flattened_list...self::mergeSearchResults() is responsible
                //  for that
                if ( ($flattened_list[$dr_id] & SearchAPIService::MATCHES_BOTH) === SearchAPIService::MATCHES_BOTH ) {
                    // Only mark this top-level datarecord as matching if this wasn't an inverse
                    //  search
                    if ($save_descendants)
                        $matching_datarecords[$dr_id] = 1;

                    // If the top-level datarecord has any descendant datatypes...
                    if ( is_array($child_dt_list) ) {
                        // ...then they need to be also checked to find which of the descendant
                        //  datarecords matched the search
                        $matching_children = self::getMatchingDatarecords_worker($flattened_list, $child_dt_list, $target_datatype_id, $save_descendants);
                        foreach ($matching_children as $child_dr_id => $num)
                            $matching_datarecords[$child_dr_id] = 1;
                    }
                }
            }
        }

        return $matching_datarecords;
    }


    /**
     * @deprecated
     * Recursively cross-references the tree structure of $inflated_list with the values stored in
     * $flattened_list to determine all datarecords (top-level and descendants) that ended up
     * matching the search.
     *
     * The recursion looks a little strange in order to avoid recursing into an empty child array.
     *
     * @param array $flattened_list {@link self::getSearchArrays()}
     * @param array $dt_list
     * @param integer $target_datatype_id {@link self::getMatchingDatarecords()}
     * @param bool $save_descendants If true, then this datatype and all its descendants could be
     *                               part of the "complete" datarecord list
     *
     * @return array
     */
    private function getMatchingDatarecords_worker($flattened_list, $dt_list, $target_datatype_id, $save_descendants)
    {
        $matching_datarecords = array();
        foreach ($dt_list as $dt_id => $dr_list) {
            // Check whether this childtype should get saved as part of the complete datarecord list
            $save_child_descendants = $save_descendants;
            if ( $dt_id === $target_datatype_id )
                $save_child_descendants = true;

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
                    if ($save_child_descendants)
                        $matching_datarecords[$dr_id] = 1;

                    if ( is_array($child_dt_list) ) {
                        // Continue checking all descendants of this datatype as well
                        $matching_children = self::getMatchingDatarecords_worker($flattened_list, $child_dt_list, $target_datatype_id, $save_child_descendants);
                        foreach ($matching_children as $child_dr_id => $num)
                            $matching_datarecords[$child_dr_id] = 1;
                    }
                }
            }
        }

        return $matching_datarecords;
    }


    /**
     * The search system usually just returns the grandparent datarecord ids...but when running
     * MassEdit or CSVExport, the search system needs to instead return a list that also contains child
     * records that matched the search.  This is non-negotiable, because the search might not match
     * all the descendant records...e.g. samples with 532nm raman spectra...and this makes a
     * difference if you want to MassEdit the raman spectra...
     *
     * @param array $datatree_array {@see DatatreeInfoService::getDatatreeArray()}
     * @param array $graph {@see self::buildSearchDependencyGraph()}
     * @param array $flattened_list {@see self::getSearchArrays()}
     * @param int $top_level_datatype_id
     * @return array The keys of the array are the datarecord ids
     */
    public function getCompleteDatarecordList($datatree_array, $graph, $flattened_list, $top_level_datatype_id)
    {
        // Need to juggle a couple lists of datarecords...first is the complete list of records that
        //  match the search...
        $complete_datarecord_list = array();
        // ...second is the list of datarecords from this top-level datatype that match the search
        $matching_top_level_dr_list = array();

        // $flattened_list is already separated by datatype
        foreach ($flattened_list[$top_level_datatype_id] as $dr_id => $val) {
            // If this top-level datarecord "ended up" "matching the search", then it'll have a
            //  value of (0b0-11) in $flattened_list...
            if ( ($val & SearchAPIService::MATCHES_BOTH) === SearchAPIService::MATCHES_BOTH ) {
                $matching_top_level_dr_list[$dr_id] = 1;
                $complete_datarecord_list[$dr_id] = 1;
            }
        }

        // If the top-level datatype has any descendants...
        if ( !empty($graph[$top_level_datatype_id]) ) {
            // ...then going to need to convert this list of matching top-level records into lists
            //  of records of each descendant datatype
            foreach ($graph[$top_level_datatype_id] as $descendant_dt_id => $num) {
                $matching_descendant_dr_list = self::getCompleteDatarecordList_worker($datatree_array, $graph, $flattened_list, $top_level_datatype_id, $descendant_dt_id, $matching_top_level_dr_list);
                // Each of the records returned needs to be added to the master list
                foreach ($matching_descendant_dr_list as $dr_id => $num)
                    $complete_datarecord_list[$dr_id] = 1;
            }
        }

        return $complete_datarecord_list;
    }


    /**
     * Split off from {@see self::getCompleteDatarecordList()} for readability.
     *
     * @param array $datatree_array {@see DatatreeInfoService::getDatatreeArray()}
     * @param array $graph {@see self::buildSearchDependencyGraph()}
     * @param array $flattened_list {@see self::getSearchArrays()}
     * @param int $ancestor_dt_id The "ancestor" of the current datatype from the perspective of $graph.
     *                            Not necessarily an "ancestor" from the POV of the rendering systems.
     * @param int $current_dt_id The "current" datatype being looked at
     * @param array $ancestor_dr_list The list of ancestor records that matched the search
     * @return array
     */
    private function getCompleteDatarecordList_worker($datatree_array, $graph, $flattened_list, $ancestor_dt_id, $current_dt_id, $ancestor_dr_list)
    {
        // Need to juggle a couple lists of datarecords...first is the complete list of records that
        //  match the search...
        $complete_datarecord_list = array();
        // ...second is the list of datarecords from this specific datatype that match the search
        $current_matching_dr_list = array();

        // ...and third is the "search equivalent" list of datarecords from the ancestor
        $transformed_dr_list = self::transformRecordsFromList($datatree_array, $flattened_list, $ancestor_dt_id, $current_dt_id, $ancestor_dr_list);
        foreach ($transformed_dr_list as $dr_id => $num) {
            // The criteria for a child record to "match the search" is slightly different than that
            //  of a top-level record...primarily, whether the record matched the general search isn't
            //  actually relevant.  Since the top-level record does, any descendant record is assumed
            //  to match as long as it doesn't have a reason to be excluded.

            // The two reasons to exclude a descendant are if the user can't see it (0b1---), but
            //  that has effectively already been enforced by earlier logic
            // The other reason is the descendant doesn't match the advanced search (0b-10-)

            // Therefore, as long as the third bit is a 1 or the second bit is 0, this record
            //  ends up maching the search
            if ( $flattened_list[$current_dt_id][$dr_id] & SearchAPIService::MATCHES_ADV   // second bit set
                || !($flattened_list[$current_dt_id][$dr_id] & SearchAPIService::MUST_MATCH)    // third bit not set
            ) {
                $current_matching_dr_list[$dr_id] = 1;
                $complete_datarecord_list[$dr_id] = 1;
            }
        }

        // If this datatype has any descendants...
        if ( !empty($graph[$current_dt_id]) ) {
            // ...then going to need to convert this datatype's list of matching records into lists
            //  of records of each descendant datatype
            foreach ($graph[$current_dt_id] as $descendant_dt_id => $num) {
                $matching_descendant_dr_list = self::getCompleteDatarecordList_worker($datatree_array, $graph, $flattened_list, $current_dt_id, $descendant_dt_id, $current_matching_dr_list);
                // Each of the records returned needs to be added to the master list
                foreach ($matching_descendant_dr_list as $dr_id => $num)
                    $complete_datarecord_list[$dr_id] = 1;
            }
        }

        return $complete_datarecord_list;
    }


    /**
     * The transforms required to locate the "complete datarecord list" for the purposes of MassEdit
     * or CSVExport are quite similar to {@link self::transformRecordsFromCriteria()}, but the list
     * of records is...better behaved...at the point this functionality gets called.
     *
     * In the future, I might refactor the other function so that its internal complexity is moved
     * into {@link self::performMerge()} and this function effectively is the base for both...but
     * today is not that day.
     *
     * @param array $datatree_array {@see DatatreeInfoService::getDatatreeArray()}
     * @param array $flattened_list {@see self::getSearchArrays()}
     * @param int $source_dt_id
     * @param int $target_dt_id
     * @param array $matching_source_dr_list The list of records to be transformed into $target_dt_id
     * @return array
     */
    private function transformRecordsFromList($datatree_array, $flattened_list, $source_dt_id, $target_dt_id, $matching_source_dr_list)
    {
        // In order to pull off the transform, you need to determine how the records are related to
        //  each other.  ODR has four different possible relations, and there are cache entries for
        //  ODR to use to expedite this...
        $source_is_child_descendant = $source_is_link_descendant = false;
        $target_is_child_descendant = $target_is_link_descendant = false;

        if ( isset($datatree_array['descendant_of'][$source_dt_id])
            && $datatree_array['descendant_of'][$source_dt_id] === $target_dt_id
        ) {
            $source_is_child_descendant = true;
        }
        else if ( isset($datatree_array['descendant_of'][$target_dt_id])
            && $datatree_array['descendant_of'][$target_dt_id] === $source_dt_id
        ) {
            $target_is_child_descendant = true;
        }
        else if ( isset($datatree_array['linked_from'][$source_dt_id])
            && in_array($target_dt_id, $datatree_array['linked_from'][$source_dt_id])
        ) {
            $source_is_link_descendant = true;
        }
        else if ( isset($datatree_array['linked_from'][$target_dt_id])
            && in_array($source_dt_id, $datatree_array['linked_from'][$target_dt_id])
        ) {
            $target_is_link_descendant = true;
        }

        // One of these has to be true to continue
        if ( !($source_is_child_descendant || $target_is_child_descendant || $source_is_link_descendant || $target_is_link_descendant) )
            throw new ODRBadRequestException('source/target are not directly related TODO');


        // ----------------------------------------
        // Most of the cache entries have extra datarecords that aren't relevant to the search
//        $source_dr_list = $flattened_list[$source_dt_id];
        $target_dr_list = $flattened_list[$target_dt_id];

        // The actual transformation depends on the structure of the cache entries...
        $transformed_records = array();
        if ($source_is_child_descendant) {
            // ...this gets an array where <child_dr_id> => <parent_record_id>
            $source_child_to_parent = $this->search_service->getCachedDatarecordList($source_dt_id, false, false);
            // The child records belong to $source_dt_id, and the parent records belong to $target_dt_id

            // For this transformation, need to take the child records...
            foreach ($matching_source_dr_list as $source_dr_id => $num) {
                if ( isset($source_child_to_parent[$source_dr_id])
                    && $flattened_list[$source_dt_id][$source_dr_id] < SearchAPIService::CANT_VIEW
                ) {
                    // If the user can view the child record, then get its parent...
                    $parent_dr_id = $source_child_to_parent[$source_dr_id];
                    // ...and if the user can view the parent...
                    if ( $target_dr_list[$parent_dr_id] < SearchAPIService::CANT_VIEW ) {
                        // ...then save the parent record id
                        $transformed_records[$parent_dr_id] = 1;
                    }
                }
            }
        }
        else if ($target_is_child_descendant) {
            // ...this gets an array where <parent_dr_id> => array(<child_dr_1> => '', <child_dr_2> => '',...)
            $source_parent_to_child = $this->search_service->getCachedDatarecordList($source_dt_id, true, false);
            // The parent records belong to $source_dt_id, but the child records don't necessarily
            //  belong to $target_dt_id...

            // For this transformation, need to take the matching parent records...
            foreach ($matching_source_dr_list as $source_dr_id => $num) {
                if ( !empty($source_parent_to_child[$source_dr_id])
                    && $flattened_list[$source_dt_id][$source_dr_id] < SearchAPIService::CANT_VIEW
                ) {
                    // ...if the parent record has child records, and the user can view the
                    //  parent record...
                    foreach ($source_parent_to_child[$source_dr_id] as $descendant_dr_id => $str) {
                        // ...then determine which child records belong to the target datatype...
                        if ( isset($target_dr_list[$descendant_dr_id])
                            && $target_dr_list[$descendant_dr_id] < SearchAPIService::CANT_VIEW
                        ) {
                            // ...and if the user can also view the child record, then save it
                            $transformed_records[$descendant_dr_id] = 1;
                        }
                    }
                }
            }
        }
        else if ($source_is_link_descendant) {
            // ...this gets an array where <descendant_dr_id> => array(<ancestor_dr_1> => '', <ancestor_dr_2> => '', ...)
            $source_linked_to_by = $this->search_service->getCachedDatarecordList($source_dt_id, false, true);
            // The descendant belongs to $source_dt_id, but the ancestor records don't necessarily
            //  belong to $target_dt_id...

            // For this transformation, need to take the descendant records...
            foreach ($matching_source_dr_list as $source_dr_id => $num) {
                if ( !empty($source_linked_to_by[$source_dr_id])
                    && $flattened_list[$source_dt_id][$source_dr_id] < SearchAPIService::CANT_VIEW
                ) {
                    // If the user can view the descendant record, then check its linked
                    //  ancestors...
                    foreach ($source_linked_to_by[$source_dr_id] as $ancestor_dr_id => $str) {
                        if ( isset($target_dr_list[$ancestor_dr_id])
                            && $target_dr_list[$ancestor_dr_id] < SearchAPIService::CANT_VIEW
                        ) {
                            // ...if the user can view the ancestor record and it also belongs
                            //  to the correct datatype, then save it
                            $transformed_records[$ancestor_dr_id] = 1;
                        }
                    }
                }
            }
        }
        else if ($target_is_link_descendant) {
            // ...this gets an array where <ancestor_dr_id> => array(<descendant_dr_1> => '', <descendant_dr_2> => '', ...)
            $source_links_to = $this->search_service->getCachedDatarecordList($source_dt_id, true, true);

            // The ancestor belongs to $source_dt_id, but the descendant records don't necessarily
            //  belong to $target_dt_id...so for each matching ancestor record...
            foreach ($matching_source_dr_list as $source_dr_id => $num) {
                if ( !empty($source_links_to[$source_dr_id])
                    && $flattened_list[$source_dt_id][$source_dr_id] < SearchAPIService::CANT_VIEW
                ) {
                    // ...and the user can view it, then locate which of its linked descendants
                    //  belong to the target datatype...
                    foreach ($source_links_to[$source_dr_id] as $descendant_dr_id => $str) {
                        if ( isset($target_dr_list[$descendant_dr_id])
                            && $target_dr_list[$descendant_dr_id] < SearchAPIService::CANT_VIEW
                        ) {
                            // ...and save the descendant record if the user can view it
                            $transformed_records[$descendant_dr_id] = 1;
                        }
                    }
                }
            }
        }

        return $transformed_records;
    }
}
