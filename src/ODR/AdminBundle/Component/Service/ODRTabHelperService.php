<?php

/**
 * Open Data Repository Data Publisher
 * ODR Tab Helper Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Provides functions to maintain lists of sorted datarecords for the search results page, as well
 * as the next/prev/return to search results functionality on View and Edit pages.
 */

namespace ODR\AdminBundle\Component\Service;

// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
// Other
use FOS\UserBundle\Util\TokenGenerator;
use Symfony\Component\HttpFoundation\RequestStack;


class ODRTabHelperService
{

    /**
     * @var RequestStack
     */
    private $request_stack;

    /**
     * @var TokenGenerator
     */
    private $token_generator;

    /**
     * @var int
     */
    private $default_page_length;


    /**
     * ODRTabHelperService constructor.
     *
     * @param string $default_search_results_limit
     * @param RequestStack $request_stack
     * @param TokenGenerator $token_generator
     */
    public function __construct(
        string $default_search_results_limit,
        RequestStack $request_stack,
        TokenGenerator $token_generator
    ) {
        $this->request_stack = $request_stack;
        $this->token_generator = $token_generator;

        if ( is_numeric($default_search_results_limit) )
            $this->default_page_length = intval($default_search_results_limit);
        else
            $this->default_page_length = 100;
    }


    /**
     * Returns a string suitable for identifying a specific tab in a user's session.
     *
     * @return string
     */
    public function createTabId()
    {
        return substr($this->token_generator->generateToken(), 0, 15);
    }


