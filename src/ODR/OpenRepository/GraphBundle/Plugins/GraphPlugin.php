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

//            $str = '<pre>'.print_r($datarecords, true)."\n".print_r($datatype, true)."\n".print_r($render_plugin, true)."\n".print_r($theme, true).'</pre>';
//            throw new \Exception($str);


            // ----------------------------------------
            // Grab various properties from the render plugin array
            $render_plugin_instance = $render_plugin['renderPluginInstance'][0];
            $render_plugin_map = $render_plugin_instance['renderPluginMap'];
            $render_plugin_options = $render_plugin_instance['renderPluginOptions'];

            // Remap render plugin by name => value
            $options = array();
            foreach($render_plugin_options as $option) {
                if ( $option['active'] == 1 )
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

                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpf['fieldName']) );

                $datafield_mapping[$key] = array('datafield' => $df);
            }

//            throw new \Exception( '<pre>'.print_r($datafield_mapping, true).'</pre>' );


            // ----------------------------------------
            // ...
            $nv_chart_id = "Chart_" . rand(1000000,9999999);

            // ...
            $pivot_values['rollup'] = 'Combined Chart';
            foreach ($datarecords as $dr_id => $dr) {
                $pivot_datafield_id = $datafield_mapping['pivot_field']['datafield']['id'];
                $pivot_datafield_typeclass = $datafield_mapping['pivot_field']['datafield']['dataFieldMeta']['fieldType']['typeClass'];

                $entity = array();
                $drf = $dr['dataRecordFields'][$pivot_datafield_id];
                switch ($pivot_datafield_typeclass) {
                    case 'IntegerValue':
                        $entity = $drf['integerValue'];
                        break;
                    case 'ShortVarchar':
                        $entity = $drf['shortVarchar'];
                        break;
                    case 'MediumVarchar':
                        $entity = $drf['mediumVarchar'];
                        break;
                    case 'LongVarchar':
                        $entity = $drf['longVarchar'];
                        break;

                    default:
                        throw new \Exception('Invalid Fieldtype for pivot_field');
                        break;
                }

                $pivot_values[$dr_id] = $entity[0]['value'];
            }



//            throw new \Exception( '<pre>'.print_r($pivot_values, true).'</pre>' );

            // ----------------------------------------
            //TODO Check if the graph file is set as private or public, Then build a graph with only the public files, and one with both the public and private files.
            // For each datarecord that has been passed to this plugin, determine if the associated graph file exists
            $unique_id = '';
            $nv_filenames = array();
            $nv_files = array();
            foreach ($datarecords as $dr_id => $dr) {
                $graph_datafield_id = $datafield_mapping['graph_file']['datafield']['id'];
                foreach ($dr['dataRecordFields'][$graph_datafield_id]['file'] as $file_num => $file) {
//                    $jp_files[$dr_id] = $file['localFileName'];
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

//            $jp_output_files = array();
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
                    $filename = 'Chart__'.$file_id_list.'.svg';
                    $page_data['file_id_list'] = $file_id_list;
                    $page_data['filename'] = $filename;
                    $nv_output_files['rollup'] = '/uploads/files/graphs/'.$filename;
                    $page_data['nv_output_files'] = $nv_output_files;
                    
                    if (file_exists(dirname(__FILE__).'/../../../../../web/uploads/files/graphs/'.$filename)) {
                        /* Pre-rendered graph file exists, do nothing */
//                    self::buildGraph($filename, $options, $jp_files, $this->jpgraph_line_colors, $pivot_values);
//                            self::buildGraph($page_data, $filename);
//                            $page_data["svg_contents"] = file_get_contents(dirname(__FILE__).'/../../../../../web/uploads/files/graphs/'.$filename);

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
                // Files exist in this datafield

//                $jp_output_files['rollup'] = '/uploads/files/graphs/'.$filename;


            }
            else {
                // No files exist to graph
            }








            // Return to whatever called this


//            shell_exec('curl -X POST -d @/home/sam/test_request.json -H "Content-Type: application/json" localhost:9494');

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
     */
    private function buildGraph($page_data, $filename)
    {

        $fs = new \Symfony\Component\Filesystem\Filesystem();

        //The HTML file that generates the svg graph that will be saved to the server by Phantomjs.
        //TODO Make paths relative
        $output1 = $this->templating->render(
            'ODROpenRepositoryGraphBundle:Graph:graph_builder.html.twig', $page_data
        );
        $fs->dumpFile(dirname(__FILE__)."/../../../../../web/uploads/files/Chart__".$page_data['file_id_list'].'.html', $output1);

        //JSON data to be passed to the phantom js server
        $json_data = array (
            "data" => array (
                'URL' => dirname(__FILE__)."/../../../../../web/uploads/files/Chart__".$page_data['file_id_list'].'.html',
                'selector' => $page_data['nv_chart_id'],
                'output' => dirname(__FILE__)."/../../../../../web/uploads/files/graphs/".$filename
            )
        );

        $data_string = json_encode($json_data);

        //Curl request to the phantomjs server
        $ch = curl_init('localhost:9494');

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: '.strlen($data_string)
        ));

        $response = curl_exec($ch);

        curl_close($ch);


    }

    /**
     * Returns the power set of a one dimensional array, a 2-D array.
     * [a,b,c] -> [ [a], [b], [c], [a, b], [a, c], [b, c], [a, b, c] ]
     *
     * Powerset - Used to build all possible combinatons of static graphs.
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


    /**
     * TODO
     *
     * @param string $filename
     * @param array $plugin_options
     * @param array $jp_files
     * @param array $jpgraph_line_colors
     * @param array $nv_pivot
     *
     * @throws \Exception
     */
