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
    private $requestStack;

    /**
     * @var TokenGenerator
     */
    private $tokenGenerator;

    /**
     * @var int
     */
    private $default_page_length;


    /**
     * ODRTabHelperService constructor.
     *
     * @param RequestStack $requestStack
     * @param TokenGenerator $tokenGenerator
     */
    public function __construct(
        RequestStack $requestStack,
        TokenGenerator $tokenGenerator
    ) {
        $this->requestStack = $requestStack;
        $this->tokenGenerator = $tokenGenerator;

        $this->default_page_length = 100;
    }


    /**
     * Returns a string suitable for identifying a specific tab in a user's session.
     *
     * @return string
     */
    public function createTabId()
    {
        return substr($this->tokenGenerator->generateToken(), 0, 15);
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
        if ( is_null($tab_data) || !isset($tab_data['page_length']) ) {
            // If for some reason this value doesn't exist, set to the default page_length
            $tab_data = array('page_length' => $this->default_page_length);
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
     * Returns an array with the datafield_id and the sort direction used to create the lists of
     * datarecord ids stored by self::setViewableDatarecordList() and self::setEditableDatarecordList().
     *
     * A datafield id of 0 in the returned array indicates the lists are sorted by datarecord id.
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
     * Saves the sorting criteria that was used to order the list of datarecord ids stored by
     * self::setViewableDatarecordList() and self::setEditableDatarecordList().
     *
     * A datafield id of 0 should be used when the lists are sorted by datarecord id.
     *
     * @param string $odr_tab_id
     * @param int $datafield_id
     * @param string $sort_direction 'ASC'|'DESC'
     *
     * @throws ODRException
     *
     * @return true if updated, false otherwise
     */
    public function setSortCriteria($odr_tab_id, $datafield_id, $sort_direction)
    {
        if ($sort_direction !== 'ASC' && $sort_direction !== 'DESC')
            throw new ODRBadRequestException('$sort_direction must be "ASC" or "DESC"', 0xebcca9ca);

        // Check that the requested tab exists in the user's session
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) )
            return false;

        // TODO - create a new entry for the tab if one doesn't exist?

        // Store the resulting tab data
        $tab_data['sort_criteria'] = array(
            'datafield_id' => $datafield_id,
            'sort_direction' => $sort_direction,
        );
        self::setTabData($odr_tab_id, $tab_data);

        return true;
    }


    /**
     * Returns whether the sort criteria stored in the current tab is different from the given
     * parameters.  Returns true when sort criteria doesn't exist in the first place.
     *
     * @param string $odr_tab_id
     * @param int $datafield_id
     * @param string $sort_direction
     *
     * @throws ODRException
     *
     * @return true if different, false otherwise
     */
    public function hasSortCriteriaChanged($odr_tab_id, $datafield_id, $sort_direction)
    {
        if ($sort_direction !== 'ASC' && $sort_direction !== 'DESC')
            throw new ODRBadRequestException('$sort_direction must be "ASC" or "DESC"', 0x5ab8c2d1);

        // If the requested tab does not exist in the user's session, then the given parameters
        //  are different by default
        $tab_data = self::getTabData($odr_tab_id);
        if ( is_null($tab_data) )
            return true;

        if ( !isset($tab_data['sort_criteria']) )
            return true;


        $stored_df_id = $tab_data['sort_criteria']['datafield_id'];
        $stored_sort_dir = $tab_data['sort_criteria']['sort_direction'];

        if ( intval($stored_df_id) !== intval($datafield_id)
            || $stored_sort_dir !== $sort_direction
        ) {
            return true;
        }

        // Otherwise, no difference
        return false;
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
        $request = $this->requestStack->getCurrentRequest();
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
       $request = $this->requestStack->getCurrentRequest();
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
