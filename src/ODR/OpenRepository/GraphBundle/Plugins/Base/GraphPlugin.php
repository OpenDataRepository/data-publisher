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
use ODR\AdminBundle\Component\Event\FileDeletedEvent;
use ODR\AdminBundle\Component\Event\PluginOptionsChangedEvent;
// Services
use ODR\AdminBundle\Component\Service\CryptoService;
// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\ODRGraphPlugin;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
// Other
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
     * GraphPlugin constructor.
     *
     * @param EngineInterface $templating
     * @param CryptoService $crypto_service
     * @param string $odr_tmp_directory
     * @param string $odr_web_directory
     */
    public function __construct(
        EngineInterface $templating,
        CryptoService $crypto_service,
        string $odr_tmp_directory,
        string $odr_web_directory
    ) {
        parent::__construct($templating, $odr_tmp_directory, $odr_web_directory);

        $this->templating = $templating;
        $this->crypto_service = $crypto_service;
        $this->odr_web_directory = $odr_web_directory;
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
            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id);

                // Grab the field name specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf_name) );
                $datafield_mapping[$key] = array('datafield' => $df);
            }

            // Need to sort by the datarecord's sort value if possible
            $datarecord_sortvalues = array();
            $sort_typeclass = '';

            $legend_values['rollup'] = 'Combined Chart';
            foreach ($datarecords as $dr_id => $dr) {
                // Store sort values for later...
                $datarecord_sortvalues[$dr_id] = $dr['sortField_value'];
                $sort_typeclass = $dr['sortField_typeclass'];

                // Locate the value for the Pivot Field if possible
                $legend_datafield_id = $datafield_mapping['pivot_field']['datafield']['id'];
                $legend_datafield_typeclass = $datafield_mapping['pivot_field']['datafield']['dataFieldMeta']['fieldType']['typeClass'];

                $entity = array();
                if ( isset($dr['dataRecordFields'][$legend_datafield_id]) ) {

                    $drf = $dr['dataRecordFields'][$legend_datafield_id];
                    switch ($legend_datafield_typeclass) {
                        case 'IntegerValue':
                            if (isset($drf['integerValue'])) {
                                $entity = $drf['integerValue'];
                            }
                            break;
                        case 'DecimalValue':
                            if (isset($drf['decimalValue'])) {
                                $entity = $drf['decimalValue'];
                            }
                            break;
                        case 'ShortVarchar':
                            if (isset($drf['shortVarchar'])) {
                                $entity = $drf['shortVarchar'];
                            }
                            break;
                        case 'MediumVarchar':
                            if (isset($drf['mediumVarchar'])) {
                                $entity = $drf['mediumVarchar'];
                            }
                            break;
                        case 'LongVarchar':
                            if (isset($drf['longVarchar'])) {
                                $entity = $drf['longVarchar'];
                            }
                            break;

                        default:
                            throw new \Exception('Invalid Fieldtype for pivot_field');
                            break;
                    }

                    $legend_values[$dr_id] = $entity[0]['value'];
                }
                else {
                    // Use Datafield ID as Pivot Value
                    $legend_values[$dr_id] = $legend_datafield_id;
                }
            }

            // Sort datarecords by their sortvalue
            $flag = SORT_NATURAL;
            if ($sort_typeclass == 'IntegerValue' ||
                $sort_typeclass == 'DecimalValue' ||
                $sort_typeclass == ''   // if empty string, sort values will be datarecord ids
            ) {
                $flag = SORT_NUMERIC;
            }

            asort($datarecord_sortvalues, $flag);
            $datarecord_sortvalues = array_flip( array_keys($datarecord_sortvalues) );


            // ----------------------------------------
            // Initialize arrays
            $odr_chart_ids = array();
            $odr_chart_file_ids = array();
            $odr_chart_files = array();
            $odr_chart_output_files = array();
            // We must create all file names if not using rollups

            $datatype_folder = '';

            // Or create rollup name for rollup chart
            foreach ($datarecords as $dr_id => $dr) {
                $graph_datafield_id = $datafield_mapping['graph_file']['datafield']['id'];
                if ( isset($dr['dataRecordFields'][$graph_datafield_id]) ) {
                    foreach ($dr['dataRecordFields'][$graph_datafield_id]['file'] as $file_num => $file) {

                        if ( $file_num > 0 ) {
                            $df_name = $datafield_mapping['graph_file']['datafield']['dataFieldMeta']['fieldName'];
                            $file_count = count( $dr['dataRecordFields'][$graph_datafield_id]['file'] );

                            throw new \Exception('The Graph Plugin can only handle a single uploaded file per datafield, but the Datafield "'.$df_name.'" has '.$file_count.' uploaded files.');
                        }

                        if ($datatype_folder === '')
                            $datatype_folder = 'datatype_'.$dr['dataType']['id'].'/';

                        // File ID list is used only by rollup
                        $odr_chart_file_ids[] = $file['id'];

                        // This is the master data used to graph
                        $odr_chart_files[$dr_id] = $file;

                        // We need to generate a unique chart id for each chart
                        $odr_chart_id = "plotly_" . Uuid::uuid4()->toString();
                        $odr_chart_id = str_replace("-","_", $odr_chart_id);
                        $odr_chart_ids[$dr_id] = $odr_chart_id;


                        // This filename must remain predictable
                        $filename = 'Chart__' . $file['id'] . '__' . $graph_datafield_id . '.svg';
                        $odr_chart_output_files[$dr_id] = '/uploads/files/graphs/'.$datatype_folder.$filename;
                    }
                }
            }


            // If $datatype_folder is blank at this point, it's most likely because the user can
            //  view the datarecord/datafield, but not the file...so the graph can't be displayed
            $display_graph = true;
            if ($datatype_folder === '')
                $display_graph = false;

            // Only create a directory for the graph file if the graph is actually being displayed...
            if ( $display_graph ) {
                if ( !file_exists($this->odr_web_directory.'/uploads/files/graphs/') )
                    mkdir($this->odr_web_directory.'/uploads/files/graphs/');
                if ( !file_exists($this->odr_web_directory.'/uploads/files/graphs/'.$datatype_folder) )
                    mkdir($this->odr_web_directory.'/uploads/files/graphs/'.$datatype_folder);
            }

            // Rollup related calculations
            $file_id_list = implode('_', $odr_chart_file_ids);

            // Generate the rollup chart ID for the page chart object
            $odr_chart_id = "Chart_" . Uuid::uuid4()->toString();
            $odr_chart_id = str_replace("-","_", $odr_chart_id);

            $graph_datafield_id = $datafield_mapping['graph_file']['datafield']['id'];
            $filename = 'Chart__' . $file_id_list. '__' . $graph_datafield_id . '.svg';

            // Add a rollup chart
            $odr_chart_ids['rollup'] = $odr_chart_id;
            $odr_chart_output_files['rollup'] = '/uploads/files/graphs/'.$datatype_folder.$filename;


            // ----------------------------------------
            // Should only be one element in $theme_array...
            $theme = null;
            foreach ($theme_array as $t_id => $t)
                $theme = $t;

            // Pulled up here so build graph can access the data
            $page_data = array(
                'datatype_array' => array($datatype['id'] => $datatype),
                'datarecord_array' => $datarecords,
                'theme_array' => $theme_array,

                'target_datatype_id' => $datatype['id'],
                'target_theme_id' => $theme['id'],

                'is_top_level' => $rendering_options['is_top_level'],
                'is_link' => $rendering_options['is_link'],
                'display_type' => $rendering_options['display_type'],
                'display_graph' => $display_graph,

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

            if ( isset($rendering_options['build_graph']) ) {
                // Determine file name
                $graph_filename = "";
                if ( isset($options['use_rollup']) && $options['use_rollup'] == "yes" ) {
                    $graph_filename = $odr_chart_output_files['rollup'];
                }
                else {
                    // Determine target datarecord
                    if ( isset($rendering_options['datarecord_id'])
                        && preg_match("/^\d+$/", trim($rendering_options['datarecord_id']))
                    ) {
                        $graph_filename = $odr_chart_output_files[$rendering_options['datarecord_id']];
                    }
                    else {
                        throw new \Exception('Target datarecord id not set.');
                    }
                }


                // We need to know if this is a rollup or direct record request here...
                if ( file_exists($this->odr_web_directory.$graph_filename) ) {
                    /* Pre-rendered graph file exists, do nothing */
                    return $graph_filename;
                }
                else {
                    // In this case, we should be building a single graph (either rollup or individual datarecord)
                    $files_to_delete = array();
                    if ( isset($options['use_rollup']) && $options['use_rollup'] == "yes" ) {
                        // For each of the files that will be used in the graph...
                        foreach ($odr_chart_files as $dr_id => $file) {
                            // ...ensure that it exists
                            $filepath = $this->odr_web_directory.'/'.$file['localFileName'];
                            if ( !file_exists($filepath) ) {
                                // File does not exist, decryption depends on whether the file is
                                //  public or not...
                                $public_date = $file['fileMeta']['publicDate'];
                                $now = new \DateTime();
                                if ($now < $public_date) {
                                    // File is not public...decrypt to something hard to guess
                                    $non_public_filename = md5($file['original_checksum'].'_'.$file['id'].'_'.random_int(2500,10000)).'.'.$file['ext'];
                                    $filepath = $this->crypto_service->decryptFile($file['id'], $non_public_filename);

                                    // Tweak the stored filename so phantomJS can find it
                                    $page_data['odr_chart_files'][$dr_id]['localFileName'] = 'uploads/files/'.$non_public_filename;

                                    // Ensure the decrypted version gets deleted later
                                    array_push($files_to_delete, $filepath);
                                }
                                else {
                                    // File is public, but not decrypted for some reason
                                    $filepath = $this->crypto_service->decryptFile($file['id']);
                                }
                            }
                        }

                        // Set the chart id
                        $page_data['odr_chart_id'] = $odr_chart_ids['rollup'];
                    }
                    else {
                        // Only a single file will be needed.  Check if it needs to be decrypted.
                        $dr_id = $rendering_options['datarecord_id'];
                        $file = $odr_chart_files[$dr_id];

                        $filepath = $this->odr_web_directory.'/'.$file['localFileName'];
                        if ( !file_exists($filepath) ) {
                            // File does not exist, decryption depends on whether the file is
                            //  public or not...
                            $public_date = $file['fileMeta']['publicDate'];
                            $now = new \DateTime();
                            if ($now < $public_date) {
                                // File is not public...decrypt to something hard to guess
                                $non_public_filename = md5($file['original_checksum'].'_'.$file['id'].'_'.random_int(2500,10000)).'.'.$file['ext'];
                                $filepath = $this->crypto_service->decryptFile($file['id'], $non_public_filename);

                                // Tweak the stored filename so phantomJS can find it
                                $file['localFileName'] = 'uploads/files/'.$non_public_filename;

                                // Ensure the decrypted version gets deleted later
                                array_push($files_to_delete, $filepath);
                            }
                            else {
                                // File is public, but not decrypted for some reason
                                $filepath = $this->crypto_service->decryptFile($file['id']);
                            }
                        }

                        // Set the chart id
                        $page_data['odr_chart_id'] = $odr_chart_ids[$dr_id];

                        $page_data['odr_chart_file_ids'] = array($file['id']);
                        $page_data['odr_chart_files'] = array($dr_id => $file);

                        $graph_datafield_id = $datafield_mapping['graph_file']['datafield']['id'];
                        $filename = 'Chart__'.$file['id'].'__'.$graph_datafield_id.'.svg';
                    }

                    // Pre-rendered graph file does not exist...need to create it
                    $page_data['template_name'] = 'ODROpenRepositoryGraphBundle:Base:Graph/graph_builder.html.twig';
                    $output_filename = parent::buildGraph($page_data, $filename);

                    // Delete previously encrypted non-public files
                    foreach ($files_to_delete as $file_path)
                        unlink($file_path);

                    // File has been created.  Now can return it.
                    return $output_filename;
                }
            }
            else {
                // Render the graph html
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:Graph/graph_wrapper.html.twig', $page_data
                );
            }

            if (!isset($rendering_options['build_graph'])) {
                return $output;
            }
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Called when a user changes RenderPluginOptions or RenderPluginMaps entries for this plugin.
     *
     * @param PluginOptionsChangedEvent $event
     */
    public function onPluginOptionsChanged(PluginOptionsChangedEvent $event)
    {
        // NOTE - $event->getRenderPluginInstance()->getDataField() returns null, because this is a
        //  datatype plugin...have to use a roundabout method to get the correct datafield
        $plugin_df = null;
        foreach ($event->getRenderPluginInstance()->getRenderPluginMap() as $rpm) {
            /** @var RenderPluginMap $rpm */
            if ($rpm->getRenderPluginFields()->getFieldName() === "Graph File")
                $plugin_df = $rpm->getDataField();
        }

        parent::deleteCachedGraphs(0, $plugin_df);
    }


    /**
     * Handles when a file is deleted from a datafield that's using this plugin.
     *
     * @param FileDeletedEvent $event
     */
    public function onFileDelete(FileDeletedEvent $event)
    {
        parent::deleteCachedGraphs($event->getFileId(), $event->getDatafield());
    }

}
