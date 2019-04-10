<?php

/**
 * Open Data Repository Data Publisher
 * Search Query Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Creates and runs the various native SQL queries required to accurately search ODR.
 *
 */

namespace ODR\OpenRepository\SearchBundle\Component\Service;

// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class SearchQueryService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var array
     */
    private $typeclass_map;


    /**
     * SearchQueryService constructor.
     *
     * @param EntityManager $entityManager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->logger = $logger;

        $this->typeclass_map = array(
            // All of these are searched via their "value" field in the backend database
            'ShortVarchar' => 'odr_short_varchar',
            'MediumVarchar' => 'odr_medium_varchar',
            'LongVarchar' => 'odr_long_varchar',
            'LongText' => 'odr_long_text',
            'IntegerValue' => 'odr_integer_value',
            'DecimalValue' => 'odr_decimal_value',
            'DatetimeValue' => 'odr_datetime_value',
            'Boolean' => 'odr_boolean',

            // Files/images are searched for by filename and/or existence
            'File' => 'odr_file',
            'Image' => 'odr_image',

            // Searches on radio options require multiple tables in the query
        );
    }


    /**
     * Searches for all datarecords that match the given created/createdBy/modified/modifiedBy
     * criteria.
     *
     * @param int $datatype_id
     * @param string $type
     * @param array $params
     *
     * @return array
     */
    public function searchCreatedModified($datatype_id, $type, $params)
    {
        // ----------------------------------------
        // Convert the given params into SQL query fragments
        $search_params = array(
            'str' => '',
            'params' => array()
        );

        if ($type === 'modified')
            $type = 'updated';

        if ( $type === 'updated' || $type === 'created' ) {
            $search_params['str'] = 'dr.'.$type.' BETWEEN :after AND :before';
            $search_params['params'] = array(
                'datatype_id' => $datatype_id,
                'after' => $params['after']->format('Y-m-d'),
                'before' => $params['before']->format('Y-m-d'),
            );
        }
        else {
            // $type == 'modifiedBy' || $type == 'createdBy'
            $search_params['str'] = 'dr.'.$type.' = :target_user';
            $search_params['params'] = array(
                'datatype_id' => $datatype_id,
                'target_user' => $params['user']
            );
        }


        // ----------------------------------------
        // Define the base query for searching
        $query =
           'SELECT dr.id AS dr_id
            FROM odr_data_record AS dr
            WHERE dr.data_type_id = :datatype_id AND '.$search_params['str'].'
            AND dr.deletedAt IS NULL';


        // ----------------------------------------
        // Execute and return the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, $search_params['params']);

        $datarecords = array();
        foreach ($results as $result)
            $datarecords[ $result['dr_id'] ] = 1;

        return $datarecords;
    }


    /**
     * Searches for all datarecords that have a public status matching $is_public.
     *
     * @param int $datatype_id
     * @param bool $is_public
     *
     * @return array
     */
    public function searchPublicStatus($datatype_id, $is_public)
    {
        // ----------------------------------------
        // Assume by default that caller wants all public datarecords
        $search_params = array(
            'str' => 'drm.public_date != :public_date',
            'params' => array(
                'datatype_id' => $datatype_id,
                'public_date' => '2200-01-01 00:00:00'
            )
        );

        if ( !$is_public ) {
            $search_params = array(
                'str' => 'drm.public_date = :public_date',
                'params' => array(
                    'datatype_id' => $datatype_id,
                    'public_date' => '2200-01-01 00:00:00'
                )
            );
        }

        // Define the base query for searching
        $query =
           'SELECT dr.id AS dr_id
            FROM odr_data_record AS dr
            JOIN odr_data_record_meta AS drm ON drm.data_record_id = dr.id
            WHERE dr.data_type_id = :datatype_id AND '.$search_params['str'].'
            AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL';


        // ----------------------------------------
        // Execute and return the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, $search_params['params']);

        $datarecords = array();
        foreach ($results as $result)
            $datarecords[ $result['dr_id'] ] = 1;

        return $datarecords;
    }


    /**
     * Returns two arrays of datarecord ids...one array has all the datarecords where the radio option
     * is selected...the other array has all the datarecords where the radio option is unselected.
     *
     * @param array $all_datarecord_ids
     * @param int $radio_option_id
     *
     * @return array
     */
    public function searchRadioDatafield($all_datarecord_ids, $radio_option_id)
    {
        // ----------------------------------------
        // Get all datarecords of this datatype where this radio option is selected
        $query =
           'SELECT dr.id AS dr_id
            FROM odr_radio_options AS ro
            JOIN odr_radio_selection AS rs ON rs.radio_option_id = ro.id
            JOIN odr_data_record_fields AS drf ON rs.data_record_fields_id = drf.id
            JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
            WHERE ro.id = :radio_option_id AND rs.selected = 1
            AND ro.deletedAt IS NULL AND rs.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL';

        // Execute the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, array('radio_option_id' => $radio_option_id));

        // The results are the datarecords which are selected...
        $selected_datarecords = array();
        foreach ($results as $result)
            $selected_datarecords[ $result['dr_id'] ] = 1;

        // The difference between all the datarecords and the previous list are the unselected
        //  datarecords
        $unselected_datarecords = array_diff_key($all_datarecord_ids, $selected_datarecords);

        return array(
            '0' => $unselected_datarecords,
            '1' => $selected_datarecords
        );
    }


    /**
     * Returns two arrays of datarecord ids...one array has all the datarecords where the given
     * template radio option is selected...the other array has all the datarecords where it isn't.
     *
     * @param array $all_datarecord_ids
     * @param string $radio_option_uuid
     *
     * @return array
     */
    public function searchRadioTemplateDatafield($all_datarecord_ids, $radio_option_uuid)
    {
        // ----------------------------------------
        // Get all datarecords of this datatype involving this radio option
        $query =
           'SELECT dt.id AS dt_id, df.id AS df_id, rs.selected, dr.id AS dr_id
            FROM odr_data_type AS mdt
            JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
            JOIN odr_data_fields AS df ON df.data_type_id = dt.id
            JOIN odr_radio_options AS ro ON ro.data_fields_id = df.id
            LEFT JOIN odr_radio_selection AS rs ON rs.radio_option_id = ro.id
            LEFT JOIN odr_data_record_fields AS drf ON rs.data_record_fields_id = drf.id
            LEFT JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
            WHERE ro.radio_option_uuid = :radio_option_uuid AND mdt.deletedAt IS NULL
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND ro.deletedAt IS NULL
            AND (rs.deletedAt IS NULL OR rs.selected IS NULL)
            AND (drf.deletedAt IS NULL OR drf.id IS NULL)
            AND (dr.deletedAt IS NULL OR dr.id IS NULL)';

        // Execute the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, array('radio_option_uuid' => $radio_option_uuid));

        // Need to use the search result and $all_datarecord_ids to build two arrays...one of
        //  datarecords that are selected, and another of all datarecords that aren't
        $unselected_datarecords = array();
        $selected_datarecords = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $df_id = $result['df_id'];
            $selected = $result['selected'];
            $dr_id = $result['dr_id'];

            // Datatype/datafield ids should always exist in the array...
            if ( !isset($unselected_datarecords[$dt_id]) ) {
                $unselected_datarecords[$dt_id] = array();
                $selected_datarecords[$dt_id] = array();
            }
            if ( !isset($unselected_datarecords[$dt_id][$df_id]) ) {
                $unselected_datarecords[$dt_id][$df_id] = $all_datarecord_ids[$dt_id];
                $selected_datarecords[$dt_id][$df_id] = array();
            }

            // The results set contains at least one entry for each datatype/datafield pair...
            if ( !is_null($selected) && $selected === '1' ) {
                // ...but only need to do extra stuff when the datarecord is selected
                unset( $unselected_datarecords[$dt_id][$df_id][$dr_id] );
                $selected_datarecords[$dt_id][$df_id][$dr_id] = 1;
            }
        }

        // Filter out empty entries from both arrays
        foreach ($unselected_datarecords as $dt_id => $df_list) {
            foreach ($df_list as $df_id => $dr_list) {
                if ( empty($dr_list) )
                    unset( $unselected_datarecords[$dt_id][$df_id] );
            }
            if ( empty($unselected_datarecords[$dt_id]) )
                unset( $unselected_datarecords[$dt_id] );
        }
        foreach ($selected_datarecords as $dt_id => $df_list) {
            foreach ($df_list as $df_id => $dr_list) {
                if ( empty($dr_list) )
                    unset( $selected_datarecords[$dt_id][$df_id] );
            }
            if ( empty($selected_datarecords[$dt_id]) )
                unset( $selected_datarecords[$dt_id] );
        }

        return array(
            '0' => $unselected_datarecords,
            '1' => $selected_datarecords
        );
    }


    /**
     * Returns two arrays of datarecord ids...one array has all the datarecords where the tag is
     * selected...the other array has all the datarecords where the tag is unselected.
     *
     * This function doesn't care whether the tag it receives is leaf-level or not, but technically
     * it should only receive leaf level tags...non-leaf tags are supposed to be turned into a
     * collection of leaf tags, and each one of those get searched instead.
     *
     * @param array $all_datarecord_ids
     * @param int $tag_id
     *
     * @return array
     */
    public function searchTagDatafield($all_datarecord_ids, $tag_id)
    {
        // ----------------------------------------
        // Get all datarecords of this datatype where this tag is selected
        $query =
           'SELECT dr.id AS dr_id
            FROM odr_tags AS t
            JOIN odr_tag_selection AS ts ON ts.tag_id = t.id
            JOIN odr_data_record_fields AS drf ON ts.data_record_fields_id = drf.id
            JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
            WHERE t.id = :tag_id AND ts.selected = 1
            AND t.deletedAt IS NULL AND ts.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL';

        // Execute the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, array('tag_id' => $tag_id));

        // The results are the datarecords which are selected...
        $selected_datarecords = array();
        foreach ($results as $result)
            $selected_datarecords[ $result['dr_id'] ] = 1;

        // The difference between all the datarecords and the previous list are the unselected
        //  datarecords
        $unselected_datarecords = array_diff_key($all_datarecord_ids, $selected_datarecords);

        return array(
            '0' => $unselected_datarecords,
            '1' => $selected_datarecords
        );
    }


    /**
     * Returns two arrays of datarecord ids...one array has all the datarecords where the given
     * template tag is selected...the other array has all the datarecords where it isn't.
     *
     * @param array $all_datarecord_ids
     * @param string $tag_uuid
     *
     * @return array
     */
    public function searchTagTemplateDatafield($all_datarecord_ids, $tag_uuid)
    {
        // ----------------------------------------
        // Get all datarecords of this datatype involving this tag
        $query =
           'SELECT dt.id AS dt_id, df.id AS df_id, ts.selected, dr.id AS dr_id
            FROM odr_data_type AS mdt
            JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
            JOIN odr_data_fields AS df ON df.data_type_id = dt.id
            JOIN odr_tags AS t ON t.data_fields_id = df.id
            LEFT JOIN odr_tag_selection AS ts ON ts.tag_id = t.id
            LEFT JOIN odr_data_record_fields AS drf ON ts.data_record_fields_id = drf.id
            LEFT JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
            WHERE t.tag_uuid = :tag_uuid AND mdt.deletedAt IS NULL
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND t.deletedAt IS NULL
            AND (ts.deletedAt IS NULL OR ts.selected IS NULL)
            AND (drf.deletedAt IS NULL OR drf.id IS NULL)
            AND (dr.deletedAt IS NULL OR dr.id IS NULL)';

        // Execute the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, array('tag_uuid' => $tag_uuid));

        // Need to use the search result and $all_datarecord_ids to build two arrays...one of
        //  datarecords that are selected, and another of all datarecords that aren't
        $unselected_datarecords = array();
        $selected_datarecords = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $df_id = $result['df_id'];
            $selected = $result['selected'];
            $dr_id = $result['dr_id'];

            // Datatype/datafield ids should always exist in the array...
            if ( !isset($unselected_datarecords[$dt_id]) ) {
                $unselected_datarecords[$dt_id] = array();
                $selected_datarecords[$dt_id] = array();
            }
            if ( !isset($unselected_datarecords[$dt_id][$df_id]) ) {
                $unselected_datarecords[$dt_id][$df_id] = $all_datarecord_ids[$dt_id];
                $selected_datarecords[$dt_id][$df_id] = array();
            }

            // The results set contains at least one entry for each datatype/datafield pair...
            if ( !is_null($selected) && $selected === '1' ) {
                // ...but only need to do extra stuff when the datarecord is selected
                unset( $unselected_datarecords[$dt_id][$df_id][$dr_id] );
                $selected_datarecords[$dt_id][$df_id][$dr_id] = 1;
            }
        }

        // Filter out empty entries from both arrays
        foreach ($unselected_datarecords as $dt_id => $df_list) {
            foreach ($df_list as $df_id => $dr_list) {
                if ( empty($dr_list) )
                    unset( $unselected_datarecords[$dt_id][$df_id] );
            }
            if ( empty($unselected_datarecords[$dt_id]) )
                unset( $unselected_datarecords[$dt_id] );
        }
        foreach ($selected_datarecords as $dt_id => $df_list) {
            foreach ($df_list as $df_id => $dr_list) {
                if ( empty($dr_list) )
                    unset( $selected_datarecords[$dt_id][$df_id] );
            }
            if ( empty($selected_datarecords[$dt_id]) )
                unset( $selected_datarecords[$dt_id] );
        }

        return array(
            '0' => $unselected_datarecords,
            '1' => $selected_datarecords
        );
    }


    /**
     * Searches for all datarecord ids where the given datafield has a selected radio option matching
     * the given value.  Primarily useful for a "general" search.
     *
     * @param int $datafield_id
     * @param string $value
     *
     * @return array
     */
    public function searchForSelectedRadioOptions($datafield_id, $value)
    {
        // ----------------------------------------
        // Convert the given value into an array of parameters
        $is_filename = false;
        // RadioOption name column in db can not be null
        $can_be_null = false;
        $search_params = self::parseField($value, $is_filename, $can_be_null);
        $search_params['params']['datafield_id'] = $datafield_id;

        // The search_param string has "e.value", but needs to have "rom.option_name" instead
        $search_params['str'] = str_replace('e.value', 'rom.option_name', $search_params['str']);

        // Get all datarecords of this datatype where a radio option name matching $value is selected
        $query =
           'SELECT dr.id AS dr_id
            FROM odr_radio_options AS ro
            JOIN odr_radio_options_meta AS rom ON rom.radio_option_id = ro.id
            JOIN odr_radio_selection AS rs ON rs.radio_option_id = ro.id
            JOIN odr_data_record_fields AS drf ON rs.data_record_fields_id = drf.id
            JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
            WHERE ro.data_fields_id = :datafield_id AND rs.selected = 1
            AND ('.$search_params['str'].')
            AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL AND rs.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL';

        // Execute the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, $search_params['params']);

        // The results are the datarecords which are selected...
        $datarecords = array();
        foreach ($results as $result)
            $datarecords[ $result['dr_id'] ] = 1;

        return $datarecords;
    }


    /**
     * Searches for and returns an array that lists all datarecords that have at least one selected
     * radio option from the given template datafield.
     *
     * The parts about radio_option_uuid and option_name are only necessary because of the API
     * route for APIController::getfieldstatsAction()...unfortunately that specific route ends up
     * requiring permissions across datatypes, and it's easier in the long run to hijack the
     * existing template search system, as opposed to re-implementing ~2/3rds of it just for that
     * purpose.
     *
     * @param string $master_template_uuid
     * @param string $master_datafield_uuid
     * @param string $value
     *
     * @return array
     */
    public function searchForSelectedTemplateRadioOptions($master_template_uuid, $master_datafield_uuid, $value)
    {
        // ----------------------------------------
        // Convert the given value into an array of parameters
        $is_filename = false;
        // Tag name column in db can not be null
        $can_be_null = false;
        $search_params = self::parseField($value, $is_filename, $can_be_null);
        $search_params['params']['template_dt_id'] = $master_template_uuid;
        $search_params['params']['template_df_id'] = $master_datafield_uuid;

        // The search_param string has "e.value", but needs to have "rom.option_name" instead
        $search_params['str'] = str_replace('e.value', 'rom.option_name', $search_params['str']);

        $query =
           'SELECT
                dt.id AS dt_id, df.id AS df_id, dr.id AS dr_id

            FROM odr_data_type AS mdt
            JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
            JOIN odr_data_fields AS df ON df.data_type_id = dt.id
            JOIN odr_radio_options AS ro ON ro.data_fields_id = df.id
            JOIN odr_radio_options_meta AS rom ON rom.radio_option_id = ro.id
            JOIN odr_radio_selection AS rs ON rs.radio_option_id = ro.id
            JOIN odr_data_record_fields AS drf ON rs.data_record_fields_id = drf.id
            JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
            WHERE mdt.unique_id = :template_dt_id AND df.template_field_uuid = :template_df_id
            AND ('.$search_params['str'].') AND rs.selected = 1
            AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
            AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL AND rs.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL';


         // Execute the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, $search_params['params']);

        // Convert the results into an array of datarecord ids
        $datarecords = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];

            // Create an array structure so the matching datarecords can be filtered based on user
            //  datatype/datafield permissions later
            if ( !isset($datarecords[$dt_id]) )
                $datarecords[$dt_id] = array();
            if ( !isset($datarecords[$dt_id][$df_id]) )
                $datarecords[$dt_id][$df_id] = array();
            if ( !isset($datarecords[$dt_id][$df_id][$dr_id]) )
                $datarecords[$dt_id][$df_id][$dr_id] = array();

            $datarecords[$dt_id][$df_id][$dr_id] = 1;
        }

        return $datarecords;
    }


    /**
     * Searches for all tags where their name matches the given value.  DOES NOT return datarecord
     * ids.  This only returns tags because $value could match a non-leaf tag, which shouldn't be
     * selected in the first place.
     *
     * @param int $datafield_id
     * @param string $value
     *
     * @return array of tag ids and tag uuids
     */
    public function searchForTagNames($datafield_id, $value)
    {
        // ----------------------------------------
        // Convert the given value into an array of parameters
        $is_filename = false;
        // Tag name column in db can not be null
        $can_be_null = false;
        $search_params = self::parseField($value, $is_filename, $can_be_null);
        $search_params['params']['datafield_id'] = $datafield_id;

        // The search_param string has "e.value", but needs to have "tm.tag_name" instead
        $search_params['str'] = str_replace('e.value', 'tm.tag_name', $search_params['str']);

        // Get all tags that match $value
        $query =
           'SELECT t.id AS t_id, t.tag_uuid AS tag_uuid, tm.tag_name AS tag_name
            FROM odr_tags AS t
            JOIN odr_tag_meta AS tm ON tm.tag_id = t.id
            WHERE t.data_fields_id = :datafield_id
            AND ('.$search_params['str'].')
            AND t.deletedAt IS NULL AND tm.deletedAt IS NULL';

        // Execute the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, $search_params['params']);

        // The results are the datarecords which are selected...
        $tags = array();
        foreach ($results as $result) {
            $tag_id = $result['t_id'];
            $tag_uuid = $result['tag_uuid'];
            $tag_name = $result['tag_name'];

            $tags[$tag_id] = array(
                'tag_uuid' => $tag_uuid,
                'tag_name' => $tag_name,
            );
        }

        return $tags;
    }


    /**
     * SearchService::searchTagTemplateDatafield() needs to be able to return data when it gets
     * passed an empty selections array (see comments in that function).  However, the lack of a
     * tag uuid means SearchQueryService::searchTagTemplateDatafield() won't work...so this function
     * ends up collecting the data to return a similar array where everything is "unselected".
     *
     * @param array $all_datarecord_ids
     * @param int $template_field_id
     *
     * @return array
     */
    public function searchEmptyTagTemplateDatafield($all_datarecord_ids, $template_field_id)
    {
        // ----------------------------------------
        // Get all datafields that have the given template field as their master datafield
        $query =
           'SELECT dt.id AS dt_id, df.id AS df_id
            FROM odr_data_type AS mdt
            JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
            JOIN odr_data_fields AS df ON df.data_type_id = dt.id
            WHERE df.master_datafield_id = :mdf_id
            AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL';

        // Execute the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, array('mdf_id' => $template_field_id));

        $unselected_datarecords = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $df_id = $result['df_id'];

            // Datatype/datafield ids should always exist in the array...
            if ( !isset($unselected_datarecords[$dt_id]) )
                $unselected_datarecords[$dt_id] = array();
            if ( !isset($unselected_datarecords[$dt_id][$df_id]) )
                $unselected_datarecords[$dt_id][$df_id] = $all_datarecord_ids[$dt_id];
        }

        // Filter out empty entries from the array
        foreach ($unselected_datarecords as $dt_id => $df_list) {
            foreach ($df_list as $df_id => $dr_list) {
                if ( empty($dr_list) )
                    unset( $unselected_datarecords[$dt_id][$df_id]) ;
            }
            if ( empty($unselected_datarecords[$dt_id]) )
                unset( $unselected_datarecords[$dt_id] );
        }

        return array(
            '0' => $unselected_datarecords,
            '1' => array()
        );
    }


    /**
     * Rather than attempt to make searchForSelectedTemplateRadioOptions() also work for the API
     * fieldstats request, it makes more sense to have a completely separate query to pull the data
     * for a radio option template datafield.
     *
     * @param int $master_template_uuid
     * @param int $master_datafield_uuid
     *
     * @return array
     */
    public function getRadioOptionTemplateFieldstats($master_template_uuid, $master_datafield_uuid)
    {
        $query =
           'SELECT
                dt.id AS dt_id, df.id AS df_id, dr.id AS dr_id,
                ro.radio_option_uuid, ro.option_name

            FROM odr_data_type AS mdt
            JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
            JOIN odr_data_fields AS df ON df.data_type_id = dt.id
            JOIN odr_radio_options AS ro ON ro.data_fields_id = df.id
            JOIN odr_radio_selection AS rs ON rs.radio_option_id = ro.id
            JOIN odr_data_record_fields AS drf ON rs.data_record_fields_id = drf.id
            JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
            WHERE mdt.unique_id = :template_dt_id AND df.template_field_uuid = :template_df_id
            AND rs.selected = 1
            AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
            AND ro.deletedAt IS NULL AND rs.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND dr.deletedAt IS NULL';

        $params = array(
            'template_dt_id' => $master_template_uuid,
            'template_df_id' => $master_datafield_uuid
        );

        // Execute the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, $params);

        // Turn the result into useful arrays...
        $labels = array();
        $datarecords = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];
            $ro_uuid = $result['radio_option_uuid'];
            $option_name = $result['option_name'];

            // To save a bit of space, store the labels separately
            if ( !isset($labels[$ro_uuid]) )
                $labels[$ro_uuid] = $option_name;

            // Create an array structure so the matching datarecords can be filtered based on user
            //  datatype/datafield permissions later
            if ( !isset($datarecords[$dt_id]) )
                $datarecords[$dt_id] = array();
            if ( !isset($datarecords[$dt_id][$df_id]) )
                $datarecords[$dt_id][$df_id] = array();
            if ( !isset($datarecords[$dt_id][$df_id][$dr_id]) )
                $datarecords[$dt_id][$df_id][$dr_id] = array();

            $datarecords[$dt_id][$df_id][$dr_id][] = $ro_uuid;
        }

        return array(
            'labels' => $labels,
            'records' => $datarecords,
        );
    }


    /**
     * Rather than attempt to make the searchForTagNames() and searchTagTemplateDatafield() functions
     * in the SearchService attempt to do this, it makes more sense to have a completely separate
     * query to get field_stats for a tag template datafield.
     *
     * @param int $master_template_uuid
     * @param int $master_datafield_uuid
     *
     * @return array
     */
    public function getTagTemplateFieldstats($master_template_uuid, $master_datafield_uuid)
    {
        $query =
           'SELECT
                dt.id AS dt_id, df.id AS df_id, dr.id AS dr_id,
                t.tag_uuid, t.tag_name

            FROM odr_data_type AS mdt
            JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
            JOIN odr_data_fields AS df ON df.data_type_id = dt.id
            JOIN odr_tags AS t ON t.data_fields_id = df.id
            JOIN odr_tag_selection AS ts ON ts.tag_id = t.id
            JOIN odr_data_record_fields AS drf ON ts.data_record_fields_id = drf.id
            JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
            WHERE mdt.unique_id = :template_dt_id AND df.template_field_uuid = :template_df_id
            AND ts.selected = 1
            AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
            AND t.deletedAt IS NULL AND ts.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND dr.deletedAt IS NULL';

        $params = array(
            'template_dt_id' => $master_template_uuid,
            'template_df_id' => $master_datafield_uuid
        );

        // Execute the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, $params);

        // Turn the result into useful arrays...
        $labels = array();
        $datarecords = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];
            $tag_uuid = $result['tag_uuid'];
            $tag_name = $result['tag_name'];

            // To save a bit of space, store the labels separately
            if ( !isset($labels[$tag_uuid]) )
                $labels[$tag_uuid] = $tag_name;

            // Create an array structure so the matching datarecords can be filtered based on user
            //  datatype/datafield permissions later
            if ( !isset($datarecords[$dt_id]) )
                $datarecords[$dt_id] = array();
            if ( !isset($datarecords[$dt_id][$df_id]) )
                $datarecords[$dt_id][$df_id] = array();
            if ( !isset($datarecords[$dt_id][$df_id][$dr_id]) )
                $datarecords[$dt_id][$df_id][$dr_id] = array();

            $datarecords[$dt_id][$df_id][$dr_id][] = $tag_uuid;
        }

        return array(
            'labels' => $labels,
            'records' => $datarecords,
        );
    }


    /**
     * Searches a file/image datafield for a filename and/or whether it has files or not, returning
     * an array of datarecord ids that match the criteria
     *
     * @param int $datatype_id
     * @param int $datafield_id
     * @param string $typeclass
     * @param string|null $filename
     * @param bool $has_files
     *
     * @return array
     */
    public function searchFileOrImageDatafield($datatype_id, $datafield_id, $typeclass, $filename, $has_files)
    {
        // ----------------------------------------
        // Figure out which type of query to use
        $conn = $this->em->getConnection();
        $results = array();

        if ( !is_null($filename) && $filename !== '' ) {
            // Filename could have logical terms in it
            $is_filename = true;
            // Filename column in db can not be null
            $can_be_null = false;
            $search_params = self::parseField($filename, $is_filename, $can_be_null);
            $search_params['params']['datafield_id'] = $datafield_id;

            $filename_match_query =
               'SELECT dr.id AS dr_id
                FROM odr_data_record AS dr
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN '.$this->typeclass_map[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                JOIN '.$this->typeclass_map[$typeclass].'_meta AS e_m ON e_m.'.strtolower($typeclass).'_id = e.id
                WHERE e.data_field_id = :datafield_id AND ('.$search_params['str'].')
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL
                AND e.deletedAt IS NULL AND e_m.deletedAt IS NULL';

            $results = $conn->fetchAll($filename_match_query, $search_params['params']);
        }
        else if ($has_files) {
            $has_files_query =
               'SELECT dr.id AS dr_id
                FROM odr_data_record AS dr
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN '.$this->typeclass_map[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE e.data_field_id = :datafield_id
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL';

            // Don't need any special parameters when searching for which datarecords have files
            $params = array(
                'datafield_id' => $datafield_id
            );

            $results = $conn->fetchAll($has_files_query, $params);
        }
        else {
            $does_not_have_files_query =
               'SELECT dr.id AS dr_id
                FROM odr_data_record AS dr
                LEFT JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id AND ((drf.data_field_id = '.$datafield_id.' AND drf.deletedAt IS NULL) OR drf.id IS NULL)
                LEFT JOIN '.$this->typeclass_map[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE dr.data_type_id = :datatype_id AND e.id IS NULL AND dr.deletedAt IS NULL';

            // Don't need any special parameters when searching for which datarecords don't have files
            $params = array(
                'datatype_id' => $datatype_id
            );

            $results = $conn->fetchAll($does_not_have_files_query, $params);
        }

        // Convert the results into an array of datarecord ids
        $datarecords = array();
        foreach ($results as $result)
            $datarecords[ $result['dr_id'] ] = 1;

        return $datarecords;
    }


    /**
     * Searches the specified File/Image template datafield for a filename and/or whether it has
     * files or not, returning an array of datarecord ids that match the criteria
     *
     * @param string $master_datafield_uuid
     * @param string $typeclass
     * @param string|null $filename
     * @param bool $has_files
     *
     * @return array
     */
    public function searchFileOrImageTemplateDatafield($master_datafield_uuid, $typeclass, $filename, $has_files)
    {
        // ----------------------------------------
        // Figure out which type of query this is
        $conn = $this->em->getConnection();
        $results = array();

        if ( !is_null($filename) && $filename !== '' ) {
            // Filename could have logical terms in it
            $is_filename = true;
            // Filename column in db can not be null
            $can_be_null = false;
            $search_params = self::parseField($filename, $is_filename, $can_be_null);
            $search_params['params']['template_df_id'] = $master_datafield_uuid;

            $filename_match_query =
               'SELECT dt.id AS dt_id, df.id AS df_id, dr.id AS dr_id
                FROM odr_data_type AS mdt
                JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
                JOIN odr_data_fields AS df ON df.data_type_id = dt.id
                JOIN odr_data_record_fields AS drf ON drf.data_field_id = df.id
                JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
                JOIN '.$this->typeclass_map[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                JOIN '.$this->typeclass_map[$typeclass].'_meta AS e_m ON e_m.'.strtolower($typeclass).'_id = e.id
                WHERE df.template_field_uuid = :template_df_id AND ('.$search_params['str'].')
                AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND e.deletedAt IS NULL AND e_m.deletedAt IS NULL';

            $results = $conn->fetchAll($filename_match_query, $search_params['params']);
        }
        else if ($has_files) {
            $has_files_query =
               'SELECT dt.id AS dt_id, df.id AS df_id, dr.id AS dr_id
                FROM odr_data_type AS mdt
                JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
                JOIN odr_data_fields AS df ON df.data_type_id = dt.id
                JOIN odr_data_record_fields AS drf ON drf.data_field_id = df.id
                JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
                JOIN '.$this->typeclass_map[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE df.template_field_uuid = :template_df_id
                AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND e.deletedAt IS NULL';

            // Don't need any special parameters when searching for which datarecords have files
            $params = array(
                'template_df_id' => $master_datafield_uuid
            );

            $results = $conn->fetchAll($has_files_query, $params);
        }
        else {
            $does_not_have_files_query =
               'SELECT dt.id AS dt_id, df.id AS df_id, dr.id AS dr_id
                FROM odr_data_type AS mdt
                JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
                JOIN odr_data_record AS dr ON dr.data_type_id = dt.id
                JOIN odr_data_fields AS df ON df.data_type_id = dt.id
                LEFT JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id AND ((drf.data_field_id = df.id AND drf.deletedAt IS NULL) OR drf.id IS NULL)
                LEFT JOIN '.$this->typeclass_map[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE df.template_field_uuid = :template_df_id AND e.id IS NULL
                AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND df.deletedAt IS NULL';

            // Don't need any special parameters when searching for which datarecords don't have files
            $params = array(
                'template_df_id' => $master_datafield_uuid
            );

            $results = $conn->fetchAll($does_not_have_files_query, $params);
        }

        // Convert the results into an array of datarecord ids
        $datarecords = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];

            // Create an array structure so the matching datarecords can be filtered based on user
            //  datatype/datafield permissions later
            if ( !isset($datarecords[$dt_id]) )
                $datarecords[$dt_id] = array();
            if ( !isset($datarecords[$dt_id][$df_id]) )
                $datarecords[$dt_id][$df_id] = array();
            if ( !isset($datarecords[$dt_id][$df_id][$dr_id]) )
                $datarecords[$dt_id][$df_id][$dr_id] = array();

            $datarecords[$dt_id][$df_id][$dr_id] = 1;
        }

        return $datarecords;
    }


    /**
     * Searches the specified DatetimeValue datafield for the given values, returning an array of
     * datarecord ids that match the search.
     *
     * @param int $datafield_id
     * @param array $params
     *
     * @return array
     */
    public function searchDatetimeDatafield($datafield_id, $params)
    {
        // ----------------------------------------
        // Convert the given params into SQL query fragments
        $search_params = array(
            'str' => 'e.value BETWEEN :after AND :before',
            'params' => array(
                'datafield_id' => $datafield_id,
                'after' => $params['after']->format('Y-m-d'),
                'before' => $params['before']->format('Y-m-d')
            )
        );


        // ----------------------------------------
        // TODO - provide the option to search for fields without dates?
        // Define the base query for searching
        $typeclass = 'DatetimeValue';
        $query =
           'SELECT dr.id AS dr_id
            FROM odr_data_record AS dr
            JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
            JOIN '.$this->typeclass_map[$typeclass].' AS e ON e.data_record_fields_id = drf.id
            WHERE e.data_field_id = :datafield_id AND ('.$search_params['str'].')
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL';


        // ----------------------------------------
        // Execute and return the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, $search_params['params']);

        $datarecords = array();
        foreach ($results as $result)
            $datarecords[ $result['dr_id'] ] = 1;

        return $datarecords;
    }


    /**
     * Searches the specified DatetimeValue template datafield for the given values, returning an
     * array of datarecord ids that match the search.
     *
     * @param string $master_datafield_uuid
     * @param array $params
     *
     * @return array
     */
    public function searchDatetimeTemplateDatafield($master_datafield_uuid, $params)
    {
        // ----------------------------------------
        // Convert the given params into SQL query fragments
        $search_params = array(
            'str' => 'e.value BETWEEN :after AND :before',
            'params' => array(
                'template_df_id' => $master_datafield_uuid,
                'after' => $params['after']->format('Y-m-d'),
                'before' => $params['before']->format('Y-m-d')
            )
        );


        // ----------------------------------------
        // TODO - provide the option to search for fields without dates?
        // Define the base query for searching
        $typeclass = 'DatetimeValue';
        $query =
           'SELECT dt.id AS dt_id, df.id AS df_id, dr.id AS dr_id
            FROM odr_data_type AS mdt
            JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
            JOIN odr_data_fields AS df ON df.data_type_id = dt.id
            JOIN odr_data_record_fields AS drf ON drf.data_field_id = df.id
            JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
            JOIN '.$this->typeclass_map[$typeclass].' AS e ON e.data_record_fields_id = drf.id
            WHERE df.template_field_uuid = :template_df_id AND ('.$search_params['str'].')
            AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL';


        // ----------------------------------------
        // Execute and return the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, $search_params['params']);

        $datarecords = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $df_id = $result['df_id'];
            $dr_id = $result['dr_id'];

            // Create an array structure so the matching datarecords can be filtered based on user
            //  datatype/datafield permissions later
            if ( !isset($datarecords[$dt_id]) )
                $datarecords[$dt_id] = array();
            if ( !isset($datarecords[$dt_id][$df_id]) )
                $datarecords[$dt_id][$df_id] = array();
            if ( !isset($datarecords[$dt_id][$df_id][$dr_id]) )
                $datarecords[$dt_id][$df_id][$dr_id] = array();

            $datarecords[$dt_id][$df_id][$dr_id] = 1;
        }

        return $datarecords;
    }


    /**
     * Searches the specified datafield for the specified value, returning an array of
     * datarecord ids that match the search.
     *
     * @param int $datatype_id
     * @param int $datafield_id
     * @param string $typeclass
     * @param string $value
     *
     * @return array
     */
    public function searchTextOrNumberDatafield($datatype_id, $datafield_id, $typeclass, $value)
    {
        // ----------------------------------------
        // Convert the given value into an array of parameters
        $is_filename = false;

        // The value stored in the text-based datafields searched by this can't be null...
        $can_be_null = false;
        if ($typeclass === 'IntegerValue' || $typeclass === 'DecimalValue')
            // ...but the value stored in the number-based datafields can
            $can_be_null = true;

        $search_params = self::parseField($value, $is_filename, $can_be_null);
        $search_params['params']['datafield_id'] = $datafield_id;

        // Define the base query for searching
        $query =
           'SELECT dr.id AS dr_id
            FROM odr_data_record AS dr
            JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
            JOIN '.$this->typeclass_map[$typeclass].' AS e ON e.data_record_fields_id = drf.id
            WHERE e.data_field_id = :datafield_id AND ('.$search_params['str'].')
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL';

        // Also define the query used when one of the search parameters is the empty string
        $null_query =
           'SELECT dr.id AS dr_id
            FROM odr_data_record AS dr
            LEFT JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id AND ((drf.data_field_id = '.$datafield_id.' AND drf.deletedAt IS NULL) OR drf.id IS NULL)
            LEFT JOIN '.$this->typeclass_map[$typeclass].' AS e ON e.data_record_fields_id = drf.id
            WHERE dr.data_type_id = :datatype_id AND e.id IS NULL AND dr.deletedAt IS NULL';
        // This query won't pick up cases where the drf exists and the storage entity was deleted,
        //  but that shouldn't happen...if it does, most likely it's either a botched fieldtype
        //  migration, or a change to the contents of a storage entity didn't complete properly


        // ----------------------------------------
        // Determine whether this query's search parameters contain an empty string...if so, may
        //  have to to run an additional query because of how ODR is designed...
        if ( self::isNullDrfPossible($search_params['str'], $search_params['params']) ) {
            // ...but only when the query actually has a logical chance of returning results...
            if ( self::canQueryReturnResults($search_params['str'], $search_params['params']) ) {
                $search_params['params']['datatype_id'] = $datatype_id;
                $query .= "\nUNION\n".$null_query;
            }
        }


        // ----------------------------------------
        // Execute and return the native SQL query
        $conn = $this->em->getConnection();
        $results = $conn->fetchAll($query, $search_params['params']);

        $datarecords = array();
        foreach ($results as $result)
            $datarecords[ $result['dr_id'] ] = 1;

        return $datarecords;
    }


    /**
     * Searches the specified datafield for the specified value, returning an array of
     * datarecord ids that match the search.
     *
     * @param string $master_datafield_uuid
     * @param string $typeclass
     * @param string $value
     *
     * @return array
     */
    public function searchTextOrNumberTemplateDatafield($master_datafield_uuid, $typeclass, $value)
    {
        $typeclasses = array(
            0 => 'odr_short_varchar',
            1 => 'odr_medium_varchar',
            2 => 'odr_long_varchar',
            3 => 'odr_long_text',
            4 => 'odr_integer_value',
            5 => 'odr_decimal_value',
        );
        $fieldtypes = array(
            0 => 'ShortVarchar',
            1 => 'MediumVarchar',
            2 => 'LongVarchar',
            3 => 'LongText',
            4 => 'IntegerValue',
            5 => 'DecimalValue',
        );
        $params = array();
        $queries = array();
        $null_queries = array(
            0 => false,
            1 => false,
            2 => false,
            3 => false,
            4 => false,
            5 => false,
        );


        // ----------------------------------------
        // Convert the given value into two arrays of parameters...
        $is_filename = false;

        // ...one for the text fieldtypes because their value columns can't store nulls...
        $search_params_text = self::parseField($value, $is_filename, false);
        $search_params_text['params']['template_df_id'] = $master_datafield_uuid;
        $params[0] = $params[1] = $params[2] = $params[3] = $search_params_text;

        // ...and a second for the numericla fieldtypes because their value columns can store nulls
        $search_params_num = self::parseField($value, $is_filename, true);
        $search_params_num['params']['template_df_id'] = $master_datafield_uuid;
        $params[4] = $params[5] = $search_params_num;


        // Determine whether this query's search parameters contain an empty string...if so, may
        //  have to to run an additional query because of how ODR is designed...
        if ( self::isNullDrfPossible($search_params_text['str'], $search_params_text['params']) ) {
            // ...but only when the query actually has a logical chance of returning results...
            if ( self::canQueryReturnResults($search_params_text['str'], $search_params_text['params']) ) {
                $null_queries[0] = $null_queries[1] = $null_queries[2] = $null_queries[3] = true;
            }
        }

        // ...I believe that currently, the question "is a null drf possible" will always have the
        //  same answer for both a text query and a numerical query...keeping them separate just
        //  in case, though...
        if ( self::isNullDrfPossible($search_params_num['str'], $search_params_num['params']) ) {
            if ( self::canQueryReturnResults($search_params_num['str'], $search_params_num['params']) ) {
                $null_queries[4] = $null_queries[5] = true;
            }
        }


        // ----------------------------------------
        // Define the base queries for each of the typeclasses to be searched on
        foreach ($typeclasses as $id => $typeclass) {
            $queries[$id] =
                // The joins to the DatafieldMeta and FieldType tables aren't strictly necessary
                //  in this query, but they are in the subsequent null query...
               'SELECT dt.id AS dt_id, df.id AS df_id, dr.id AS dr_id
                FROM odr_data_type AS mdt
                JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
                JOIN odr_data_fields AS df ON df.data_type_id = dt.id
                JOIN odr_data_fields_meta AS dfm ON dfm.data_field_id = df.id
                JOIN odr_field_type AS ft ON dfm.field_type_id = ft.id
                JOIN odr_data_record_fields AS drf ON drf.data_field_id = df.id
                JOIN odr_data_record AS dr ON drf.data_record_id = dr.id
                JOIN '.$typeclass.' AS e ON e.data_record_fields_id = drf.id
                WHERE df.template_field_uuid = :template_df_id AND ft.type_class = "'.$fieldtypes[$id].'"
                AND ('.$params[$id]['str'].')
                AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL';

            if ($null_queries[$id]) {
                $queries[$id] .= "\nUNION\n".
                   'SELECT dt.id AS dt_id, df.id AS df_id, dr.id AS dr_id
                    FROM odr_data_type AS mdt
                    JOIN odr_data_type AS dt ON dt.master_datatype_id = mdt.id
                    JOIN odr_data_record AS dr ON dr.data_type_id = dt.id
                    JOIN odr_data_fields AS df ON df.data_type_id = dt.id
                    JOIN odr_data_fields_meta AS dfm ON dfm.data_field_id = df.id
                    JOIN odr_field_type AS ft ON dfm.field_type_id = ft.id
                    LEFT JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id AND ((drf.data_field_id = df.id AND drf.deletedAt IS NULL) OR drf.id IS NULL)
                    LEFT JOIN '.$typeclass.' AS e ON e.data_record_fields_id = drf.id
                    WHERE df.template_field_uuid = :template_df_id AND ft.type_class = "'.$fieldtypes[$id].'"
                    AND e.id IS NULL
                    AND mdt.deletedAt IS NULL AND dt.deletedAt IS NULL
                    AND dr.deletedAt IS NULL AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL';

                // This query won't pick up cases where the drf exists and the storage entity was
                //  deleted, but that shouldn't happen...if it does, most likely it's either a
                //  botched fieldtype migration, or a change to the contents of a storage entity
                //  didn't complete properly
            }
        }


        // ----------------------------------------
        // Execute each of the native SQL queries
        $conn = $this->em->getConnection();

        $results = array();
        foreach ($typeclasses as $id => $typeclass)
            $results[$id] = $conn->fetchAll($queries[$id], $params[$id]['params']);

        // Create an array structure so the matching datarecords can be filtered based on user
        //  datatype/datafield permissions later
        $datarecords = array();
        foreach ($results as $id => $result_set) {
            foreach ($result_set as $result) {
                $dt_id = $result['dt_id'];
                $df_id = $result['df_id'];
                $dr_id = $result['dr_id'];

                if ( !isset($datarecords[$dt_id]) )
                    $datarecords[$dt_id] = array();
                if ( !isset($datarecords[$dt_id][$df_id]) )
                    $datarecords[$dt_id][$df_id] = array();
                if ( !isset($datarecords[$dt_id][$df_id][$dr_id]) )
                    $datarecords[$dt_id][$df_id][$dr_id] = array();
                $datarecords[$dt_id][$df_id][$dr_id] = 1;
            }
        }

        return $datarecords;
    }


    /**
     * Determines whether the provided string of MYSQL conditions potentially matches the empty
     * string...this is needed because ODR considers nonexistent datarecordfield or storage entities
     * to be the same as the empty string, while the two are quite different to the underlying
     * database.
     *
     * @param string $str
     * @param array $params
     *
     * @return boolean
     */
    private function isNullDrfPossible($str, $params)
    {
        // Roughly speaking, there are seven possibilities...
        // search for: ""   => e.value = ""                   could match null drf
        // search for: !""  => e.value != ""                  can't match null drf
        // search for:  a   => e.value LIKE "<something>"     can't match null drf
        // search for: !a   => e.value NOT LIKE "<something>" could match null drf
        // search for: "a"  => e.value = "<something>"        can't match null drf
        // search for: !"a" => e.value != "<something>"       could match null drf

        // searches involving inequalities (e.g. <, >, <=, >=) can't match null drf

        // Because right now the user isn't allowed to group logical operators, this single php
        //  statment will effectively suffice for determining MYSQL order of operations
        // Individual statements connected by AND will be executed first...the results of each
        //  block of ANDs will then be ORed together
        $blocks = explode(' OR ', $str);

        $results = array();
        foreach ($blocks as $block) {
            $possible = true;

            $pieces = explode(' AND ', $block);
            foreach ($pieces as $piece) {
                $char = $piece{8};
                if ($char === 'L') {
                    // searching for  e.value LIKE <something>  ...can't be null
                    $possible = false;
                }
                else if ($char === 'N') {
                    // searching for  e.value NOT LIKE <something>  ...can be null
                }
                else if ($char === '<' || $char === '>') {
                    // searching on some inequality...can't be null
                    $possible = false;
                }
                else {
                    // searching on equality...need to look into the params list...
                    $term = substr($piece, strpos($piece, ':')+1);

                    if ($char === '!' && $params[$term] === '') {
                        // seaching for  e.value != "" ...can't be null
                        $possible = false;
                    }
                    else if ($char === '=' && $params[$term] !== '') {
                        // searching for  e.value = "<something>"  ...can't be null
                        $possible = false;
                    }
                }
            }

            $results[] = $possible;
        }

        // If any part of this query could legitimately have the empty string as a result, return true
        $null_drf_is_possible = false;
        foreach ($results as $num => $result)
            $null_drf_is_possible = $null_drf_is_possible || $result;

        return $null_drf_is_possible;
    }


    /**
     * Determines whether the provided string of MYSQL conditions has a chance of returning search
     * results or not.  If it has no chance of returning results, then the union query that locates
     * null drf entries shouldn't be run...it would return datarecords that only match part of the
     * query, instead of all.
     *
     * @param string $str
     * @param array $params
     *
     * @return boolean
     */
    private function canQueryReturnResults($str, $params)
    {
        // Because right now the user isn't allowed to group logical operators, this single php
        //  statment will effectively suffice for determining MYSQL order of operations
        // Individual statements connected by AND will be executed first...the results of each block
        //  of ANDs will then be ORed together
        $pieces = explode(' OR ', $str);

        $results = array();
        foreach ($pieces as $piece) {
            if ( strpos($piece, 'AND') === false ) {
                // A single entry at this point of the array always has the chance to evaluate to true
                $results[] = true;
            }
            else {
                // If there are multiple exact matches required...
                // e.g. e.value = :term_x AND e.value = :term_y
                if ( substr_count($piece, '=') > 1 ) {
                    // ...then unless each term of each exact match is identical, this piece of the
                    //  query is guaranteed to return false...e.value can't be equal to 'a' and
                    //  equal to 'b' at the same time, for instance

                    // Determine which of these search terms must be exact
                    $matches = array();
                    $pattern = '/ = :(term_\d+)/';
                    preg_match_all($pattern, $piece, $matches);

                    // Get the unique list of all of the search terms
                    $terms = array();
                    foreach ($matches[1] as $match)
                        $terms[] = $params[$match];
                    $terms = array_unique($terms);

                    // If all of the search terms are the same, then this piece of the search query
                    //  has the chance to evaluate to true...otherwise, it will never evaluate to true
                    if ( count($terms) == 1 )
                        $results[] = true;
                    else
                        $results[] = false;
                }
            }
        }

        // If any part of this query has the chance of returning true, then actual search results
        //  are possible
        $results_are_possible = false;
        foreach ($results as $num => $result)
            $results_are_possible = $results_are_possible || $result;

        return $results_are_possible;
    }



    /**
     * Turns a piece of the search string into a more SQL-friendly format.
     *
     * @param string $str The string to turn into SQL...
     * @param bool $is_filename Whether this is being parsed as a filename or not
     * @param bool $can_be_null Whether the underlying database column allows null values or not
     *
     * @return array
     */
    private function parseField($str, $is_filename, $can_be_null) {
        // ?
        $str = str_replace(array("\n", "\r"), '', $str);
/*
if ( isset($debug['search_string_parsing']) ) {
    print "\n".'--------------------'."\n";
    print $str."\n";
}
*/
        $pieces = array();
        $in_quotes = false;
        $tmp = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];

            if ($char == "\"") {
                if ($in_quotes) {
                    // found closing quote
                    $in_quotes = false;

                    // save fragment
                    $tmp .= "\"";
                    $pieces[] = $tmp;
                    $tmp = '';

                    // skip over next character?
//                    $i++;
                }
                else {
                    // found opening quote
                    $in_quotes = true;
                    $tmp = "\"";
                }
            }
            else {
                if ($in_quotes) {
                    // append to fragment
                    $tmp .= $char;
                }
                else {
                    switch ($char) {
                        case ' ':
                            // save any existing piece before saving the operator
                            if ($tmp !== '') {
                                $pieces[] = $tmp;
                                $tmp = '';
                            }
                            $pieces[] = '&&';
                            break;
                        case '!':
//                        case '-':
                            // attempt to ignore the operator if not attached to a term
                            /*if ( $str[$i+1] !== ' ' )*/
                            $pieces[] = '!';
                            break;
                        case '>':
                            // attempt to ignore the operator if not attached to a term
                            if ( $str[$i+1] == '=' /*&& $str[$i+2] !== ' '*/ ) {
                                $pieces[] = '>=';
                                $i++;
                            }
                            else /*if ( $str[$i+1] !== ' ' )*/
                                $pieces[] = '>';
                            break;
                        case '<':
                            // attempt to ignore the operator if not attached to a term
                            if ( $str[$i+1] == '=' /*&& $str[$i+2] !== ' '*/ ) {
                                $pieces[] = '<=';
                                $i++;
                            }
                            else /*if ( $str[$i+1] !== ' ' )*/
                                $pieces[] = '<';
                            break;
                        case 'o':
                        case 'O':
                            // only count this as an operator if the 'O' is part of the substring ' OR '
                            if ( $i != 0 && $str[$i-1] == ' ' && ($str[$i+1] == 'R' || $str[$i+1] == 'r') && $str[$i+2] == ' ' ) {
                                $pieces[] = '||';
//                                $i++;
                                $i += 2;

                                // cut out the 'AND' token that was added as a result of the preceding space
//                                if ( $pieces[count($pieces)-2] == '&&' )
//                                    unset( $pieces[count($pieces)-2] );
                            }
                            else {
                                // otherwise, part of a string
                                $tmp .= $char;
                            }
                            break;
                        default:
                            // part of a string
                            $tmp .= $char;
                            break;
                    }
                }
            }
        }
        // save any remaining piece
        if ($tmp !== '')
            $pieces[] = $tmp;
