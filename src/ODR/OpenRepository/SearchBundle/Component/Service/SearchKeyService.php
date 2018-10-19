<?php

/**
 * Open Data Repository Data Publisher
 * Search Key Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Holds functions responsible for manipulation/validation of an ODR search key.
 *
 */

namespace ODR\OpenRepository\SearchBundle\Component\Service;

// Services
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
// Other
use Symfony\Bridge\Monolog\Logger;


class SearchKeyService
{

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var Logger
     */
    private $logger;


    // NOTE - don't bother doing anything that requires the PermissionsManagementService in here...
    //  it'll create a circular service reference

    /**
     * SearchKeyService constructor.
     *
     * @param DatatypeInfoService $datatypeInfoService
     * @param SearchService $searchService
     * @param Logger $logger
     */
    public function __construct(
        DatatypeInfoService $datatypeInfoService,
        SearchService $searchService,
        Logger $logger
    ) {
        $this->dti_service = $datatypeInfoService;
        $this->search_service = $searchService;
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
        // Always sort the array to ensure it comes out the same
        ksort($search_params);
        // Encode the search string and strip any padding characters at the end
        $encoded = rtrim(base64_encode(json_encode($search_params)), '=');

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
        $decoded = base64_decode(strtr($search_key, '-_', '+/'));

        // Return an array instead of an object
        $array = json_decode($decoded, true);
        ksort($array);
        if (is_null($array))
            throw new ODRException('Invalid JSON', 400, 0x6e1c96a1);
        else
            return $array;
    }


    /**
     * Converts some form of JSON into an ODR search key...
     * TODO - this is just a placeholder
     *
     * @param string $json
     *
     * @return string
     */
    public function convertJSONtoSearchKey($json)
    {
        // TODO
        $post = json_decode($json);

        $search_params = array();
        foreach ($post as $key => $value) {
            // Ignore empty entries
            if ($value === '')
                continue;

            // Don't care whether the contents of the POST are technically valid or not here
            $search_params[$key] = $value;
        }

        // The important part is to sort by key, so different orderings result in the same search_key...
        ksort($search_params);
        $search_key = self::encodeSearchKey($search_params);

        //
        return $search_key;
    }


    /**
     * Converts ODR's default search page POST into an ODR search key.
     * TODO - modify the default search page to send JSON?
     *
     * @param array $post
     *
     * @return string
     */
    public function convertPOSTtoSearchKey($post)
    {
        $search_params = array();
        foreach ($post as $key => $value) {
            // Ignore empty entries
            if ($value === '')
                continue;

            // Technically don't care whether the contents of the POST are valid or not here
            $search_params[$key] = $value;
        }

        // Important to sort the results, so different input orders result in the same key
        ksort($search_params);
        $search_key = self::encodeSearchKey($search_params);

        //
        return $search_key;
    }

    // TODO - other conversion functions?


