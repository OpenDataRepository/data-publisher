<?php

/**
 * Open Data Repository Data Publisher
 * Pagination Helper Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Search Results, View, and Edit pages all have pagination that depends on a combination of the
 * given search key and stuff that can be set in the user's session...to reduce duplication of code,
 * that logic is contained here.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataType;
// Exceptions
// Services
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Other
use Symfony\Bridge\Monolog\Logger;

class PaginationHelperService
{

    /**
     * @var ODRTabHelperService
     */
    private $odr_tab_service;

    /**
     * @var SearchKeyService
     */
    private $search_key_service;

    /**
     * @var SearchAPIService
     */
    private $search_api_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * Pagination Helper Service constructor
     *
     * @param ODRTabHelperService $odr_tab_service
     * @param SearchKeyService $search_key_service
     * @param SearchAPIService $search_api_service
     * @param Logger $logger
     */
    public function __construct(
        ODRTabHelperService $odr_tab_service,
        SearchKeyService $search_key_service,
        SearchAPIService $search_api_service,
        Logger $logger
    ) {
        $this->odr_tab_service = $odr_tab_service;
        $this->search_key_service = $search_key_service;
        $this->search_api_service = $search_api_service;

        $this->logger = $logger;
    }


    /**
     * TODO
     *
     * @param string $odr_tab_id
     * @param DataType $datatype
     * @param array $user_permissions {@link PermissionsManagementService::getUserPermissionsArray()}
     * @param string $search_key
     * @param bool $new_search If true, then always run a search with the given search key
     * @return array An array of grandparent datarecord ids that matched the given search key
     */
    public function updateTabSearchCriteria($odr_tab_id, $datatype, $user_permissions, $search_key, $new_search = false)
    {
        // If no search key provided, then nothing to do here
        if ( $search_key === '' )
            return array();

        // Ensure the search key is valid
        $search_params = $this->search_key_service->validateSearchKey($search_key);

        // Ensure the tab refers to the given search key...not just calling setSearchKey() directly
        //  because that intentionally wipes all other criteria stored in the tab
        $expected_search_key = $this->odr_tab_service->getSearchKey($odr_tab_id);
        if ( $expected_search_key !== $search_key )
            $this->odr_tab_service->setSearchKey($odr_tab_id, $search_key, $datatype->getId());


        // Need to ensure a sort criteria is set for this tab, otherwise the table plugin
        //  will display stuff in a different order
        $sort_datafields = array();
        $sort_directions = array();

        $sort_criteria = $this->odr_tab_service->getSortCriteria($odr_tab_id);
        if ( !is_null($sort_criteria) ) {
            // Prefer the criteria from the user's session whenever possible
            $sort_datafields = $sort_criteria['datafield_ids'];
            $sort_directions = $sort_criteria['sort_directions'];
        }
        else if ( isset($search_params['sort_by']) ) {
            // If the user's session doesn't have anything but the search key does, then
            //  use that
            foreach ($search_params['sort_by'] as $display_order => $data) {
                $sort_datafields[$display_order] = intval($data['sort_df_id']);
                $sort_directions[$display_order] = $data['sort_dir'];
            }

            // Store this in the user's session
            $this->odr_tab_service->setSortCriteria($odr_tab_id, $sort_datafields, $sort_directions);
        }
        else {
            // If there's nothing in the user's session or the search key...then get this datatype's
            //  current list of sort fields, and convert it into sort criteria for the user's session
            foreach ($datatype->getSortFields() as $display_order => $df) {
                $sort_datafields[$display_order] = $df->getId();
                $sort_directions[$display_order] = 'asc';
            }
            $this->odr_tab_service->setSortCriteria($odr_tab_id, $sort_datafields, $sort_directions);
        }

        // Ensure the tab has a list of datarecords that matched the search
        $original_datarecord_list = $this->odr_tab_service->getSearchResults($odr_tab_id);
        if ( is_null($original_datarecord_list) || $new_search ) {
            $original_datarecord_list = $this->search_api_service->performSearch(
                $datatype,
                $search_key,
                $user_permissions,
                false,  // only want the grandparent datarecord ids that match the search
                $sort_datafields,
                $sort_directions
            );
            $this->odr_tab_service->setSearchResults($odr_tab_id, $original_datarecord_list);
        }

        return $original_datarecord_list;
    }
}