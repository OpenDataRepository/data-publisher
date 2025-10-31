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

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use FOS\UserBundle\Doctrine\UserManager;
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Utility\UniqueUtility;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class SearchKeyService
{
    /**
     * @var string
     */
    private $search_key_char_limit;

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var DatabaseInfoService
     */
    private $database_info_service;

    /**
     * @var DatatreeInfoService
     */
    private $datatree_info_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var UserManager
     */
    private $user_manager;

    /**
     * @var Logger
     */
    private $logger;


    // NOTE: DO NOT use the PermissionsManagementService in here...it'll create a circular service reference

    /**
     * SearchKeyService constructor.
     *
     * @param string $search_key_char_limit
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param DatabaseInfoService $database_info_service
     * @param DatatreeInfoService $datatree_info_service
     * @param SearchService $search_service
     * @param UserManager $user_manager
     * @param Logger $logger
     */
    public function __construct(
        string $search_key_char_limit,
        EntityManager $entity_manager,
        CacheService $cache_service,
        DatabaseInfoService $database_info_service,
        DatatreeInfoService $datatree_info_service,
        SearchService $search_service,
        UserManager $user_manager,
        Logger $logger
    ) {
        $this->search_key_char_limit = $search_key_char_limit;
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->database_info_service = $database_info_service;
        $this->datatree_info_service = $datatree_info_service;
        $this->search_service = $search_service;
        $this->user_manager = $user_manager;

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
        // If this parameter exists and is in array format...
        if ( isset($search_params['ignore']) && is_array($search_params['ignore']) ) {
            // ...convert it back into a string
            $search_params['ignore'] = implode(',', $search_params['ignore']);
        }

        // Always sort the array to ensure it comes out the same
        ksort($search_params);
        // Encode the search string and strip any padding characters at the end
        $encoded = rtrim(base64_encode(json_encode($search_params)), '=');

        // Replace all occurrences of the '+' character with '-', and the '/' character with '_'
        return strtr($encoded, '+/', '-_');
    }


    /**
     * Utility function to report on whether the given search key is "oversized"...one that exceeds
     * the configured character limit.
     *
     * This limit exists to prevent ODR from giving search keys that run afoul of server settings
     * for maximum length of $_GET parameters.
     *
     * @param string $search_key
     * @return bool
     */
    public function isOversizedSearchKey($search_key)
    {
        // Replace all occurrences of the '-' character with '+', and the '_' character with '/'
        $decoded = base64_decode(strtr($search_key, '-_', '+/'));
        // The base64 string describes a JSON array...
        $array = json_decode($decoded, true);

        if ( is_null($array) ) {
            // ...if it doesn't, then that's an error
            throw new ODRException('Invalid JSON', 400, 0x1570e7da);
        }
        else if ( isset($array['uuid']) ) {
            // If the search params have the 'uuid' entry, then it points to an "oversize" search key
            return true;
        }
        else {
            // Otherwise, it's a "regular" search key
            return false;
        }
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
        // The base64 string describes a JSON array...
        $array = json_decode($decoded, true);

        if ( is_null($array) ) {
            // ...if it doesn't, then that's an error
            throw new ODRException('Invalid JSON', 400, 0x6e1c96a1);
        }
        else if ( isset($array['uuid']) ) {
            // If the search params have the 'uuid' entry, then it points to an "oversize" search key
            $uuid = $array['uuid'];

            // Get the "oversized" search key this uuid points to...
            if ( !$this->cache_service->exists('oversize_searchkey_'.$uuid) )
                throw new ODRNotFoundException('This search key no longer exists', true, 0x6e1c96a1);
            // TODO - need to deal with persisted oversize search keys

            $actual_search_key = $this->cache_service->get('oversize_searchkey_'.$uuid);
            // ...and decode that instead
            $array = self::decodeSearchKey($actual_search_key);
            return $array;
        }
        else {
            // Otherwise, it should be a "regular" search key
            // TODO - should these two modifications be moved?
            if ( isset($array['sort_by']) ) {
                // The values for the "sort_by" key are technically allowed to be an object...
                // e.g. {"dt_id":"3","sort_by":{"sort_df_id":"18","sort_dir":"asc"}}
                // ...but since the rest of ODR would prefer to be able to multi-datafield sort,
                //  the previous format needs to be converted into an array of objects
                // e.g. {"dt_id":"3","sort_by":[{"sort_df_id":"18","sort_dir":"asc"}]}
                $sort_info = $array['sort_by'];
                if ( count($sort_info) === 2 && isset($sort_info['sort_df_id']) && isset($sort_info['sort_dir']) )
                    $array['sort_by'] = array($sort_info);
            }

            // Slightly easier if this parameter is converted into an array...
            if ( isset($array['ignore']) && !is_array($array['ignore']) )
                $array['ignore'] = explode(',', $array['ignore']);

            // Sort the array prior to returning it
            ksort($array);
            return $array;
        }
    }


    /**
     * ODR enables shareable search results links by base64 encoding a JSON array of search parameters
     * and passing that base64 string in the URL...but this makes it possible to exceed defined
     * limits on URL length.
     *
     * To get around this, ODR can also use an alternate base64 encoded JSON array that only contains
     * a UUID. {@link self::decodeSearchKey()} will silently convert this alternate array
     *
     * @param string $oversized_search_key
     * @return string
     */
    public function handleOversizedSearchKey($oversized_search_key)
    {
        $new_search_key = '';
        do {
            // Generate a uuid, and wrap it into a search key for ODR
            $uuid = UniqueUtility::uniqueIdReal(28);
            $tmp = rtrim(base64_encode('{"uuid":"'.$uuid.'"}'), '=');

            // If this uuid isn't already in use...
            if ( !$this->cache_service->exists('oversize_searchkey_'.$uuid) ) {
                // ...then the oversized search key can be successfully stored in redis
                $this->cache_service->set('oversize_searchkey_'.$uuid, $oversized_search_key);
                $new_search_key = $tmp;

                // TODO - need to deal with persisted oversize search keys...but I don't think it should be here
                // TODO - ...SearchAPIService::filterSearchKeyForUser() needs to have "temporary" redirect keys due to permissions permutations anyways
            }
        }
        while ($new_search_key === '');

        // Should perform a safety check here...if the config value 'search_key_char_limit' is too
        //  low, then the newly generated search key could still be considered "too long"
        if ( strlen($new_search_key) > intval($this->search_key_char_limit) )
            throw new ODRException("ODR CONFIGURATION ERROR: symfony parameter 'search_key_char_limit' is set too low to accomodate method for preventing oversized search keys.", 500, 0x8717d0f3);

        return $new_search_key;
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
            $value = trim($value);    // TODO - was this breaking API searches?
            if ($value === '')
                continue;

            // Don't care whether the contents of the POST are technically valid or not here
            $search_params[$key] = self::clean($value);    // TODO - was this breaking API searches?
        }

        // The important part is to sort by key, so different orderings result in the same search_key...
        ksort($search_params);
        $search_key = self::encodeSearchKey($search_params);

        //
        return $search_key;
    }


    /**
     * Converts an array into a search key.
     *
     * @param array $post
     * @param bool $is_wordpress_integrated
     *
     * @return string
     */
    public function convertPOSTtoSearchKey($post, $is_wordpress_integrated)
    {
        $search_params = array();
        foreach ($post as $key => $value) {
            // Ignore empty entries
            $value = trim($value);
            if ($value === '')
                continue;

            // Need to unescape the value if it's coming from a wordpress install...
            if ( $is_wordpress_integrated )
                $value = stripslashes($value);

            // Technically don't care whether the contents of the POST are valid or not at this time
            $search_params[$key] = self::clean($value);
        }

        // Important to sort the results, so different input orders result in the same key
        ksort($search_params);
        $search_key = self::encodeSearchKey($search_params);

        //
        return $search_key;
    }


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

        // Don't add extra doublequotes if they're unmatched...user could be trying to search for
        //  a single doublequote