    /**
     * Returns the search_key this tab is currently displaying.
     *
     * @param string $odr_tab_id
     * @return string|null
     */
    public function getSearchKey($odr_tab_id)
    {
        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) )
            return null;

        // Return the stored search key if it exists
        if ( isset($tab_data['search_key']) )
            return $tab_data['search_key'];
        else
            return null;
    }


    /**
     * Sets the search_key this tab is currently displaying.
     *
     * IMPORTANT: deletes all other data in the session for this tab...needing to change the search
     * key implies the rest of the settings are also out of date.
     *
     * @param string $odr_tab_id
     * @param string $search_key
     * @return bool
     */
    public function setSearchKey($odr_tab_id, $search_key)
    {
        if ( $odr_tab_id == '' || $search_key == '' )
            return false;

        // Overwrite any existing data for this tab with the new search key
        self::setTabData($odr_tab_id, array('search_key' => $search_key));

        return true;
    }


    /**
     * Locates the necessary values and datarecord ids from the tab data stored in the current
     * user's session so ODRAdminBundle:Default:search_header.html.twig can display the correct
     * numbers and the next/prev buttons can redirect to the correct datarecords.  Requires that a
     * datarecord list already be defined for the given tab.
     *
     * @param string $odr_tab_id
     * @param int $current_datarecord_id
     * @param array $datarecord_list
     *
     * @return null|array
     */
    public function getSearchHeaderValues($odr_tab_id, $current_datarecord_id, $datarecord_list)
    {
        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) )
            return null;

        // Extract the required data from the user's session for this browser tab
        $page_length = self::getPageLength($odr_tab_id);

        // Turn the list of datarecord ids that matched search results string into an array
        $search_result_count = count($datarecord_list);

        // Find the desired datarecord id in the array of datarecord ids
        $pos = array_search($current_datarecord_id, $datarecord_list);
        if ($pos === false) {
            // TODO - what to do when datarecord isn't in search results
//            throw new ODRBadRequestException('The datarecord '.$current_datarecord_id.' does not match the current search key', 0xc0f79d1d);
            return null;
        }
        $pos = intval($pos);

        // Locate the datarecord ids that come before/after the desired datarecord
        $search_result_current = $pos+1;

        if ( $pos === $search_result_count-1 )
            // Desired datarecord is at the "end" of the list
            $next_datarecord_id = intval( $datarecord_list[0] );
        else
            $next_datarecord_id = intval( $datarecord_list[$pos+1] );

        if ( $pos === 0 )
            // Desired datarecord is at the "beginning" of the list
            $prev_datarecord_id = intval( $datarecord_list[ $search_result_count-1 ] );
        else
            $prev_datarecord_id = intval( $datarecord_list[$pos-1] );


        // Return the required data
        return array(
            'page_length' => $page_length,
            'next_datarecord_id' => $next_datarecord_id,
            'prev_datarecord_id' => $prev_datarecord_id,
            'search_result_current' => $search_result_current,
            'search_result_count' => $search_result_count
        );
    }


    /**
     * Locates the values required to render ODRAdminBundle:Default:pagination_header.html.twig for
     * a non-table search results theme.  Requires that a datarecord list already be defined for
     * the given tab.
     *
     * @param string $odr_tab_id
     * @param int $offset
     * @param array $datarecord_list
     *
     * @return null|array
     */
    public function getPaginationHeaderValues($odr_tab_id, $offset, $datarecord_list)
    {
        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) )
            return null;

        // Extract the required data from the user's session for this browser tab
        $page_length = self::getPageLength($odr_tab_id);

        // Turn the list of datarecord ids that matched search results string into an array
        $num_datarecords = count($datarecord_list);

        $num_pages = intval( ceil( $num_datarecords / $page_length ) );

        // Ensure $offset is in bounds
        if ($offset === '' || $offset < 1)
            $offset = 1;
        if ( (($offset-1) * $page_length) > $num_datarecords )
            $offset = $num_pages;

        // Return the required data
        return array(
            'num_pages' => $num_pages,
            'num_datarecords' => $num_datarecords,
            'offset' => $offset,
            'page_length' => $page_length,
        );
    }


    /**
     * Returns whatever ODR's default page length for a search results page is.
     *
     * @return int
     */
    public function getDefaultPageLength()
    {
        return $this->default_page_length;
    }


    /**
     * Returns the page length (currently either 10, 25, 50, or 100) for the specified browser tab.
     * If the tab doesn't have a page length value set, then a default value of 100 is set.
     *
     * @param string $odr_tab_id
     *
     * @return null|int
     */
    public function getPageLength($odr_tab_id)
    {
        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) ) {
            // If no data exists for this tab, then create an entry for it
            $tab_data = array();
        }

        if ( !isset($tab_data['page_length']) ) {
            // If the page_length value doesn't exist, set to the default page_length
            $tab_data['page_length'] = $this->default_page_length;
            self::setTabData($odr_tab_id, $tab_data);

            return $this->default_page_length;
        }

        // Otherwise, return the page length for the current tab
        return intval( $tab_data['page_length'] );
    }


    /**
     * Updates the page_length variables in the specified browser tab to have a given page length.
     *
     * @param string $odr_tab_id
     * @param int $page_length
     *
     * @return bool true if data stored, false otherwise
     */
    public function setPageLength($odr_tab_id, $page_length)
    {
        $page_length = intval($page_length);
        if ($odr_tab_id == '')
            return false;

        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) ) {
            // No stored tab data for this user's session...start a new one
            self::setTabData($odr_tab_id, array('page_length' => $page_length));
        }
        else {
            // Set the page_length for this tab, creating an entry if it doesn't exist
            $tab_data['page_length'] = $page_length;

            // Also set the page length in the datatables of tab data, if that exists
            if ( isset($tab_data['state']) && isset($tab_data['state']['length']) )
                $tab_data['state']['length'] = $page_length;

            // Store the resulting tab data
            self::setTabData($odr_tab_id, $tab_data);
        }

        return true;
    }


    /**
     * Using the next/prev buttons on ODRAdminBundle:Default:search_header.html.twig to change
     * which datarecord is currently being viewed or edited can also change which page of search
     * results should be displayed.
     *
     * @param string $odr_tab_id
     * @param int $offset
     *
     * @return bool true if updated, false otherwise
     */
    public function updateDatatablesOffset($odr_tab_id, $offset)
    {
        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) )
            return false;

        if ( is_null($tab_data) || !isset($tab_data['state']) )
            return false;


        // Update which page datatables claims it's on
        $length = intval($tab_data['state']['length']);
        $new_start = (intval($offset) - 1) * $length;

        // Store the resulting tab data
        $tab_data['state']['start'] = strval( $new_start );
        self::setTabData($odr_tab_id, $tab_data);

        return true;
    }


    /**
     * Returns an array with the datafield_ids and the sort direction used to sort the datarecords
     * for this tab, or null if no such criteria has been set.
     *
     * An empty array of datafield ids indicates the lists are sorted by datarecord id.
     *
     * @param string $odr_tab_id
     *
     * @return null|array
     */
    public function getSortCriteria($odr_tab_id)
    {
        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) )
            return null;

        // If the list of datarecord ids doesn't exist...
        if ( !isset($tab_data['sort_criteria']) )
            return null;

        // Otherwise, return the list of datarecord ids
        return $tab_data['sort_criteria'];
    }


    /**
     * Saves the sorting criteria that was used to order the list of datarecord ids for this tab.
     *
     * An empty array of datafield ids should be used when the lists are sorted by datarecord id.
     *
     * @param string $odr_tab_id
     * @param int[] $datafield_ids
     * @param string[] $sort_directions
     *
     * @throws ODRException
     *
     * @return bool true if updated, false otherwise
     */
    public function setSortCriteria($odr_tab_id, $datafield_ids, $sort_directions)
    {
        if ( !empty($sort_directions) ) {
            foreach ($sort_directions as $display_order => $dir) {
                if ( $dir !== 'asc' && $dir !== 'desc' )
                    throw new ODRBadRequestException('sort_direction must be "asc" or "desc"', 0xebcca9ca);
            }
        }

        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) )
            return false;

        if ( !empty($datafield_ids) && !empty($sort_directions) ) {
            // If both datafields and sort directions are specified, then store those
            $tab_data['sort_criteria'] = array(
                'datafield_ids' => $datafield_ids,
                'sort_directions' => $sort_directions,
            );
        }
        else {
            // ...if not, then interpret it as a request to clear any existing sort criteria
            unset( $tab_data['sort_criteria'] );

            // Doing this will typically cause ODR to revert back to any "sort_by" criteria the
            //  search key defines...and if that doesn't exist, then to the default sort order for
            //  the given datatype
        }
        self::setTabData($odr_tab_id, $tab_data);

        return true;
    }


    /**
     * Returns whether the sort criteria stored in the current tab is different from the given
     * parameters.  Returns true when sort criteria doesn't exist in the first place.
     *
     * @param string $odr_tab_id
     * @param int[] $datafield_ids
     * @param string[] $sort_directions
     *
     * @throws ODRException
     *
     * @return bool true if different, false otherwise
     */
    public function hasSortCriteriaChanged($odr_tab_id, $datafield_ids, $sort_directions)
    {
        if ( !empty($sort_directions) ) {
            foreach ($sort_directions as $display_order => $dir) {
                if ( $dir !== 'asc' && $dir !== 'desc' )
                    throw new ODRBadRequestException('sort_direction must be "asc" or "desc"', 0xebcca9ca);
            }
        }

        // If the requested tab does not exist in the user's session, then the given parameters
        //  are different by definition
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) )
            return true;

        if ( !isset($tab_data['sort_criteria']) )
            return true;

        $stored_sort_directions = $tab_data['sort_criteria']['sort_directions'];
        if ( count($sort_directions) !== count($stored_sort_directions) )
            return true;
        foreach ($sort_directions as $display_order => $dir) {
            if ( !isset($stored_sort_directions[$display_order]) || $stored_sort_directions[$display_order] !== $dir )
                return true;
        }

        $stored_df_ids = $tab_data['sort_criteria']['datafield_ids'];
        if ( count($datafield_ids) !== count($stored_df_ids) )
            return true;
        foreach ($datafield_ids as $display_order => $df_id) {
            if ( !isset($stored_df_ids[$display_order]) || $stored_df_ids[$display_order] !== $df_id )
                return true;
        }

        // Otherwise, no difference
        return false;
    }


    /**
     * Returns the contents of the search_results variable for the specified browser tab.
     *
     * @param string $odr_tab_id
     *
     * @return null|array
     */
    public function getSearchResults($odr_tab_id)
    {
        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) || !isset($tab_data['search_results']) ) {
            // If this value doesn't exist, then return null
            return null;
        }

        // Otherwise, return the search_results for the current tab
        return $tab_data['search_results'];
    }


    /**
     * Updates the search_results variable in the specified browser tab to have a given value.
     *
     * @param string $odr_tab_id
     * @param array $search_results
     * @return bool
     */
    public function setSearchResults($odr_tab_id, $search_results)
    {
        if ($odr_tab_id == '')
            return false;

        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) ) {
            // No stored tab data for this user's session...start a new one
            self::setTabData($odr_tab_id, array('search_results' => $search_results));
        }
        else {
            // Set the search_results for this tab, creating an entry if it doesn't exist
            $tab_data['search_results'] = $search_results;

            // Store the resulting tab data
            self::setTabData($odr_tab_id, $tab_data);
        }

        return true;
    }


    /**
     * Deletes the search_results variable from the specified browser tab if it exists.
     *
     * @param string $odr_tab_id
     */
    public function clearSearchResults($odr_tab_id)
    {
        if ($odr_tab_id == '')
            return;

        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) ) {
            // No stored tab data for this user's session...don't need to do anything
            return;
        }
        else if ( isset($tab_data['search_results']) ) {
            // Deletes the search_results for this tab
            unset( $tab_data['search_results'] );

            // Store the resulting tab data
            self::setTabData($odr_tab_id, $tab_data);
        }
    }


    /**
     * Checks whether the requested tab exists in the user's session, returning null if it doesn't.
     *
     * @param string $odr_tab_id
     *
     * @return null|array
     */
    public function getTabData($odr_tab_id)
    {
        // No sense looking for data if no tab id was passed in
        if ($odr_tab_id === '')
            return null;

        // Get the current user's session from the request...
        $request = $this->request_stack->getCurrentRequest();
        $session = $request->getSession();
        if ( !$session->has('stored_tab_data') )
            return null;

        // If there is data stored for this tab, return it
        $stored_tab_data = $session->get('stored_tab_data');
        if ( !isset($stored_tab_data[$odr_tab_id]) )
            return null;

        return $stored_tab_data[$odr_tab_id];
    }


    /**
     * Stores the given array under the given tab id.
     *
     * @param string $odr_tab_id
     * @param array $tab_data
     */
    public function setTabData($odr_tab_id, $tab_data)
    {
       $request = $this->request_stack->getCurrentRequest();
       $session = $request->getSession();

       if ( !$session->has('stored_tab_data') ) {
           // No stored tab data for this user's session...start a new one
           $session->set('stored_tab_data', array($odr_tab_id => $tab_data));
       }
       else {
           $stored_tab_data = $session->get('stored_tab_data');
           $stored_tab_data[$odr_tab_id] = $tab_data;

           $session->set('stored_tab_data', $stored_tab_data);
       }
    }
}