/*
if ( isset($debug['search_string_parsing']) )
    print_r($pieces);
*/
        // clean up the array as best as possible
        $pieces = array_values($pieces);
        $first = true;
        $previous = 0;
        foreach ($pieces as $num => $piece) {
            // prevent operators needing two operands from being out in front
            if ( $first && self::isConnective($piece) ) {
                unset( $pieces[$num] );
                continue;
            }
            // save the first "good" token
            if ($first) {
                $first = false;
                $previous = $piece;
                continue;
            }

            if ( !isset($pieces[$num]) || !isset($pieces[$num+1]) )
                continue;

            // Delete some consecutive logical operators
            if ( $pieces[$num] == '&&' && $pieces[$num+1] == '&&' )
                unset( $pieces[$num] );
            else if ( $pieces[$num] == '&&' && $pieces[$num+1] == '||' )
                unset( $pieces[$num] );
            else if ( self::isConnective($previous) && self::isConnective($piece) )
                unset( $pieces[$num] );
            // delete operators after inequalities
            else if ( self::isInequality($previous) && (self::isConnective($piece) || self::isInequality($piece)) )
                unset( $pieces[$num] );
            // legitimate token
            else
                $previous = $piece;
        }

        // remove trailing operators...they're unmatched by definition
        $pieces = array_values($pieces);
        $num = count($pieces)-1;
        if ( self::isLogicalOperator($pieces[$num]) || self::isInequality($pieces[$num]) )
            unset( $pieces[$num] );