//        if ( $in_quote )
//            $cleaned .= '"';

        return $cleaned;
    }


    /**
     * Splits a string that has been run through {@link self::clean()} into an array of terms that
     * were separated by logical operators...e.g.
     * <pre>
     * "Gold" => array("Gold")
     * "Gold OR Silver" => array("Gold", "||", "Silver")
     * "Gold || Silver" => array("Gold", "||", "Silver")
     * "Gold Silver" => array("Gold", "&&", "Silver")
     * "Gold Silver OR Quartz" => array("Gold", "&&", "Silver", "||", "Quartz")
     * </pre>
     * This also works on strings that have been run through clean() but aren't entirely "fixed"...e.g.
     * <pre>
     * "Gold OR OR Quartz" => array("Gold", "||", "OR", "&&", "Quartz")
     * </pre>
     *
     * The current implementation is ONLY useful for the current version of general search...in the
     * future this will need to get completely rewritten to return an expression tree instead.
     *
     * {@link self::tokenizeGeneralSearch()} should probably be the only function that calls this.
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
            $char = $str[$i];

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
                case ',':
                    if ($in_quote) {
                        // Always want to save this comma if in quotes...
                        $token .= $char;
                    }
                    else {
                        // Otherwise, it indicates an OR operator...save the existing string
                        $tokens[] = $token;

                        // Insert an OR token here
                        $tokens[] = '||';

                        // Reset for next potential token
                        $prev_token = '||';
                        $token = '';

                        // Due to the search string having already been modified, a string like
                        //  "this,     that"  will have already been converted into  "this, that"
                        $check = $i+1;
                        if ( $check < $len && $str[$i+1] === ' ' ) {
                            // Skip over the space if it exists, since that'll create an extranous
                            //  AND operator
                            $i++;
                        }
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
                        $check = $i + 2;    // need to ensure $str[$i+2] doesn't go out of bounds
                        if ( $i != 0 && $check < $len && $str[$i-1] == ' ' && ($str[$i+1] == 'R' || $str[$i+1] == 'r') && $str[$i+2] == ' ' ) {
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
                    break;
                case '|':
                    if ($in_quote) {
                        // No special meaning if inside quotes
                        $token .= $char;
                    }
                    else {
                        // OR operators are only valid if the parser thought the last token was an
                        //  AND operator and there's a space after the "||"...
                        $check = $i + 2;    // need to ensure $str[$i+2] doesn't go out of bounds
                        if ( $i != 0 && $check < $len && $str[$i-1] == ' ' && $str[$i+1] == '|' && $str[$i+2] == ' ' ) {
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
     * Modifies a search term meant for a general search so the rest of the search system can work
     * correctly with it.
     *
     * @param string $value
     * @return array
     */
    private function tokenizeGeneralSearch($value)
    {
        /**
         * So general search is technically a shorthand...a general search for "Gold" needs
         * to find all records where at least one of the searchable fields contains "Gold"
         * e.g. (field_1 = Gold OR field_2 = Gold OR field_3 = Gold OR ...)
         *
         * However, a general search for "Gold AND Quartz" needs to find all records where
         * at least one of the searchable fields contains "Gold", AND at least one field
         * IN THE SAME RECORD contains "Quartz"  e.g.
         * (field_1 = "Gold" OR field_2 = "Gold" ...) AND (field_1 = "Quartz" OR field_2 = "Quartz" ...)
         *
         * The above query is already fully simplified, and CAN NOT be simplified further.
         * Attempting to distribute the search terms creates an exponentional increase in
         * the amount of work that searching has to do to return the correct result.
         *
         *
         * Furthermore, searches involving negation such as "!Gold" also require special handling...
         * (field_1 = !Gold OR field_2 = !Gold OR ...)  WILL NOT RETURN THE CORRECT RESULT.
         * Instead, it MUST to be the result of !(field_1 = Gold OR field_2 = Gold OR ...).
         * The result of (field_1 = !Gold AND field_2 = !Gold AND ...) WILL NOT WORK either, because
         * of the whole "inability to directly search for the empty string" issue ODR has.
         *
         * Due to the previous two requirements, it's better for the general search to be completely
         * split apart into tokens.  Technically this splitting isn't required when the search term
         * is comprised completely of ORs without any negation, such as "Gold OR Quartz"...but is
         * required again when searching on something such as "!Gold OR Quartz".
         */
        // Attempt to split the general search string into tokens
        $tokens = self::tokenize($value);

        /**
         * The existing implementation of general search can't deal with search queries that
         * combine both OR and AND...I'm not entirely sure there's even a viable generic methodology
         * to handle that.  Furthermore, due to ODR's lack of grouping operators, you would be stuck
         * writing ambiguous queries like "Gold OR Silver AND Quartz".
         *
         * Fortunately, if the user is putting in stuff that the previous parser thinks has both
         * OR and AND, then they're most likely looking for a phrase of some sort...and in that case,
         * it should be (reasonably) acceptable to silently tweak the parser to get closer to what
         * (I assume) they're expecting.  In theory.
         */
        $and_count = $or_count = 0;
        foreach ($tokens as $token) {
            if ( $token === '||' )
                $or_count++;
            else if ( $token === '&&' )
                $and_count++;
        }

        // If there's only one kind of logical connector, then don't need to do anything
        if ( $and_count == 0 || $or_count == 0 ) {
            // Intentionally leaving the logical connectors in the array
            return $tokens;
        }
        else if ( $or_count > $and_count ) {
            // If the string has more ORs than ANDs, then silently replace all the ANDs with ORs
            foreach ($tokens as $num => $token) {
                if ( $token === '&&' )
                    $tokens[$num] = '||';
            }
            return $tokens;
        }
        else {
            // If the string has more ANDs than ORs, or an equal number of both...then silently
            //  replace all ORs with ANDs
            foreach ($tokens as $num => $token) {
                if ( $token === '||' )
                    $tokens[$num] = '&&';
            }
            return $tokens;
        }
    }


    /**
     * Takes a search key and throws an exception if any part of the content is invalid.
     *
     * @param string $search_key
     *
     * @return array
     * @throws ODRBadRequestException
     */
    public function validateSearchKey($search_key)
    {
        $exception_code = 0x2608d201;
        
        // Want the search key in array format...
        $search_params = self::decodeSearchKey($search_key);

        // Search keys should always have the "dt_id" key...
        if ( !isset($search_params['dt_id']) || !is_numeric($search_params['dt_id']) )
            throw new ODRBadRequestException('Invalid search key: missing "dt_id"', $exception_code);
        $datatype_id = $search_params['dt_id'];

        // ...they should not have both "gen" and "gen_lim"..."gen_lim" is a subset of "gen"
        if ( isset($search_params['gen']) && isset($search_params['gen_lim']) )
            throw new ODRBadRequestException('Invalid search key: only allowed to have at most one of "gen" or "gen_lim"', $exception_code);

        // Extract the inverse target datatype, if it exists
        $inverse_target_datatype_id = null;
        if ( isset($search_params['inverse']) ) {
            $inverse_target_datatype_id = intval($search_params['inverse']);

            // values less than 0 disable this feature
            if ( $inverse_target_datatype_id < 0 ) {
                unset( $search_params['inverse'] );
                $inverse_target_datatype_id = null;
            }
        }

        // Going to need the datatype array to verify the given parameters...
        $grandparent_datatype_id = $this->datatree_info_service->getGrandparentDatatypeId($datatype_id);
        $datatype_array = array();
        if ( is_null($inverse_target_datatype_id) )
            $datatype_array = $this->database_info_service->getDatatypeArray($grandparent_datatype_id);
        else
            $datatype_array = $this->database_info_service->getInverseDatatypeArray($grandparent_datatype_id, $inverse_target_datatype_id);

        // ...and the list of searchable datafields
        $searchable_datafields = $this->search_service->getSearchableDatafields($datatype_id, $inverse_target_datatype_id);

        // Want to ignore metadata requests for datatypes without datafields
        $hidden_datatype_ids = array();
        foreach ($searchable_datafields as $dt_id => $dt_data) {
            if ( count($dt_data['datafields']) === 1 && empty($dt_data['datafields']['non_public']) )
                $hidden_datatype_ids[$dt_id] = 1;
        }

        $sortable_typenames = null;

        foreach ($search_params as $key => $value) {
            if ( $key === 'dt_id' || $key === 'gen' || $key === 'gen_lim' || $key === 'inverse' ) {
                // Nothing to validate
                continue;
            }
            else if ($key === 'merge') {
                if ( !($value === 'AND' || $value === 'OR') )
                    throw new ODRBadRequestException('Invalid search key: "merge" should be either "AND" or "OR"', $exception_code);
            }
            else if ($key === 'sort_by') {
                // self::decodeSearchKey() should have "fixed" the parameters to be an array of
                //  objects already...
                // TODO - eventually need sort by created/modified date

                // At this point, the value must be an array
                if ( !isset($value[0]) )
                    throw new ODRBadRequestException('Invalid search key: "sort_by" segment is not properly formed', $exception_code);

                // Iterate over each of the datafields listed as sort keys
                foreach ($value as $num => $sort_criteria) {
                    if ( !(count($sort_criteria) === 2 && isset($sort_criteria['sort_df_id']) && isset($sort_criteria['sort_dir'])) )
                        throw new ODRBadRequestException('Invalid search key: element '.$num.' in "sort_by" segment is not properly formed', $exception_code);

                    // Ensure both the sort direction and the sort datafield are minimally correct...
                    $sort_dir = $sort_criteria['sort_dir'];
                    if ( !($sort_dir === 'asc' || $sort_dir === 'desc') )
                        throw new ODRBadRequestException('Invalid search key: element '.$num.' in "sort_by" segment has invalid sort direction "'.$sort_dir.'"', $exception_code);
                    $sort_df_id = $sort_criteria['sort_df_id'];
                    if ( !is_numeric($sort_df_id) )
                        throw new ODRBadRequestException('Invalid search key: element '.$num.' in "sort_by" segment has non_numeric sort field "'.$sort_df_id.'"', $exception_code);

                    // ...and also probably should ensure the sort datafield is valid at this point
                    $sort_df_typename = null;

                    // First part of this is ensuring the given field is related to the datatype
                    //  being searched...
                    $dt = $datatype_array[$datatype_id];
                    if ( isset($dt['dataFields'][$sort_df_id]) ) {
                        // ...it belongs to the top-level datatype, so it's related by default
                        $df = $dt['dataFields'][$sort_df_id];
                        $sort_df_typename = $df['dataFieldMeta']['fieldType']['typeName'];
                    }
                    else if ( isset($dt['descendants']) ) {
                        // ...otherwise, the datafield is only related if it belongs to a child/linked
                        //  descendant that is only allowed to have a single record
                        $valid_descendants = array();

                        $datatypes_to_check = $dt['descendants'];
                        while ( !empty($datatypes_to_check) ) {
                            $tmp = array();
                            foreach ($datatypes_to_check as $tmp_dt_id => $tmp_dt_data) {
                                if ( $tmp_dt_data['multiple_allowed'] === 0 && isset($datatype_array[$tmp_dt_id]) ) {
                                    // Since this descendant only allows a single record, the top
                                    //  level datatype is allowed to sort by it
                                    $valid_descendants[$tmp_dt_id] = $datatype_array[$tmp_dt_id];

                                    // Should check this datatype's descendants too, if any exist
                                    if ( isset($datatype_array[$tmp_dt_id]['descendants']) ) {
                                        foreach ($datatype_array[$tmp_dt_id]['descendants'] as $descendant_dt_id => $descendant_dt_data)
                                            $tmp[$descendant_dt_id] = $descendant_dt_data;
                                    }
                                }
                            }

                            // Reset for the next loop
                            $datatypes_to_check = $tmp;
                        }

                        // The datafield is valid for sorting if it belongs to any of the datatypes
                        //  found in the previous loop
                        foreach ($valid_descendants as $tmp_dt_id => $tmp_dt) {
                            if ( isset($tmp_dt['dataFields'][$sort_df_id]) ) {
                                // ...it belongs to the top-level datatype, so it's related by default
                                $df = $tmp_dt['dataFields'][$sort_df_id];
                                $sort_df_typename = $df['dataFieldMeta']['fieldType']['typeName'];
                            }
                        }
                    }

                    // If not found, then the datafield isn't valid to use as a sort field
                    if ( is_null($sort_df_typename) )
                        throw new ODRBadRequestException('Invalid search key: element '.$num.' in "sort_by" segment, sort_df_id "'.$sort_df_id.'" is not an acceptable sortfield', $exception_code);

                    // Second part of this is verifying the datafield has a sortable typename
                    if ( is_null($sortable_typenames) ) {
                        $query = $this->em->createQuery(
                           'SELECT ft.typeName
                            FROM ODRAdminBundle:FieldType ft
                            WHERE ft.canBeSortField = 1
                            AND ft.deletedAt IS NULL'
                        );
                        $results = $query->getArrayResult();

                        $sortable_typenames = array();
                        foreach ($results as $result) {
                            $typename = $result['typeName'];
                            $sortable_typenames[$typename] = 1;
                        }
                    }

                    // Technically, this can reveal which typeclass a datafield is even if the user
                    //  isn't allowed to see it, but...that's probably not critical?
                    if ( !isset($sortable_typenames[$sort_df_typename]) )
                        throw new ODRBadRequestException('Invalid search key: element '.$num.' in "sort_by" segment, sort_df_id "'.$sort_df_id.'" cannot be sorted', $exception_code);
                }
            }
            else if ( $key === 'ignore' ) {
                // self::decodeSearchKey() has already exploded this value if it exists
                foreach ($value as $num => $prefix) {
                    $dt_ids = explode('_', $prefix);
                    foreach ($dt_ids as $num => $dt_id) {
                        if ( !isset($datatype_array[$dt_id]) )
                            throw new ODRBadRequestException('Invalid search key: the prefix "'.$prefix.'" refers to a descendant that is not related to this datatype', $exception_code);
                    }
                }
            }
            else if ( is_numeric($key) ) {
                // Ensure the datafield is valid to search on
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
                        if ( $ro_id[0] === '-' || $ro_id[0] === '~' )
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
                        if ( $t_id[0] === '-' || $t_id[0] === '~' )
                            $t_id = intval(substr($t_id, 1));
                        else
                            $t_id = intval($t_id);

                        // ...and ensure it's a valid tag for the given datafield
                        if ( !isset($available_tags[$t_id]) )
                            throw new ODRBadRequestException('Invalid search key: invalid tag '.$t_id, $exception_code);
                    }
                }
                else if ( $typeclass === 'DatetimeValue' ) {
                    if ( isset($search_params['value']) ) {
                        $value = $search_params['value'];
                        if ( $value !== "\"\"" && $value !== "!\"\"" )
                            throw new ODRBadRequestException('Invalid search key: only allowed to search on empty string in Datetime field');
                    }
                }
                else if ( $typeclass === 'XYZData' ) {
                    // This is for an XYZData field...ensure the user hasn't specified more
                    //  dimensions than the field allows
                    $df = $datatype_array[$dt_id]['dataFields'][$df_id];
                    $xyz_column_names = explode(',', $df['dataFieldMeta']['xyz_data_column_names']);

                    $entries = explode('|', $value);
                    foreach ($entries as $entry) {
                        $pieces = explode(',', $entry);
                        if ( count($pieces) !== count($xyz_column_names) )
                            throw new ODRBadRequestException('Invalid search key: column num mismatch for datafield '.$df_id, $exception_code);
                    }
                }

                // Don't need to validate anything related to the other typeclasses in here
            }
            else {
                $pieces = explode('_', $key);
                if ( count($pieces) < 2 || count($pieces) > 4 )
                    throw new ODRBadRequestException('Invalid search key: unrecognized parameter "'.$key.'"', $exception_code);

                if ( is_numeric($pieces[0]) && count($pieces) === 2 ) {
                    // $key is for a DatetimeValue, a File/Image's public status/quality, or a
                    //  simple XYZData search
                    //  ...ensure the datafield is valid to search on
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


                    if ( $pieces[1] === 's' || $pieces[1] === 'e' ) {
                        // This is for a DatetimeValue...ensure the given value is a valid datetime
                        $ret = \DateTime::createFromFormat('Y-m-d', $value);
                        if (!$ret)
                            throw new ODRBadRequestException('Invalid search key: "'.$value.'" is not a valid date', $exception_code);

                        // Also ensure the user isn't trying to search for empty string and a date
                        //  at the same time
                        if ( isset($search_params[$df_id]) )
                            throw new ODRBadRequestException('Invalid search key: Unable to simultaneously search for the empty string and a date in the same field');

                        // TODO - check that the 'end' date is later than the 'start' date?
                    }
                    else if ( $pieces[1] === 'pub' || $pieces[1] === 'qual' ) {
                        // This is for a File/Image...nothing to validate here
                    }
                    else if ( $pieces[1] === 'x' || $pieces[1] === 'y' || $pieces[1] === 'z' ) {
                        // This is for an XYZData field...ensure the user hasn't specified more
                        //  dimensions than the field allows
                        $df = $datatype_array[$dt_id]['dataFields'][$df_id];
                        $xyz_column_names = explode(',', $df['dataFieldMeta']['xyz_data_column_names']);

                        if ( ($pieces[1] === 'y' && count($xyz_column_names) < 2)
                            || ($pieces[1] === 'z' && count($xyz_column_names) < 3)
                        ) {
                            throw new ODRBadRequestException('Invalid search key: column num mismatch for datafield '.$df_id, $exception_code);
                        }
                    }
                    else {
                        throw new ODRBadRequestException('Invalid search key: unrecognized parameter "'.$key.'"', $exception_code);
                    }
                }
                else {
                    if ( $pieces[0] !== 'dt' || !is_numeric($pieces[1]) )
                        throw new ODRBadRequestException('Invalid search key: unrecognized parameter "'.$key.'"', $exception_code);

                    // $key should be one of the modified/created/modifiedBy/createdBy/publicStatus entries
                    $dt_id = intval($pieces[1]);
                    if ( !isset($searchable_datafields[$dt_id]) )
                        throw new ODRBadRequestException('Invalid search key: invalid datatype '.$dt_id, $exception_code);
                    if ( isset($hidden_datatype_ids[$dt_id]) )
                        throw new ODRBadRequestException('Invalid search key: parameter "'.$key.'" is not valid because datatype '.$dt_id.' has no datafields', $exception_code);


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
                        else if ( $pieces[3] !== 'by' ) {
                            throw new ODRBadRequestException('Invalid search key: unrecognized parameter "'.$key.'"', $exception_code);
                        }
                    }
                }
            }
        }

        // No errors found
        return $search_params;
    }


    /**
     * Converts a search key into an array of searching criteria for use by {@link SearchAPIService::performSearch()}
     * <pre>
     * $search_params = array(
     *     'affected_datatypes' => array(    // This has the id of each datatype with a datafield/metadata condition being searched on
     *         0 => <datatype_A_id>,
     *         [1] => <datatype_B_id>,
     *         ...
     *     ),
     *     'all_datatypes' => array(    // This includes the datatype being searched on, and all its child/linked descendants
     *         0 => <datatype_A_id>,
     *         [1] => <datatype_B_id>,
     *         ...
     *     ),
     *     ['general'] => array(    // This only exists when a general search term is defined
     *         'merge_type' = '<AND>' or '<OR>'
     *         0 => array(
     *             'facet_type' => 'general',
     *             'merge_type' = 'OR',
     *             ['negated'] => true    // optional entry, triggers dealing with negations later on
     *             'search_terms' => array(
     *                 '<df_id>' => array(
     *                     'value' => ...,
     *                     'entity_type' => 'datafield',
     *                     'entity_id' => <df_id>,
     *                 ),
     *                 ['<additional datafield ids>'] => array(...),
     *                 ...
     *             )
     *         ),
     *         [1] => array(...),    // Additional facets only exist when general search has multiple tokens
     *         ...
     *     ),
     *     <datatype_A_id> => array(    // These exists even when no search terms are entered...
     *         0 => array(              // ...but the facets don't exist without a search term
     *             'facet_type' => 'single',
     *             'merge_type' = 'AND',
     *             'search_terms' => array(
     *                 '<df_id>' => array(
     *                     'value' => ...,
     *                     'entity_type' => 'datafield',
     *                     'entity_id' => <df_id>
     *                 ),
     *                 ['<additional datafield ids>'] => array(...),
     *                 ['<additional metadata keys such as created, createdBy, etc>'] => array(...),
     *                 ...
     *             )
     *         )
     *     ),
     *     [<datatype_B_id>] => array(...),    // All child/linked datatypes with a searchable datafield have an entry
     *     ...
     * )
     * </pre>
     *
     * NOTE: whether the search key has "gen" or "gen_lim" doesn't change the array's structure, but
     * instead changes which datafields are mentioned inside the facet.
     *
     * @param string $search_key
     * @param array $searchable_datafields {@link SearchAPIService::getSearchableDatafieldsForUser()}
     * @param array $user_permissions
     * @param bool $search_as_super_admin
     *
     * @return array
     */
    public function convertSearchKeyToCriteria($search_key, $searchable_datafields, $user_permissions, $search_as_super_admin = false)
    {
        // Each datatype with a searchable datafield gets its own entry in the criteria array
        $criteria = array(
            'search_type' => 'datatype',
        );
        foreach ($searchable_datafields as $dt_id => $df_list)
            $criteria[$dt_id] = array();


        // Want the search key in array format...
        $search_params = self::decodeSearchKey($search_key);
        $datatype_id = intval($search_params['dt_id']);

        // Extract the inverse target datatype, if it exists
        $inverse_target_datatype_id = null;
        if ( isset($search_params['inverse']) ) {
            $inverse_target_datatype_id = intval($search_params['inverse']);

            // values less than 0 disable this feature
            if ( $inverse_target_datatype_id < 0 ) {
                unset( $search_params['inverse'] );
                $inverse_target_datatype_id = null;
            }
        }

        // It's easier to understand the searching when it works by ensuring results match everything
        //  in the sidebar, but sometimes this needs to be changed...
        $default_merge_type = 'AND';

        foreach ($search_params as $key => $value) {

            if ( $key === 'dt_id' || $key === 'inverse' || $key === 'ignore' ) {
                // Don't want to do anything with these keys
                continue;
            }
            else if ( $key === 'sort_by' ) {
                // Don't want to do anything with this key either...
                continue;

                // ...the reason being that if SearchAPIService::performSearch() directly used this
                //  entry, then any sort_criteria for this tab in the user's session would be ignored
            }
            else if ( $key === 'merge' ) {
                // If set, then this overrides the default merge type used by the search system
                $default_merge_type = $value;
            }
            else if ( $key === 'gen' || $key === 'gen_lim' ) {
                // Don't do anything if this key is empty
                if ($value === '')
                    continue;


                // ----------------------------------------
                // Attempt to split the general search string into tokens
                $tokens = self::tokenizeGeneralSearch($value);

                if ( count($tokens) == 1 ) {
                    // There's only a single search token in the general search...e.g. "Gold"
                    $criteria['general']['merge_type'] = 'AND';

                    // It technically doesn't matter whether this is 'AND' or 'OR' from a boolean
                    //  logic standpoint, because there will never be multiple facets to merge
                    //  together with only one token
                }
                else {
                    // ...otherwise, there are multiple tokens...e.g. "Gold OR Quartz"
                    // Because self::tokenizeGeneralSearch() would've thrown an error if ANDs and ORs
                    //  were mixed, we can just use the second entry in the array
                    if ( $tokens[1] === '||' )
                        $criteria['general']['merge_type'] = 'OR';
                    else
                        $criteria['general']['merge_type'] = 'AND';
                }

                // For each token in the search string...
                foreach ($tokens as $token_num => $token) {
                    // Don't create criteria entries for the logical operators
                    if ( $token === '&&' || $token === '||' )
                        continue;

                    // Need to find each datafield that qualifies for general search...
                    foreach ($searchable_datafields as $dt_id => $df_list) {
                        // Don't create criteria for fields from descendant datatypes if the user
                        //  only wants the top-level datatype
                        if ( $key === 'gen_lim' && $dt_id !== $datatype_id )
                            continue;

                        // After this point, the 'gen_lim' key effectively ceases to exist

                        // Each token in the general search string gets its own facet
                        if ( !isset($criteria['general'][$token_num]) ) {
                            $criteria['general'][$token_num] = array(
                                'facet_type' => 'general',
                                'merge_type' => 'OR',
                                'search_terms' => array()
                            );
                        }

                        // Save a bit of effort later on...
                        if ( substr($token, 0, 1) === '!' )
                            $criteria['general'][$token_num]['negated'] = true;


                        foreach ($df_list as $df_id => $df_data) {
                            // General search needs both the searchable flag and the typeclass
                            $searchable = $df_data['searchable'];
                            $typeclass = $df_data['typeclass'];

                            // In early May 2024, the 'searchable' property got changed from allowing four values
                            //  (NOT_SEARCHED, GENERAL_SEARCH, ADVANCED_SEARCH, and ADVANCED_SEARCH_ONLY) to only
                            //  allowing two values (NOT_SEARCHABLE, SEARCHABLE)

                            // ...because three of the old values map to a single new value, equality
                            //  should only be checked against NOT_SEARCHABLE
                            if ( $searchable !== DataFields::NOT_SEARCHABLE ) {
                                switch ($typeclass) {
                                    case 'Boolean':
                                        // Excluding because a Boolean's value has a different
                                        //  meaning than the other fieldtypes
                                    case 'DatetimeValue':
                                    case 'File':
                                    case 'Image':
                                    case 'XYZData':
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
                                        // A general search makes sense for these fieldtypes
                                        $criteria['general'][$token_num]['search_terms'][$df_id] = array(
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

                // Due to SearchAPIService::getSearchableDatafieldsForUser(), this array only has
                //  datafields the user can view
                foreach ($searchable_datafields as $dt_id => $df_list) {
                    if ( isset($df_list[$df_id]) ) {
                        $typeclass = $df_list[$df_id]['typeclass'];
                        break;
                    }
                }

                // Every search except for the general search merges by AND, and so they can all
                //  go into the same facet...will label it 0 for convenience
                if ( !isset($criteria[$dt_id][0]) ) {
                    $criteria[$dt_id][0] = array(
                        'facet_type' => 'single',
                        'merge_type' => $default_merge_type,
                        'search_terms' => array()
                    );
                }

                if ($typeclass === 'File' || $typeclass === 'Image') {
                    // Create an entry in the criteria array for this datafield...
                    if ( !isset($criteria[$dt_id][0]['search_terms'][$df_id]) ) {
                        $criteria[$dt_id][0]['search_terms'][$df_id] = array(
                            'entity_type' => 'datafield',
                            'entity_id' => $df_id,
                            'datatype_id' => $dt_id,
                        );
                    }

                    // Now that the array is guaranteed to exist, search on the filename
                    $criteria[$dt_id][0]['search_terms'][$df_id]['filename'] = $value;

                    // Need to determine if the user can view non-public files/images...
                    $can_view_file = false;
                    if ( isset($user_permissions['datatypes'][$dt_id]['dr_view'])
                        && isset($user_permissions['datafields'][$df_id]['view'])
                    ) {
                        $can_view_file = true;
                    }
                    if ( $search_as_super_admin )
                        $can_view_file = true;

                    // If they can't view non-public files/images, then silently force their search
                    //  to only work on public files/images while ignoring non-public files/images
                    if ( !$can_view_file )
                        $criteria[$dt_id][0]['search_terms'][$df_id]['public_only'] = 1;

                    // NOTE: this enables slightly different search logic in the backend.  While users
                    //  wouldn't be able to actually see non-public files, they would be able to use
                    //  the results to deduce which records have non-public files without this change
                    //  in logic.  Simply using the existing 'public_status' flag won't handle this.
                    // @see SearchService::searchFileOrImageDatafield()
                }
                else if ($typeclass === 'Radio' || $typeclass === 'Tag') {
                    // Since these fieldtypes can have multiple selected options/tags per search,
                    //  there's a property to control how these selections are merged.  By default,
                    //  ODR merges these by OR, but the "merge_by_AND" datafield property can change
                    //  that to merging by AND instead.

                    // Radio selections and Tags are provided by id, separated by commas
                    $items = explode(',', $value);

                    $selections = array();
                    foreach ($items as $num => $item) {
                        // By default, the options/tags are provided as either "<id>" or "-<id>"...
                        //  "<id>" indicates "selected", and uses the default merge_type for the
                        //  field..."-<id>" indicates "unselected", and is always merged by AND

                        // They can also be provided as "~<id>"...this also indicates "selected",
                        //  but uses the opposite merge_type of whatever the default for the field
                        //  is set to.
                        // The UI will only provide this when the "search_can_request_both_merges"
                        //  flag is active for the field, but the search logic will handle it
                        //  regardless
                        if ( $item[0] === '-' ) {
                            $item = substr($item, 1);
                            $selections[$item] = 0;
                        }
                        else if ( $item[0] === '~' ) {
                            $item = substr($item, 1);
                            $selections[$item] = 2;
                        }
                        else {
                            $selections[$item] = 1;
                        }
                    }

                    // Create an entry in the criteria array for this datafield...there won't be any
                    //  duplicate entries
                    $criteria[$dt_id][0]['search_terms'][$df_id] = array(
                        'selections' => $selections,
                        'entity_type' => 'datafield',
                        'entity_id' => $df_id,
                        'datatype_id' => $dt_id,
                    );
                }
                else {
                    // Create an entry in the criteria array for this datafield...there won't be any
                    //  duplicate entries
                    $criteria[$dt_id][0]['search_terms'][$df_id] = array(
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
                    // This is a DatetimeValue or the public_status/quality for a File/Image field
                    //  ...need to find the datatype id
                    $dt_id = null;
                    $df_id = intval($pieces[0]);
                    foreach ($searchable_datafields as $dt_id => $df_list) {
                        if ( isset($df_list[$df_id]) )
                            break;
                    }

                    // Every search except for the general search currently merges by AND, and so
                    //  they can all go into the same facet...will label it 0 for convenience
                    if ( !isset($criteria[$dt_id][0]) ) {
                        $criteria[$dt_id][0] = array(
                            'facet_type' => 'single',
                            'merge_type' => $default_merge_type,
                            'search_terms' => array()
                        );
                    }

                    if ($pieces[1] === 'pub' || $pieces[1] === 'qual') {
                        // This is a File/Image field
                        if ( !isset($criteria[$dt_id][0]['search_terms'][$df_id]) ) {
                            $criteria[$dt_id][0]['search_terms'][$df_id] = array(
                                'entity_type' => 'datafield',
                                'entity_id' => $df_id,
                                'datatype_id' => $dt_id,
                            );
                        }

                        // Need to determine if the user can view non-public files/images...
                        $can_view_file = false;
                        if ( isset($user_permissions['datatypes'][$dt_id]['dr_view'])
                            && isset($user_permissions['datafields'][$df_id]['view'])
                        ) {
                            $can_view_file = true;
                        }
                        if ( $search_as_super_admin )
                            $can_view_file = true;

                        if ($pieces[1] === 'pub') {
                            // public status for a File/Image
                            if ( $can_view_file ) {
                                // If the user can view non-public files, then use their choice
                                $criteria[$dt_id][0]['search_terms'][$df_id]['public_status'] = intval($value);
                            }
                            else {
                                // ...otherwise, ignore the public_status search and force public
                                //  files/images only
                                $criteria[$dt_id][0]['search_terms'][$df_id]['public_only'] = 1;
                            }
                        }
                        else {
                            // quality for a File/Image
                            $criteria[$dt_id][0]['search_terms'][$df_id]['quality'] = intval($value);

                            // If they can't view non-public files/images, then silently force their
                            //  search to only check public files/images
                            if ( !$can_view_file )
                                $criteria[$dt_id][0]['search_terms'][$df_id]['public_only'] = 1;

                            // NOTE: this is to prevent users without permissions from being able
                            //  to figure out which records match a filename/presence/absence search
                            //  ...using the existing 'public_status' flag won't work
                            // @see SearchService::searchFileOrImageDatafield()
                        }
                    }
                    else if ($pieces[1] === 's' || $pieces[1] === 'e') {
                        // This is a DatetimeValue field
                        if ( !isset($criteria[$dt_id][0]['search_terms'][$df_id]) ) {
                            $criteria[$dt_id][0]['search_terms'][$df_id] = array(
                                'before' => null,
                                'after' => null,
                                'entity_type' => 'datafield',
                                'entity_id' => $df_id,
                                'datatype_id' => $dt_id,
                            );
                        }

                        if ($pieces[1] === 's') {
                            // start date for a DatetimeValue, aka "after this date"
                            $criteria[$dt_id][0]['search_terms'][$df_id]['after'] = new \DateTime($value);
                        }
                        else {
                            // end date for a DatetimeValue, aka "before this date"
                            $date_end = new \DateTime($value);

                            $starting_key = $pieces[0].'_s';
                            if ( !isset($search_params[$starting_key]) || $search_params[$starting_key] === ''  ) {
                                // When a user only specifies an end date, then they're assuming
                                //  that they'll only get records with a date before whatever they
                                //  selected...because ODR only stores/shows the date portion, this
                                //  means the actual value that's searched needs to be adjusted
                                //  one day earlier
                                $date_end->sub(new \DateInterval('P1D'));

                                // NOTE: if ODR eventually gets modified to store the time, then
                                //  this needs to get removed
                            }
                            else {
                                // If a user specifies both a start and an end date, then they're
                                //  assuming that the search will return everything between the dates,
                                //  including the end points...since ODR currently doesn't store the
                                //  time portion, the actual value doesn't need to be adjusted for now

                                // NOTE: if ODR eventually gets modified to store the time, then
                                //  this may need to get uncommented
//                                $date_end->add(new \DateInterval('P1D'));
                            }

                            $criteria[$dt_id][0]['search_terms'][$df_id]['before'] = $date_end;
                        }
                    }
                    else if ( $pieces[1] === 'x' || $pieces[1] === 'y' || $pieces[1] === 'z' ) {
                        // This is a simple XYZData search...which is ironically more complicated
                        //  here, because the other type of XYZData search has already compressed
                        //  its entire set of parameters into a single string for a single field
                        if ( !isset($criteria[$dt_id][0]['search_terms'][$df_id]) ) {
                            $criteria[$dt_id][0]['search_terms'][$df_id] = array(
                                'entity_type' => 'datafield',
                                'entity_id' => $df_id,
                                'datatype_id' => $dt_id,
                            );
                        }

                        // ...I'm not going to risk screwing up compressing the criteria into a
                        //  format that the other search understands, so searching this XYZData
                        //  field gets its own search logic
                        if ( $pieces[1] === 'x' )
                            $criteria[$dt_id][0]['search_terms'][$df_id]['x'] = $value;
                        else if ( $pieces[1] === 'y' )
                            $criteria[$dt_id][0]['search_terms'][$df_id]['y'] = $value;
                        else if ( $pieces[1] === 'z' )
                            $criteria[$dt_id][0]['search_terms'][$df_id]['z'] = $value;
                    }
                }
                else {
                    // $key is one of the modified/created/modifiedBy/createdBy/publicStatus entries
                    $dt_id = intval($pieces[1]);

                    // Every search except for the general search currently merges by AND, and so
                    //  they can all go into the same facet...will label it 0 for convenience
                    if ( !isset($criteria[$dt_id][0]) ) {
                        $criteria[$dt_id][0] = array(
                            'facet_type' => 'single',
                            'merge_type' => $default_merge_type,
                            'search_terms' => array()
                        );
                    }

                    if ($pieces[2] === 'pub') {
                        // publicStatus
                        $criteria[$dt_id][0]['search_terms']['publicStatus'] = array(
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
                            $criteria[$dt_id][0]['search_terms'][$type] = array(
                                'user' => intval($value),
                                'entity_type' => 'datatype',
                                'entity_id' => $dt_id,
                                'datatype_id' => $dt_id,
                            );
                        }
                        else {
                            if ( !isset($criteria[$dt_id][0]['search_terms'][$type]) ) {
                                $criteria[$dt_id][0]['search_terms'][$type] = array(
                                    'before' => null,
                                    'after' => null,
                                    'entity_type' => 'datatype',
                                    'entity_id' => $dt_id,
                                    'datatype_id' => $dt_id,
                                );
                            }

                            if ($pieces[3] === 's') {
                                // start date, aka "after this date"
                                $criteria[$dt_id][0]['search_terms'][$type]['after'] = new \DateTime($value);
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
                                $criteria[$dt_id][0]['search_terms'][$type]['before'] = $date_end;
                            }
                        }
                    }
                }
            }
        }


        // ----------------------------------------
        // Determine which datatypes have datafields that are being searched on...any datarecord of
        //  that datatype must be marked as "needs to match", so that the latter parts of the search
        //  process can correctly exclude records that don't match
        $affected_datatypes = array();
        foreach ($criteria as $key => $facet_list) {
            if ( !is_numeric($key) )
                continue;

            foreach ($facet_list as $facet_num => $facet) {
                // Currently, all criteria except for general search are merged by AND
//                if ( $facet['merge_type'] === 'AND' ) {
                    foreach ($facet['search_terms'] as $key => $params) {
                        $dt_id = $params['datatype_id'];
                        $affected_datatypes[$dt_id] = 1;
                    }
//                }
            }
        }
        $affected_datatypes = array_keys($affected_datatypes);
        $criteria['affected_datatypes'] = $affected_datatypes;

        $criteria['default_merge_type'] = $default_merge_type;

        // Also going to need a list of all datatypes this search could run on, for later hydration
        if ( is_null($inverse_target_datatype_id) )
            $criteria['all_datatypes'] = $this->datatree_info_service->getAssociatedDatatypes($datatype_id, true);
        else
            $criteria['all_datatypes'] = $this->datatree_info_service->getInverseAssociatedDatatypes($datatype_id, $inverse_target_datatype_id, true);

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
        $dt = $this->database_info_service->getDatatypeFromUniqueId($template_uuid);
        $dt_id = $dt->getId();

        $grandparent_datatype_id = $this->datatree_info_service->getGrandparentDatatypeId($dt_id);
        $datatype_array = $this->database_info_service->getDatatypeArray($grandparent_datatype_id, true);

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
                            case 'XYZData':
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
                    else if ( isset($search_df['public_status']) ) {
                        if ($typeclass !== 'File' && $typeclass !== 'Image' )
                            throw new ODRBadRequestException('Invalid search key: "public_status" defined for a "'.$typeclass.'" datafield, expected a File or Image datafield', $exception_code);

                        // Don't need to do anything else
                    }
                    else if ( isset($search_df['quality']) ) {
                        if ($typeclass !== 'File' && $typeclass !== 'Image' )
                            throw new ODRBadRequestException('Invalid search key: "quality" defined for a "'.$typeclass.'" datafield, expected a File or Image datafield', $exception_code);

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
     * Converts a search key for templates into a format usable by {@link SearchAPIService::performTemplateSearch()}
     *
     * @param string $search_key
     * @param array $template_structure {@link SearchAPIService::getSearchableTemplateDatafields()}
     *
     * @return array
     */
    public function convertSearchKeyToTemplateCriteria($search_key, $template_structure)
    {
        // ----------------------------------------
        // Need to collapse the provided template structure somewhat...
        $searchable_datafields = array();
        foreach ($template_structure as $dt_uuid => $dt_data) {
            // self::validateTemplateSearchKey() has already verified that the search key doesn't
            //  contain template fields the user isn't allowed to view...so the arrays of both the
            //  non-public and the public fields can be combined without risking the user being able
            //  to see something they shouldn't be able to
            if ( !isset($searchable_datafields[$dt_uuid]) )
                $searchable_datafields[$dt_uuid] = array();

            foreach ($dt_data['datafields'] as $df_id => $df_data ) {
                $df_uuid = $df_data['field_uuid'];
                $searchable_datafields[$dt_uuid][$df_uuid] = $df_data;
            }
        }


        // ----------------------------------------
        // Want the search key in array format...
        $search_params = self::decodeSearchKey($search_key);

        $template_uuid = $search_params['template_uuid'];
        $criteria = array(
            'search_type' => 'template',
            'template_uuid' => $template_uuid,
        );
        // Each datatype with a searchable datafield gets its own entry in the criteria array
        foreach ($searchable_datafields as $dt_uuid => $df_list)
            $criteria[$dt_uuid] = array();


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
                $criteria['field_stats'][0] = array(
                    'facet_type' => 'field_stats',
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

                // ----------------------------------------
                // Attempt to split the general search string into tokens
                $tokens = self::tokenizeGeneralSearch($value);

                // For each token in the search string...
                foreach ($tokens as $token_num => $token) {
                    // Need to find each datafield that qualifies for general search...
                    foreach ($searchable_datafields as $dt_uuid => $df_list) {
                        // Each token in the general search string gets its own facet
                        if ( !isset($criteria['general'][$token_num]) ) {
                            $criteria['general'][$token_num] = array(
                                'facet_type' => 'general',
                                'merge_type' => 'OR',
                                'search_terms' => array()
                            );
                        }

                        foreach ($df_list as $df_id => $df_data) {
                            $field_uuid = $df_data['field_uuid'];

                            // General search needs both the searchable flag and the typeclass
                            $searchable = $df_data['searchable'];
                            $typeclass = $df_data['typeclass'];

                            // In early May 2024, the 'searchable' property got changed from allowing four values
                            //  (NOT_SEARCHED, GENERAL_SEARCH, ADVANCED_SEARCH, and ADVANCED_SEARCH_ONLY) to only
                            //  allowing two values (NOT_SEARCHABLE, SEARCHABLE)

                            // ...because three of the old values map to a single new value, equality
                            //  should only be checked against NOT_SEARCHABLE
                            if ( $searchable !== DataFields::NOT_SEARCHABLE ) {
                                switch ($typeclass) {
                                    case 'Boolean':
                                        // Excluding because a Boolean's value has a different
                                        //  meaning than the other fieldtypes
                                    case 'DatetimeValue':
                                    case 'File':
                                    case 'Image':
                                    case 'XYZData':
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
                                        // A general search makes sense for these fieldtypes
                                        $criteria['general'][$token_num]['search_terms'][$df_id] = array(
                                            'value' => $token,
                                            'entity_type' => 'datafield',
                                            'entity_id' => $field_uuid,
                                            'datatype_id' => $dt_uuid,
                                        );
                                        break;
                                }
                            }
                        }
                    }
                }
            }
            else if ($key === 'fields') {
                // Only define this facet if something is going to be put into it...
                if ( count($search_params['fields']) === 0 )
                    continue;

                foreach ($value as $num => $df) {
                    $field_uuid = $df['template_field_uuid'];

                    // All of these values are datafield entries...need to find the datatype_uuid
                    $dt_uuid = null;
                    foreach ($searchable_datafields as $dt_uuid => $df_list) {
                        if ( isset($df_list[$field_uuid]) ) {
                            break;
                        }
                    }

                    // Every search except for the general search merges by AND, and so they can all
                    //  go into the same facet...will label it 0 for convenience
                    if ( !isset($criteria[$dt_uuid][0]) ) {
                        $criteria[$dt_uuid][0] = array(
                            'facet_type' => 'single',
                            'merge_type' => 'AND',    // TODO - combine_by_AND/combine_by_OR
                            'search_terms' => array()
                        );
                    }


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


                        $criteria[$dt_uuid][0]['search_terms'][$field_uuid] = array(
                            'combine_by_OR' => $combine_by_or,
                            'selections' => $selections,
                            'entity_type' => 'datafield',
                            'entity_id' => $field_uuid,
                            'datatype_id' => $dt_uuid,
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


                        $criteria[$dt_uuid][0]['search_terms'][$field_uuid] = array(
                            'combine_by_OR' => $combine_by_or,
                            'selections' => $selections,
                            'entity_type' => 'datafield',
                            'entity_id' => $field_uuid,
                            'datatype_id' => $dt_uuid,
                        );
                    }
                    else if ( isset($df['before']) || isset($df['after']) ) {
                        // Datetime datafields...ensure an entry exists
                        if ( !isset($criteria[$dt_uuid][0]['search_terms'][$field_uuid]) ) {
                            $criteria[$dt_uuid][0]['search_terms'][$field_uuid] = array(
                                'after' => null,
                                'before' => null,
                                'entity_type' => 'datafield',
                                'entity_id' => $field_uuid,
                                'datatype_id' => $dt_uuid,
                            );
                        }


                        if ( isset($df['after']) ) {
                            // start date, aka "after this date"
                            $date = new \DateTime($df['after']);
                            $criteria[$dt_uuid][0]['search_terms'][$field_uuid]['after'] = $date;
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
                            $criteria[$dt_uuid][0]['search_terms'][$field_uuid]['before'] = $date;
                        }

                    }
                    else if ( isset($df['filename']) || isset($df['public_status']) || isset($df['quality']) ) {
                        // Create an entry in the criteria array for this datafield...there won't be any
                        //  duplicate entries
                        if ( !isset($criteria[$dt_uuid][0]['search_terms'][$field_uuid]) ) {
                            $criteria[$dt_uuid][0]['search_terms'][$field_uuid] = array(
                                'entity_type' => 'datafield',
                                'entity_id' => $field_uuid,
                                'datatype_id' => $dt_uuid,
                            );
                        }

                        if ( isset($df['filename']) )
                            $criteria[$dt_uuid][0]['search_terms'][$field_uuid]['filename'] = $df['filename'];
                        if ( isset($df['public_status']) )
                            $criteria[$dt_uuid][0]['search_terms'][$field_uuid]['public_status'] = $df['public_status'];
                        if ( isset($df['quality']) )
                            $criteria[$dt_uuid][0]['search_terms'][$field_uuid]['quality'] = $df['quality'];
                    }
                    else if ( isset($df['value']) ) {
                        // All other searchable fieldtypes

                        // Create an entry in the criteria array for this datafield...there won't be
                        //  any duplicate entries
                        $criteria[$dt_uuid][0]['search_terms'][$field_uuid] = array(
                            'value' => $df['value'],
                            'entity_type' => 'datafield',
                            'entity_id' => $field_uuid,
                            'datatype_id' => $dt_uuid,
                        );
                    }
                }
            }
        }

        // ----------------------------------------
        // Determine which datatypes have datafields that are being searched on...any datarecord of
        //  that datatype must be marked as "needs to match", so that the latter parts of the search
        //  process can correctly exclude records that don't match
        $affected_datatypes = array();
        foreach ($criteria as $key => $facet_list) {
            if ( $key === 'search_type' || $key === 'template_uuid' || $key === 'general' )
                continue;

            foreach ($facet_list as $facet_num => $facet) {
                // Currently, all criteria except for general search are merged by AND
                if ( $facet['merge_type'] === 'AND' ) {
                    foreach ($facet['search_terms'] as $key => $params) {
                        $dt_id = $params['datatype_id'];
                        $affected_datatypes[$dt_id] = 1;
                    }
                }
            }
        }
        $affected_datatypes = array_keys($affected_datatypes);
        $criteria['affected_datatypes'] = $affected_datatypes;

        return $criteria;
    }


    /**
     * Converts a search key into an array that's more human readable.  Attempts to compensate for
     * any errors in the search key.
     *
     * @param string $search_key
     *
     * @return array
     */
    public function getReadableSearchKey($search_key)
    {
        // Search key is assumed to be valid, so it should always have a dataype id in it
        $search_params = self::decodeSearchKey($search_key);
        $dt_id = $search_params['dt_id'];
        if ( isset($search_params['inverse']) && $search_params['inverse'] > 0 )
            $dt_id = $search_params['inverse'];


        // ----------------------------------------
        // Use the datatype id to load the cached datatype array...
        $dt_array = $this->database_info_service->getDatatypeArray($dt_id);    // may need linked data
        // ...and then extract the relevant datatype/datafield data from it
        $dt_lookup = array();
        $df_lookup = array();
        foreach ($dt_array as $dt_id => $dt_data) {
            // Need to store some data for the datatypes that could show up in the search key...
            $dt_lookup[$dt_id] = $dt_data['dataTypeMeta']['shortName'];

            foreach ($dt_data['dataFields'] as $df_id => $df_data) {
                // No sense having markdown fields in this
                if ( $df_data['dataFieldMeta']['fieldType']['typeClass'] !== 'Markdown' ) {
                    // Need to store some data for any datafield that could show up in the
                    //  search key...
                    $df_lookup[$df_id] = array(
                        'fieldName' => $df_data['dataFieldMeta']['fieldName'],
                        'typeClass' => $df_data['dataFieldMeta']['fieldType']['typeClass'],
                    );
                    // Also need to store radio/tag names if they exist...
                    $key = '';
                    if ( $df_lookup[$df_id]['typeClass'] === 'Radio' )
                        $key = 'radioOptions';
                    else if ( $df_lookup[$df_id]['typeClass'] === 'Tag' )
                        $key = 'tags';
                    else
                        continue;
                    // Also need to store XYZData column names
                    if ( $df_lookup[$df_id]['typeClass'] === 'XYZData' )
                        $df_lookup[$df_id]['xyz_column_names'] = explode(',', $df_data['dataFieldMeta']['xyz_data_column_names']);

                    $df_lookup[$df_id][$key] = array();
                    foreach ($df_data[$key] as $num => $entity) {
                        $id = $entity['id'];
                        if ( $key === 'radioOptions' ) {
                            $df_lookup[$df_id][$key][$id] = $entity['optionName'];
                        }
                        else {
                            $tag_names = self::getTagNames($entity);
                            foreach ($tag_names as $tag_id => $tag_name)
                                $df_lookup[$df_id][$key][$tag_id] = $tag_name;
                        }
                    }
                }
            }
        }


        // ----------------------------------------
        $readable_search_key = array();
        foreach ($search_params as $key => $value) {
            // Ignore these keys
            if ( $key === 'dt_id' || $key === 'sort_by' || $key === 'ignore' || $key === 'merge' )
                continue;

            if ( $key === 'gen' || $key === 'gen_lim' ) {
                // Don't do anything if this key is empty
                if ($value === '')
                    continue;

                if ( $key === 'gen_lim' )
                    $readable_search_key['All Fields (current database)'] = $value;
                else
                    $readable_search_key['All Fields (including descendants)'] = $value;
            }
            else if ( $key === 'inverse' ) {
                $readable_search_key['Search Ancestor'] = $dt_lookup[$value];
            }
            else if ( is_numeric($key) ) {
                // If this datafield doesn't exist (most likely due to deletion), then don't fully
                //  process it
                if ( !isset($df_lookup[$key]) ) {
                    $readable_search_key['unrecognized df_id '.$key] = '<ERROR: inaccessible/deleted datafield>';
                    continue;
                }

                $df_name = $df_lookup[$key]['fieldName'];
                $df_typeclass = $df_lookup[$key]['typeClass'];

                switch ($df_typeclass) {
                    case 'Boolean':
                        if ($value == 0)
                            $value = 'unselected';
                        else
                            $value = 'selected';
                        break;
                    case 'Radio':
                    case 'Tag':
                        $value = explode(',', $value);
                        break;
                    // TODO - XYZData?
                    default:
                        // Don't need to do anything for the text/number/file/image fields
                        break;
                }

                if ( is_array($value) ) {
                    // When dealing with radio/tags...
                    $readable_search_key[$df_name] = array();

                    $entity_key = 'radioOptions';
                    if ( $df_typeclass === 'Tag' )
                        $entity_key = 'tags';

                    foreach ($value as $num => $entity_id) {
                        $selected = 'selected';
                        if ( $entity_id[0] === '-' ) {
                            $entity_id = substr($entity_id, 1);
                            $selected = 'unselected';
                        }

                        $entity_name = $df_lookup[$key][$entity_key][$entity_id];
                        $readable_search_key[$df_name][$entity_id] = '"'.$entity_name.'" '.$selected;
                    }
                }
                else {
                    // Store a more human-readable version of the data
                    if ( $value === '""' ) {
                        if ( $df_typeclass === 'File' || $df_typeclass === 'Image' )
                            $value = '<no '.lcfirst($df_typeclass).'s uploaded>';
                        else
                            $value = '<empty>';
                    }
                    else if ( $value === '!""' ) {
                        if ( $df_typeclass === 'File' || $df_typeclass === 'Image' )
                            $value = '<has '.lcfirst($df_typeclass).'s uploaded>';
                        else
                            $value = '<not empty>';
                    }
                    else if ( $df_typeclass === 'File' || $df_typeclass === 'Image' ) {
                        $value = '<filename matches "'.$value.'">';
                    }

                    // Files/Images might have an existing entry in $readable_search_key
                    if ( !isset($readable_search_key[$df_name]) )
                        $readable_search_key[$df_name] = $value;
                    else
                        $readable_search_key[$df_name] .= ', '.$value;
                }
            }
            else {
                $pieces = explode('_', $key);

                if ( is_numeric($pieces[0]) && count($pieces) === 2 ) {
                    // If this datafield doesn't exist (most likely due to deletion), then don't fully
                    //  process it
                    if ( !isset($df_lookup[ $pieces[0] ]) ) {
                        $readable_search_key['unrecognized df_id '.$pieces[0]] = '<ERROR: inaccessible/deleted datafield>';
                        continue;
                    }

                    // This could be either a DatetimeValue, a File/Image, or an XYZData field...
                    $df_name = $df_lookup[ $pieces[0] ]['fieldName'];
                    $start = $end = null;
                    $public_status = $quality = null;

                    // These are for DatetimeValues...
                    if ( $pieces[1] === 's' )
                        $start = $search_params[$key];
                    if ( $pieces[1] === 'e' )
                        $end = $search_params[$key];

                    // These are for File/Images...
                    if ( $pieces[1] === 'pub' )
                        $public_status = $search_params[$key];
                    if ( $pieces[1] === 'qual' )
                        $quality = $search_params[$key];


                    if ( !is_null($start) || !is_null($end) ) {
                        // Datetime values won't have an existing entry for $readable_search_key
                        if ( !is_null($start) && !is_null($end) )
                            $readable_search_key[$df_name] = 'Between "'.$start.'" and "'.$end.'"';
                        else if ( !is_null($start) )
                            $readable_search_key[$df_name] = 'After "'.$start.'"';
                        else
                            $readable_search_key[$df_name] = 'Before "'.$end.'"';
                    }
                    else if ( !is_null($public_status) || !is_null($quality) ) {
                        // Files/Images might have an existing entry in $readable_search_key
                        $tmp = array();
                        if ( isset($readable_search_key[$df_name]) )
                            $tmp[] = $readable_search_key[$df_name];

                        if ( !is_null($public_status) ) {
                            $df_typeclass = $df_lookup[ $pieces[0] ]['typeClass'];
                            if ( intval($value) === 1 )
                                $tmp[] = '<public '.$df_typeclass.'s only>';
                            else
                                $tmp[] = '<non-public '.$df_typeclass.'s only>';
                        }
                        if ( !is_null($quality) )
                            $tmp[] = '<quality: '.$value.'>';

                        $readable_search_key[$df_name] = implode(', ', $tmp);
                    }
                }
                else if ( count($pieces) === 3 ) {
                    // If this datatype doesn't exist (most likely due to deletion), then don't fully
                    //  process it
                    if ( !isset($dt_lookup[ $pieces[1] ]) ) {
                        $readable_search_key['unrecognized dt_id '.$pieces[1]] = '<ERROR: inaccessible/deleted datatype>';
                        continue;
                    }

                    $dt_name = $dt_lookup[ $pieces[1] ];

                    // $key is the public status entry
                    if ( intval($value) === 1 )
                        $readable_search_key[$dt_name]['pub'] = 'Public records';
                    else
                        $readable_search_key[$dt_name]['pub'] = 'Non-public records';
                }
                else if ( $pieces[3] === 'by' ) {
                    // If this datatype doesn't exist (most likely due to deletion), then don't fully
                    //  process it
                    if ( !isset($dt_lookup[ $pieces[1] ]) ) {
                        $readable_search_key['unrecognized dt_id '.$pieces[1]] = '<ERROR: inaccessible/deleted datatype>';
                        continue;
                    }

                    $dt_name = $dt_lookup[ $pieces[1] ];

                    // $key is either createdBy or modifiedBy
                    $type = 'Created';
                    $sub_key = 'c_by';
                    if ( $pieces[2] === 'm' ) {
                        $type = 'Modified';
                        $sub_key = 'm_by';
                    }

                    /** @var ODRUser $user */
                    $user = $this->user_manager->findUserBy(array('id' => $value));
                    $user_name = $user->getUserString();
                    $readable_search_key[$dt_name][$sub_key] = $type.' by '.$user_name;
                }
                else {
                    // If this datatype doesn't exist (most likely due to deletion), then don't fully
                    //  process it
                    if ( !isset($dt_lookup[ $pieces[1] ]) ) {
                        $readable_search_key['unrecognized dt_id '.$pieces[1]] = '<ERROR: inaccessible/deleted datatype>';
                        continue;
                    }

                    $dt_name = $dt_lookup[ $pieces[1] ];

                    // $key is either created or modified
                    $type = 'Created';
                    $sub_key = 'c';
                    if ( $pieces[2] === 'm' ) {
                        $type = 'Modified';
                        $sub_key = 'm';
                    }

                    $start_key = $pieces[0].'_'.$pieces[1].'_'.$pieces[2].'_s';
                    $start = null;
                    if ( isset($search_params[$start_key]) )
                        $start = $search_params[$start_key];
                    unset( $search_params[$start_key] );

                    $end_key = $pieces[0].'_'.$pieces[1].'_'.$pieces[2].'_e';
                    $end = null;
                    if ( isset($search_params[$end_key]) )
                        $end = $search_params[$end_key];
                    unset( $search_params[$end_key] );

                    if ( !is_null($start) && !is_null($end) )
                        $readable_search_key[$dt_name][$sub_key] = $type.' between "'.$start.'" and "'.$end.'"';
                    else if ( !is_null($start) )
                        $readable_search_key[$dt_name][$sub_key] = $type.' after "'.$start.'"';
                    else
                        $readable_search_key[$dt_name][$sub_key] = $type.' before "'.$end.'"';
                }
            }
        }

        return $readable_search_key;
    }


    /**
     * Converts a tag from the cached datatype array, including all of its children, into a new
     * array.
     *
     * @param array $tag
     * @return array
     */
    private function getTagNames($tag)
    {
        // Save the current tag
        $tmp = array($tag['id'] => $tag['tagName']);

        // If the tag has children...
        if ( !empty($tag['children']) ) {
            // ...then get the names of all of the child tags
            foreach ($tag['children'] as $num => $child_tag) {
                $child_tags = self::getTagNames($child_tag);

                foreach ($child_tags as $child_tag_id => $child_tag_name)
                    $tmp[$child_tag_id] = $tag['tagName'].' >> '.$child_tag_name;
            }
        }

        return $tmp;
    }
}
