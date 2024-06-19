<?php 

/**
 * Open Data Repository Data Publisher
 * GC Mass Spec Plugin
 * (C) 2016 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2016 by Alex Pires (ajpires@email.arizona.edu)
 * (C) 2016 by Hunter Carter (hunter@stoneumbrella.com)
 * Released under the GPLv2
 *
 * The GCMS Plugin handles the specialized requirements to allow users to make sense of the uploaded
 * Gas Chromatography Mass Spectrometry files
 *
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


class GCMassSpecPlugin extends ODRGraphPlugin implements DatatypePluginInterface
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
     * GCMassSpecPlugin constructor.
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
     * Executes the GCMassSpec Plugin on the provided datarecords
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

            // GCMS graphs aren't rollup
            $is_rollup = false;

            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ( $df == null ) {
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
                }

                // Grab the field name specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf_name) );
                $datafield_mapping[$key] = array('datafield' => $df);
            }

            // Graphs are always going to be labelled with the id of the graph file datafield
            $graph_datafield_id = $datafield_mapping['graph_file']['datafield']['id'];

            // Need to sort by the datarecord's sort value if possible
            $datarecord_sortvalues = array();
            $sortField_type = '';

            $legend_values['rollup'] = 'Combined Chart';
            foreach ($datarecords as $dr_id => $dr) {
                $legend_values[$dr_id] = $dr_id;

                // Store sort values for later...
                $datarecord_sortvalues[$dr_id] = $dr['sortField_value'];
                $sortField_type = $dr['sortField_types'];
//
//                // Locate the value for the Pivot Field if possible
//                $legend_datafield_id = $datafield_mapping['pivot_field']['datafield']['id'];
//                $legend_datafield_typeclass = $datafield_mapping['pivot_field']['datafield']['dataFieldMeta']['fieldType']['typeClass'];
//
//                $entity = array();
//                if ( isset($dr['dataRecordFields'][$legend_datafield_id]) ) {
//
//                    $drf = $dr['dataRecordFields'][$legend_datafield_id];
//                    switch ($legend_datafield_typeclass) {
//                        case 'IntegerValue':
//                            if (isset($drf['integerValue'])) {
//                                $entity = $drf['integerValue'];
//                            }
//                            break;
//                        case 'DecimalValue':
//                            if (isset($drf['decimalValue'])) {
//                                $entity = $drf['decimalValue'];
//                            }
//                            break;
//                        case 'ShortVarchar':
//                            if (isset($drf['shortVarchar'])) {
//                                $entity = $drf['shortVarchar'];
//                            }
//                            break;
//                        case 'MediumVarchar':
//                            if (isset($drf['mediumVarchar'])) {
//                                $entity = $drf['mediumVarchar'];
//                            }
//                            break;
//                        case 'LongVarchar':
//                            if (isset($drf['longVarchar'])) {
//                                $entity = $drf['longVarchar'];
//                            }
//                            break;
//
//                        default:
//                            throw new \Exception('Invalid Fieldtype for pivot_field');
//                            break;
//                    }
//
//                    $legend_values[$dr_id] = $entity[0]['value'];
//                }
//                else {
//                    // Use Datafield ID as Pivot Value
//                    $legend_values[$dr_id] = $legend_datafield_id;
//                }
            }

            // Sort datarecords by their sortvalue
            $flag = SORT_NATURAL | SORT_FLAG_CASE;
            if ( $sortField_type === 'numeric' )
                $flag = SORT_NUMERIC;

            asort($datarecord_sortvalues, $flag);
            $datarecord_sortvalues = array_flip( array_keys($datarecord_sortvalues) );

            // Should only be one element in $theme_array...
            $theme = null;
            foreach ($theme_array as $t_id => $t)
                $theme = $t;


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

                if ( isset($dr['dataRecordFields'][$graph_datafield_id]) ) {
                    foreach ($dr['dataRecordFields'][$graph_datafield_id]['file'] as $file_num => $file) {
                        // Complain if the graph datafield has more than one file uploaded for this
                        //  datarecord
                        if ( $file_num > 1 ) {
                            $df_name = $datafield_mapping['graph_file']['datafield']['dataFieldMeta']['fieldName'];
                            $file_count = count( $dr['dataRecordFields'][$graph_datafield_id]['file'] );

                            throw new \Exception('The GCMassSpec Plugin can only handle a single uploaded file per datafield, but the Datafield "'.$df_name.'" has '.$file_count.' uploaded files.');
                        }

                        // Should only be one file here...save it so it can be graphed later
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
                        $filename = 'Chart_'.sha1( $file['id'] ).'_'.$graph_datafield_id.'.svg';
                        $odr_chart_output_files[$dr_id] = '/graphs/'.$datatype_folder.'/'.$filename;
                    }
                }
            }


            // Also need to create the chart ID for a rollup graph...
            $odr_chart_id = "Chart_" . Uuid::uuid4()->toString();
            $odr_chart_id = str_replace("-","_", $odr_chart_id);
            $odr_chart_ids['rollup'] = $odr_chart_id;

            // ...and the filename for the rollup graph
            $file_id_hash = sha1( implode('_', $odr_chart_file_ids) );
            $rollup_filename = 'Chart_'.$file_id_hash.'_'.$graph_datafield_id.'.svg';
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
                    $page_data['template_name'] = 'ODROpenRepositoryGraphBundle:Base:GCMS/graph_builder.html.twig';
                    parent::buildGraph($page_data, $graph_filepath, $builder_filepath, $files_to_delete);
                }
            }


            // ----------------------------------------
            // What to return depends on what called this plugin...
            if ( !isset($rendering_options['build_graph']) ) {
                // ...if called via twig, then render and return the graph html
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:GCMS/graph_wrapper.html.twig', $page_data
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
