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
use ODR\AdminBundle\Exception\ODRNotImplementedException;
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
     * Converts a Base64-encoded JSON string into an ODR search key...
     *
     * @param string $base64
     *
     * @return string
     */
    public function convertBase64toSearchKey($base64)
    {
        // TODO
        $json = base64_decode($base64);
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
            else if ($key === 'sort_by') {
                if ( !isset($value[0]) )
                    continue;

                // TODO - eventually need multi-datafield sorting
                if ( count($value) > 1 )
                    throw new ODRNotImplementedException('Unable to sort by multiple fields at the moment', $exception_code);

                if ( !isset($value[0]['dir']) )
                    throw new ODRBadRequestException('Invalid search key: "dir" not set in "sort_by" segment', $exception_code);
                if ( !isset($value[0]['df_id']) )
                    throw new ODRBadRequestException('Invalid search key: missing "df_id" inside "sort_by"', $exception_code);

                $sort_dir = $value[0]['dir'];
                $sort_df_id = $value[0]['df_id'];

                if ($sort_dir !== 'asc' && $sort_dir !== 'desc')
                    throw new ODRBadRequestException('Invalid search key: received invalid sort direction "'.$sort_dir.'"', $exception_code);
                if ( !is_numeric($sort_df_id) )
                    throw new ODRBadRequestException('Invalid search key: sort field id "'.$sort_df_id.'" is not numeric', $exception_code);
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

                // Don't need to validate anything related to the other typeclasses in here
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

                    // TODO - provide the option to search for fields without dates?
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
     * @param array $searchable_datafields @see SearchAPIService::getSearchableDatafieldsForUser()
     *
     * @return array
     */
    public function convertSearchKeyToCriteria($search_key, $searchable_datafields)
    {
        // Want the search key in array format...
        $search_params = self::decodeSearchKey($search_key);

        $datatype_id = intval($search_params['dt_id']);

        $criteria = array(
            'search_type' => 'datatype',
            $datatype_id => array(
                'merge_type' => "AND",
                'search_terms' => array(),
            )
        );

        foreach ($search_params as $key => $value) {

            if ($key === 'dt_id') {
                // Don't want to do anything with this key
                continue;
            }
            else if ($key === 'sort_by') {
                $sort_dir = $value[0]['dir'];
                $sort_df_id = $value[0]['df_id'];

                $criteria['sort_by'] = array(
                    'sort_dir' => $sort_dir,
                    'sort_df_id' => $sort_df_id
                );
            }
            else if ($key === 'gen') {
                // Don't do anything if this key is empty
                if ($value === '')
                    continue;

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
                                case 'DatetimeValue':
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
        foreach ($criteria as $key => $facet) {
            if ($key === 'search_type')
                continue;

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


    /**
     * Takes a template search key and throws an exception if any part of the content is invalid.
     *
     * @param string $search_key
     *
     * @return bool
     * @throws ODRBadRequestException
     */
    public function validateTemplateSearchKey($search_key)
    {
        $exception_code = 0x735c298b;

        // Want the search key in array format...
        $search_params = self::decodeSearchKey($search_key);

        if ( !isset($search_params['template_uuid']) )
            throw new ODRBadRequestException('Invalid search key: missing "template_uuid"', $exception_code);
        $pattern = '/^[a-z0-9]{7}$/';
        if ( preg_match($pattern, $search_params['template_uuid']) !== 1 )
            throw new ODRBadRequestException('Invalid search key: "template_uuid" is in wrong format', $exception_code);

        $template_uuid = $search_params['template_uuid'];
        $dt = $this->dti_service->getDatatypeFromUniqueId($template_uuid);
        $dt_id = $dt->getId();

        $grandparent_datatype_id = $this->dti_service->getGrandparentDatatypeId($dt_id);
        $datatype_array = $this->dti_service->getDatatypeArray($grandparent_datatype_id, true);

        // The template search key isn't supposed to know about any datatypes derived from said
        //  template, so this is an acceptable use of this function
        $searchable_datafields = $this->search_service->getSearchableDatafields($dt_id);

        $ignored_keys = array(
            'template_uuid',
            'template_name',
            'field_name',
            'name',
            'general'
        );

        foreach ($search_params as $key => $value) {
            if ( in_array($key, $ignored_keys) ) {
                // Nothing to validate
                continue;
            }
            else if ($key === 'sort_by') {
                if ( !isset($value[0]) )
                    continue;

                // TODO - eventually need multi-datafield sorting
                if ( count($value) > 1 )
                    throw new ODRNotImplementedException('Unable to sort by multiple fields at the moment', $exception_code);

                if ( !isset($value[0]['dir']) )
                    throw new ODRBadRequestException('Invalid search key: "dir" not set in "sort_by" segment', $exception_code);
                if ( !isset($value[0]['template_field_uuid']) )
                    throw new ODRBadRequestException('Invalid search key: missing "template_field_uuid" inside "sort_by"', $exception_code);

                $sort_dir = $value[0]['dir'];
                $sort_df_uuid = $value[0]['template_field_uuid'];

                if ($sort_dir !== 'asc' && $sort_dir !== 'desc')
                    throw new ODRBadRequestException('Invalid search key: received invalid sort direction "'.$sort_dir.'"', $exception_code);
                if ( preg_match($pattern, $sort_df_uuid) !== 1 )
                    throw new ODRBadRequestException('Invalid search key: sort field "'.$sort_df_uuid.'" is not in valid uuid format', $exception_code);
            }
            else if ($key === 'fields') {
                foreach ($value as $num => $search_df) {
                    // Ensure the unique id for this datafield is set...
                    if ( !isset($search_df['template_field_uuid']) )
                        throw new ODRBadRequestException('Invaild search key: missing "template_field_uuid" inside "fields", offset '.$num, $exception_code);

                    // Ensure the unique id refers to a datafield in this datatype...
                    $field_uuid = $search_df['template_field_uuid'];
                    $df_id = null;
                    $typeclass = null;
                    $found = false;

                    foreach ($searchable_datafields as $dt_id => $data) {
                        // Search the public datafields first...
                        $datafields = $data['datafields'];
                        foreach ($datafields as $df_key => $df) {
                            if ($df_key === 'non_public') {
                                continue;
                            }
                            else if ($df['field_uuid'] === $field_uuid) {
                                // Datafield is public...
                                $found = true;
                                $df_id = $df_key;
                                $typeclass = $data['datafields'][$df_key]['typeclass'];
                                break;
                            }
                        }

                        if (!$found) {
                            // ...then search the non-public datafields...
                            $non_public_datafields = $data['datafields']['non_public'];
                            foreach ($non_public_datafields as $df_key => $df) {
                                if ($df['field_uuid'] === $field_uuid) {
                                    // Datafield is non-public...
                                    $found = true;
                                    $df_id = $df_key;
                                    $typeclass = $data['datafields']['non_public'][$df_key]['typeclass'];
                                    break;
                                }
                            }
                        }

                        if ($found)
                            break;
                    }

                    if (!$found)
                        throw new ODRBadRequestException('Invalid search key: invalid datafield '.$field_uuid, $exception_code);

                    if ( isset($search_df['value']) ) {
                        // Verify typeclass first...
                        switch ($typeclass) {
                            case 'Boolean':
                            case 'IntegerValue':
                            case 'DecimalValue':
                            case 'ShortVarchar':
                            case 'MediumVarchar':
                            case 'LongVarchar':
                            case 'LongText':
                                // valid typeclass, continue
                                break;

                            default:
                                throw new ODRBadRequestException('Invalid search key: "value" defined for a "'.$typeclass.'" datafield', $exception_code);
                                break;
                        }

                        // ...but other than that, there's nothing to validate
                    }
                    else if ( isset($search_df['selected_options']) ) {
                        // Radio selections
                        if ($typeclass !== 'Radio')
                            throw new ODRBadRequestException('Invalid search key: "selected_options" defined for a "'.$typeclass.'" datafield, expected a Radio datafield', $exception_code);

                        foreach ($search_df['selected_options'] as $num => $option) {
                            if ( !isset($option['template_radio_option_uuid']) )
                                throw new ODRBadRequestException('Invalid search key: missing key "template_radio_option_uuid" for datafield '.$field_uuid, $exception_code);
                            $option_uuid = $option['template_radio_option_uuid'];

                            // Verify radio option belongs to datafield
                            $found = false;
                            foreach ($datatype_array[$dt_id]['dataFields'][$df_id]['radioOptions'] as $num => $ro) {
                                if ( $option_uuid === $ro['radioOptionUuid'] ) {
                                    $found = true;
                                    break;
                                }
                            }

                            if (!$found)
                                throw new ODRBadRequestException('Invalid search key: radio option "'.$option_uuid.'" does not belong to datafield '.$field_uuid, $exception_code);
                        }
                    }
                    else if ( isset($search_df['before']) ) {
                        if ($typeclass !== 'DatetimeValue')
                            throw new ODRBadRequestException('Invalid search key: "before" defined for a "'.$typeclass.'" datafield, expected a Datetime datafield', $exception_code);

                        // TODO - check that the 'end' date is later than the 'start' date?
                        // Ensure the values are valid datetimes
                        $ret = \DateTime::createFromFormat('Y-m-d', $search_df['before']);
                        if (!$ret)
                            throw new ODRBadRequestException('Invalid search key: "'.$search_df['before'].'" is not a valid date', $exception_code);

                        // TODO - provide the option to search for fields without dates?
                    }
                    else if ( isset($search_df['after']) ) {
                        if ($typeclass !== 'DatetimeValue')
                            throw new ODRBadRequestException('Invalid search key: "after" defined for a "'.$typeclass.'" datafield, expected a Datetime datafield', $exception_code);

                        // TODO - check that the 'end' date is later than the 'start' date?
                        // Ensure the values are valid datetimes
                        $ret = \DateTime::createFromFormat('Y-m-d', $search_df['after']);
                        if (!$ret)
                            throw new ODRBadRequestException('Invalid search key: "'.$search_df['after'].'" is not a valid date', $exception_code);

                        // TODO - provide the option to search for fields without dates?
                    }
                    else if ( isset($search_df['filename']) ) {
                        if ($typeclass !== 'File' && $typeclass !== 'Image' )
                            throw new ODRBadRequestException('Invalid search key: "filename" defined for a "'.$typeclass.'" datafield, expected a File or Image datafield', $exception_code);

                        // Don't need to do anything else
                    }
                    else {
                        //
                        throw new ODRBadRequestException('Invalid search key: no search criteria defined for datafield '.$field_uuid, $exception_code);
                    }
                }
            }
        }

        // No errors found
        return true;
    }


    /**
     * Converts a search key for templates into a format usable by performTemplateSearch()
     *
     * @param string $search_key
     *
     * @return array
     */
    public function convertSearchKeyToTemplateCriteria($search_key)
    {
        // Want the search key in array format...
        $search_params = self::decodeSearchKey($search_key);

        $template_uuid = $search_params['template_uuid'];
        $criteria = array(
            'search_type' => 'template',
            'template_uuid' => $template_uuid,
        );

        foreach ($search_params as $key => $value) {

            if ($key === 'template_uuid') {
                // Don't want to do anything with this key
                continue;
            }
            else if ($key === 'field_stats') {
                // Used by APIController::getfieldstatsAction()...create an abbreviated version of
                //  a general search entry so SearchAPIService::performTemplateSearch() searches
                //  for selected radio options of the given field, but doesn't search anything else
                $criteria['general'] = array(
                    'merge_type' => 'OR',
                    'search_terms' => array(
                        $value => array(
                            'value' => 'any',
                            'entity_type' => 'datafield',
                            'entity_id' => $value,
                            'datatype_id' => $template_uuid
                        )
                    )
                );
            }
            else if ($key === 'sort_by' && isset($value[0]) ) {
                $sort_dir = $value[0]['dir'];
                $sort_df_uuid = $value[0]['template_field_uuid'];

                $criteria['sort_by'] = array(
                    'sort_dir' => $sort_dir,
                    'sort_df_uuid' => $sort_df_uuid
                );
            }
            else if ($key === 'general') {
                // Don't do anything if this key is empty
                if ($value === '')
                    continue;

                // Going to need this array to be able to locate the datafields for a general search
                $searchable_datafields = $this->search_service->getSearchableTemplateDatafields($template_uuid);

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
                foreach ($searchable_datafields as $dt_uuid => $df_list) {
                    foreach ($df_list as $df_uuid => $df_data) {
                        // For general search, both the searchable flag and the typeclass are needed
                        $searchable = $df_data['searchable'];
                        $typeclass = $df_data['typeclass'];

                        if ($searchable == '1' || $searchable == '2') {
                            switch ($typeclass) {
                                case 'Boolean':
                                    // Excluding because a Boolean's value has a different
                                    //  meaning than the other fieldtypes
                                case 'DatetimeValue':
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
                                case 'Radio':
                                    // A general search makes sense for each of these
                                    $criteria['general']['search_terms'][$df_uuid] = array(
                                        'value' => $value,
                                        'entity_type' => 'datafield',
                                        'entity_id' => $df_uuid,
                                        'datatype_id' => $template_uuid,
                                    );
                                    break;
                            }
                        }
                    }
                }
            }
            else if ($key === 'fields') {
                // Only define this facet if something is going to be put into it...
                if ( !isset($criteria[$template_uuid]) ) {
                    $criteria[$template_uuid] = array(
                        'merge_type' => 'AND',    // TODO - combine_by_AND/combine_by_OR
                        'search_terms' => array()
                    );
                }

                // TODO -
                foreach ($value as $num => $df) {
                    $field_uuid = $df['template_field_uuid'];

                    if ( isset($df['selected_options']) ) {
                        // This is a radio datafield
                        $selections = array();
                        foreach ($df['selected_options'] as $num => $ro) {
                            $ro_uuid = $ro['template_radio_option_uuid'];

                            // TODO - search for unselected radio options
                            $selections[$ro_uuid] = 1;

                            // TODO - combine_by_AND/combine_by_OR
                        }

                        $criteria[$template_uuid]['search_terms'][$field_uuid] = array(
                            'combine_by_OR' => true,    // TODO - needs more robust setting
                            'selections' => $selections,
                            'entity_type' => 'datafield',
                            'entity_id' => $field_uuid,
                            'datatype_id' => $template_uuid,
                        );
                    }
                    else if ( isset($df['before']) || isset($df['after']) ) {
                        // Datetime datafields...ensure an entry exists
                        if ( !isset($criteria[$template_uuid]['search_terms'][$field_uuid]) ) {
                            $criteria[$template_uuid]['search_terms'][$field_uuid] = array(
                                'after' => null,
                                'before' => null,
                                'entity_type' => 'datafield',
                                'entity_id' => $field_uuid,
                                'datatype_id' => $template_uuid,
                            );
                        }


                        if ( isset($df['after']) ) {
                            // start date, aka "after this date"
                            $date = new \DateTime($df['after']);
                            $criteria[$template_uuid]['search_terms'][$field_uuid]['after'] = $date;
                        }

                        if ( isset($df['before']) ) {
                            $date = new \DateTime($df['before']);

                            if ( isset($df['after']) ) {
                                // When a user selects a start date of...say, 2015-04-26 and an
                                //  end date of 2015-04-28...they're under the assumption that
                                //  the search will return everything between the "26th" and the
                                //  "28th", inclusive.

                                // However, to actually include results from the "28th", the
                                //  end date needs to be incremented by 1 to 2015-04-29...
                                $date->add(new \DateInterval('P1D'));
                            }

                            // end date, aka "before this date"
                            $criteria[$template_uuid]['search_terms'][$field_uuid]['before'] = $date;
                        }

                    }
                    else if ( isset($df['filename']) ) {
                        // Files/Images need to tweak the single given parameter into two...
                        $filename = $df['filename'];
                        $has_files = null;
                        if ($filename === "\"\"") {
                            $has_files = false;
                            $filename = '';
                        }
                        else if ($filename === "!\"\"") {
                            $has_files = true;
                            $filename = '';
                        }

                        // Create an entry in the criteria array for this datafield...there won't be any
                        //  duplicate entries
                        $criteria[$template_uuid]['search_terms'][$field_uuid] = array(
                            'filename' => $filename,
                            'has_files' => $has_files,
                            'entity_type' => 'datafield',
                            'entity_id' => $field_uuid,
                            'datatype_id' => $template_uuid,
                        );
                    }
                    else if ( isset($df['value']) ) {
                        // All other searchable fieldtypes

                        // Create an entry in the criteria array for this datafield...there won't be
                        //  any duplicate entries
                        $criteria[$template_uuid]['search_terms'][$field_uuid] = array(
                            'value' => $df['value'],
                            'entity_type' => 'datafield',
                            'entity_id' => $field_uuid,
                            'datatype_id' => $template_uuid,
                        );
                    }
                }
            }
        }

        // Also going to need a list of all datatypes this search could run on, for later hydration
        $criteria['all_templates'] = $this->search_service->getRelatedTemplateDatatypes($template_uuid);

        return $criteria;
    }
}
