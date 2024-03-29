<?php

/**
 * Open Data Repository Data Publisher
 * Chemical Elements Search Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * RRUFF originally stored chemical elements as a single string. e.g. Quartz has a chemical formula
 * of "SiO_2_", which means it has the chemical elements "Si O".  ODR performs this derivation in
 * IMAPlugin::convertIMAFormula() and IMAPlugin::convertRRUFFFormula().
 *
 * This is a considerably simpler and more compact method than storing chemical elements as tags...
 * (espcially since the RRUFF formula introduces multiple valence states per element  e.g. Fe^3+^)
 * ...but the downside is that the field can't be strictly searched as a text field.  If it is, then
 * a search for minerals containing sulfur ("S") will also return minerals containing silicon ("Si").
 *
 * Fixing this requires some additional processing of the values in the database prior to claiming
 * they match the search results or not.  This search is case-insensitive.
 *
 * The other main feature RRUFF had for this is that there were three categories to search with...
 * ..."ALL OF", "AT LEAST ONE OF", and "NONE OF"...since this is supposed to work off a text field
 * instead of the javascript RRUFF used for this, at the moment elements intended for the 2nd and 3rd
 * categories are prefixed with a '~' and a '!' respectively.
 *
 * There is also special handling for 'all', as that was a shorthand for placing every element not
 * in the first two categories into the third...for example, of "Si O all" only returns minerals that
 * have both Si and O, and no other elements.  A search for "~Si ~C all" only returns minerals that
 * are comprised entirely of Si, or entirely of C, or entirely of both elements...and nothing more.
 *
 *
 * While the plugin was originally meant for chemical elements, it technically will work on any
 * field that has multiple values split by spaces...
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// Entities
use ODR\AdminBundle\Entity\DataFields;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\SearchOverrideInterface;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Bridge\Monolog\Logger;


class ChemicalElementsSearchPlugin implements DatafieldPluginInterface, SearchOverrideInterface
{

    // The plugin assumes that it is receiving chemical elements...so it can also take the liberty
    //  of assuming the elements might have specific character prefixes
    // TODO - change these with renderPluginOptions?
    private const AT_LEAST_ONE_CHAR = '~';
    private const NONE_CHAR = '!';

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var SortService
     */
    private $sort_service;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var array
     */
    private $typeclass_map;


    /**
     * ChemicalElementsSearchPlugin constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param SearchService $search_service
     * @param SortService $sort_service
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        SearchService $search_service,
        SortService $sort_service,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->search_service = $search_service;
        $this->sort_service = $sort_service;
        $this->templating = $templating;
        $this->logger = $logger;

        $this->typeclass_map = array(
            // All of these are searched via their "value" field in the backend database
            'ShortVarchar' => 'odr_short_varchar',
            'MediumVarchar' => 'odr_medium_varchar',
            'LongVarchar' => 'odr_long_varchar',
            'LongText' => 'odr_long_text',
//            'IntegerValue' => 'odr_integer_value',
//            'DecimalValue' => 'odr_decimal_value',
//            'DatetimeValue' => 'odr_datetime_value',
//            'Boolean' => 'odr_boolean',

            // Files/images are searched for by filename and/or existence
//            'File' => 'odr_file',
//            'Image' => 'odr_image',

            // Searches on radio options require multiple tables in the query
        );
    }


    /**
     * Given an array of datafields mapped by this plugin, returns which datafields SearchAPIService
     * should call {@link SearchOverrideInterface::searchOverriddenField()} on instead of running
     * the default searches.
     *
     * @param array $df_list
     * @return array An array where the values are datafield ids
     */
    public function getSearchOverrideFields($df_list)
    {
        // Since this is a datafield plugin, $df_list will only have one field...always want to
        //  override how it's searched
        return $df_list;
    }


    /**
     * Searches the specified datafield for the specified value, returning an array of datarecord
     * ids that match the search.
     *
     * @param DataFields $datafield
     * @param array $search_term
     * @param array $render_plugin_options
     *
     * @return array
     */
    public function searchOverriddenField($datafield, $search_term, $render_plugin_options)
    {
        // ----------------------------------------
        // Don't continue if called on the wrong type of datafield
        $allowed_typeclasses = array(
            'ShortVarchar',
            'MediumVarchar',
            'LongVarchar',
            'LongText'
        );
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ( !in_array($typeclass, $allowed_typeclasses) )
            throw new ODRBadRequestException('ChemicalElementsSearchPlugin::searchPluginField() called with '.$typeclass.' datafield', 0xc508f89f);

        // This should already be cached from earlier in the search routine
        $datarecord_list = $this->search_service->getCachedSearchDatarecordList($datafield->getDataType()->getId());

        // ----------------------------------------
        // Searching on chemical elements is vaugely similar to searching a radio/tag field...
        //  the options/tags are just concatenated into a single string value
        $elements = self::parseValue($search_term);

        // RRUFF originally had three "groups" of elements in its chemical search...
        // 1) search for minerals with ALL of these elements
        $all = null;
        // 2) search for minerals with AT LEAST ONE of these elements
        $at_least_one = null;
        // 3) search for minerals with NONE of these elements
        $none = null;


        // ----------------------------------------
        // Going to attempt to use the cache system if at all possible
        $recache = false;
        $cached_searches = $this->cache_service->get('cached_search_df_'.$datafield->getId());
        if ( !$cached_searches )
            $cached_searches = array();

        // Need to go through all three "groups" of elements...
        foreach ($elements['all'] as $element) {
            // If the results for this element aren't cached...
            if ( !isset($cached_searches[$element]) ) {
                // ...then run the search again
                $cached_searches[$element] = self::runSearch(
                    $typeclass,
                    $datafield->getId(),
                    $datarecord_list,
                    $element
                );
                $recache = true;
            }

            // Otherwise, get the list of minerals (records) that have this element...
            $tmp = $cached_searches[$element][1];
            if ( is_null($all) ) {
                // ...if this is the first element in this category, then just store the array
                $all = $tmp;
            }
            else {
                // ...otherwise, only save the datarecord ids that are in both arrays, since the
                //  matching minerals need to have ALL of these elements
                $all = array_intersect_key($all, $tmp);
            }
        }

        foreach ($elements['at_least_one'] as $element) {
            // If the results for this element aren't cached...
            if ( !isset($cached_searches[$element]) ) {
                // ...then run the search again
                $cached_searches[$element] = self::runSearch(
                    $typeclass,
                    $datafield->getId(),
                    $datarecord_list,
                    $element
                );
                $recache = true;
            }

            // Otherwise, get the list of minerals (records) that have this element...
            $tmp = $cached_searches[$element][1];
            if ( is_null($at_least_one) ) {
                // ...if this is the first element in this category, then just store the array
                $at_least_one = $tmp;
            }
            else {
                // ...otherwise, save every datarecord id from this array, since the matching
                //  minerals need to have AT LEAST ONE of these elements
                foreach ($tmp as $dr_id => $num)
                    $at_least_one[$dr_id] = $num;
            }
        }

        foreach ($elements['none'] as $element) {
            // If the results for this element aren't cached...
            if ( !isset($cached_searches[$element]) ) {
                // ...then run the search again
                $cached_searches[$element] = self::runSearch(
                    $typeclass,
                    $datafield->getId(),
                    $datarecord_list,
                    $element
                );
                $recache = true;
            }

            // Otherwise, get the list of minerals (records) that do NOT have this element...
            $tmp = $cached_searches[$element][0];
            if ( is_null($none) ) {
                // ...if this is the first element in this category, then just store the array
                $none = $tmp;
            }
            else {
                // ...otherwise, only save the datarecord ids that are in both arrays, since the
                //  matching minerals need to have NONE of these elements
                $none = array_intersect_key($none, $tmp);

                // NOTE - this is not a typo...merging by OR is not correct here, and RRUFF
                //  intentionally merged by AND for this category
            }
        }


        // ----------------------------------------
        // All three of these "groups" need to be recombined into a single list of datarecords, but
        //  there's no guarantee any of them were searched on...
        $results = null;

        if ( !is_null($all) )
            $results = $all;

        // The remaining two "groups" need to check whether there's already a list of results, and
        //  intersect with it if that's the case
        if ( !is_null($at_least_one) ) {
            if ( is_null($results) )
                $results = $at_least_one;
            else
                $results = array_intersect_key($results, $at_least_one);
        }

        if ( !is_null($none) ) {
            if ( is_null($results) )
                $results = $none;
            else
                $results = array_intersect_key($results, $none);
        }

        // Pretty sure this isn't needed, but remain safe
        if ( is_null($results) )
            $results = array();


        // ----------------------------------------
        // If the search is supposed to exclude every nonselected element, and the rest of the search
        //  terms have provided a list of datarecords...
        if ( $elements['exclude_nonselected'] === true && count($results) > 0 ) {
            // ...then the easiest way to pull off this exclusion is to use the SortService to get
            //  a (hopefully) cached list of all values in this field for each datarecord that matched
            //  the search
            $dr_list = implode(',', array_keys($results));
            $dr_list = $this->sort_service->sortDatarecordsByDatafield($datafield->getId(), 'asc', $dr_list);

            // These records must not contain elements that aren't already in the 'all' and
            //  'at_least_one' arrays in order to pass this requirement
            $existing_elements = array();
            foreach ($elements['all'] as $num => $elem)
                $existing_elements[$elem] = 1;
            foreach ($elements['at_least_one'] as $num => $elem)
                $existing_elements[$elem] = 1;

            // Intentionally wipe the existing list of matching records, it's part of $dr_list now
            $results = array();
            foreach ($dr_list as $dr_id => $value) {
                // Explode the chemical element list for this record...
                $dr_elems = explode(' ', strtolower($value));

                $include_record = true;
                foreach ($dr_elems as $elem) {
                    // If the record contains an element that doesn't already exist in the 'all' or
                    //  'at_least_one' arrays...
                    if ( !isset($existing_elements[$elem]) ) {
                        // ...then it doesn't match the search
                        $include_record = false;
                        break;
                    }
                }

                // If this point is reached, then it matches the search
                if ( $include_record )
                    $results[$dr_id] = 1;
            }
        }


        // ----------------------------------------
        // If any database queries had to be made, then save the modifications
        if ( $recache )
            $this->cache_service->set('cached_search_df_'.$datafield->getId(), $cached_searches);

        // Now that the elements have been combined, convert into the format SearchAPIService
        //  expects...
        $end_result = array(
            'dt_id' => $datafield->getDataType()->getId(),
            'records' => $results
        );

        // ...then return the results of the search
        return $end_result;
    }


    /**
     * Converts the given search term into an array of chemical elements.
     *
     * @param array $search_term
     * @return array
     */
    private function parseValue($search_term)
    {
        $value = mb_strtolower($search_term['value']);

        // Get rid of "smart" quotes
        $value = str_replace(array("“", "”"), '"', $value);

        // If the empty string was given, then search for records without elements
        if ( $value === "\"\"" ) {
            // ...without this condition, there would be no search terms, and therefore there would
            //  never be any records that match
            return array(
                'all' => array(''),
                'at_least_one' => array(),
                'none' => array(),
                'exclude_nonselected' => false,
            );
        }

        // Otherwise...eliminate any commas and quotes, then explode on spaces
        $value = str_replace(array(',', "\"", "\'"), ' ', $value);
        $elements = explode(' ', $value);

        // RRUFF originally had three "groups" of elements in its chemical search...
        // 1) search for minerals with ALL of these elements
        $all = array();
        // 2) search for minerals with AT LEAST ONE of these elements
        $at_least_one = array();
        // 3) search for minerals with NONE of these elements
        $none = array();

        // For the purposes of ODR, categories 2 and 3 are indicated by the characters '~' or '!'
        //  before the element, respectively    TODO - change these with renderPluginOptions?
        $exclude_nonselected = false;
        foreach ($elements as $element) {
            $element = trim($element);
            if ( $element === 'all' ) {
                // This is a special value indicating every element not in $all or $at_least_one
                //  ends up in $none...but since this plugin doesn't enforce a list of elements,
                //  actually using it won't be entirely straightforward...
                $exclude_nonselected = true;
            }
            else if ( $element !== '' ) {
                if ( strpos($element, self::AT_LEAST_ONE_CHAR) !== false )
                    $at_least_one[] = substr($element, 1);
                else if ( strpos($element, self::NONE_CHAR) !== false )
                    $none[] = substr($element, 1);
                else
                    $all[] = $element;
            }
        }

        // If the "exclude nonselected" flag exists, then completely ignore anything in $none
        if ( $exclude_nonselected )
            $none = array();

        return array(
            'all' => $all,
            'at_least_one' => $at_least_one,
            'none' => $none,
            'exclude_nonselected' => $exclude_nonselected,
        );
    }


    /**
     * Returns two arrays of datarecord ids...one array has all the datarecords where the element
     * exists...the other array has all the datarecords where the element does not exist
     *
     * @param string $typeclass
     * @param int $datafield_id
     * @param array $all_datarecord_ids
     * @param string $element
     *
     * @return array
     */
    private function runSearch($typeclass, $datafield_id, $all_datarecord_ids, $element)
    {
        // ----------------------------------------
        // Get all datarecords of this datatype that contain the string $element
        $query =
           'SELECT dr.id AS dr_id, e.value AS element_str
            FROM odr_data_record AS dr
            JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
            JOIN '.$this->typeclass_map[$typeclass].' AS e ON e.data_record_fields_id = drf.id
            WHERE e.data_field_id = :datafield_id AND e.value LIKE :element
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL';
        $params = array(
            'datafield_id' => $datafield_id,
            'element' => '%'.$element.'%',
        );
        $types = array(
            'datafield_id' => ParameterType::INTEGER,
            'element' => ParameterType::STRING,
        );

        // Execute the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, $params, $types);


        // ----------------------------------------
        // The results need another filtering pass...if the user searched for "S", then the results
        //  also currently have everything with "Si", "Sn", etc...this is the entire issue the plugin
        //  is meant to solve
        $selected_datarecords = array();
        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $element_str = mb_strtolower($result['element_str']);

            // The list of elements needs to be exploded...
            $elements = explode(' ', $element_str);
            foreach ($elements as $e) {
                // ...and the datarecord should only be saved if the list contains an exact match
                if ( $element === $e ) {
                    $selected_datarecords[$dr_id] = 1;
                    break;
                }
            }
        }

        // The difference between all the datarecords and the previous list are the unselected
        //  datarecords
        $unselected_datarecords = array_diff_key($all_datarecord_ids, $selected_datarecords);

        return array(
            '0' => $unselected_datarecords,
            '1' => $selected_datarecords
        );
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datafield
     * @param array|null $datarecord
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        // Never want to execute this plugin in the datafield context
        return false;
    }


    /**
     * Executes the RenderPlugin on the provided datafield
     *
     * @param array $datafield
     * @param array|null $datarecord
     * @param array $render_plugin_instance
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $rendering_options)
    {
        // If this is called for some reason, return nothing so ODR's rendering does its thing
        return '';
    }


    /**
     * Returns which of its entries the plugin wants to override in the search sidebar.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $datafield
     * @param array $rendering_options
     *
     * @return array|bool returns true/false if a datafield plugin, or an array of datafield ids if a datatype plugin
     */
    public function canExecuteSearchPlugin($render_plugin_instance, $datatype, $datafield, $rendering_options)
    {
        // Don't need any of the provided parameters to make a decision
        return true;
    }


    /**
     * Returns HTML to override a datafield's entry in the search sidebar.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $datafield
     * @param string|array $preset_value
     * @param array $rendering_options
     *
     * @return string
     */
    public function executeSearchPlugin($render_plugin_instance, $datatype, $datafield, $preset_value, $rendering_options)
    {
        $output = $this->templating->render(
            'ODROpenRepositoryGraphBundle:RRUFF:ChemicalElementsSearch/chemical_elements_search_datafield.html.twig',
            array(
                'datatype' => $datatype,
                'datafield' => $datafield,

                'preset_value' => $preset_value,
            )
        );

        return $output;
    }
}
