<?php 

/**
 * Open Data Repository Data Publisher
 * Graph Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The graph plugin plots a line graph out of data files uploaded
 * to a File DataField, and labels them using a "legend" field
 * selected when the graph plugin is created...
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Services
use ODR\AdminBundle\Component\Service\CryptoService;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
// Other
use Ramsey\Uuid\Uuid;


class GraphPlugin
{
    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Container
     */
    private $container;


    /**
     * GraphPlugin constructor.
     *
     * @param EngineInterface $templating
     * @param Logger $logger
     * @param Container $container
     */
    public function __construct(EngineInterface $templating, Logger $logger, Container $container) {
        $this->templating = $templating;
	    $this->logger = $logger;
        $this->container = $container;
    }


    /**
     * Executes the Graph Plugin on the provided datarecords
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin
     * @param array $theme_array
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin, $theme_array, $rendering_options)
    {

        try {
            // ----------------------------------------
            // Grab various properties from the render plugin array
            $render_plugin_instance = $render_plugin['renderPluginInstance'][0];
            $render_plugin_map = $render_plugin_instance['renderPluginMap'];
            $render_plugin_options = $render_plugin_instance['renderPluginOptions'];

            // Remap render plugin by name => value
            $max_option_date = 0;
            $options = array();
            foreach($render_plugin_options as $option) {
                if ( $option['active'] == 1 )
                    $option_date = new \DateTime($option['updated']->date);
                    $us = $option_date->format('u');
                    $epoch = strtotime($option['updated']->date) * 1000000;
                    $epoch = $epoch + $us;
                    if($epoch > $max_option_date) {
                        $max_option_date = $epoch;
                    }
                    $options[ $option['optionName'] ] = $option['optionValue'];
            }

            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($render_plugin_map as $rpm) {
                // Get the entities connected by the render_plugin_map entity??
                $rpf = $rpm['renderPluginFields'];
                $df_id = $rpm['dataField']['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$df_id]) )
                    $df = $datatype['dataFields'][$df_id];

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf['fieldName'].'", mapped to df_id '.$df_id);

                // Grab the field name specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );

                $datafield_mapping[$key] = array('datafield' => $df);
            }


            $legend_values['rollup'] = 'Combined Chart';
            foreach ($datarecords as $dr_id => $dr) {
                $legend_datafield_id = $datafield_mapping['pivot_field']['datafield']['id'];
                $legend_datafield_typeclass = $datafield_mapping['pivot_field']['datafield']['dataFieldMeta']['fieldType']['typeClass'];

                $entity = array();
                // Check if Pivot Field is set
                if(isset($dr['dataRecordFields'][$legend_datafield_id])) {

                    $drf = $dr['dataRecordFields'][$legend_datafield_id];
                    switch ($legend_datafield_typeclass) {
                        case 'IntegerValue':
                            if(isset($drf['integerValue'])) {
                                $entity = $drf['integerValue'];
                            }
                            break;
                        case 'ShortVarchar':
                            if(isset($drf['shortVarchar'])) {
                                $entity = $drf['shortVarchar'];
                            }
                            break;
                        case 'MediumVarchar':
                            if(isset($drf['mediumVarchar'])) {
                                $entity = $drf['mediumVarchar'];
                            }
                            break;
                        case 'LongVarchar':
                            if(isset($drf['longVarchar'])) {
                                $entity = $drf['longVarchar'];
                            }
                            break;

                        default:
                            throw new \Exception('Invalid Fieldtype for legend_field');
                            break;
                    }

                    $legend_values[$dr_id] = $entity[0]['value'];
                }
                else {
                    // Use Datafield ID as Pivot Value
                    $legend_values[$dr_id] = $legend_datafield_id;
                }
            }


            // Initialize arrays
            $odr_chart_ids = array();
            $odr_chart_file_names = array();
            $odr_chart_file_ids = array();
            $odr_chart_files = array();
            $odr_chart_output_files = array();
            // We must create all file names if not using rollups

            // Or create rollup name for rollup chart
            foreach ($datarecords as $dr_id => $dr) {
                $graph_datafield_id = $datafield_mapping['graph_file']['datafield']['id'];
                if ( isset($dr['dataRecordFields'][$graph_datafield_id]) ) {
                    foreach ($dr['dataRecordFields'][$graph_datafield_id]['file'] as $file_num => $file) {
                        // File ID list is used only by rollup
                        $odr_chart_file_ids[] = $file['id'];

                        // TODO - Possibly still used by system to download data - possibly redundant with file records DEPRECATED
                        $odr_chart_file_names[$dr_id] = $file['localFileName'];

                        // This is the master data used to graph
                        $odr_chart_files[$dr_id] = $file;

                        // We need to generate a unique chart id for each chart
                        $odr_chart_id = "plotly_" . Uuid::uuid4()->toString();
                        $odr_chart_id = str_replace("-","_", $odr_chart_id);
                        $odr_chart_ids[$dr_id] = $odr_chart_id;


                        // This filename must remain predictable
                        // TODO - Long term the UUID should be saved to database
                        // whenever a new chart is created.  This UUID should be unique
                        // to each file version to prevent scraping of data.
                        $filename = 'Chart__' . $file['id'] . '_' . $max_option_date . '.svg';
                        $odr_chart_output_files[$dr_id] = '/uploads/files/graphs/' . $filename;
                    }
                }
            }

            // Rollup related calculations
            $file_id_list = implode('_', $odr_chart_file_ids);

            // Generate the rollup chart ID for the page chart object
            $odr_chart_id = "Chart_" . Uuid::uuid4()->toString();
            $odr_chart_id = str_replace("-","_", $odr_chart_id);
            $filename = 'Chart__' . $file_id_list. '_' . $max_option_date . '.svg';

            // Add a rollup chart
            $odr_chart_ids['rollup'] = $odr_chart_id;
            $odr_chart_output_files['rollup'] = '/uploads/files/graphs/' . $filename;

            // Pulled up here so build graph can access the data.
            $page_data = array(
                'datatype_array' => array($datatype['id'] => $datatype),
                'datarecord_array' => $datarecords,
                'theme_array' => $theme_array,
                'target_datatype_id' => $datatype['id'],

                // TODO - figure out what these do
                'is_top_level' => $rendering_options['is_top_level'],
                'is_link' => $rendering_options['is_link'],
                'display_type' => $rendering_options['display_type'],

                // Options for graph display
                'render_plugin' => $render_plugin,
                'plugin_options' => $options,

                // All of these are indexed by datarecord id
                'odr_chart_ids' => $odr_chart_ids,
                'odr_chart_legend' => $legend_values,
                'odr_chart_file_ids' => $odr_chart_file_ids,
                'odr_chart_file_names' => $odr_chart_file_names,
                'odr_chart_files' => $odr_chart_files,
                'odr_chart_output_files' => $odr_chart_output_files
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
                        throw new \Exception('Target data record id not set.');
                    }
                }


                // We need to know if this is a rollup or direct record request here...
                if ( file_exists($this->container->getParameter('odr_web_directory').$graph_filename) ) {
                    /* Pre-rendered graph file exists, do nothing */
                    return $graph_filename;
                }
                else {
                    // In this case, we should be building a single graph (either rollup or individual datarecord)
                    /** @var CryptoService $crypto_service */
                    $crypto_service = $this->container->get('odr.crypto_service');

                    $files_to_delete = array();
                    if ( isset($options['use_rollup']) && $options['use_rollup'] == "yes" ) {
                        // For each of the files that will be used in the graph...
                        foreach ($odr_chart_files as $dr_id => $file) {
                            // ...ensure that it exists
                            $filepath = $this->container->getParameter('odr_web_directory').'/'.$file['localFileName'];
                            if ( !file_exists($filepath) ) {
                                // File does not exist, decrypt it
                                $file_path = $crypto_service->decryptFile($file['id']);

                                // If file is not public, make sure it gets deleted later
                                $public_date = $file['fileMeta']['publicDate'];
                                $now = new \DateTime();
                                if ($now < $public_date)
                                    array_push($files_to_delete, $file_path);
                            }
                        }

                        // Set the chart id
                        $page_data['odr_chart_id'] = $odr_chart_ids['rollup'];
                    }
                    else {
                        // Only a single file will be needed.  Check if it needs to be decrypted.
                        $dr_id = $rendering_options['datarecord_id'];
                        $file = $odr_chart_files[$dr_id];

                        $filepath = $this->container->getParameter('odr_web_directory').'/'.$file['localFileName'];
                        if ( !file_exists($filepath) ) {
                            // File does not exist, decrypt it
                            $file_path = $crypto_service->decryptFile($file['id']);

                            // If file is not public, make sure it gets deleted later
                            $public_date = $file['fileMeta']['publicDate'];
                            $now = new \DateTime();
                            if ($now < $public_date)
                                array_push($files_to_delete, $file_path);
                        }

                        // Set the chart id
                        $page_data['odr_chart_id'] = $odr_chart_ids[$dr_id];

                        $page_data['odr_chart_file_ids'] = array($file['id']);
                        $page_data['odr_chart_file_names'] = array($dr_id => $file['localFileName']);
                        $page_data['odr_chart_files'] = array($dr_id => $file);

                        $filename = 'Chart__'.$file['id'].'_'.$max_option_date.'.svg';
                    }

                    // Pre-rendered graph file does not exist...need to create it
                    $output_filename = self::buildGraph($page_data, $filename);

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
                    'ODROpenRepositoryGraphBundle:Graph:graph_wrapper.html.twig', $page_data
                );
            }

            if (!isset($rendering_options['build_graph'])) {
                return $output;
            }
        }
        catch (\Exception $e) {
            // TODO Can we remove this?...
            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Default:default_error.html.twig',
                array(
                    'message' => $e->getMessage()
                )
            );
            throw new \Exception( $output );
        }
    }


    /**
     * Builds the static graphs for the server.
     *
     * @param array $page_data A Map holding all the data that is needed for creating the graph
     *                          html, and for the phantomjs js server to render it.
     * @param string $filename The name that the svg file should have.
     *
     * @throws \Exception
     *
     * @return string
     */
    private function buildGraph($page_data, $filename)
    {
        // Prepare the file_id list
        $file_id_list = implode('_', $page_data['odr_chart_file_ids']);

        // Path to writeable files in web folder
        $files_path = $this->container->getParameter('odr_web_directory').'/uploads/files/';
        $fs = new \Symfony\Component\Filesystem\Filesystem();

        //The HTML file that generates the svg graph that will be saved to the server by Phantomjs.
        //TODO Make paths relative
        $output1 = $this->templating->render(
            'ODROpenRepositoryGraphBundle:Graph:graph_builder.html.twig', $page_data
        );
        $fs->dumpFile($files_path . "Chart__" . $file_id_list . '.html', $output1);

        // Temporary output file masked by UUIDv4 (random)
        // TODO - Create cleaner to remove masked_files from /tmp
        $output_tmp_svg = "/tmp/graph_" . Uuid::uuid4()->toString();
        $output_svg = $files_path . "graphs/" . $filename;

        //JSON data to be passed to the phantom js server
        $json_data = array(
            "data" => array(
                'URL' => $files_path . "Chart__" . $file_id_list . '.html',
                'selector' => $page_data['odr_chart_id'],
                'output' => $output_tmp_svg
            )
        );

        $data_string = json_encode($json_data);

        //Curl request to the PhantomJS server
        $ch = curl_init('localhost:9494');

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string)
        ));

        $response = curl_exec($ch);
        curl_close($ch);

        // Parse output to fix CamelCase in SVG element
        if ( file_exists($output_tmp_svg) ) {
            $created_file = file_get_contents($output_tmp_svg);
            $fixed_file = str_replace('viewbox', 'viewBox', $created_file);
            $fixed_file = str_replace('preserveaspectratio', 'preserveAspectRatio', $fixed_file);
            file_put_contents($output_svg, $fixed_file);

            // Remove the HTML file
            unlink($files_path . "Chart__" . $file_id_list . '.html');
            return '/uploads/files/graphs/'.$filename;
        }
        else {
            if ( strlen($output_svg) > 40 ) {
                $output_svg = "..." . substr($output_svg,(strlen($output_svg) - 40), strlen($output_svg));
            }

            throw new \Exception('The file "'. $output_svg .'" does not exist');
        }
    }
}