/*
if ( isset($debug['search_string_parsing']) )
    print_r($pieces);
*/
        $negate = false;
        $inequality = false;
        $searching_on_null = false;
        $parameters = array();

        $str = 'e.value';
        if ($is_filename)
            $str = 'e_m.original_file_name';

        $count = 0;
        foreach ($pieces as $num => $piece) {
            if ($piece == '!') {
                $negate = true;
            }
            else if ($piece == '&&') {
                if (!$is_filename)
                    $str .= ' AND e.value';
                else
                    $str .= ' AND e_m.original_file_name';
            }
            else if ($piece == '||') {
                if (!$is_filename)
                    $str .= ' OR e.value';
                else
                    $str .= ' OR e_m.original_file_name';
            }
            else if ($piece == '>') {
                $inequality = true;
                if ($negate)
                    $str .= ' <= ';
                else
                    $str .= ' > ';
            }
            else if ($piece == '<') {
                $inequality = true;
                if ($negate)
                    $str .= ' >= ';
                else
                    $str .= ' < ';
            }
            else if ($piece == '>=') {
                $inequality = true;
                if ($negate)
                    $str .= ' < ';
                else
                    $str .= ' >= ';
            }
            else if ($piece == '<=') {
                $inequality = true;
                if ($negate)
                    $str .= ' > ';
                else
                    $str .= ' <= ';
            }
            else {
                if (!$inequality) {
                    if ( $piece === "\"\"" && $can_be_null ) {
                        if ($negate)
                            $str .= ' IS NOT NULL ';
                        else
                            $str .= ' IS NULL ';

                        $searching_on_null = true;
                    }
                    else if ( strpos($piece, "\"") !== false ) {  // does have a quote
                        $piece = str_replace("\"", '', $piece);
                        if ( is_numeric($piece) )
                            if ( strpos($piece, '.') === false )
                                $piece = intval($piece);
                            else
                                $piece = floatval($piece);

                        if ($negate)
                            $str .= ' != ';
                        else
                            $str .= ' = ';
                    }
                    else {
                        // MYSQL escape characters due to use of LIKE
                        $piece = str_replace("\\", '\\\\', $piece);     // replace backspace character with double backspace
                        $piece = str_replace( array('%', '_'), array('\%', '\_'), $piece);   // escape existing percent and understore characters

                        $piece = '%'.$piece.'%';
                        if ($negate)
                            $str .= ' NOT LIKE ';
                        else
                            $str .= ' LIKE ';
                    }
                }
                else if ( is_numeric($piece) ) {
                    if ( strpos($piece, '.') === false )
                        $piece = intval($piece);
                    else
                        $piece = floatval($piece);
                }
                $negate = false;
                $inequality = false;

                if ($searching_on_null) {
                    //
                    $searching_on_null = false;
                }
                else {
                    //
                    $str .= ':term_'.$count;
                    $parameters['term_'.$count] = $piece;
                    $count++;
                }
            }
        }
        $str = trim($str);