    /**
     * Takes a search key and throws an exception if any part of the content is invalid.
     *
     * @param string $search_key
     *
     * @return bool
     * @throws ODRBadRequestException
     */
    public function validateSearchKey($search_key)
    {
        $exception_code = 0x2608d201;
        
        // Want the search key in array format...
        $search_params = self::decodeSearchKey($search_key);

        if ( !isset($search_params['dt_id']) || !is_numeric($search_params['dt_id']) )
            throw new ODRBadRequestException('Invalid search key: missing "dt_id"', $exception_code);
        $dt_id = $search_params['dt_id'];


        $grandparent_datatype_id = $this->dti_service->getGrandparentDatatypeId($dt_id);
        $datatype_array = $this->dti_service->getDatatypeArray($grandparent_datatype_id, true);

        $searchable_datafields = $this->search_service->getSearchableDatafields($dt_id);

        foreach ($search_params as $key => $value) {
            if ( $key === 'dt_id' || $key === 'gen' ) {
                // Nothing to validate
                continue;
            }
            else if ( is_numeric($key) ) {
                // Ensure the datafield is valid to search on
                // 0 - not searchable
                // 1 - searchable only through general search
                // 2 - searchable in both general and advanced search
                // 3 - searchable only in advanced search
                $df_id = intval($key);
                $typeclass = null;
                $found = false;
                foreach ($searchable_datafields as $dt_id => $data) {
                    if ( isset($data['datafields'][$df_id]) ) {
                        // Datafield is public...
                        $found = true;
                        $typeclass = $data['datafields'][$df_id]['typeclass'];
                        break;
                    }
                    else if ( isset($data['datafields']['non_public'][$df_id]) ) {
                        // Datafield is non-public
                        $found = true;
                        $typeclass = $data['datafields']['non_public'][$df_id]['typeclass'];
                        break;
                    }
                }

                if (!$found)
                    throw new ODRBadRequestException('Invalid search key: invalid datafield '.$df_id, $exception_code);

                if ($typeclass === 'Radio') {
                    // Since the datafield was found in $searchable_datafields, it's guaranteed to
                    //  also be in $datatype_array...
                    $available_radio_options = $datatype_array[$dt_id]['dataFields'][$df_id]['radioOptions'];
                    foreach ($available_radio_options as $num => $ro)
                        $available_radio_options[$num] = $ro['id'];
                    $available_radio_options = array_flip($available_radio_options);

                    // Convert the given string into an array of radio option ids...
                    $radio_options = explode(',', $search_params[$df_id]);
                    foreach ($radio_options as $num => $ro_id) {
                        if ( $ro_id{0} === '-' )
                            $ro_id = intval(substr($ro_id, 1));
                        else
                            $ro_id = intval($ro_id);

                        // ...and ensure it's a valid radio option for the given datafield
                        if ( !isset($available_radio_options[$ro_id]) )
                            throw new ODRBadRequestException('Invalid search key: invalid radio option '.$ro_id, $exception_code);
                    }

                }
            }
            else {
                $pieces = explode('_', $key);
                if ( count($pieces) < 2 || count($pieces) > 4 )
                    throw new ODRBadRequestException('Invalid search key: unrecognized parameter "'.$key.'"', $exception_code);

                if ( is_numeric($pieces[0]) && count($pieces) === 2 ) {
                    // $key is for a DatetimeValue...ensure the datafield is valid to search on
                    $df_id = intval($pieces[0]);

                    $found = false;
                    foreach ($searchable_datafields as $dt_id => $data) {
                        if ( isset($data['datafields'][$df_id]) ) {
                            // Datafield is public...
                            $found = true;
                            break;
                        }
                        else if ( isset($data['datafields']['non_public'][$df_id]) ) {
                            // Datafield is non-public
                            $found = true;
                            break;
                        }
                    }

                    if (!$found)
                        throw new ODRBadRequestException('Invalid search key: invalid datafield '.$df_id, $exception_code);

                    // TODO - check that the 'end' date is later than the 'start' date?
                    // Ensure the values are valid datetimes
                    $ret = \DateTime::createFromFormat('Y-m-d', $value);
                    if (!$ret)
                        throw new ODRBadRequestException('Invalid search key: "'.$value.'" is not a valid date', $exception_code);
                }
                else {
                    if ( $pieces[0] !== 'dt' || !is_numeric($pieces[1]) )
                        throw new ODRBadRequestException('Invalid search key: unrecognized parameter "'.$key.'"', $exception_code);

                    // $key should be one of the modified/created/modifiedBy/createdBy/publicStatus entries
                    $dt_id = intval($pieces[1]);
                    if ( !isset($searchable_datafields[$dt_id]) )
                        throw new ODRBadRequestException('Invalid search key: invalid datatype '.$dt_id, $exception_code);


                    if (count($pieces) === 3) {
                        if ($pieces[2] !== 'pub')
                            throw new ODRBadRequestException('Invalid search key: unrecognized parameter "'.$key.'"', $exception_code);
                    }
                    else {
                        if ( $pieces[2] !== 'c' && $pieces[2] !== 'm' )
                            throw new ODRBadRequestException('Invalid search key: unrecognized parameter "'.$key.'"', $exception_code);

                        if ( $pieces[3] === 'e' || $pieces[3] === 's' ) {
                            // TODO - check that the 'end' date is later than the 'start' date?
                            $ret = \DateTime::createFromFormat('Y-m-d', $value);
                            if (!$ret)
                                throw new ODRBadRequestException('Invalid search key: "'.$value.'" is not a valid date', $exception_code);
                        }
                        else if ( $pieces[3] !== 'by' ) {
                            throw new ODRBadRequestException('Invalid search key: unrecognized parameter "'.$key.'"', $exception_code);
                        }
                    }
                }
            }
        }

        // No errors found
        return true;
    }


