<?php 

/**
 * Open Data Repository Data Publisher
 * Graph Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The graph plugin plots a line graph out of data files uploaded
 * to a File DataField, and labels them using a "pivot" field
 * selected when the graph plugin is created...
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;

class GraphPlugin
{


    /**
     * @var mixed
     */
    private $templating;


    /**
     * @var mixed
     */
    private $logger;


    /**
     * @var array
     */
    private $line_colors;

    /**
     * @var array
     */
    private $jpgraph_line_colors;


    /**
     * GraphPlugin constructor.
     *
     * @param $templating
     * @param $logger
     */
    public function __construct($templating, $logger) {
        $this->templating = $templating;
	    $this->logger = $logger;
    }


    /**
     * TODO
     *
     * @param $string
     *
     * @return bool
     */
    private function is_all_multibyte($string)
    {
        // check if the string doesn't contain invalid byte sequence
        if (mb_check_encoding($string, 'UTF-8') === false)
            return false;

        $length = mb_strlen($string, 'UTF-8');
        for ($i = 0; $i < $length; $i += 1) {

            $char = mb_substr($string, $i, 1, 'UTF-8');

            // check if the string doesn't contain single character
            if (mb_check_encoding($char, 'ASCII'))
                return false;
        }

        return true;
    }


    /**
     * TODO - delete this?
     *
     * @param $string
     *
     * @return bool
     */
/*
    private function contains_any_multibyte($string)
    {
        return !mb_check_encoding($string, 'ASCII') && mb_check_encoding($string, 'UTF-8');
    }
*/

    /**
     * TODO - delete this?
     *
     * @param $str
     *
     * @return string
     */
