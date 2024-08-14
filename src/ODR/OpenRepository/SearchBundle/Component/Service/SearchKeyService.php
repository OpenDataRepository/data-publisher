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
// Services
use FOS\UserBundle\Doctrine\UserManager;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class SearchKeyService
{
    /**
     * @var EntityManager
     */
    private $em;

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
     * @param EntityManager $entity_manager
     * @param DatabaseInfoService $database_info_service
     * @param DatatreeInfoService $datatree_info_service
     * @param SearchService $search_service
     * @param UserManager $user_manager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatabaseInfoService $database_info_service,
        DatatreeInfoService $datatree_info_service,
        SearchService $search_service,
        UserManager $user_manager,
        Logger $logger
    ) {
        $this->em = $entity_manager;
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
        if ( is_null($array) ) {
            throw new ODRException('Invalid JSON', 400, 0x6e1c96a1);
        }
        else {
            ksort($array);
            return $array;
        }
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
     * Converts ODR's default search page POST into an ODR search key.
     * TODO - modify the default search page to send JSON?
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

        // Don't add extra doublequotes if they're unmatched...user could be trying to search for
        //  a single doublequote
//        if ( $in_quote )
//            $cleaned .= '"';

        return $cleaned;
    }


    /**
     * Splits a string that has been run through {@link clean()} into an array of terms that
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
     * {@link tokenizeGeneralSearch()} should probably be the only function that calls this.
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
                            $second_char = $str[$i+1];
                            $third_char = $str[$i+2];

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
                            $second_char = $str[$i+1];
                            $third_char = $str[$i+2];

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
     * Modifies a value meant for a general search so the search system can work correctly with it.
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

        // Search keys should always have the "dt_id" key...
        if ( !isset($search_params['dt_id']) || !is_numeric($search_params['dt_id']) )
            throw new ODRBadRequestException('Invalid search key: missing "dt_id"', $exception_code);
        $dt_id = $search_params['dt_id'];

        // ...they should not have both "gen" and "gen_all"..."gen" is a subset of "gen_all"
        if ( isset($search_params['gen']) && isset($search_params['gen_all']) )
            throw new ODRBadRequestException('Invalid search key: only allowed to have at most one of "gen" or "gen_all"', $exception_code);

        $inverse = false;
        if ( isset($search_params['inverse']) )
            $inverse = true;

        $grandparent_datatype_id = $this->datatree_info_service->getGrandparentDatatypeId($dt_id);
        $datatype_array = array();
        if ( !$inverse )
            $datatype_array = $this->database_info_service->getDatatypeArray($grandparent_datatype_id);
        else
            $datatype_array = $this->database_info_service->getInverseDatatypeArray($grandparent_datatype_id);

        $searchable_datafields = $this->search_service->getSearchableDatafields($dt_id, $inverse);
        $sortable_typenames = null;

        foreach ($search_params as $key => $value) {
            if ( $key === 'dt_id' || $key === 'gen' || $key === 'gen_all' || $key === 'inverse' ) {
                // Nothing to validate
                continue;
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
                    $dt = $datatype_array[$dt_id];
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

                // Don't need to validate anything related to the other typeclasses in here
            }
            else {
                $pieces = explode('_', $key);
                if ( count($pieces) < 2 || count($pieces) > 4 )
                    throw new ODRBadRequestException('Invalid search key: unrecognized parameter "'.$key.'"', $exception_code);

                if ( is_numeric($pieces[0]) && count($pieces) === 2 ) {
                    // $key is for a DatetimeValue, or a File/Image's public status/quality
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

                        // TODO - check that the 'end' date is later than the 'start' date?
                        // TODO - provide the option to search for fields without dates?
                    }
                    else if ( $pieces[1] === 'pub' || $pieces[1] === 'qual' ) {
                        // This is for a File/Image...nothing to validate here
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
     *         0 => array(
     *             'facet_type' => 'general',
     *             'merge_type' = 'OR',
     *             'search_terms' => array(
     *                 '<df_id>' => array(
     *                     'value' => ...,
     *                     'entity_type' => 'datafield',
     *                     'entity_id' => <df_id>
     *                 ),
     *                 '<additional datafields>' => array(...),
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
     *                 '[<df_id>]' => array(
     *                     'value' => ...,
     *                     'entity_type' => 'datafield',
     *                     'entity_id' => <df_id>
     *                 ),
     *                 '[<additional datafield ids>]' => array(...),
     *                 '[<additional metadata keys such as created, createdBy, etc>]' => array(...),
     *                 ...
     *             )
     *         )
     *     ),
     *     [<datatype_B_id>] => array(...),    // All child/linked datatypes with a searchable datafield have an entry
     *     ...
     * )
     * </pre>
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

        foreach ($search_params as $key => $value) {

            if ( $key === 'dt_id' || $key === 'inverse' ) {
                // Don't want to do anything with these keys
                continue;
            }
            else if ( $key === 'sort_by' ) {
                // Don't want to do anything with this key either...
                continue;

                // ...the reason being that if SearchAPIService::performSearch() directly used this
                //  entry, then any sort_criteria for this tab in the user's session would be ignored
            }
            else if ( $key === 'gen' || $key === 'gen_all' ) {
                // Don't do anything if this key is empty
                if ($value === '')
                    continue;


                // ----------------------------------------
                // Attempt to split the general search string into tokens
                $tokens = self::tokenizeGeneralSearch($value);

                // For each token in the search string...
                foreach ($tokens as $token_num => $token) {
                    // Need to find each datafield that qualifies for general search...
                    foreach ($searchable_datafields as $dt_id => $df_list) {
                        // Don't create criteria for fields from descendant datatypes unless that's
                        //  what the user wants
                        if ( $key === 'gen' && $dt_id !== $datatype_id )
                            continue;

                        // After this point, the 'gen_all' key effectively ceases to exist

                        // Each token in the general search string gets its own facet
                        if ( !isset($criteria['general'][$token_num]) ) {
                            $criteria['general'][$token_num] = array(
                                'facet_type' => 'general',
                                'merge_type' => 'OR',
                                'search_terms' => array()
                            );
                        }

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
                        'merge_type' => 'AND',
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
                    // This is a DatetimeValue, or the public_status/quality for a File/Image field
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
                            'merge_type' => 'AND',
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
                            if ( isset($search_params[$starting_key]) && $search_params[$starting_key] !== '' ) {
                                // When a user selects a start date of...say, 2015-04-26 and an end date
                                //  of 2015-04-28...they're under the assumption that the search will
                                //  return everything between the "26th" and the "28th", inclusive.

                                // However, to actually include results from the "28th", the end date
                                //  needs to be incremented by 1 to 2015-04-29...
                                $date_end->add(new \DateInterval('P1D'));
                            }

                            $criteria[$dt_id][0]['search_terms'][$df_id]['before'] = $date_end;
                        }
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
                            'merge_type' => 'AND',
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

        // Also going to need a list of all datatypes this search could run on, for later hydration
        if ( !isset($search_params['inverse']) )
            $criteria['all_datatypes'] = $this->datatree_info_service->getAssociatedDatatypes($datatype_id, true);
        else
            $criteria['all_datatypes'] = $this->datatree_info_service->getInverseAssociatedDatatypes($datatype_id, true);

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
            if ( $key === 'dt_id' || $key === 'sort_by' )
                continue;

            if ( $key === 'gen' || $key === 'gen_all' ) {
                // Don't do anything if this key is empty
                if ($value === '')
                    continue;

                if ( $key === 'gen' )
                    $readable_search_key['All Fields (current database)'] = $value;
                else
                    $readable_search_key['All Fields (including descendants)'] = $value;
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

                    // This could be either a DatetimeValue field or a File/Image field...
                    $df_name = $df_lookup[ $pieces[0] ]['fieldName'];
                    $start = $end = $public_status = $quality = null;

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