    /**
     * Converts a search key into an array of searching criteria for use by self::performSearch()
     *
     * $search_params = array(
     *     ['affected_datatypes'] => array(
     *         0 => <datatype_A_id>,
     *         1 => <datatype_B_id>,
     *         ...
     *     ),
     *     ['general'] => array(
     *         'merge_type' = 'OR',
     *         'search_terms' => array(
     *             '<df_id>' => array(
     *                 'value' => ...,
     *                 'entity_type' => 'datafield',
     *                 'entity_id' => <df_id>
     *             ),
     *             ...
     *         )
     *     ),
     *     [<datatype_A_id>] => array(
     *         'merge_type' = 'AND',
     *         'search_terms' => array(
     *             '<df_id>' => array(
     *                 'value' => ...,
     *                 'entity_type' => 'datafield',
     *                 'entity_id' => <df_id>
     *             ),
     *             ...
     *             '<created>' => array(
     *                 'after' => ...,
     *                 'before' => ...,
     *                 'entity_type' => 'datatype',
     *                 'entity_id' => <dt_id>
     *             ),
     *             ...
     *             '<createdBy>' => array(
     *                 'value' => ...,
     *                 'entity_type' => 'datatype',
     *                 'entity_id' => <dt_id>
     *             ),
     *             ...
     *         )
     *     ),
     *     ...
     * )
     *
     * @param string $search_key
     * @param array $searchable_datafields @see self::getSearchableDatafieldsForUser()
     *
     * @return array
     */
    public function convertSearchKeyToCriteria($search_key, $searchable_datafields)
    {
        // Want the search key in array format...
        $search_params = self::decodeSearchKey($search_key);

        $datatype_id = intval($search_params['dt_id']);

        $criteria = array();
        foreach ($search_params as $key => $value) {

            if ($key === 'dt_id') {
                // Don't want to do anything with this key
                continue;
            }
            else if ($key === 'gen') {
                // General search needs to be its own facet
                $criteria['general'] = array(
                    'merge_type' => 'OR',
                    'search_terms' => array()
                );

                // Need to find each datafield that qualifies for general search...
                // 0 - not searchable
                // 1 - searchable only through general search
                // 2 - searchable in both general and advanced search
                // 3 - searchable only in advanced search
                foreach ($searchable_datafields as $dt_id => $df_list) {
                    foreach ($df_list as $df_id => $df_data) {
                        // For general search, both the searchable flag and the typeclass are needed
                        $searchable = $df_data['searchable'];
                        $typeclass = $df_data['typeclass'];

                        if ($searchable == '1' || $searchable == '2') {
                            switch ($typeclass) {
                                case 'Boolean':
                                    // Excluding because a Boolean's value has a different
                                    //  meaning than the other fieldtypes
                                case 'File':
                                case 'Image':
                                    // A general search doesn't make sense for Files/Images
                                    continue;

                                case 'IntegerValue':
                                case 'DecimalValue':
                                case 'ShortVarchar':
                                case 'MediumVarchar':
                                case 'LongVarchar':
                                case 'LongText':
                                case 'DatetimeValue':
                                case 'Radio':
                                    // A general search makes sense for each of these
                                    $criteria['general']['search_terms'][$df_id] = array(
                                        'value' => $value,
                                        'entity_type' => 'datafield',
                                        'entity_id' => $df_id,
                                        'datatype_id' => $dt_id,
                                    );
                                    break;
                            }
                        }
                    }
                }
            }
            else if ( is_numeric($key) ) {
                // This is a datafield's entry...need to find its datatype id
                $dt_id = null;
                $df_id = intval($key);
                $typeclass = null;

                foreach ($searchable_datafields as $dt_id => $df_list) {
                    if ( isset($df_list[$df_id]) ) {
                        $typeclass = $df_list[$df_id]['typeclass'];
                        break;
                    }
                }

                // Every search except for the general search merges by AND
                if ( !isset($criteria[$dt_id]) ) {
                    $criteria[$dt_id] = array(
                        'merge_type' => 'AND',
                        'search_terms' => array()
                    );
                }

                if ($typeclass === 'File' || $typeclass === 'Image') {
                    // Files/Images need to tweak the single given parameter into two...
                    $filename = $value;
                    $has_files = null;
                    if ($value === "\"\"") {
                        $has_files = false;
                        $filename = '';
                    }
                    else if ($value === "!\"\"") {
                        $has_files = true;
                        $filename = '';
                    }

                    // Create an entry in the criteria array for this datafield...there won't be any
                    //  duplicate entries
                    $criteria[$dt_id]['search_terms'][$df_id] = array(
                        'filename' => $filename,
                        'has_files' => $has_files,
                        'entity_type' => 'datafield',
                        'entity_id' => $df_id,
                        'datatype_id' => $dt_id,
                    );
                }
                else if ($typeclass === 'Radio') {
                    // Radio selections are stored by id, separated by commas
                    $radio_options = explode(',', $value);

                    $search_unselected = 0;
                    $selections = array();
                    foreach ($radio_options as $num => $ro) {
                        // Searches for unselected radio options are preceded by a dash
                        $str = 1;
                        if ( $ro{0} === '-' ) {
                            $str = 0;
                            $ro = substr($ro, 1);
                            $search_unselected++;
                        }

                        $selections[$ro] = $str;
                    }

                    // TODO - figure out a way to work with human intuition on this
                    // Default to combining results by OR, unless all radio options being searched
                    //  by the user are unselected...searching for !a || !b || !c makes no sense
                    $combine_by_or = true;
                    if ( count($radio_options) === $search_unselected )
                        $combine_by_or = false;

                    // Create an entry in the criteria array for this datafield...there won't be any
                    //  duplicate entries
                    $criteria[$dt_id]['search_terms'][$df_id] = array(
                        'combine_by_OR' => $combine_by_or,
                        'selections' => $selections,
                        'entity_type' => 'datafield',
                        'entity_id' => $df_id,
                        'datatype_id' => $dt_id,
                    );
                }
                else {
                    // Create an entry in the criteria array for this datafield...there won't be any
                    //  duplicate entries
                    $criteria[$dt_id]['search_terms'][$df_id] = array(
                        'value' => $value,
                        'entity_type' => 'datafield',
                        'entity_id' => $df_id,
                        'datatype_id' => $dt_id,
                    );
                }
            }
            else {
                $pieces = explode('_', $key);

                if ( is_numeric($pieces[0]) && count($pieces) === 2 ) {
                    // This is a DatetimeValue field...need to find the datatype id
                    $dt_id = null;
                    $df_id = intval($pieces[0]);
                    foreach ($searchable_datafields as $dt_id => $df_list) {
                        if ( isset($df_list[$df_id]) )
                            break;
                    }

                    // Every search except for the general search merges by AND
                    if ( !isset($criteria[$dt_id]) ) {
                        $criteria[$dt_id] = array(
                            'merge_type' => 'AND',
                            'search_terms' => array()
                        );
                    }

                    if ( !isset($criteria[$dt_id]['search_terms'][$df_id]) ) {
                        $criteria[$dt_id]['search_terms'][$df_id] = array(
                            'before' => null,
                            'after' => null,
                            'entity_type' => 'datafield',
                            'entity_id' => $df_id,
                            'datatype_id' => $dt_id,
                        );
                    }

                    // $key is a datetime entry
                    if ($pieces[1] === 's') {
                        // start date, aka "after this date"
                        $criteria[$dt_id]['search_terms'][$df_id]['after'] = new \DateTime($value);
                    }
                    else {
                        // end date, aka "before this date"
                        $date_end = new \DateTime($value);

                        $starting_key = $pieces[0].'_s';
                        if ( isset($search_params[$starting_key]) && $search_params[$starting_key] !== '' ) {
                            // When a user selects a start date of...say, 2015-04-26 and an end date
                            //  of 2015-04-28...they're under the assumption that the search will
                            //  return everything between the "26th" and the "28th", inclusive.

                            // However, to actually include results from the "28th", the end date
                            //  needs to be incremented by 1 to 2015-04-29...
                            $date_end->add(new \DateInterval('P1D'));
                        }

                        $criteria[$dt_id]['search_terms'][$df_id]['before'] = $date_end;
                    }
                }
                else {
                    // $key is one of the modified/created/modifiedBy/createdBy/publicStatus entries
                    $dt_id = intval($pieces[1]);

                    // Every search except for the general search merges by AND
                    if (!isset($criteria[$dt_id])) {
                        $criteria[$dt_id] = array(
                            'merge_type' => 'AND',
                            'search_terms' => array()
                        );
                    }

                    if ($pieces[2] === 'pub') {
                        // publicStatus
                        $criteria[$dt_id]['search_terms']['publicStatus'] = array(
                            'value' => $value,
                            'entity_type' => 'datatype',
                            'entity_id' => $dt_id,
                            'datatype_id' => $dt_id,
                        );
                    }
                    else {
                        // created/modified/createdBy/modifiedBy
                        $type = 'created';
                        if ($pieces[2] === 'm')
                            $type = 'modified';

                        if ($pieces[3] === 'by') {
                            // createdBy or modifiedBy
                            $type .= 'By';
                            $criteria[$dt_id]['search_terms'][$type] = array(
                                'value' => $value,
                                'entity_type' => 'datatype',
                                'entity_id' => $dt_id,
                                'datatype_id' => $dt_id,
                            );
                        }
                        else {
                            if (!isset($criteria[$dt_id]['search_terms'][$type])) {
                                $criteria[$dt_id]['search_terms'][$type] = array(
                                    'before' => null,
                                    'after' => null,
                                    'entity_type' => 'datatype',
                                    'entity_id' => $dt_id,
                                    'datatype_id' => $dt_id,
                                );
                            }

                            if ($pieces[3] === 's') {
                                // start date, aka "after this date"
                                $criteria[$dt_id]['search_terms'][$type]['after'] = new \DateTime($value);
                            }
                            else {
                                $date_end = new \DateTime($value);

                                $starting_key = $pieces[0].'_'.$pieces[1].'_'.$pieces[2].'_s';
                                if ( isset($search_params[$starting_key]) && $search_params[$starting_key] !== '' ) {
                                    // When a user selects a start date of...say, 2015-04-26 and an
                                    //  end date of 2015-04-28...they're under the assumption that
                                    //  the search will return everything between the "26th" and the
                                    //  "28th", inclusive.

                                    // However, to actually include results from the "28th", the
                                    //  end date needs to be incremented by 1 to 2015-04-29...
                                    $date_end->add(new \DateInterval('P1D'));
                                }

                                // end date, aka "before this date"
                                $criteria[$dt_id]['search_terms'][$type]['before'] = $date_end;
                            }
                        }
                    }
                }
            }
        }

        // Save the list of datatypes being searched on, not including the ones to be merged by OR
        $affected_datatypes = array();
        foreach ($criteria as $facet) {
            if ($facet['merge_type'] === 'AND') {
                foreach ($facet['search_terms'] as $key => $params) {
                    $dt_id = $params['datatype_id'];
                    $affected_datatypes[$dt_id] = 1;
                }
            }
        }
        $affected_datatypes = array_keys($affected_datatypes);
        $criteria['affected_datatypes'] = $affected_datatypes;

        // Also going to need a list of all datatypes this search could run on, for later hydration
        $criteria['all_datatypes'] = $this->search_service->getRelatedDatatypes($datatype_id);

        return $criteria;
    }
}