/*
    private function convert_to_utf8($str) {
        $encoding =  mb_detect_encoding($content, $enclist, true);
        // $this->logger->info('GraphPlugin :: ' . $encoding);

 	    static $enclist = array(
            'UTF-16', 'UTF-16LE', 'UTF-16BE', 'UTF-8', 'ASCII', 
            'ISO-8859-1', 'ISO-8859-2', 'ISO-8859-3', 'ISO-8859-4', 'ISO-8859-5', 
            'ISO-8859-6', 'ISO-8859-7', 'ISO-8859-8', 'ISO-8859-9', 'ISO-8859-10', 
            'ISO-8859-13', 'ISO-8859-14', 'ISO-8859-15', 'ISO-8859-16', 
            'Windows-1251', 'Windows-1252', 'Windows-1254', 
        );
        // $content = file_get_contents($fn); 
        return mb_convert_encoding(
                   $str, 
                   'UTF-8', 
                   mb_detect_encoding($str, $enclist, true)); 
    }
*/

    /**
     * Executes the Graph Plugin on the provided datarecords
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin
     * @param array $theme
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin, $theme, $rendering_options)
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


            // ----------------------------------------
            // TODO - currently not using user-defined colors
            // $line_colors = explode(',',$plugin_options['line_colors']);
            $this->line_colors = array(
                'rgb(114,114,114)',
                'rgb(241,89,95)',
                'rgb(121,195,106)',
                'rgb(89,154,211)',
                'rgb(249,166,90)',
                'rgb(158,102,171)',
                'rgb(205,112,88)',
                'rgb(215,127,179)'
            );
            $this->jpgraph_line_colors = array(
                // '#00ffff',
                '#ff00b5',
                '#7bd1ff',
                '#ffc200',
                // '#99ffff',
                '#ffe799',
                '#ff4dcb',
                '#b99236',
                '#ffd54d'
            );


            // Retrieve mapping between datafields and render plugin fields
            $datafield_mapping = array();
            foreach ($render_plugin_map as $rpm) {
                // Get the entities connected by the render_plugin_map entity??
                $rpf = $rpm['renderPluginFields'];
                $df_id = $rpm['dataField']['id'];

                // Want the full-fledged datafield entry...the one in $rpm['dataField'] has no render plugin or meta data
                // Unfortunately, the desired one is buried inside the $theme array somewhere...
                $df = null;
                foreach ($theme['themeElements'] as $te) {
                    if ( isset($te['themeDataFields']) ) {
                        foreach ($te['themeDataFields'] as $tdf) {
                            if ( isset($tdf['dataField']) && $tdf['dataField']['id'] == $df_id ) {
                                $df = $tdf['dataField'];
                                break;
                            }
                        }
                    }

                    if ($df !== null)
                        break;
                }

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf['fieldName'].'", mapped to df_id '.$df_id);

                // Grab the field name specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );

                $datafield_mapping[$key] = array('datafield' => $df);
            }

            // Switch to UUID?
            $nv_chart_id = "Chart_" . Uuid::uuid4()->toString();
            $nv_chart_id = str_replace("-","_", $nv_chart_id);

            $pivot_values['rollup'] = 'Combined Chart';
            foreach ($datarecords as $dr_id => $dr) {
                $pivot_datafield_id = $datafield_mapping['pivot_field']['datafield']['id'];
                $pivot_datafield_typeclass = $datafield_mapping['pivot_field']['datafield']['dataFieldMeta']['fieldType']['typeClass'];

                $entity = array();
                // Check if Pivot Field is set
                if(isset($dr['dataRecordFields'][$pivot_datafield_id])) {

                    $drf = $dr['dataRecordFields'][$pivot_datafield_id];
                    switch ($pivot_datafield_typeclass) {
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
                            throw new \Exception('Invalid Fieldtype for pivot_field');
                            break;
                    }

                    $pivot_values[$dr_id] = $entity[0]['value'];
                }
                else {
                    // Use Datafield ID as Pivot Value
                    $pivot_values[$dr_id] = $pivot_datafield_id;
                }
            }


            // ----------------------------------------
            //TODO Check if the graph file is set as private or public, Then build a graph with only the public files, and one with both the public and private files.
            // For each datarecord that has been passed to this plugin, determine if the associated graph file exists
            $unique_id = '';
            $nv_filenames = array();
            $nv_files = array();
            foreach ($datarecords as $dr_id => $dr) {
                $graph_datafield_id = $datafield_mapping['graph_file']['datafield']['id'];
                foreach ($dr['dataRecordFields'][$graph_datafield_id]['file'] as $file_num => $file) {
                    $nv_files[$dr_id] = $file['id'];
                    $nv_filenames[$dr_id] = $file['localFileName'];
                }

                $unique_id = $datatype['id'].'_'.$dr['parent']['id'];
            }



            // Pulled up here so build graph can access the data.
            $page_data = array(
                'datatype_array' => array($datatype['id'] => $datatype),
                'datarecord_array' => $datarecords,
                'theme_id' => $theme['id'],
                'target_datatype_id' => $datatype['id'],

                'is_top_level' => $rendering_options['is_top_level'],
                'is_link' => $rendering_options['is_link'],
                'display_type' => $rendering_options['display_type'],

                // Required for the rest of the graph plugin
                'line_colors' => $this->line_colors,
                'jpgraph_line_colors' => $this->jpgraph_line_colors,
                'plugin_options' => $options,
                'unique_id' => $unique_id,
                'nv_chart_id' => $nv_chart_id,
                'nv_pivot' => $pivot_values,
                'nv_files' => $nv_files,
                'nv_filenames' => $nv_filenames
            );
            $graph_keys = array();
            foreach($nv_files as $file_key => $file_value){
                $graph_keys[] = $file_key;
                $graph_values[] = $file_value;
            }

            $nv_sets = array();

            if ( count($nv_files) > 0 ) {
                // If more than one element in the nv files array build the powerset of the elements, done to currently handle permissions.
                if ( count($nv_files) > 1 ) {
                    $nv_sets = self::powerSet($graph_keys, 1);
                }
                // If its one just set it in a an array, This is due to the fact that the powerset function does not handle arrays with length 1.
                else {
                    $nv_sets = array($graph_keys);
                }
                // Iterate over the array, build the file names and check if they exist, if they dont then call build graph.
                $page_data['current_drcids'] = array();
                foreach ($nv_sets as $nv_set){
                    $file_ids = array();
                    for( $i = 0; $i < count($nv_set); $i++) {
                        $file_ids[] = $nv_files[$nv_set[$i]];
                    }
                    $page_data['file_ids'] = $file_ids;
                    $page_data['current_drcids'] = $nv_set;
                    $file_id_list = implode('_', $file_ids);
                    // Use the Max Option Date to ensure graph is up-to-date option wise.
                    $filename = 'Chart__'.$file_id_list. '_' . $max_option_date . '.svg';
                    $page_data['file_id_list'] = $file_id_list;
                    $page_data['filename'] = $filename;
                    $nv_output_files['rollup'] = '/uploads/files/graphs/'.$filename;
                    $page_data['nv_output_files'] = $nv_output_files;
                    
                    if (file_exists(dirname(__FILE__).'/../../../../../web/uploads/files/graphs/'.$filename)) {
                        /* Pre-rendered graph file exists, do nothing */
                        // TODO Determine if we can do this earlier and avoid CPU time.
                    }
                    else {
                        // Pre-rendered graph file does not exist...need to create it
                        self::buildGraph($page_data, $filename);
                    }
                }

                // ----------------------------------------
                // Render the graph html
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Graph:graph_wrapper.html.twig', $page_data
                );

            }
            else {
                // No files exist to graph
            }

            return $output;
        }
        catch ( \JpGraphException $e ) {
            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Default:default_error.html.twig',
                array(
                    'message' => 'JpGraphException: '.$e->getMessage()
                )
            );
            throw new \Exception( $output );
        }
        catch (\Exception $e) {
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
     * Builds the static graphs for the server
     * @param $page_data - A Map holding all the data that is needed for creating the graph html, and for the phantomjs
     *      js server to render it.
     * @param $filename - The name that the svg file should have.
     * @throws \Exception  - Standard PHP exception.
     */
    private function buildGraph($page_data, $filename)
    {
        // Path to writeable files in web folder
        $files_path = dirname(__FILE__) . "/../../../../../web/uploads/files/";

        $fs = new \Symfony\Component\Filesystem\Filesystem();

        //The HTML file that generates the svg graph that will be saved to the server by Phantomjs.
        //TODO Make paths relative
        $output1 = $this->templating->render(
            'ODROpenRepositoryGraphBundle:Graph:graph_builder.html.twig', $page_data
        );
        $fs->dumpFile($files_path . "Chart__" . $page_data['file_id_list'] . '.html', $output1);

        // Temporary output file masked by UUIDv4 (random)
        // TODO - Create cleaner to remove masked_files from /tmp
        $output_tmp_svg = "/tmp/graph_" . Uuid::uuid4()->toString();
        $output_svg = $files_path . "graphs/" . $filename;

        //JSON data to be passed to the phantom js server
        $json_data = array(
            "data" => array(
                'URL' => $files_path . "Chart__" . $page_data['file_id_list'] . '.html',
                'selector' => $page_data['nv_chart_id'],
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
        if (file_exists($output_tmp_svg)) {
            $created_file = file_get_contents($output_tmp_svg);
            $fixed_file = str_replace('viewbox', 'viewBox', $created_file);
            $fixed_file = str_replace('preserveaspectratio', 'preserveAspectRatio', $fixed_file);
            file_put_contents($output_svg, $fixed_file);
        } else {
            throw new \Exception('The file "'. $output_svg .'" does not exist');
        }

    }

    /**
     * Returns the power set of a one dimensional array, a 2-D array.
     * [a,b,c] -> [ [a], [b], [c], [a, b], [a, c], [b, c], [a, b, c] ]
     *
     * PowerSet - Used to build all possible combinations of static graphs.
     */
    private function powerSet($in,$minLength = 1) {
        $count = count($in);
        $members = pow(2,$count);
        $return = array();
        for ($i = 0; $i < $members; $i++) {
            $b = sprintf("%0".$count."b",$i);
            $out = array();
            for ($j = 0; $j < $count; $j++) {
                $testval = $b[$j];
                $inval = $in[$j];
                if ($b[$j] == '1') $out[] = $in[$j];
            }
            if (count($out) >= $minLength) {
                $return[] = $out;
            }
        }
        return $return;
    }

}