//    private function buildGraph($filename, $plugin_options, $jp_files, $jpgraph_line_colors, $nv_pivot)
//    {
//        // JPGraph Caching/File Creation
//        // Include JPGraph Stuff
//        $JPGraphSrc = dirname(__FILE__).'/../jpgraph';
//        require_once($JPGraphSrc.'/jpgraph.php');
//        require_once($JPGraphSrc.'/jpgraph_log.php');
//        require_once($JPGraphSrc.'/jpgraph_line.php');
//        require_once($JPGraphSrc.'/jpgraph_scatter.php');
//
//        // Graph output path
//        $img_path = dirname(__FILE__)."/../../../../../web";
//
//        // Set plugin options
//        // All graphs this format
//        if (!isset($plugin_options['graph_height'])) {
//            $plugin_options['graph_height'] = 500;
//            $plugin_options['graph_width'] = 1800;
//        }
//        else {
//            // Attempt to preserve aspect ratio of size user wants
////            $plugin_options['graph_height'] = 1800/$plugin_options['graph_width'] * $plugin_options['graph_height'];
//            $plugin_options['graph_height'] = 1800 * ($plugin_options['graph_height'] / $plugin_options['graph_width']);
//            $plugin_options['graph_width'] = 1800;
//        }
//
//
//        // Create a new graph
//        $chart_id = "Chart_";
//        $graph = new \Graph($plugin_options['graph_width'], $plugin_options['graph_height']);
////        $theme_class = new \UniversalTheme;
//
//
//        // ------------------------------
//        // Need to save min/max values of all graphs read
//        $combined_x_max = null;
//        $combined_y_max = null;
//        $combined_x_min = null;
//        $combined_y_min = null;
//
//        // Read min/max values from plugin options
//        $x_max_plugin = 'auto';
//        $y_max_plugin = 'auto';
//        $x_min_plugin = 'auto';
//        $y_min_plugin = 'auto';
//        if (!preg_match("/auto/i", trim($plugin_options['x_axis_min']))) {
//            $x_min_plugin = intval($plugin_options['x_axis_min']);
//            $combined_x_min = $x_min_plugin;
//        }
//
//        if (!preg_match("/auto/i", trim($plugin_options['x_axis_max']))) {
//            $x_max_plugin = intval($plugin_options['x_axis_max']);
//            $combined_x_max = $x_max_plugin;
//        }
//
//        if (!preg_match("/auto/i", trim($plugin_options['y_axis_min']))) {
//            $y_min_plugin = intval($plugin_options['y_axis_min']);
//            $combined_y_min = $y_min_plugin;
//        }
//
//        if (!preg_match("/auto/i", trim($plugin_options['y_axis_max']))) {
//            $y_max_plugin = intval($plugin_options['y_axis_max']);
//            $combined_y_max = $y_max_plugin;
//        }
//
//
//        // ------------------------------
//        // Read the graph files
//        ini_set('auto_detect_line_endings', TRUE);
//
//        $debug_txt = "";
//        $counter = 0;
//        $valid_data = false;
//        $lines = array();
//        foreach ($jp_files as $jp_id => $jp_filename) {
//            $graph_data = array();
//
//            $file_path = dirname(__FILE__)."/../../../../../web/".$jp_filename;
//            // $graph_file_data = preg_split("/\s\s/", self::file_get_contents_utf8($file_path));
//            $graph_file_data = array();
//            if (file_exists($file_path)) {
//                $graph_file_data = file($file_path);
//            }
//            else {
//                throw new \Exception('the file "'.$jp_filename.'" does not exist');
//            }
//
//            $x_data = array();
//            $y_data = array();
//            if (isset($graph_file_data) && count($graph_file_data) > 0) {
//                foreach ($graph_file_data as $line) {
//                    if (!preg_match("/^#/", $line)) {
//                        // $this->logger->info('GraphPlugin :: ' . preg_replace("/\t/",',',trim($line)) . " :::");
//                        $data = array();
//                        if (preg_match("/\t/", $line)) {
//                            $data = preg_split("/\t/", $line);
//                        }
//                        else {
//                            $data = preg_split("/,/", $line);
//                        }
//                        // $this->logger->info('GraphPlugin :: ' . implode(' -- ', $data) . ' :::' );
//                        if (isset($data[0]) && isset($data[1])) {
//                            if (self::is_all_multibyte($data[0])) {
//                                $x = $data[0];
//                                $y = $data[1];
//                            }
//                            else {
//                                $x = 0 + trim($data[0]);  // Adding 0 converts to Scientific Notation
//                                $y = 0 + trim($data[1]);
//                            }
//                            if (preg_match("/[\d\.]+/", $x) && preg_match("/[\d\.]+/", $y)) {
//
//                                // $this->logger->info('GraphPlugin :: x1=' . $x . ", y=". $y );
////                                    array_push($graph_data, array("x" => $x, "y" => $y));
//                                array_push($x_data, $x);
//                                array_push($y_data, $y);
//
//                            }
//                        }
//                    }
//                }
//            }
//
//            // Ensure values are in bounds
//            if (count($x_data) > 0 && count($y_data) > 0) {
//                //
//                foreach ($x_data as $num => $x_value) {
//                    if (($x_max_plugin !== 'auto' && $x_value > $x_max_plugin) || ($x_min_plugin !== 'auto' && $x_value < $x_min_plugin)) {
//                        // If the x value is out of bounds, get rid of that point entirely so jpgraph doesn't try to draw it
//                        unset($x_data[$num]);
//                        unset($y_data[$num]);
//                    }
//                }
//
//                //
//                foreach ($y_data as $num => $y_value) {
//                    if (($y_max_plugin !== 'auto' && $y_value > $y_max_plugin) || ($y_min_plugin !== 'auto' && $y_value < $y_min_plugin)) {
//                        // If the y values are out of bounds, the x coordinate is still useful...but null out the y value so jpgraph doesn't try to draw it
//                        $y_data[$num] = '';
//                    }
//                }
//
//                // Re-index the data arrays
//                $x_data = array_values($x_data);
//                $y_data = array_values($y_data);
//            }
//
//            // Save the remaining data
//            if (count($x_data) > 0 && count($y_data) > 0) {
//
//                // Calculate combined x/y min/max...don't override values set via plugin
//                if ($x_min_plugin == 'auto') {
//                    $graph_x_min = min($x_data);
//
//                    if ($combined_x_min == null)
//                        $combined_x_min = $graph_x_min;
//                    else if ($graph_x_min < $combined_x_min)
//                        $combined_x_min = $graph_x_min;
//                }
//                if ($x_max_plugin == 'auto') {
//                    $graph_x_max = max($x_data);
//
//                    if ($combined_x_max == null)
//                        $combined_x_max = $graph_x_max;
//                    else if ($graph_x_max > $combined_x_max)
//                        $combined_x_max = $graph_x_max;
//                }
//                if ($y_min_plugin == 'auto') {
//                    $graph_y_min = min($y_data);
//
//                    if ($combined_y_min == null)
//                        $combined_y_min = $graph_y_min;
//                    else if ($graph_y_min < $combined_y_min)
//                        $combined_y_min = $graph_y_min;
//                }
//                if ($y_max_plugin == 'auto') {
//                    $graph_y_max = max($y_data);
//
//                    if ($combined_y_max == null)
//                        $combined_y_max = $graph_y_max;
//                    else if ($graph_y_max > $combined_y_max)
//                        $combined_y_max = $graph_y_max;
//                }
//
//
//                // Create a new LinePlot from the data and add to graph
//                $lines[$counter] = new \LinePlot($y_data, $x_data);
//                $graph->Add($lines[$counter]);
//
//                // Set LinePlot options
//                $lines[$counter]->SetWeight($plugin_options['line_stroke']);
//                $lines[$counter]->SetColor($jpgraph_line_colors[$counter]);
//                $lines[$counter]->SetLegend($nv_pivot[$jp_id]);
////                $lines[$counter]->SetFastStroke();
//
//                $debug_txt .= $counter." -- ".count($y_data)." - ".count($x_data)."\n";
//                $chart_id .= "_".$jp_id;
//                $valid_data = true;
//                $counter++;
//            }
//        }
//
//
///*
//        print $combined_x_min."\n";
//        print $combined_x_max."\n";
//        print $combined_y_min."\n";
//        print $combined_y_max."\n";
//        print '-----'."\n";
//*/
///*
//        // ------------------------------
//        // Convert combined x/y min/max into whole numbers divisible by 10
//        $combined_x_min = floor($combined_x_min / 10) * 10;
//        $combined_y_min = floor($combined_y_min / 10) * 10;
//
//        $combined_x_max = floor($combined_x_max / 10) * 10 * 1.05;  // add 5% for padding?
//        $combined_y_max = floor($combined_y_max / 10) * 10 * 1.05;
//*/
//
///*
//        if ($combined_x_min > 0)
//            $combined_x_min *= 0.95;
//        else
//            $combined_x_min *= 1.05;
//        if ($combined_x_max > 0)
//            $combined_x_max *= 1.05;
//        else
//            $combined_x_max *= 0.95;
//
//        if ($combined_y_min > 0)
//            $combined_y_min *= 0.95;
//        else
//            $combined_y_min *= 1.05;
//        if ($combined_y_max > 0)
//            $combined_y_max *= 1.05;
//        else
//            $combined_y_max *= 0.95;
//*/
//
//        $combined_x_min = floor($combined_x_min);
//        $combined_x_max = ceil($combined_x_max);
//        $combined_y_min = floor($combined_y_min);
//        $combined_y_max = ceil($combined_y_max);
//
///*
//        print $combined_x_min."\n";
//        print $combined_x_max."\n";
//        print $combined_y_min."\n";
//        print $combined_y_max."\n";
//*/
//
//        // TODO - logarithm scales?
//        // Set the scale of the graph
//// print '-- jp_id: '.$jp_id.' jp_filename: '.$jp_filename.' y_min: '.$combined_y_min.' x_min: '.$combined_x_min.' y_max: '.$combined_y_max.' x_max: '.$combined_x_max."\n";
//        $graph->SetScale("linlin", $combined_y_min, $combined_y_max, $combined_x_min, $combined_x_max);
////        $graph->SetScale("linlin",0,0,0,0);
//
//
//        // Required - no anti aliasing module
//        $graph->img->SetAntiAliasing(false);
//        // Use built in font
//        $graph->title->SetFont(FF_DV_SANSSERIF, FS_NORMAL, 24);
//
//
//        // ------------------------------
//        // Graph margins
//        $left_margin = 120;
//        $right_margin = 60;
//        $top_margin = 60;
//        $bottom_margin = 120;
//
//        if ($plugin_options['y_axis_labels'] == 'no')
//            $left_margin = 60;
//        if ($plugin_options['x_axis_labels'] == 'no')
//            $bottom_margin = 80;
//
//        $graph->img->SetMargin($left_margin, $right_margin, $top_margin, $bottom_margin);
//        $graph->SetMarginColor("#ffffff");
//
//
//        // ------------------------------
//        // y-axis options
//        if ($plugin_options['y_axis_caption'] != "") {
//            $graph->yaxis->SetTitle($plugin_options['y_axis_caption'], 'middle');
//            $graph->yaxis->title->SetFont(FF_DV_SANSSERIF, FS_NORMAL, 20);
//        }
//
//        // y-axis labels/ticks
//        if ($plugin_options['y_axis_labels'] == "no") {
//            $graph->yaxis->HideLabels();
//            $graph->yaxis->SetTitleMargin(12);
//        }
//        else {
//            $graph->yaxis->SetTitleMargin(60);
//            $graph->yaxis->SetFont(FF_DV_SANSSERIF, FS_NORMAL, 12);
//            $graph->yaxis->SetLabelAngle(45);
//            $graph->yaxis->SetColor('#DDD7A6', '#3C3930');
//
//            // Set Ticks
//            $graph->yaxis->SetTickSide(SIDE_BOTTOM);
//            if (!preg_match("/auto/i", $plugin_options['y_axis_tick_interval'])) {
//                $interval = intval($plugin_options['y_axis_tick_interval']);
//                $graph->yscale->ticks->Set($interval, $interval / 2);
//            }
//        }
//
//        $graph->yaxis->scale->SetGrace(1, 2);
//        $graph->yaxis->SetPos('min');
//
//
//        // ------------------------------
//        // x-axis options
//        if ($plugin_options['x_axis_caption'] != "") {
//            $graph->xaxis->SetTitle($plugin_options['x_axis_caption'], 'middle');
//            $graph->xaxis->title->SetFont(FF_DV_SANSSERIF, FS_NORMAL, 20);
//        }
//
//
//        // x-axis labels/ticks
//        if ($plugin_options['x_axis_labels'] == "no") {
//            $graph->xaxis->HideLabels();
//            $graph->xaxis->SetTitleMargin(12);
//        }
//        else {
//            $graph->xaxis->SetTitleMargin(50);
//            $graph->xaxis->SetFont(FF_DV_SANSSERIF, FS_NORMAL, 12);
//            $graph->xaxis->SetLabelAngle(45);
//            $graph->xaxis->SetColor('#DDD7A6', '#3C3930');
//
//            // Set Ticks
//            $graph->xaxis->SetTickSide(SIDE_BOTTOM);
//            if (!preg_match("/auto/i", $plugin_options['x_axis_tick_interval'])) {
//                $interval = intval($plugin_options['x_axis_tick_interval']);
//                $graph->xscale->ticks->Set($interval, $interval / 2);
//            }
//        }
//
//        $graph->xaxis->scale->SetGrace(0, 0);
//        $graph->xaxis->SetPos('min');
//
//
//        // ------------------------------
//        // Graph Legend
//        $graph->legend->SetFrameWeight(1);
//        // $graph->legend->SetShadow('gray', 1);
//        $graph->legend->SetLayout();
//        $graph->legend->Pos('0.08', '0.15', "right");
//        $graph->legend->SetFont(FF_DV_SANSSERIF, FS_NORMAL, 18);
//        $graph->legend->SetMarkAbsHSize(20);
//        $graph->legend->SetMarkAbsVSize(20);
//
//
//        // Output line
//        $graph->img->SetImgFormat('png');
//
//        if ($valid_data) {
//            // http://stackoverflow.com/questions/6825959/jpgraph-bottom-margin-with-legend-on-off
//            $graph->graph_theme = null;
//
//            $graph->Stroke( dirname(__FILE__).'/../../../../../web/uploads/files/graphs/'.$filename );
//        }
//        else {
//            // Write No Data Image
////            throw new \Exception("invalid data?");
//        }
//    }




}