/*
if ( isset($debug['search_string_parsing']) ) {
    print $str."\n";
    print_r($parameters);
    print "\n".'--------------------'."\n";
}
*/
        return array('str' => $str, 'params' => $parameters);
    }


    /**
     * Returns true if the string describes a binary operator  a && b, a || b
     *
     * @param string $str The string to test
     *
     * @return boolean
     */
    private function isConnective($str) {
        if ( $str == '&&' || $str == '||' )
            return true;
        else
            return false;
    }


    /**
     * Returns true if the string describes a logical operator  &&, ||, !
     *
     * @param string $str The string to test
     *
     * @return boolean
     */
    private function isLogicalOperator($str) {
        if ( $str == '&&' || $str == '||' || $str == '!' )
            return true;
        else
            return false;
    }


    /**
     * Returns true if the string describes an inequality
     *
     * @param string $str The string to test
     *
     * @return boolean
     */
    private function isInequality($str) {
        if ( $str == '>=' || $str == '<=' || $str == '<' || $str == '>' )
            return true;
        else
            return false;
    }


    /**
     * Runs a query to return an array where the keys are datarecords ids belonging to the given
     * datatype, and the values are the ids of their parent.
     *
     * @param int $datatype_id
     *
     * @return array
     */
    public function getParentDatarecords($datatype_id)
    {
        // Define the base query
        $query = $this->em->createQuery(
           'SELECT dr.id AS id, parent.id AS parent_id
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
            JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
            WHERE dr.dataType = :datatype_id
            AND dr.deletedAt IS NULL
            AND parent.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype_id) );
        $results = $query->getArrayResult();

        // Child datarecords can only have a single parent datarecord
        $datarecords = array();
        foreach ($results as $result)
            $datarecords[ $result['id'] ] = $result['parent_id'];

        return $datarecords;
    }


    /**
     * Runs a query to return an array where the keys are datarecords ids belonging to the given
     * datatype, and the values are the ids of the datarecords that link to them.
     *
     * @param int $datatype_id
     *
     * @return array
     */
    public function getLinkedParentDatarecords($datatype_id)
    {
        // This function is only called when trying to build a list of all related datarecords from
        //  the point of view of the ancestor, therefore this intentionally does not return
        //  descendant datarecords that aren't linked to from some ancestor datarecord...
        $query = $this->em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
            FROM ODRAdminBundle:DataRecord AS ancestor
            JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
            JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
            WHERE descendant.dataType = :datatype_id
            AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL
            AND ldt.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $datatype_id) );
        $results = $query->getArrayResult();

        // Linked datarecords can have multiple ancestor datarecords
        $datarecords = array();
        foreach ($results as $result) {
            $ancestor_id = $result['ancestor_id'];
            $descendant_id = $result['descendant_id'];

            if ( !isset($datarecords[$descendant_id]) )
                $datarecords[$descendant_id] = array();

            $datarecords[$descendant_id][$ancestor_id] = '';
        }

        return $datarecords;
    }
}
