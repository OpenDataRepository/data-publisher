<?php

/**
 * Open Data Repository Data Publisher
 * Search Cache Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 *
 * Cached search results are structured like so...
 * $cached_searches = array(
 *     [$datatype_id] => array(
 *         [$search_checksum] => array(
 *             'searched_datafields' => array(),
 *             'encoded_search_key' => '',
 *             'complete_datarecord_list' => '',
 *             'datarecord_list' => array(
 *                 'all' => '',
 *                 'public' => ''
 *             )
 *         )
 *     )
 * )
 */

namespace ODR\OpenRepository\SearchBundle\Component\Service;

// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
// Symfony
use Symfony\Bridge\Monolog\Logger;


class SearchCacheService
{

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
     * @param CacheService $cache_service
     * @param Logger $logger
     */
    public function __construct(CacheService $cache_service, Logger $logger)
    {
        $this->cache_service = $cache_service;
        $this->logger = $logger;
    }


    /**
     * Converts an array of search parameters into a url-safe base64 string.
     *
     * @param array $search_params
     *
     * @return string
     */
    public function encodeSearchKey($search_params)
    {
        // Encode the search string and strip any padding characters at the end
        $encoded = rtrim( base64_encode(json_encode($search_params)), '=' );

        // Replace all occurrences of the '+' character with '-', and the '/' character with '_'
        return strtr($encoded, '+/', '-_');
    }


    /**
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
        if ( is_null($array) )
            throw new ODRException('Invalid JSON', 400, 0x6e1c96a1);
        else
            return $array;
    }


    /**
     * Deletes all search results that have been cached for the given datatype.
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
     * Deletes all cached search results that involve the given datafield.
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


}