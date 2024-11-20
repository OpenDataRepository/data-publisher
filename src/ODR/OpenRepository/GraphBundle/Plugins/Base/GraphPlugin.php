<?php 

/**
 * Open Data Repository Data Publisher
 * Graph Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The graph plugin plots a line graph out of data files uploaded to a File DataField, and labels
 * them using a "legend" field selected when the graph plugin is created...
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// Entities
use ODR\AdminBundle\Entity\RenderPluginMap;
// Events
use ODR\AdminBundle\Component\Event\PluginOptionsChangedEvent;
// Services
use ODR\AdminBundle\Component\Service\CryptoService;
// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\ODRGraphPlugin;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Bridge\Monolog\Logger;
// Other
use Pheanstalk\Pheanstalk;
use Ramsey\Uuid\Uuid;


class GraphPlugin extends ODRGraphPlugin implements DatatypePluginInterface
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
     * GraphPlugin constructor.
     *
     * @param EngineInterface $templating
     * @param CryptoService $crypto_service
     * @param Pheanstalk $pheanstalk
     * @param string $site_baseurl
     * @param string $odr_web_directory
     * @param string $odr_files_directory
     * @param Logger $logger
     */
    public function __construct(
        EngineInterface $templating,
        CryptoService $crypto_service,
        Pheanstalk $pheanstalk,
        string $site_baseurl,
        string $odr_web_directory,
        string $odr_files_directory,
        Logger $logger
    ) {
        parent::__construct($templating, $pheanstalk, $site_baseurl, $odr_web_directory, $odr_files_directory, $logger);

        $this->templating = $templating;
        $this->crypto_service = $crypto_service;
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
     * Locates the value for the given renderPluginFields entry in the datarecord array, if it exists.
     * Returns null if the rpf isn't mapped to a datafield, or the datarecord doesn't have an entry
     * for the datafield.
     *
     * @param array $datarecord
     * @param array $datafield_mapping
     * @param string $rpf_name
     * @return string|null
     */
    private function getPivotValue($datarecord, $datafield_mapping, $rpf_name)
    {
        // Extract the renderPluginFields entry out of the $datafield_mapping array
        if ( !isset($datafield_mapping[$rpf_name]) )
            return null;
        $rpf = $datafield_mapping[$rpf_name];

        // Determine the id and typeclass of the related datafield, if possible
        $df_id = null;
        $typeclass = null;
        if ( !is_null($rpf['datafield']) ) {
            $df_id = $rpf['datafield']['id'];
            $typeclass = $rpf['datafield']['dataFieldMeta']['fieldType']['typeClass'];
        }

        // If the datafield id is null, then the field is an optional one that hasn't been mapped
        if ( is_null($df_id) )
            return null;
        // If the datarecord doesn't have an entry for this datafield, then there's no value to return
        if ( !isset($datarecord['dataRecordFields'][$df_id]) )
            return null;

        // Otherwise, use the typeclass to locate the data from the cached datarecord array
        $drf = $datarecord['dataRecordFields'][$df_id];
        switch ($typeclass) {
            case 'IntegerValue':
            case 'DecimalValue':
            case 'ShortVarchar':
            case 'MediumVarchar':
            case 'LongVarchar':
                $drf_typeclass = lcfirst($typeclass);
                if ( isset($drf[$drf_typeclass][0]['value']) )
                    return $drf[$drf_typeclass][0]['value'];
                break;

            case 'Radio':
                if ( isset($drf['radioSelection']) ) {
                    foreach ($drf['radioSelection'] as $ro_id => $rs) {
                        if ( $rs['selected'] === 1 )
                            return $rs['radioOption']['optionName'];
                    }
                }
                break;

            case 'default':
                throw new \Exception('Invalid Fieldtype for '.$rpf_name);
        }

        // Otherwise, no value was found
        return null;
    }


    /**
     * Locates and returns the array for the correct file to graph.  It prefers a file uploaded to
     * the primary graph field, but if that doesn't exist then it'll try the secondary graph field.
     * If neither field has a file, then it'll return an empty array.
     *
     * @param array $datarecord
     * @param array $datafield_mapping
     * @return array
     */
    private function getGraphFile($datarecord, $datafield_mapping)
    {
        $primary_graph_df_id = null;
        if ( isset($datafield_mapping['graph_file']) && !is_null($datafield_mapping['graph_file']['datafield']) )
            $primary_graph_df_id = $datafield_mapping['graph_file']['datafield']['id'];
        $secondary_graph_df_id = null;
        if ( isset($datafield_mapping['secondary_graph_file']) && !is_null($datafield_mapping['secondary_graph_file']['datafield']) )
            $secondary_graph_df_id = $datafield_mapping['secondary_graph_file']['datafield']['id'];

        // Prefer the file(s) uploaded to the primary graph datafield...
        if ( isset($datarecord['dataRecordFields'][$primary_graph_df_id]) ) {
            if ( !empty($datarecord['dataRecordFields'][$primary_graph_df_id]['file']) )
                return array($primary_graph_df_id => $datarecord['dataRecordFields'][$primary_graph_df_id]['file']);
        }

        // ...fallback to the file(s) uploaded to the secondary graph datafield, if it is mapped and
        //  any exist...
        if ( !is_null($secondary_graph_df_id) && isset($datarecord['dataRecordFields'][$secondary_graph_df_id]) ) {
            if ( !empty($datarecord['dataRecordFields'][$secondary_graph_df_id]['file']) )
                return array($secondary_graph_df_id => $datarecord['dataRecordFields'][$secondary_graph_df_id]['file']);
        }

        // ...but if both fail, then return an empty array
        return array();
    }


    /**
     * Due to the requirement of more generalistic (aka: complicated) column selection for this
     * plugin, having some validation on the options is desirable...
     *
     * @param array $plugin_options
     */
    private function validateGraphColumns(&$plugin_options)
    {
        // ----------------------------------------
        // Should only be one positive integer for the x_values_column...
        if ( !isset($plugin_options['x_values_column']) || preg_match('/^\d+$/', $plugin_options['x_values_column']) !== 1 )
            $plugin_options['x_values_column'] = 1;

        // y values column is a bit more complicated...
        $pattern = '/^';                // always read the entire string
        $pattern .= '(?:';              // the capture groups aren't important...
            $pattern .= '(?:\d+\:)?';   // ...want an optional sequence of digits followed by a colon
            $pattern .= '(?:\d+,?)';    // ...and a non-optional sequence of digits followed by an optional comma
        $pattern .= ')+';
        $pattern .= '$/';               // always read the entire string

        // The above pattern will match stuff like "11", "1,2,3", "1:5", "1,2,3:5,6", "1,2,", etc
        // Trailing commas are acceptable
        // It will not match stuff like ",1", "1::5", ":2,3,4", "1:2:3", etc

        if ( !isset($plugin_options['y_values_column']) || preg_match($pattern, $plugin_options['y_values_column']) !== 1 ) {
            // Missing column, or failed regex should return to the default
            $plugin_options['y_values_column'] = 2;
        }
        else if ( strpos($plugin_options['y_values_column'], ':') !== false ) {
            // The range operator (e.g. 1:5) should be converted into a comma-separated list
            //  (e.g. 1,2,3,4.5) for plotly
            // Due to passing the earlier regex, can explode first by commas...
            $pieces = explode(',', $plugin_options['y_values_column']);
            $tmp = array();
            foreach ($pieces as $val) {
                if ( strpos($val, ':') === false ) {
                    $tmp[] = intval($val);
                }
                else {
                    // ...and only explode by colon if needed
                    $fragments = explode(':', $val);
                    // Don't actually care if the range is inverted (e.g. "5:1")
                    foreach ( range($fragments[0], $fragments[1]) as $num)
                        $tmp[] = $num;
                }
            }

            // ...all column vals get sorted anyways
            sort($tmp);
            $plugin_options['y_values_column'] = implode(',', $tmp);
        }

        // Don't need to do anything to the y_values_column if it doesn't contain a colon


        // ----------------------------------------
        // If both of these plugin options are defined...
        if ( isset($plugin_options['y_value_columns_start']) && isset($plugin_options['y_value_columns_end']) ) {
            // ...then going to modify them a bit to try to be as forgiving as possible
            $start = str_replace(array("\t","\r","\n"," "), '', $plugin_options['y_value_columns_start']);
            $end = str_replace(array("\t","\r","\n"," "), '', $plugin_options['y_value_columns_end']);

            if ( strlen($start) > 0 && strlen($end) > 0 ) {
                // ...that modification also involves eliminating case comparison
                $plugin_options['y_value_columns_start'] = strtolower($start);
                $plugin_options['y_value_columns_end'] = strtolower($end);
            }
            else {
                // If they fail the check, then set them to the empty string so they do nothing
                $plugin_options['y_value_columns_start'] = '';
                $plugin_options['y_value_columns_end'] = '';
            }
        }
        else {
            // If they fail the check, then set them to the empty string so they do nothing
            $plugin_options['y_value_columns_start'] = '';
            $plugin_options['y_value_columns_end'] = '';
        }
    }


    /**
     * Executes the Graph Plugin on the provided datarecords
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

            // Default to individual graphs
            $is_rollup = false;
            if ( isset($options['use_rollup']) && $options['use_rollup'] === 'yes' )
                $is_rollup = true;

            // Attempt to verify the default x/y columns...
            self::validateGraphColumns($options);

            // Should only be one element in $theme_array...
            $theme = null;
            foreach ($theme_array as $t_id => $t)
                $theme = $t;

            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];
                // This plugin allows some of the fields to be optional, so the df entries could be null
                $is_optional = false;
                if ( isset($rpf_df['properties']['is_optional']) )
                    $is_optional = true;

                $df = null;
                if ( isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ( $df == null && !$is_optional ) {
                    // If the datafield doesn't exist in the datatype_array, then either the datafield
                    //  is non-public and the user doesn't have permissions to view it (most likely),
                    //  or the plugin somehow isn't configured correctly

                    // The plugin can't continue executing in either case...
                    if ( !$is_datatype_admin )
                        // ...regardless of what actually caused the issue, the plugin shouldn't execute
                        return '';
                    else
                        // ...but if a datatype admin is seeing this, then they probably should fix it
                        throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id.'...check plugin config.');

                    // NOTE: this only triggers when the Graph File rpf is missing, since the three
                    //  other fields are optional

                    // NOTE: the parts of ODR that re-render a graph completely ignore public status
                    //  of the related datafields
                }

                // Grab the field name specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf_name) );
                $datafield_mapping[$key] = array('datafield' => $df);
            }

            // Graphs are always going to be labelled with the id of the primary graph file datafield
            $primary_graph_df_id = $datafield_mapping['graph_file']['datafield']['id'];

            // Need to sort by the datarecord's sort value if possible
            $datarecord_sortvalues = array();
            $sortField_type = '';

            $legend_values['rollup'] = 'Combined Chart';
            foreach ($datarecords as $dr_id => $dr) {
                // Store sort values for later...
                $datarecord_sortvalues[$dr_id] = $dr['sortField_value'];
                $sortField_type = $dr['sortField_types'];

                // Locate the values for the Pivot fields if possible
                $pivot_df_value = null;
                if ( isset($datafield_mapping['pivot_field']) )
                    $pivot_df_value = self::getPivotValue($dr, $datafield_mapping, 'pivot_field');
                $secondary_pivot_df_value = null;
                if ( isset($datafield_mapping['secondary_pivot_field']) )
                    $secondary_pivot_df_value = self::getPivotValue($dr, $datafield_mapping, 'secondary_pivot_field');

                // NOTE: not dealing with the option "record_name_in_legend" here

                if ( is_null($pivot_df_value) && is_null($secondary_pivot_df_value) )
                    $legend_values[$dr_id] = $dr['nameField_value'];
                else if ( !is_null($pivot_df_value) && is_null($secondary_pivot_df_value) )
                    $legend_values[$dr_id] = $pivot_df_value;
                else if ( is_null($pivot_df_value) && !is_null($secondary_pivot_df_value) )
                    $legend_values[$dr_id] = $secondary_pivot_df_value;
                else
                    $legend_values[$dr_id] = $pivot_df_value.' '.$secondary_pivot_df_value;
            }

            // Sort datarecords by their sortvalue
            $flag = SORT_NATURAL | SORT_FLAG_CASE;
            if ( $sortField_type === 'numeric' )
                $flag = SORT_NUMERIC;

            asort($datarecord_sortvalues, $flag);
            $datarecord_sortvalues = array_flip( array_keys($datarecord_sortvalues) );


            // ----------------------------------------
            // Initialize arrays
            $odr_chart_ids = array();
            $odr_chart_file_ids = array();
            $odr_chart_files = array();
            $odr_chart_output_files = array();

            // Need to locate all files that are going to be graphed
            $datatype_folder = '';
            foreach ($datarecords as $dr_id => $dr) {
                // Save the folder the graphs for this datatype reside in
                if ($datatype_folder === '')
                    $datatype_folder = 'datatype_'.$dr['dataType']['id'];

                $graph_file_data = self::getGraphFile($dr, $datafield_mapping);
                foreach ($graph_file_data as $df_id => $files) {
                    // Complain if the graph datafield has more than one file uploaded for this
                    //  datarecord
                    if ( count($files) > 1 ) {
                        $df_name = $datafield_mapping['graph_file']['datafield']['dataFieldMeta']['fieldName'];
                        $file_count = count( $dr['dataRecordFields'][$df_id]['file'] );

                        throw new \Exception('The Graph Plugin can only handle a single uploaded file per datafield, but the Datafield "'.$df_name.'" has '.$file_count.' uploaded files.');
                    }

                    // Should only be one file here...save it so it can be graphed later
                    $file = $files[0];
                    $odr_chart_files[$dr_id] = $file;
                    // Might as well save the file's id here too
                    $odr_chart_file_ids[] = $file['id'];

                    // Need to generate a unique chart id for each chart
                    $odr_chart_id = "plotly_" . Uuid::uuid4()->toString();
                    $odr_chart_id = str_replace("-","_", $odr_chart_id);
                    $odr_chart_ids[$dr_id] = $odr_chart_id;

                    // The filename for the cached graph needs to have some relation to the files
                    //  being graphed...but a list of file ids could exceed the character limit for
                    //  filenames, so it's better to hash them first
                    // sha1() is not an attempt to be cryptographically secure, but instead an
                    //  attempt to minimize the chance of collisions
                    $filename = 'Chart_'.sha1( $file['id'] ).'_'.$primary_graph_df_id.'.svg';
                    $odr_chart_output_files[$dr_id] = '/graphs/'.$datatype_folder.'/'.$filename;
                }
            }


            // Also need to create the chart ID for a rollup graph...
            $odr_chart_id = "Chart_" . Uuid::uuid4()->toString();
            $odr_chart_id = str_replace("-","_", $odr_chart_id);
            $odr_chart_ids['rollup'] = $odr_chart_id;

            // ...and the filename for the rollup graph
            $file_id_hash = sha1( implode('_', $odr_chart_file_ids) );
            $rollup_filename = 'Chart_'.$file_id_hash.'_'.$primary_graph_df_id.'.svg';
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
            $record_display_view = 'single';
            if ( isset($rendering_options['record_display_view']) )
                $record_display_view = $rendering_options['record_display_view'];

            // Pulled up here so the graph builder can access the data if needed
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
                'datatype_permissions' => $datatype_permissions,
                'datafield_permissions' => $datafield_permissions,

                // Options for graph display
                'plugin_options' => $options,

                // All of these are indexed by datarecord id
                'odr_chart_ids' => $odr_chart_ids,
                'odr_chart_legend' => $legend_values,
                'odr_chart_file_ids' => $odr_chart_file_ids,
                'odr_chart_files' => $odr_chart_files,
                'odr_chart_output_files' => $odr_chart_output_files,

                'datarecord_sortvalues' => $datarecord_sortvalues,
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
                    $page_data['template_name'] = 'ODROpenRepositoryGraphBundle:Base:Graph/graph_builder.html.twig';
                    parent::buildGraph($page_data, $graph_filepath, $builder_filepath, $files_to_delete);
                }
            }


            // ----------------------------------------
            // What to return depends on what called this plugin...
            if ( !isset($rendering_options['build_graph']) ) {
                // ...if called via twig, then render and return the graph html
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:Graph/graph_wrapper.html.twig', $page_data
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
     * Called when a user changes RenderPluginOptions or RenderPluginMaps entries for this plugin.
     *
     * @param PluginOptionsChangedEvent $event
     */
    public function onPluginOptionsChanged(PluginOptionsChangedEvent $event)
    {
        // $event->getRenderPluginInstance()->getDataField() returns null, because this is a datatype
        //  plugin...have to use a roundabout method to get the correct datafield
        foreach ($event->getRenderPluginInstance()->getRenderPluginMap() as $rpm) {
            /** @var RenderPluginMap $rpm */
            if ($rpm->getRenderPluginFields()->getFieldName() === "Graph File") {
                $plugin_df = $rpm->getDataField();
                parent::deleteCachedGraphs($plugin_df->getDataType()->getId(), $plugin_df->getId());
            }

            // NOTE: don't need to do the same for the "Secondary Graph File" field...all the graphs
            //  are created based off the "primary" graph file field
        }
    }
}
