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
        DatatypeInfoService $datatype_info_service,
        SearchService $search_service,
        Logger $logger
    ) {
        $this->dti_service = $datatype_info_service;
        $this->search_service = $search_service;
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

        // TODO Figure out why this change was breaking
        /*
        $search_params = array();
        foreach ($post as $key => $value) {
            // Ignore empty entries
            $value = trim($value);
            if ($value === '')
                continue;

            // Don't care whether the contents of the POST are technically valid or not here
            $search_params[$key] = self::clean($value);
        }

        // The important part is to sort by key, so different orderings result in the same search_key...
        ksort($search_params);
        $search_key = self::encodeSearchKey($search_params);

        //
        return $search_key;
        */
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
            $value = trim($value);
            if ($value === '')
                continue;

            // Technically don't care whether the contents of the POST are valid or not here
            $search_params[$key] = self::clean($value);
        }

        // Important to sort the results, so different input orders result in the same key
        ksort($search_params);
        $search_key = self::encodeSearchKey($search_params);

        //
        return $search_key;
    }

    // TODO - other conversion functions?

    /**
     * Strips newlines and extra spaces from search parameters, and also replaces several multibyte
     * character sequences with ascii equivalents.  Expects a trimmed string as input.
     *
     * @param string $str
     *
     * @return string
     */
    private function clean($str)
    {
        // Replace newlines with empty strings, and the unicode "smart quotes" (U+201C and U+201D)
        //  with a regular doublequote (U+0022)
        $str = str_replace(array("\n", "\r", "“", "”"), array('','','"', '"'), $str);

        // Read through the string, replacing sequences of multiple spaces with at most one space
        $cleaned = '';
        $prev_char = '';
        $in_quote = false;

        $len = mb_strlen($str);
        for ($i = 0; $i < $len; $i++) {
            // Need to use multibyte substr to ensure multibyte characters don't get mangled
            $char = mb_substr($str, $i, 1);

            switch ($char) {
                case "\"":
                    if ( $in_quote ) {
                        // Found ending quote
                        $in_quote = false;
                    }
                    else {
                        // Found starting quote
                        $in_quote = true;
                    }

                    // Always transcribe this character
                    $cleaned .= $char;
                    break;
                case " ":
                    // Always want to save this space if in quotes...if not in quotes, then only save
                    //  when the previous character wasn't a space
                    if ($in_quote || $prev_char !== " ") {
                        $cleaned .= $char;
                    }
                    break;
                default:
                    // Some other character, save it
                    $cleaned .= $char;
                    break;
            }

            $prev_char = $char;
        }

        // If the quotation marks are unmatched, add one to the end of the string...
        if ( $in_quote )
            $cleaned .= '"';

        return $cleaned;
    }


    /**
     * Splits a string that has been run through clean() into an array of terms that were separated
     * by logical operators.  e.g.
     * "Gold" => array("Gold")
     * "Gold OR Silver" => array("Gold", "||", "Silver")
     * "Gold || Silver" => array("Gold", "||", "Silver")
     * "Gold Silver" => array("Gold", "&&", "Silver")
     * "Gold Silver OR Quartz" => array("Gold", "&&", "Silver", "||", "Quartz")
     *
     * Strings that have been run through clean() but aren't entirely logical aren't "fixed"...e.g.
     * "Gold OR OR Quartz" => array("Gold", "||", "OR", "&&", "Quartz")
     *
     * The current implementation is ONLY useful for the current version of general search...in the
     * future this will need to get completely rewritten to return an expression tree instead.
     *
     * @param string $str
     *
     * @return array
     */
    private function tokenize($str)
    {
        $tokens = array();
        $token = '';
        $prev_token = '';
        $in_quote = false;

        // The UTF-8 sequences with special meaning to ODR have already been converted into ascii,
        //  so the string no longer needs special UTF-8 treatment
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $char = $str{$i};

            switch ($char) {
                case "\"":
                    if ( $in_quote ) {
                        // Found ending quote
                        $in_quote = false;

                        $token .= "\"";
                        $tokens[] = $token;

                        // Reset for next potential token
                        $prev_token = $token;
                        $token = '';
                    }
                    else {
                        // Found starting quote
                        $in_quote = true;
                        $token = "\"";
                    }
                    break;
                case " ":
                    if ($in_quote) {
                        // Always want to save this space if in quotes...
                        $token .= $char;
                    }
                    else {
                        // Otherwise, it indicates an AND operator...save the existing string
                        $tokens[] = $token;

                        // Insert an AND token here
                        $tokens[] = '&&';

                        // Reset for next potential token
                        $prev_token = '&&';
                        $token = '';
                    }
                    break;
                case 'o':
                case 'O':
                    if ($in_quote) {
                        // No special meaning if inside quotes
                        $token .= $char;
                    }
                    else {
                        // OR operators are only valid if the parser thought the last token was an
                        //  AND operator and there's a space after the "OR"...
                        if ( $prev_token === '&&' && ($i+2) < $len ) {
                            // Determine whether this is an OR operator or not...
                            $second_char = $str{$i+1};
                            $third_char = $str{$i+2};

                            if ( ($second_char === 'r' || $second_char === 'R') && $third_char === ' ' ) {
                                // This is an OR operator...replace the previous token with this one
                                array_pop($tokens);
                                $tokens[] = '||';
                                $prev_token = '||';

                                // Skip over the rest of this operator
                                $i += 2;
                            }
                            else {
                                // ...not an OR operator, treat it as a regular character
                                $token .= $char;
                            }
                        }
                        else {
                            // ...not an OR operator, treat it as a regular character
                            $token .= $char;
                        }
                    }
                    break;
                case '|':
                    if ($in_quote) {
                        // No special meaning if inside quotes
                        $token .= $char;
                    }
                    else {
                        // OR operators are only valid if the parser thought the last token was an
                        //  AND operator and there's a space after the "||"...
                        if ( $prev_token === '&&' && ($i+2) < $len ) {
                            // Determine whether this is an OR operator or not...
                            $second_char = $str{$i+1};
                            $third_char = $str{$i+2};

                            if ( $second_char === '|' && $third_char === ' ' ) {
                                // This is an OR operator...replace the previous token with this one
                                array_pop($tokens);
                                $tokens[] = '||';
                                $prev_token = '||';

                                // Skip over the rest of this operator
                                $i += 2;
                            }
                            else {
                                // ...not an OR operator, treat it as a regular character
                                $token .= $char;
                            }
                        }
                        else {
                            // ...not an OR operator, treat it as a regular character
                            $token .= $char;
                        }
                    }
                    break;
                default:
                    // Some other character, save it
                    $token .= $char;
                    break;
            }
        }

        if ( $token !== '' ) {
            // Store the last token when the string ends
            $tokens[] = $token;
        }

        return $tokens;
    }


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
                // TODO - eventually need sort by created/modified date
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
                else if ($typeclass === 'Tag') {
                    // Since the datafield was found in $searchable_datafields, it's guaranteed to
                    //  also be in $datatype_array...
                    $stacked_tags = $datatype_array[$dt_id]['dataFields'][$df_id]['tags'];
                    $tag_tree = $datatype_array[$dt_id]['dataFields'][$df_id]['tagTree'];

                    // Only need tag ids to verify that the requested tag belongs to the given
                    //  datafield...so can kinda cheat and build up a list from these arrays
                    $available_tags = array();

                    // This loop gets all top-level tags...
                    foreach ($stacked_tags as $tag_id => $tag)
                        $available_tags[$tag_id] = 1;

                    // ...and need to add both children and parent ids incase the parent is a
                    //  mid-level tag of some sort
                    foreach ($tag_tree as $parent_tag_id => $children) {
                        foreach ($children as $child_tag_id => $tmp) {
                            $available_tags[$child_tag_id] = 1;
                            $available_tags[$parent_tag_id] = 1;
                        }
                    }


                    // Convert the given string into an array of tag ids...
                    $tags = explode(',', $search_params[$df_id]);
                    foreach ($tags as $num => $t_id) {
                        if ( $t_id{0} === '-' )
                            $t_id = intval(substr($t_id, 1));
                        else
                            $t_id = intval($t_id);

                        // ...and ensure it's a valid tag for the given datafield
                        if ( !isset($available_tags[$t_id]) )
                            throw new ODRBadRequestException('Invalid search key: invalid tag '.$t_id, $exception_code);
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
                        else if ( $pieces[3] === 'by' && !is_numeric($value) ) {
                            throw new ODRBadRequestException('Invalid search key: "'.$value.'" is not a valid user id', $exception_code);
                        }
                        else if ( $pieces[3] !== 'by') {
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
                'facet_type' => 'single',
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

                /**
                 * So general search is technically a shorthand...a general search for "Gold" needs
                 * to find all records where at least one of the searchable fields contains "Gold"
                 * e.g. (field_1 = Gold OR field_2 = Gold OR field_3 = Gold OR ...)
                 *
                 * A general search for "Gold OR Quartz" needs to find all records where at least
                 * one of the searchable fields contains "Gold", OR one of the searchable fields
                 * contains "Quartz"  e.g.
                 * (field_1 = "Gold" OR field_2 = "Gold" OR field_3 = "Gold" OR ...)
                 * OR
                 * (field_1 = "Quartz" OR field_2 = "Quartz" OR field_3 = "Quartz" OR ...)
                 *
                 * Because ORs are associative and commutative, the above is equvalent to
                 * (field_1 = "Gold OR Quartz" OR field_2 = "Gold OR Quartz" OR ...)
                 *
                 *
                 * However, a general search for "Gold AND Quartz" needs to find all records where
                 * at least one of the searchable fields contains "Gold", AND at least one field
                 * IN THE SAME RECORD contains "Quartz"  e.g.
                 * (field_1 = "Gold" OR field_2 = "Gold" ...) AND (field_1 = "Quartz" OR field_2 = "Quartz" ...)
                 *
                 * The above query is already fully simplified, and CAN NOT be simplified further.
                 * Attempting to distribute the search terms creates an exponentional increase in
                 * the amount of work that searching has to do to return the correct result.
                 */

                // Attempt to split the general search string into tokens
                $tokens = self::tokenize($value);

                /**
                 * The existing implementation of general search can't deal with search queries that
                 * combine both OR and AND...due to ODR's lack of grouping operators, you're stuck
                 * with writing ambiguous queries like "Gold OR Silver AND Quartz"...which due to the
                 * relative precedence of the operators actually means "Gold OR (Silver AND Quartz)"
                 *
                 * Unfortunately, ODR needs to have ANDs on the top level for query result merging
                 * to return the correct answer...so the above would need to get wrapped into a
                 * multi-level expression structure...which causes a cascade of problems down the
                 * line.
                 *
                 * Rather than undertake a prohibitive amount of work to implement this correctly,
                 * I'm assuming this is going to be rare enough to just throw an Exception for now.
                 */
                $has_or = $has_and = false;
                foreach ($tokens as $token) {
                    if ( $token === '||' )
                        $has_or = true;
                    else if ( $token === '&&' )
                        $has_and = true;
                }
                if ( $has_or && $has_and )
                    throw new ODRNotImplementedException("Unable to correctly perform a General Search that combines both OR and AND conditions");

                // Do stuff to the list of tokens so it's in a format useful for general search
                if ( isset($tokens[1]) ) {
                    if ( $tokens[1] === '||' ) {
                        // Since all the tokens are connected by ORs, they can all get merged back
                        //  into a single string (see above for reasoning)
                        $tokens = array(0 => implode(' ', $tokens));
                    }
                    else {
                        // Since all the tokens are connected by ANDs, the tokens can be turned
                        //  directly into separate facets for searching purposes
                        foreach ($tokens as $token_num => $token) {
                            // Don't want the '&&' tokens in the array, however
                            if ( $token === '&&' )
                                unset( $tokens[$token_num] );
                        }
                        $tokens = array_values($tokens);
                    }
                }


                // ----------------------------------------
                // For each token in the search string...
                foreach ($tokens as $token_num => $token) {
                    // Each token in the search string needs to be its own facet
                    $criteria['general_'.$token_num] = array(
                        'facet_type' => 'general',
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
                            // General search needs both the searchable flag and the typeclass
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
                                        // A general search doesn't make sense for these fieldtypes,
                                        //  so don't create a criteria entry to be searched on
                                        break;

                                    case 'IntegerValue':
                                    case 'DecimalValue':
                                    case 'ShortVarchar':
                                    case 'MediumVarchar':
                                    case 'LongVarchar':
                                    case 'LongText':
                                    case 'Radio':
                                    case 'Tag':
                                        // A general search makes sense for each of these
                                        $criteria['general_'.$token_num]['search_terms'][$df_id] = array(
                                            'value' => $token,
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
                        'facet_type' => 'single',
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
                else if ($typeclass === 'Radio' || $typeclass === 'Tag') {
                    // Radio selections and Tags are stored by id, separated by commas
                    $items = explode(',', $value);

                    $selections = array();
                    foreach ($items as $num => $item) {
                        // Searches for unselected radio options or tags are preceded by a dash
                        if ( $item{0} === '-' ) {
                            $item = substr($item, 1);
                            $selections[$item] = 0;
                        }
                        else {
                            $selections[$item] = 1;
                        };
                    }

                    // Whether to combine the selected options/tags by AND or OR...unselected
                    //  options/tags are always combined by AND
                    // TODO - let users change this
                    $combine_by_or = true;


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
                            'facet_type' => 'single',
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
                            'facet_type' => 'single',
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
                                'user' => intval($value),
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

        // Save the list of datatypes being searched on, not including the ones to be merged_by_OR
        // All datarecords belonging to datatypes contained in $affected_datatypes will be initially
        //  marked as -1 (does not match), and therefore must m
        $affected_datatypes = array();
        foreach ($criteria as $key => $facet) {
            if ($key === 'search_type')
                continue;

            // Datafields being searched via general search can't be marked as "-1" (needs to match)
            //  to begin with...doing so will typically cause child datatypes that are also searched
            //  to "not match", and therefore exclude their parents from the search results.
            // The final merge still works when the datarecords with the affected datafields start
            //  out with a value of "0" (doesn't matter)
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
        $pattern = '/^[a-z0-9]+$/';
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
                // TODO - eventually need sort by created/modified date
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
                    else if ( isset($search_df['selected_tags']) ) {
                        // Tags
                        if ($typeclass !== 'Tag')
                            throw new ODRBadRequestException('Invalid search key: "selected_tags" defined for a "'.$typeclass.'" datafield, expected a Tag datafield', $exception_code);

                        $search_tags = array();
                        foreach ($search_df['selected_tags'] as $num => $option) {
                            if ( !isset($option['template_tag_uuid']) )
                                throw new ODRBadRequestException('Invalid search key: missing key "template_tag_uuid" for datafield '.$field_uuid, $exception_code);

                            // Store the tags being search on in a more convenient format...
                            $search_tags[ $option['template_tag_uuid'] ] = false;
                        }

                        // Verify all the tags being searched on belong to the datafield
                        $stacked_tags = $datatype_array[$dt_id]['dataFields'][$df_id]['tags'];
                        self::findTagUuid($stacked_tags, $search_tags);

                        foreach ($search_tags as $tag_uuid => $found) {
                            if (!$found)
                                throw new ODRBadRequestException('Invalid search key: tag "'.$tag_uuid.'" does not belong to datafield '.$field_uuid, $exception_code);
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
     * Attempts to find all the tag uuids from $search_tags inside $stacked_tags...if it does exist,
     * then that tag's value in $search_tags is set to true.
     *
     * @param array $stacked_tags
     * @param array $search_tags
     */
    private function findTagUuid($stacked_tags, &$search_tags)
    {
        // Optimistically assume users are going to have higher-level tags in here, so do a
        //  breadth-first search instead of a depth-first search
        $tags_to_process = $stacked_tags;
        while ( !empty($tags_to_process) ) {
            $tmp = $tags_to_process;
            $tags_to_process = array();

            foreach ($tmp as $tag_id => $tag) {
                $tag_uuid = $tag['tagUuid'];
                if ( isset( $search_tags[$tag_uuid]) ) {
                    // Mark the tag as found
                    $search_tags[$tag_uuid] = true;
                }

                if ( isset($tag['children']) ) {
                    // Tag has children, get those processed next iteration
                    foreach ($tag['children'] as $child_tag_id => $child_tag)
                        $tags_to_process[$child_tag_id] = $child_tag;
                }
            }
        }
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
                //  for selected radio options or tags of the given field, but doesn't search for
                //  anything else
                $criteria['field_stats'] = array(
                    'merge_type' => 'OR',
                    'search_terms' => array(
                        $value => array(
//                            'value' => '!""',    // Don't need an explicit value right now...
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
                    'facet_type' => 'general',
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
                                    // A general search doesn't make sense for Files/Images/Datetime
                                    //  fields...don't create a criteria entry to be searched on
                                    break;

                                case 'IntegerValue':
                                case 'DecimalValue':
                                case 'ShortVarchar':
                                case 'MediumVarchar':
                                case 'LongVarchar':
                                case 'LongText':
                                case 'Radio':
                                case 'Tag':
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
                if ( count($search_params['fields']) === 0 )
                    continue;

                if ( !isset($criteria[$template_uuid]) ) {
                    $criteria[$template_uuid] = array(
                        'facet_type' => 'single',
                        'merge_type' => 'AND',    // TODO - combine_by_AND/combine_by_OR
                        'search_terms' => array()
                    );
                }

                foreach ($value as $num => $df) {
                    $field_uuid = $df['template_field_uuid'];

                    if ( isset($df['selected_options']) ) {
                        // This is a radio datafield
                        $selections = array();
                        foreach ($df['selected_options'] as $num => $ro) {
                            $ro_uuid = $ro['template_radio_option_uuid'];

                            // TODO - search for unselected radio options
                            $selections[$ro_uuid] = 1;
                        }

                        // Whether to combine the selected options by AND or OR...unselected
                        //  options are always combined by AND
                        // TODO - let users change this
                        $combine_by_or = true;


                        $criteria[$template_uuid]['search_terms'][$field_uuid] = array(
                            'combine_by_OR' => $combine_by_or,
                            'selections' => $selections,
                            'entity_type' => 'datafield',
                            'entity_id' => $field_uuid,
                            'datatype_id' => $template_uuid,
                        );
                    }
                    else if ( isset($df['selected_tags']) ) {
                        // This is a tag datafield
                        $selections = array();
                        foreach ($df['selected_tags'] as $num => $tag) {
                            $tag_uuid = $tag['template_tag_uuid'];

                            // TODO - search for unselected tags?
                            $selections[$tag_uuid] = 1;
                        }

                        // Whether to combine the selected tags by AND or OR...unselected tags are
                        //  always combined by AND
                        // TODO - let users change this
                        $combine_by_or = true;


                        $criteria[$template_uuid]['search_terms'][$field_uuid] = array(
                            'combine_by_OR' => $combine_by_or,
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
