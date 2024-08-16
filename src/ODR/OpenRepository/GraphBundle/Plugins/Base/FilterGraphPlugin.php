<?php

/**
 * Open Data Repository Data Publisher
 * Filter Graph Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This version of the graph plugin is somewhat better suited for large numbers of displayed files,
 * mostly by creating a collection of filters based on the values of the fields in the related
 * datatypes, which can be used to set which files plotly will display.
 *
 * As a result of having potentially dozens of spectra per graph, displaying the actual values in
 * the fields is less important and they are hidden by default...they will only display when the
 * graph is down to one file, or the user wants them to show up.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginOptionsDef;
use ODR\AdminBundle\Entity\RenderPluginOptionsMap;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\PluginOptionsChangedEvent;
// Services
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\ODRGraphPlugin;
use ODR\OpenRepository\GraphBundle\Plugins\PluginSettingsDialogOverrideInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Bridge\Monolog\Logger;
// Other
use Pheanstalk\Pheanstalk;
use Ramsey\Uuid\Uuid;


class FilterGraphPlugin extends ODRGraphPlugin implements DatatypePluginInterface, PluginSettingsDialogOverrideInterface
{

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var CryptoService
     */
    private $crypto_service;

    /**
     * @var DatabaseInfoService
     */
    private $database_info_service;

    /**
     * @var string
     */
    private $odr_web_directory;

    /**
     * @var string
     */
    private $odr_files_directory;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * FilterGraph Plugin constructor.
     *
     * @param EngineInterface $templating
     * @param CryptoService $crypto_service
     * @param DatabaseInfoService $database_info_service
     * @param Pheanstalk $pheanstalk
     * @param string $site_baseurl
     * @param string $odr_web_directory
     * @param string $odr_files_directory
     * @param Logger $logger
     */
    public function __construct(
        EngineInterface $templating,
        CryptoService $crypto_service,
        DatabaseInfoService $database_info_service,
        Pheanstalk $pheanstalk,
        string $site_baseurl,
        string $odr_web_directory,
        string $odr_files_directory,
        Logger $logger
    ) {
        parent::__construct($templating, $pheanstalk, $site_baseurl, $odr_web_directory, $odr_files_directory, $logger);

        $this->templating = $templating;
        $this->crypto_service = $crypto_service;
        $this->database_info_service = $database_info_service;
        $this->odr_web_directory = $odr_web_directory;
        $this->odr_files_directory = $odr_files_directory;
        $this->logger = $logger;
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // This render plugin is only allowed to work in display mode
            if ( $context === 'display' )
                return true;
        }

        return false;
    }


    /**
     * Digs through the stacked datatype array to find all file datafields, and the prefix entries
     * to reach them.
     *
     * The returned array looks like this:
     * array(
     *     "<prefix_1>" => array(
     *         'label' => "<prefix_1_label>",
     *         'fields' => array(
     *             "<df_id_1>" => "<df_1_name>",
     *             ...
     *         )
     *     ),
     *     ...
     *  )
     *
     * @param array $stacked_dt_array
     *
     * @return array
     */
    private function getAvailableConfigurations($stacked_dt_array)
    {
        // Going to recursively dig through the stacked datatype array searching for every file
        //  field, and simultaneously building every possible prefix along the way
        $file_fields = self::findFileFields($stacked_dt_array, '', '');

        // Only interested in the datatypes that have at least one file field
        foreach ($file_fields as $prefix => $data) {
            if ( empty($data['fields']) )
                unset( $file_fields[$prefix] );
        }

        // NOTE: don't need to also dig through the array to try to find the filter fields

        return $file_fields;
    }


    /**
     * Building prefixes and finding labels for said prefixes is easier if performed recursively.
     *
     * @param array $dt
     * @param string $parent_prefix
     * @param string $parent_name
     *
     * @return array
     */
    private function findFileFields($dt, $parent_prefix, $parent_name)
    {
        $data = array();

        // The given datatype array is not wrapped with its id
        $dt_id = strval($dt['id']);

        // Construct the prefix and the label for the current datatype
        $current_prefix = $current_name = '';
        if ( $parent_prefix === '' ) {
            // This is a top-level datatype
            $current_prefix = $dt_id;
            $current_name = $dt['dataTypeMeta']['shortName'];
        }
        else {
            // This is a descendant datatype
            $current_prefix = $parent_prefix.'_'.$dt_id;
            $current_name = $parent_name.' >> '.$dt['dataTypeMeta']['shortName'];
        }

        // Store the identifying info for this datatype
        $data[$current_prefix] = array(
            'label' => $current_name,
            'fields' => array()
        );


        // If this datatype has any file fields...
        foreach ($dt['dataFields'] as $df_id => $df) {
            if ( $df['dataFieldMeta']['fieldType']['typeClass'] === 'File' ) {
                // ...then create entries for them
                $data[$current_prefix]['fields'][$df_id] = $df['dataFieldMeta']['fieldName'];
            }
        }

        // If this datatype has descendants of its own...
        if ( isset($dt['descendants']) ) {
            foreach ($dt['descendants'] as $descendant_dt_id => $tdt) {
                // ...then recursively find the field fields in the descendants too
                $descendant_dt = $tdt['datatype'][$descendant_dt_id];
                $child_data = self::findFileFields($descendant_dt, $current_prefix, $current_name);

                foreach ($child_data as $prefix => $prefix_data)
                    $data[$prefix] = $prefix_data;
            }
        }

        return $data;
    }


    /**
     * Locates the configuration for the plugin if it exists, and converts it into a more useful
     * array format for actual use.
     *
     * The returned array looks like this:
     * array(
     *     'str' => "<original_plugin_configuration_string>",
     *     'prefix' => array(<prefix_1>,<prefix_2>,...),
     *     'graph_file_df_id' => <df_id>,
     *     'secondary_graph_file_df_id' => <df_id>,
     * )
     *
     * @param DataType|array $datatype
     * @return array
     */
    private function getCurrentPluginConfig($datatype)
    {
        $config = array();

        // This function could be passed either a cached array or a datatype entity, so ensure the
        //  rest of the function has a cached array to work off of...
        $dt = $datatype;
        if ( $datatype instanceof DataType ) {
            $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't need links
            $dt = $dt_array[ $datatype->getId() ];
        }

        if ( !empty($dt['renderPluginInstances']) ) {
            // The datatype could have more than one renderPluginInstance
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.filter_graph' ) {
                    // TODO - do I also want the graph names to be arbitrary values instead of datarecord names?
                    $plugin_config_str = $filter_config_str = '';
                    if ( isset($rpi['renderPluginOptionsMap']['plugin_config']) )
                        $plugin_config_str = trim( $rpi['renderPluginOptionsMap']['plugin_config'] );
                    if ( isset($rpi['renderPluginOptionsMap']['filter_config']) )
                        $filter_config_str = trim( $rpi['renderPluginOptionsMap']['filter_config'] );

                    // The config is stored as a string...three keys separated by commas
                    $config_tmp = explode(',', $plugin_config_str);

                    // If there aren't three entries, then the config is invalid
                    if ( count($config_tmp) !== 3 )
                        return array();

                    // If the primary graph file doesn't exist or isn't an integer, then it's invalid
                    if ( $config_tmp[1] === '' || !is_numeric($config_tmp[1]) )
                        return array();
                    // If the secondary graph file exists but isn't an integer, then it's invalid
                    if ( $config_tmp[2] !== '' && !is_numeric($config_tmp[2]) )
                        return array();

                    // Want to split up the prefix into an array too...
                    $prefix_data = explode('_', $config_tmp[0]);
                    // ...then ensure all of those values are integers
                    $prefix = array();
                    foreach ($prefix_data as $key => $value)
                        $prefix[] = intval($value);

                    // If the filter config has values in it, then convert into an array
                    $filter_fields = array();
                    if ( $filter_config_str !== '' ) {
                        $tmp = explode(',', $filter_config_str);

                        // If any of the datafield ids aren't numeric, then the config is invalid
                        foreach ($tmp as $num => $df_id) {
                            if ( !is_numeric($df_id) )
                                return array();
                            else
                                $filter_fields[$df_id] = 1;
                        }

                        // NOTE: don't really need to check whether the datafields actually belong
                        //  to relevant datafields
                    }

                    $config = array(
                        'str' => $plugin_config_str,
                        'prefix' => $prefix,
                        'graph_file_df_id' => intval($config_tmp[1]),
                        'secondary_graph_file_df_id' => intval($config_tmp[2]),
                        'hidden_filter_fields' => $filter_fields,
                    );
                }
            }
        }

        return $config;
    }


    /**
     * Digs through the given array of datarecords to find each file uploaded to the datafields
     * configured for graphing.
     *
     * @param array $datarecords
     * @param array $current_plugin_config
     *
     * @return array
     */
    private function getFileData($datarecords, $current_plugin_config)
    {
        $file_data = array();

        // The files being graphed most likely belong to descendants of the given set of datarecords...
        $graph_datarecords = array();

        // Typically, these descendant records would require recursion to find, but the given
        //  plugin config has a "prefix" value specifically to avoid this requirement
        if ( is_array($current_plugin_config['prefix']) ) {
            $prefix_values = $current_plugin_config['prefix'];
            // The first entry in the array is going to be the datatype of the current set of
            //  datarecords...don't need it for finding the files to graph
            $prefix_values = array_slice($prefix_values, 1);

            // Need a temporary list of datarecords so iteration can dig through a recursive structure
            $working_dr_list = $datarecords;

            while ( !empty($prefix_values) ) {
                // The first value in the prefix list is the id of the next child datatype to find
                $child_dt_id = $prefix_values[0];
                $prefix_values = array_slice($prefix_values, 1);

                // Find and store all child datarecords of the given child datatype
                $new_dr_list = array();
                foreach ($working_dr_list as $dr_id => $dr) {
                    if ( isset($dr['children'][$child_dt_id]) ) {
                        foreach ($dr['children'][$child_dt_id] as $child_dr_id => $child_dr) {
                            // Need to extend the nameField_value of the child datarecord with its
                            //  parent's nameField_value, so that the files can still be identified
                            if ( !is_array($dr['nameField_value']) ) {
                                // $dr is a top-level datarecord, and its nameField_value will be a
                                //  string...convert the values into an array so that sorting doesn't
                                //  go stupid
                                $child_dr['nameField_value'] = array($dr['nameField_value'], $child_dr['nameField_value']);
                            }
                            else {
                                // Otherwise, $dr is not a top-level datarecord, so it will already
                                //  be an array at this point in time...use the existing array as
                                //  a starting point for the child's nameField_value
                                $child_dr['nameField_value'] = $dr['nameField_value'][] = $child_dr['nameField_value'];
                            }

                            // Store the modified child datarecord for further processing
                            $new_dr_list[$child_dr_id] = $child_dr;
                        }
                    }
                }

                // Reset for the next iteration of the loop
                $working_dr_list = $new_dr_list;
            }

            // Save all the descendant records found
            $graph_datarecords = $working_dr_list;
        }
        else {
            // If no "prefix" is given, then the files can be found in the given datarecords
            $graph_datarecords = $datarecords;

            // NOTE: not converting the nameField_values into arrays here just for the latter half
            //  of the function to convert them back to strings
        }

        // Now that the datarecords with the actual files have been found, determine whether they
        //  have a file uploaded to the relevant datafields
        $graph_file_df_id = $current_plugin_config['graph_file_df_id'];
        // There might not be a secondary graph file...if not, then the value in the plugin config
        //  array will be null
        $secondary_graph_file_df_id = $current_plugin_config['secondary_graph_file_df_id'];

        // Going to need this for public status checking...
        $now = new \DateTime();

        foreach ($graph_datarecords as $graph_dr_id => $graph_dr) {
            $file = null;

            // Check the "primary" datafield first...
            if ( isset($graph_dr['dataRecordFields'][$graph_file_df_id]['file'][0]) ) {
                // ...use only the first uploaded file to be safe
                $file = $graph_dr['dataRecordFields'][$graph_file_df_id]['file'][0];
            }

            // If the "primary" datafield didn't have an upload, and a "secondary" datafield is configured...
            if ( is_null($file) && !is_null($secondary_graph_file_df_id) ) {
                // ...then attempt to find a file there instead
                if ( isset($graph_dr['dataRecordFields'][$secondary_graph_file_df_id]['file'][0]) )
                    $file = $graph_dr['dataRecordFields'][$secondary_graph_file_df_id]['file'][0];
            }

            // If any file was found...
            if ( !is_null($file) ) {
                // ...then store its data
                $file_data[$graph_dr_id] = array(
                    'sortField_value' => $graph_dr['nameField_value'],    // NOTE: intentionally using the adjusted nameField_value as the sortField_value...

                    'label' => $graph_dr['nameField_value'],    // TODO - should this instead be values from datafields?
                    'file' => $file,
                    'is_public' => true,    // assume it's public for the moment...
                );

                // Need sortField_value to be an array
                if ( !is_array($graph_dr['nameField_value']) )
                    $graph_dr['nameField_value'] = array($graph_dr['nameField_value']);

                // Verify public status of the file
                $public_date = $file['fileMeta']['publicDate'];
                if ($now < $public_date) {
                    // File is not public...
                    $file_data[$graph_dr_id]['is_public'] = false;

                    // ...it will need to be decrypted later, but can assign it an impossible-to-guess
                    //  filename for the time being
                    $file_data[$graph_dr_id]['file']['localFileName'] = md5($file['original_checksum'].'_'.$file['id'].'_'.random_int(2500,10000)).'.'.$file['ext'];
                }

                // NOTE: at this point, public files will have part of a directory structure in
                //  $file_data[$graph_dr_id]['file']['localFileName'], while non-public files won't
                // This is fine, and the part that does decryption later will deal with it then
            }
        }

        return $file_data;
    }


    /**
     * Due to potentially having dozens of files per graph, the files definitely need to be sorted
     * by whatever is being used to label them...but php's sorting algorithms tend to not sort these
     * labels correctly when they're given as strings.
     *
     * Therefore, the labels are given as arrays where the keys indicate how many levels down
     * self::getFileData() had to go to find each descendant record...but sorting on that requires
     * shennanigans to get array_multisort() to handle an arbitrary number of arguments...
     *
     * @param array $graph_files
     * @return int[]
     */
    private function sortFileData($graph_files)
    {
        $values = array();

        // First step is to reorganize the sort values into columns so array_multisort() works
        $levels_count = 0;
        foreach ($graph_files as $dr_id => $data) {
            $levels_count = count($data['sortField_value']);
            break;
        }
        // Intentionally using '<=' instead of '<', because this use of array_multisort() demands
        //  another column to match values to datarecord ids
        for ($i = 0; $i <= $levels_count; $i++)
            $values[$i] = array();

        // Now that $values has been instantiated, transfer the values to sort on
        foreach ($graph_files as $dr_id => $data) {
            $tmp = $data['sortField_value'];
            foreach ($tmp as $level => $value)
                $values[$level][] = $value;

            // Need the datarecord id in the final column
            $values[$level+1][] = $dr_id;
        }

        // Now that $values has been initialized, need to set up an arbitrary number of arguments
        //  for array_multisort() to use...
        $args = array();
        for ($i = 0; $i < $levels_count; $i++) {
            // array_multisort() requires the data...
            $args[] = $values[$i];

            // ...then the sort direction for this data...
            $args[] = SORT_ASC;

            // ...and then ODR needs to specify which type of sort to use
//                if ( $numeric_datafields[$display_order] )
//                    $args[] = SORT_NUMERIC;
//                else
            $args[] = SORT_NATURAL | SORT_FLAG_CASE;
        }

        // The final argument needs to be the list of datarecord ids, otherwise array_multisort()
        //  will appear to do nothing
        $args[] = &$values[$levels_count];
        call_user_func_array('array_multisort', $args);

        // array_multisort() will have modified the final argument...
        $datarecord_sortvalues = array_flip( array_pop($args) );
        // ...which can then be returned
        return $datarecord_sortvalues;
    }


    /**
     * Executes the FilterGraph Plugin on the provided datarecords
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     * @param array $token_list
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {
        try {
            // ----------------------------------------
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Filter graphs are always rollup
            $is_rollup = true;

            // Several options make no sense to change for this plugin, but are still needed for the
            //  rest of the graph stuff to work...
            $options['graph_type'] = 'xy';

            // Should only be one element in $theme_array...
            $theme = null;
            foreach ($theme_array as $t_id => $t)
                $theme = $t;


            // ----------------------------------------
            // Get the current plugin config...
            $current_plugin_config = self::getCurrentPluginConfig($datatype);
            if ( empty($current_plugin_config) ) {
                // If there was some sort of problem with the plugin config...
                if ( !$is_datatype_admin )
                    // ...then regardless of what actually caused the issue, the plugin shouldn't execute
                    return '';
                else
                    // ...but if a datatype admin is seeing this, then they probably should fix it
                    throw new \Exception('Invalid plugin config');
            }

            // Graphs are always going to be labelled with the id of the primary graph file datafield
            $primary_graph_df_id = $current_plugin_config['graph_file_df_id'];

            // Use the plugin config to find all the files that should be graphed
            $graph_files = self::getFileData($datarecords, $current_plugin_config);
            // If there are no files to graph, then the plugin shouldn't execute
            if ( empty($graph_files) )
                return '';

            // TODO - should this instead be considered some sort of an error?  and how, if so?

            // Sort the list of files by their legend values
            $datarecord_sortvalues = self::sortFileData($graph_files);


            // ----------------------------------------
            // Initialize arrays
            $odr_chart_ids = array();
            $odr_chart_file_ids = array();
            $odr_chart_files = array();
            $odr_chart_output_files = array();
            $legend_values = array();

            // Need to locate all files that are going to be graphed
            $datatype_folder = '';
            foreach ($graph_files as $dr_id => $file_data) {
                // If any files exist to be graphed, then ensure there's a directory to store the
                //  cached graph images in...use the first id in the prefix so the graphs don't
                //  get spread out to different directories
                $top_level_prefix_id = $current_plugin_config['prefix'][0];
                $datatype_folder = 'datatype_'.$top_level_prefix_id;

                // Might as well make all of the file's data available to twig...
                $odr_chart_files[$dr_id] = $file_data['file'];
                // Also need a list of file IDs to determine the names of the cached graph image...
                $odr_chart_file_ids[] = $file_data['file']['id'];

                // Need to ensure plotly has one legend per file
                $legend_values[$dr_id] = implode(' ', $file_data['sortField_value']);
            }

            // Also need this value set so twig will correctly link to the rollup graph
            $legend_values['rollup'] = 'Combined Chart';

            // Generate a random ID to identify the graph div on the page
            $odr_chart_id = 'Filter_Chart_'.Uuid::uuid4()->toString();
            $odr_chart_id = str_replace("-", "_", $odr_chart_id);
            $odr_chart_ids['rollup'] = $odr_chart_id;

            // ...and the filename for the rollup graph
            $file_id_hash = sha1( implode('_', $odr_chart_file_ids) );
            $rollup_filename = 'Filter_Chart_'.$file_id_hash.'_'.$primary_graph_df_id.'.svg';
            $odr_chart_output_files['rollup'] = '/graphs/'.$datatype_folder.'/'.$rollup_filename;


            // ----------------------------------------
            // If $datatype_folder is blank at this point, it's most likely because the user can
            //  view the datarecord/datafield, but not the file...so the graph can't be displayed
            $display_graph = true;
            if ($datatype_folder === '')
                $display_graph = false;

            // Only ensure a directory for the graph file exists if the graph is actually being displayed...
            $files_basedir = $this->odr_web_directory.$this->odr_files_directory;
            if ( $display_graph ) {
                if ( !file_exists($files_basedir.'/graphs/') )
                    mkdir($files_basedir.'/graphs/');
                if ( !file_exists($files_basedir.'/graphs/'.$datatype_folder) )
                    mkdir($files_basedir.'/graphs/'.$datatype_folder);
            }


            // ----------------------------------------
            // Filtering makes more sense if a list of options is provided...because the plugin needs
            //  to cross usual ODR boundaries to find files, and because there's no guarantee the
            //  given records have child/linked descendants...it's easier to first determine what
            //  info needs to be extracted from the datarecord array
            $filter_info = self::getFilterDatafields($datatype, $current_plugin_config['prefix']);

            // ...this info provides a framework to determine the values (or lack thereof) in the
            //  given datarecords
            $dr_lookup = array();
            $available_filter_values = self::getAvailableFilterValues($filter_info, $datarecords, $current_plugin_config['prefix'], $dr_lookup);

            // $dr_lookup needs another pass before it's useful...
            self::combineDatarecordLookup($dr_lookup);


            // If there's only one file, then the following step needs to include "all" the filter
            //  values and not "just the values that change"...
            $only_one_file = false;
            if ( count($odr_chart_file_ids) === 1 )
                $only_one_file = true;

            // The pile of datafield_ids/values/datarecord_ids that get returned need to be reduced
            //  to only contain the values that change, and simultaneously tweak which values refer
            //  to which records to make the graph plugin javascript's life easier
            $reduced_filter_values = self::reduceFilterValues($available_filter_values, $dr_lookup, $only_one_file);

            // It's a bit easier to find the desired value if they're sorted...
            foreach ($reduced_filter_values['values'] as $df_id => $values) {
                $tmp = $values;
                ksort($tmp);
                $reduced_filter_values['values'][$df_id] = $tmp;
            }


            // ----------------------------------------
            // Pulled up here so the graph builder can access the data if needed
            $record_display_view = 'single';
            if ( isset($rendering_options['record_display_view']) )
                $record_display_view = $rendering_options['record_display_view'];

            $page_data = array(
                'datatype_array' => array($datatype['id'] => $datatype),
                'datarecord_array' => $datarecords,
                'theme_array' => $theme_array,

                'target_datatype_id' => $datatype['id'],
                'parent_datarecord' => $parent_datarecord,
                'target_theme_id' => $theme['id'],

                'record_display_view' => $record_display_view,
                'is_top_level' => $rendering_options['is_top_level'],
                'is_link' => $rendering_options['is_link'],
                'display_type' => $rendering_options['display_type'],
                'multiple_allowed' => $rendering_options['multiple_allowed'],
                'display_graph' => $display_graph,

                'is_datatype_admin' => $is_datatype_admin,

                // Options for graph display
                'plugin_options' => $options,

                // All of these are indexed by datarecord id
                'odr_chart_ids' => $odr_chart_ids,
                'odr_chart_legend' => $legend_values,
                'odr_chart_file_ids' => $odr_chart_file_ids,
                'odr_chart_files' => $odr_chart_files,
                'odr_chart_output_files' => $odr_chart_output_files,

                'datarecord_sortvalues' => $datarecord_sortvalues,

                // Needed for building the filter
                'filter_data' => $reduced_filter_values,
                'hidden_filter_fields' => $current_plugin_config['hidden_filter_fields'],
            );


            // ----------------------------------------
            // If the graph doesn't exist when the page is built, it makes more sense to immediately
            //  trigger a rebuild rather than having the browser do it when it figures out that the
            //  file doesn't exist...
            $missing_output_files = array();
            foreach ($odr_chart_output_files as $dr_id => $graph_filepath) {
                if ( $is_rollup && $dr_id === 'rollup' ) {
                    // If the plugins options want a rollup graph, then only check that one
                    if ( !file_exists($files_basedir.$graph_filepath) )    // The paths in $odr_chart_output_files have "/graphs/datatype_#/" already
                        $missing_output_files[] = $dr_id;
                }
                else if ( !$is_rollup && $dr_id !== 'rollup' ) {
                    // Otherwise, only check the graphs for individual datarecords
                    if ( !file_exists($files_basedir.$graph_filepath) )
                        $missing_output_files[] = $dr_id;
                }
            }

            if ( !empty($missing_output_files) ) {
                foreach ($missing_output_files as $num => $dr_id) {
                    // Puppeteer will need the files decrypted, but it might also have to delete some
                    //  of them afterwards if any are non-public...
                    $files_to_delete = array();

                    // Determine the final filename of this missing graph file
                    $pieces = explode('/', $odr_chart_output_files[$dr_id]);
                    $graph_filename = $pieces[3];

                    // Need to also determine the filepath for this specific graph file...this is
                    //  mostly for when it's not a rollup graph
                    $graph_filepath = $files_basedir.$odr_chart_output_files[$dr_id];

                    // Determine the filename of the temporary file used to build the graph
                    $pieces = explode('_', $graph_filename);
                    $file_id_hash = $pieces[1];
                    $builder_filepath = $this->odr_files_directory.'/'.'Chart__'.sha1($file_id_hash.'_'.$pieces[1].random_int(2500,10000)).'.html';


                    // Need to ensure each of the files that will be rendered exist on the server,
                    //  but the behavior changes slightly depending on whether it's a rollup graph
                    //  on the server
                    $files_to_check = array();
                    if ( !$is_rollup ) {
                        // If this isn't a rollup graph, then only need to check a single file
                        $files_to_check[$dr_id] = $odr_chart_files[$dr_id];

                        // Need to also change a couple variables so puppeteer only looks at one
                        //  file for the graph it needs to make
                        $page_data['odr_chart_id'] = $odr_chart_ids[$dr_id];

                        $relevant_file = $odr_chart_files[$dr_id];
                        $page_data['odr_chart_file_ids'] = array($relevant_file['id']);
                        $page_data['odr_chart_files'] = array($dr_id => $relevant_file);
                    }
                    else {
                        // If this is a rollup graph, then need to check all the files that will
                        //  be on the graph
                        foreach ($odr_chart_files as $dr_id => $file)
                            $files_to_check[$dr_id] = $file;

                        // Need to pass the rollup chart id to twig
                        $page_data['odr_chart_id'] = $odr_chart_ids['rollup'];
                    }

                    foreach ($files_to_check as $dr_id => $file) {
                        // Due to historical reasons, the file's localFileName property includes
                        //  the contents of the symfony parameter 'odr_files_directory'
                        $local_filename = $file['localFileName'];
                        if ( substr($local_filename, 0, 1) !== '/' )
                            $local_filename = '/'.$local_filename;

                        if ( !file_exists($this->odr_web_directory.$local_filename) ) {
                            // File does not exist on the server...what to decrypt it to depends on
                            //  whether the file is public or not
                            $public_date = $file['fileMeta']['publicDate'];
                            $now = new \DateTime();
                            if ($now < $public_date) {
                                // File is not public...decrypt to something hard to guess
                                $non_public_filename = md5($file['original_checksum'].'_'.$file['id'].'_'.random_int(2500,10000)).'.'.$file['ext'];
                                $filepath = $this->crypto_service->decryptFile($file['id'], $non_public_filename);

                                // Tweak the stored filename so puppeteer can find it
                                $page_data['odr_chart_files'][$dr_id]['localFileName'] = '/uploads/files/'.$non_public_filename;

                                // Ensure the decrypted version gets deleted later
                                array_push($files_to_delete, $filepath);
                            }
                            else {
                                // File is public, but not decrypted for some reason
                                $filepath = $this->crypto_service->decryptFile($file['id']);
                            }
                        }
                    }

                    // Trigger puppeteer to make a graph for this file
                    $page_data['template_name'] = 'ODROpenRepositoryGraphBundle:Base:FilterGraph/graph_builder.html.twig';
                    parent::buildGraph($page_data, $graph_filepath, $builder_filepath, $files_to_delete);
                }
            }


            // ----------------------------------------
            // What to return depends on what called this plugin...
            if ( !isset($rendering_options['build_graph']) ) {
                // ...if called via twig, then render and return the graph html
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:FilterGraph/graph_wrapper.html.twig', $page_data
                );

                return $output;
            }
            else {
                // ...if called via GraphController, then just return
                return;
            }
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw new \Exception($e->getMessage(), $e->getCode(), $e);
        }
    }


    /**
     * Because the plugin wants to find values to filter by that aren't necessarily in the same
     * datatype as the graph files themselves, that means whatever set of datarecords are passed to
     * this plugin aren't guaranteed to all have the same set of datafields.
     *
     * As such, the datatype array needs to be crawled through first, to build up a list of every
     * possible datafield that could be used by the Javascript filtering.
     *
     * @param array $dt The array version of the datatype of $dr_list
     * @param array $prefix_values The prefix segment returned by {@link self::getCurrentPluginConfig()},
     *                             or null if the recursion is looking in datatypes that aren't
     *                             part of the configured prefix.
     * @return array
     */
    private function getFilterDatafields($dt, $prefix_values)
    {
        $filter_info = array(
            'datafields' => array(),
            'descendants' => array(),
        );

        if ( isset($dt['dataFields']) ) {
            foreach ($dt['dataFields'] as $df_id => $df) {
                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                $quality_str = $df['dataFieldMeta']['quality_str'];

                switch ($typeclass) {
                    case 'Image':
                    case 'Markdown':
                    case 'Tag':    // TODO - implement this
                        // These can't be filtered on, skip to the next datafield
                        continue 2;

                    case 'File':
                        if ( $quality_str === '' ) {
                            // Can't filter on files when the field isn't using quality
                            continue 2;
                        }

                    case 'Boolean':
                    case 'IntegerValue':
                    case 'DecimalValue':
                    case 'ShortVarchar':
                    case 'MediumVarchar':
                    case 'LongVarchar':
                    case 'LongText':
                    case 'DatetimeValue':
                    case 'Radio':
                        // Each of these can be filtered on
                }

                // Uncapitalize the first letter of the typeclass so it can be used as an array key
                $filter_info['datafields'][$df_id] = lcfirst($typeclass);
            }
        }

        // Need to also save which child/linked descendants could be filtered on
        if ( isset($dt['descendants']) ) {
            foreach ($dt['descendants'] as $child_dt_id => $child_dt_info) {
                // There's a child/linked descendant datatype here, and it should be checked for
                //  unique values...but only if it's either:
                //  1) part of the prefix, or
                //  2) only allows a single descendant record
                // By following those two rules, it's guaranteed that we can always state that
                //  a file matches a value (or the absence of a value)
                $allowed_descendant = false;
                if ( $child_dt_info['multiple_allowed'] === 0 || ( isset($prefix_values[1]) && $prefix_values[1] === $child_dt_id ) )
                    $allowed_descendant = true;

                if ( $allowed_descendant && isset($child_dt_info['datatype'][$child_dt_id]) ) {
                    // ...since it passes the conditions, set up for the next level of recursion
                    $child_dt = $child_dt_info['datatype'][$child_dt_id];

                    // Need to inform the next level of recursion of the prefix state, if
                    //  it exists
                    $new_prefix_values = null;
                    if ( !is_null($prefix_values) && isset($prefix_values[1]) && $prefix_values[1] === $child_dt_id )
                        $new_prefix_values = array_slice($prefix_values, 1);

                    // Repeat the process for each of this datatype's descendants
                    $tmp = self::getFilterDatafields($child_dt, $new_prefix_values);

                    // Copy the descendant datatype's info into the current array
                    $filter_info['descendants'][$child_dt_id] = $tmp;
                }
            }
        }

        return $filter_info;
    }


    /**
     * Since the plugin was given a filtered version of the cached datarecord array, it's possible
     * to use recursion to build an array of the unique values for each datafield, and store which
     * datarecords have each value.  This allows the graph plugin code to build HTML elements for
     * dynamically filtering which files are drawn to a graph.
     *
     * The trick is that any record that doesn't have a graph file...parent, sibling, child, etc...
     * needs to instead point to the related record which actually has the file.  Doing this inside
     * Javascript is also possible, but this recursive process has to happen anyways so the relevant
     * data might as well be gathered here.
     *
     * The data gathered here needs to be reduced somewhat before it can be passed to twig, and that
     * happens in {@link self::reduceFilterValues()}.
     *
     * @param array $filter_info {@link self::getFilterDatafields()}
     * @param array|null $dr_list The array versions of potentially multiple datarecords
     * @param array $prefix_values The prefix segment returned by {@link self::getCurrentPluginConfig()},
     *                             or null if the recursion is looking in datatypes that aren't
     *                             part of the configured prefix.
     * @param array $dr_lookup
     *
     * @return array
     */
    private function getAvailableFilterValues($filter_info, $dr_list, $prefix_values, &$dr_lookup)
    {
        // Mostly want the actual values...but the absence of a value is important too, and needs
        //  to be handled slightly differently
        $values = array();
        $null_values = array();

        // Because the datatype array has already been traversed...
        foreach ($dr_list as $dr_id => $dr) {
            // ...this function can go straight to determining whether the datarecord has a value
            //  for each individual field
            foreach ($filter_info['datafields'] as $df_id => $typeclass) {
                // The datarecordfield entry will not exist if the datarecord never received a value
                $drf = null;
                if ( isset($dr['dataRecordFields'][$df_id]) )
                    $drf = $dr['dataRecordFields'][$df_id];

                // The typeclass of the field changes how to extract information from it...
                // NOTE: all typeclasses here were run through lcfirst() in self::getFilterDatafields()
                if ( $typeclass === 'radio' ) {
                    // The radio field might have multiple selections...
                    $has_selection = false;

                    if ( isset($drf['radioSelection']) ) {
                        foreach ($drf['radioSelection'] as $ro_id => $rs) {
                            if ( $rs['selected'] === 1 ) {
                                // ...if a radio option is selected, then save it with the associated datarecord
                                $has_selection = true;
                                $ro_name = $rs['radioOption']['optionName'];
                                $values[$df_id][$ro_name][] = $dr_id;
                            }
                        }
                    }

                    if ( !$has_selection )
                        $null_values[$df_id][] = $dr_id;
                }
                else if ( $typeclass === 'tag' ) {
                    throw new \Exception('not implemented');
                }
                else if ( $typeclass === 'file' ) {
                    // If a file datafield is in here, then it's using the quality stuff...
                    $has_file = false;
                    if ( isset($drf['dataField']) && isset($drf['file']) ) {
                        $quality_str = $drf['dataField']['dataFieldMeta']['quality_str'];
                        if ( $quality_str === 'toggle' || $quality_str === 'stars5' ) {
                            // Don't need to parse these quality values
                            $has_file = true;
                            $quality_val = $drf['file']['fileMeta']['quality'];
                            $values[$df_id][$quality_val][] = $dr_id;
                        }
                        else {
                            // Do need to parse the custom quality json...
                            $ret = ValidUtility::isValidQualityJSON($quality_str);
                            if ( is_array($ret) ) {
                                // If an array was returned, then it was a valid quality string
                                $has_file = true;
                                $quality_val = $drf['file'][0]['fileMeta']['quality'];    // should only have one file...
                                $quality_label = $ret[$quality_val];
                                $values[$df_id][$quality_label][] = $dr_id;
                            }
                            else {
                                // Ignore invalid quality strings
                            }
                        }
                    }

                    if ( !$has_file )
                        $null_values[$df_id][] = $dr_id;
                }
                else {
                    // Locating the value is straightforward for the text/integer typeclasses, though
                    //  it might also be the empty string
                    $value = null;
                    if ( isset($drf[$typeclass][0]['value']) && strlen($drf[$typeclass][0]['value']) > 0 )
                        $value = $drf[$typeclass][0]['value'];

                    // ...if it exists, save the value with the associated datarecord
                    if ( !is_null($value) )
                        $values[$df_id][$value][] = $dr_id;
                    else
                        $null_values[$df_id][] = $dr_id;
                }
            }

            // Now that the datafields of this datatype have been processed, need to check any
            //  child/linked descendants...
            foreach ($filter_info['descendants'] as $child_dt_id => $child_dt_filter_info) {
                if ( isset($dr['children'][$child_dt_id]) ) {
                    // The datarecord has children of this descendant datatype...perform the next
                    //  level of recursion
                    $child_dr_array = $dr['children'][$child_dt_id];

                    // Need to also inform the next level of recursion of the prefix state if possible
                    $new_prefix_values = null;
                    if ( !is_null($prefix_values) && isset($prefix_values[1]) && $prefix_values[1] === $child_dt_id )
                        $new_prefix_values = array_slice($prefix_values, 1);

                    $tmp = self::getAvailableFilterValues($child_dt_filter_info, $child_dr_array, $new_prefix_values, $dr_lookup);

                    // Merge the returned values with the running tally of values (or null values)
                    foreach ($tmp['values'] as $df_id => $unique_values) {
                        foreach ($unique_values as $val => $child_dr_ids) {
                            if ( !isset($values[$df_id][$val]) )
                                $values[$df_id][$val] = array();
                            foreach ($child_dr_ids as $num => $child_dr_id)
                                $values[$df_id][$val][] = $child_dr_id;
                        }
                    }
                    foreach ($tmp['null_values'] as $df_id => $child_dr_ids) {
                        foreach ($child_dr_ids as $num => $child_dr_id)
                            $null_values[$df_id][] = $child_dr_id;
                    }

                    // This is where you would end up if you were going to populate $dr_lookup in
                    //  another function...which is the main reason why that's not in it's own function
                    if ( !is_null($new_prefix_values) ) {
                        // This is a child/linked datatype that's in the prefix...any value that
                        //  would otherwise reference *this* datarecord needs to instead point to
                        //  the descendant records
                        $dr_lookup[$dr_id] = array_keys($child_dr_array);
                    }
                    else {
                        // This is a child/linked datatype that's not in the prefix...any value
                        //  referencing one of these descendant records should reference *this*
                        //  record instead
                        foreach ($child_dr_array as $child_dr_id => $child_dr) {
                            $dr_lookup[$child_dr_id][] = $dr_id;
                            // NOTE: not creating an array screws up substitution of descendants of
                            //  the datatype with the files in it
                        }
                    }
                }
                else {
                    // ...if the datarecord doesn't have any children of this descendant datatype,
                    //  then it needs to be assigned a 'null' value for each datafield belonging to
                    //  said descendant datatype

                    // Unfortunately, this descendant datatype could have descendants of its own,
                    //  which the datarecord also wouldn't have...so this assignment could end up
                    //  needing to process multiple levels of descendants...
                    $current_dt_info = $child_dt_filter_info;

                    // ...don't want to do it recursively though
                    do {
                        // Due to the rules enforced by self::getFilterDatafields(), the current
                        //  datarecord can "pretend it has" these datafields, and therefore also
                        //  pretend that each datafield has a null value
                        foreach ($current_dt_info['datafields'] as $child_df_id => $child_df_typeclass)
                            $null_values[$child_df_id][] = $dr_id;
                        // The substitution of datarecord ids later on is not affected by these
                        //  shennanigans

                        // Perform the same action for every descendant of this datatype...
                        $descendants_to_process = $current_dt_info['descendants'];
                    }
                    while ( !empty($descendants_to_process) );
                }
            }
        }

        return array(
            'values' => $values,
            'null_values' => $null_values,
        );
    }


    /**
     * The datarecord lookup created by {@link self::getAvailableFilterValues()} might need a bit
     * of work before it can be used by {@link self::reduceFilterValues()}...records in the prefix
     * or records that are descended from those with the graph files are correct, but records that
     * branch off earlier in the prefix only point to their parent.
     */
    private function combineDatarecordLookup(&$dr_lookup)
    {
        // Going to iteratively update the values in $dr_lookup until they are guaranteed to point
        //  to a record with a graph file
        do {
            $replacement_made = false;

            foreach ($dr_lookup as $source_dr_id => $target_dr_list) {
                // For each record mentioned in the array...
                foreach ($target_dr_list as $num => $target_dr_id) {
                    // ...if it points to a record that doesn't have a graph file...
                    if ( isset($dr_lookup[$target_dr_id]) ) {
                        // ...then need to transitively replace what it originally pointed to...
                        $replacement_made = true;
                        unset( $dr_lookup[$source_dr_id][$num] );

                        // ...with what its original target pointed to
                        foreach ($dr_lookup[$target_dr_id] as $num => $asdf_dr_id)
                            $dr_lookup[$source_dr_id][] = $asdf_dr_id;
                    }

                }
            }
        }
        while ($replacement_made);
    }


    /**
     * The data gathered by {@link self::getAvailableFilterValues()} needs to be reduced somewhat
     * before it's passed to twig...there are likely datafields that have no impact on which files
     * should be graphed.
     *
     * Additionally, any record that doesn't have a graph file...parent, sibling, child, etc...
     * needs to instead point to the related record which actually has the file.  Doing this inside
     * Javascript is also possible, but it might as well happen here.
     *
     * @param array $available_filter_values
     * @param array $dr_lookup
     * @param boolean $only_one_file If true, then don't filter out unchanging values
     *
     * @return array
     */
    private function reduceFilterValues($available_filter_values, $dr_lookup, $only_one_file)
    {
        $reduced_filter_values = array(
            'values' => array(),
            'null_values' => array(),
        );

        foreach ($available_filter_values['values'] as $df_id => $values) {
            if ( !$only_one_file && count($values) < 2 && !isset($available_filter_values['null_values'][$df_id]) ) {
                // Makes no sense to provide a filter to choose values when there's actually only
                //  a single value across all the graphed files (including null values)

                // As such, don't copy over to the new array
            }
            else {
                foreach ($values as $val => $dr_list) {
                    // Substitute all datarecords that don't have graph files with the related
                    //  datarecord that does actually have the file
                    $tmp = self::substituteDatarecords($dr_list, $dr_lookup);

                    // Now that all records have been substituted, convert the datarecord list into
                    //  a string so twig can write it as part of a javascript array
                    $reduced_filter_values['values'][$df_id][$val] = implode(',', $tmp);
                }
            }
        }
        foreach ($available_filter_values['null_values'] as $df_id => $dr_list) {
            if ( !$only_one_file && !isset($available_filter_values['values'][$df_id]) ) {
                // If there's nothing in the 'values' array, then a value here means there's still
                //  only a single value across all the graphed files...get rid of it

                // As such, don't copy over to the new array
            }
            else {
                // Substitute all datarecords that don't have graph files with the related
                //  datarecord that does actually have the file
                $tmp = self::substituteDatarecords($dr_list, $dr_lookup);

                // Now that all records have been substituted, convert the datarecord list into
                //  a string so twig can write it as part of a javascript array
                $reduced_filter_values['null_values'][$df_id] = implode(',', $tmp);
            }
        }

        return $reduced_filter_values;
    }


    /**
     * Substitute all datarecords that don't have graph files with the related datarecord that
     * actually have the file.
     *
     * @param array $dr_list
     * @param array $dr_lookup
     * @return array
     */
    private function substituteDatarecords($dr_list, $dr_lookup)
    {
        $tmp = array();
        foreach ($dr_list as $num => $dr_id) {
            if ( !isset($dr_lookup[$dr_id]) ) {
                // This is one of the datarecords with a file...no substitution necessary
                $tmp[$dr_id] = 1;
            }
            else {
                // This datarecord does not have a graph file...
                $tmp_dr_id = $dr_id;

                // ...any reference to this datarecord needs to be replaced with a reference to
                //  a datarecord with a graph file
                foreach ($dr_lookup[$tmp_dr_id] as $num => $child_dr_id)
                    $tmp[$child_dr_id] = 1;
            }
        }

        return array_keys($tmp);
    }


    /**
     * Called when a user changes RenderPluginOptions or RenderPluginMaps entries for this plugin.
     *
     * @param PluginOptionsChangedEvent $event
     */
    public function onPluginOptionsChanged(PluginOptionsChangedEvent $event)
    {
        foreach ($event->getRenderPluginInstance()->getRenderPluginOptionsMap() as $rpom) {
            /** @var RenderPluginOptionsMap $rpom */
            if ( $rpom->getRenderPluginOptionsDef()->getName() === 'plugin_config' ) {
                $plugin_config_str = trim( $rpom->getValue() );

                // The config is stored as a string...three keys separated by commas
                $config_tmp = explode(',', $plugin_config_str);

                // If there aren't three entries, then the config is invalid
                if ( count($config_tmp) !== 3 )
                    return;

                // If the primary graph file doesn't exist or isn't an integer, then it's invalid
                if ( $config_tmp[1] === '' || !is_numeric($config_tmp[1]) )
                    return;
                // If the secondary graph file exists but isn't an integer, then it's invalid
                if ( $config_tmp[2] !== '' && !is_numeric($config_tmp[2]) )
                    return;

                // At this point, the config is valid
                $plugin_df_id = intval($config_tmp[1]);

                // The graphs are stored under the first entry in the prefix
                $plugin_dt_id = substr($config_tmp[0], 0, strpos($config_tmp[0], '_'));

//                $this->logger->debug('FilterGraphPlugin: deleting all graphs based off df '.$plugin_df_id);
                parent::deleteCachedGraphs($plugin_dt_id, $plugin_df_id);

                // No point checking other options
                break;
            }
        }
    }


    /**
     * Returns an array of HTML strings for each RenderPluginOption in this RenderPlugin that needs
     * to use custom HTML in the RenderPlugin settings dialog.
     *
     * @param ODRUser $user The user opening the dialog
     * @param boolean $is_datatype_admin Whether the user is able to make changes to this RenderPlugin's config
     * @param RenderPlugin $render_plugin The RenderPlugin in question
     * @param DataType $datatype The relevant datatype if this is a Datatype Plugin, otherwise the Datatype of the given Datafield
     * @param DataFields|null $datafield Will be null unless this is a Datafield Plugin
     * @param RenderPluginInstance|null $render_plugin_instance Will be null if the RenderPlugin isn't in use
     * @return string[]
     */
    public function getRenderPluginOptionsOverride($user, $is_datatype_admin, $render_plugin, $datatype, $datafield = null, $render_plugin_instance = null)
    {
        // Due to having multiple renderPluginOption entries that require custom html, it's better
        //  to get the available/current plugin config out here
        $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId());    // do want links
        $stacked_dt_array = $this->database_info_service->stackDatatypeArray($dt_array, $datatype->getId());

        $available_configurations = self::getAvailableConfigurations($stacked_dt_array);
        $current_plugin_config = self::getCurrentPluginConfig( $dt_array[$datatype->getId()] );

        $current_config_str = '';
        $current_filter_fields = array();
        if ( !empty($current_plugin_config) ) {
            $current_config_str = $current_plugin_config['str'];
            $current_filter_fields = $current_plugin_config['hidden_filter_fields'];
        }

        // Easier to get rid of datafields/datatypes that can't be filtered on here, so twig/js
        //  don't have to
        $stacked_dt_array = self::filterStackedDatatypeArray($stacked_dt_array);

        $custom_rpo_html = array();
        foreach ($render_plugin->getRenderPluginOptionsDef() as $rpo) {
            // This plugin currently has several options, but only "plugin_config" and "filter_config"
            //  need to use a custom render for the dialog...
            $template_name = '';

            /** @var RenderPluginOptionsDef $rpo */
            if ( $rpo->getUsesCustomRender() ) {
                if ( $rpo->getName() === 'plugin_config' )
                    $template_name = 'ODROpenRepositoryGraphBundle:Base:FilterGraph/dialog_field_list_override_plugin_config.html.twig';
                else if ( $rpo->getName() === 'filter_config' )
                    $template_name = 'ODROpenRepositoryGraphBundle:Base:FilterGraph/dialog_field_list_override_filter_config.html.twig';

                if ( $template_name !== '' ) {
                    $custom_rpo_html[$rpo->getId()] = $this->templating->render(
                        $template_name,
                        array(
                            'rpo_id' => $rpo->getId(),
                            'stacked_dt_array' => $stacked_dt_array,

                            'available_config' => $available_configurations,
                            'current_config' => $current_plugin_config,
                            'current_config_str' => $current_config_str,
                            'current_filter_fields' => $current_filter_fields,
                        )
                    );
                }
            }
        }

        // As a side note, the plugin settings dialog does no logic to determine which options should
        //  have custom rendering...it's solely determined by the contents of the array returned by
        //  this function.  As such, there's no validation whatsoever
        return $custom_rpo_html;
    }


    /**
     * Filters out datafields that aren't valid for use by {@link self::getFilterDatafields()}, and
     * also filters out datatypes without any valid datafields so twig doesn't have to do it.
     *
     * @param array $dt_array
     * @return array
     */
    private function filterStackedDatatypeArray($dt_array)
    {
        // Due to recursion, can't just unset() in an array passed by reference...so have to return
        //  modified arrays
        $tmp = $dt_array;

        // Remove all datafields from the stacked datatype array that can't be filtered on
        if ( isset($tmp['dataFields']) ) {
            foreach ($tmp['dataFields'] as $df_id => $df) {
                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                $quality_str = $df['dataFieldMeta']['quality_str'];

                switch ($typeclass) {
                    case 'Image':
                    case 'Markdown':
                    case 'Tag':    // TODO - implement this
                        // These can't be filtered on, skip to the next datafield
                        unset( $tmp['dataFields'][$df_id] );

                    case 'File':
                        if ( $quality_str === '' ) {
                            // Can't filter on files when the field isn't using quality
                            unset( $tmp['dataFields'][$df_id] );
                        }

                    case 'Boolean':
                    case 'IntegerValue':
                    case 'DecimalValue':
                    case 'ShortVarchar':
                    case 'MediumVarchar':
                    case 'LongVarchar':
                    case 'LongText':
                    case 'DatetimeValue':
                    case 'Radio':
                        // Each of these can be filtered on
                }
            }
        }

        // Do the same for the child datatype
        if ( isset($tmp['descendants']) ) {
            foreach ($tmp['descendants'] as $child_dt_id => $child_dt_info) {
                $child_dt = $child_dt_info['datatype'][$child_dt_id];
                $child_dt = self::filterStackedDatatypeArray($child_dt);

                // If the child datatype has at least one valid datafield/descendant...
                if ( !empty($child_dt['dataFields']) || !empty($child_dt['descendants']) ) {
                    // ...then replace the existing entry in the stacked array
                    $tmp['descendants'][$child_dt_id]['datatype'][$child_dt_id] = $child_dt;
                }
                else {
                    // ...otherwise, get rid of it
                    unset( $tmp['descendants'][$child_dt_id] );
                }
            }
        }

        return $tmp;
    }
}
