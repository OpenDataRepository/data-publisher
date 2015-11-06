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

//  ODR/AdminBundle/Twig/GraphExtension.php;
namespace ODR\OpenRepository\GraphBundle\Plugins;

// use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Doctrine\ORM\EntityManger;
use Symfony\Component\Templating\EngineInterface;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementField;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class GraphPlugin
{
    /**
     * TODO: description.
     * 
     * @var mixed
     */
    private $container;

    /**
     * TODO: description.
     * 
     * @var mixed
     */
    private $entityManager;

    /**
     * TODO: short description.
     * 
     * @param Container $container 
     * 
     * @return TODO
     */
    public function __construct($entityManager, $templating, $logger) {
        $this->em = $entityManager;
        $this->templating = $templating;
	$this->logger = $logger;
    }

    private function is_all_multibyte($string)
    {
        // check if the string doesn't contain invalid byte sequence
        if (mb_check_encoding($string, 'UTF-8') === false) return false;
    
        $length = mb_strlen($string, 'UTF-8');
    
        for ($i = 0; $i < $length; $i += 1) {
    
            $char = mb_substr($string, $i, 1, 'UTF-8');
    
            // check if the string doesn't contain single character
            if (mb_check_encoding($char, 'ASCII')) {
    
                return false;
    
            }
    
        }
    
        return true;
    
    }

    private function contains_any_multibyte($string)
    {
        return !mb_check_encoding($string, 'ASCII') && mb_check_encoding($string, 'UTF-8');
    }


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

    /**
     * TODO - 
     *
     * @param mixed $obj DataRecord or array?
     * @param RenderPlugin $render_plugin
     * @param boolean $public_only If true, don't render non-public items...if false, render everything
     *
     */
    public function execute($obj, $render_plugin, $public_only = false)
    {

        try {
            $em = $this->em;
            // $repo_plugin = $em->getRepository('ODR\AdminBundle\Entity\RenderPlugin');
            $repo_render_plugin_instance = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginInstance');
            $repo_render_plugin_map = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginMap');
            $repo_render_plugin_fields = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginOptions');
    
            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $render_plugin) );

            // If an array is passed, use the first element.
            // This is a "child override" plugin instance
            $drc_group = array();
            if(is_array($obj)) {
                $drc_group = $obj;
                $obj = $obj[0];
            }

            $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $render_plugin, 'dataType' => $obj->getDataType()) );
            $render_plugin_map = $repo_render_plugin_map->findBy( array('renderPluginInstance' => $render_plugin_instance, 'dataType' => $obj->getDataType()) );
            $render_plugin_options = $repo_render_plugin_options->findBy( array('renderPluginInstance' => $render_plugin_instance) );

            // Remap Options
            $plugin_options = array(); 
            foreach($render_plugin_options as $option) {
                if($option->getActive()) {
                    $plugin_options[$option->getOptionName()] = $option->getOptionValue();
                }
            }

            $parent_id = "-1";
            $childtype_id = "";
            $unique_id = "";
            if(count($drc_group) == 0) {
                array_push($drc_group, $obj);
            }

            // Multiple Graph/Override Full Child
            $jp_files = array();
            $nv_files = array();
            $nv_pivot = array();
            foreach($drc_group as $obj) {
                $myfield = "";

                // Need to use parent id with datatype id for unique 

                $unique_id = $obj->getDataType()->getId() . "_" . $obj->getParent()->getId();
                $childtype_id = $obj->getDataType()->getId();
                $parent_id = $obj->getParent()->getId();

                $template_fields = array();
                foreach ($render_plugin_fields as $rp_field) {
                    foreach ($render_plugin_map as $map) {
                        if ( $map->getRenderPluginFields()->getId() == $rp_field->getId() ) {
                            foreach ($obj->getDataRecordFields() as $field) {
                                if ( $map->getDataField()->getId() == $field->getDataField()->getId() ) {
                                    $template_fields[strtolower(preg_replace("/\s/","_",$rp_field->getFieldName()))] = $field;
                                    if ($rp_field->getFieldName() == "Pivot Field") {
                                        switch ( $map->getDataField()->getFieldType()->getTypeClass() ) {
                                            case 'IntegerValue':
                                            case 'ShortVarchar':
                                            case 'MediumVarchar':
                                            case 'LongVarchar':
                                                $myfield = $field->getAssociatedEntity()->getValue();
                                                break;
/*
                                        if ($map->getDataField()->getFieldType()->getTypeClass() == 'IntegerValue')
                                            $myfield = $field->getIntegerValue()->getValue();
                                        else if ($map->getDataField()->getFieldType()->getTypeClass() == 'ShortVarchar')
                                            $myfield = $field->getShortVarchar()->getValue();
*/
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $nv_pivot[$obj->getId()] = $myfield;

                if(isset($template_fields['graph_file'])) {
                    $file = $template_fields['graph_file'];
                    if(is_object($file)) {
                        foreach($file->getFile() as $myfile) {
                            if(null != $myfile->getLocalFileName()) {
                                // Store for NVD3 Instances
                                $nv_file = $myfile->getId();
                                $nv_files[$obj->getId()] = $nv_file;

                                // Store for JPGraph
                                $jp_file = $myfile->getLocalFileName();
                                $jp_files[$obj->getId()] = $jp_file;
                            }
                        }
                    }
                }
            }



            // TODO - currently not using user-defined colors
            // $line_colors = explode(',',$plugin_options['line_colors']);
            $line_colors = array(
                'rgb(114,114,114)',
                'rgb(241,89,95)',
                'rgb(121,195,106)',
                'rgb(89,154,211)',
                'rgb(249,166,90)',
                'rgb(158,102,171)',
                'rgb(205,112,88)',
                'rgb(215,127,179)'
            );
            $jpgraph_line_colors = array(
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


            // Need to cache the files on server
            // TODO Add Function to delete old versions of files
            $nv_chart_id = "Chart_" . rand(1000000,9999999);


            // JPGraph Caching/File Creation
            // Include JPGraph Stuff
            $jp_output_files = array();
            $JPGraphSrc = dirname(__FILE__) . '/../jpgraph';
            require_once ($JPGraphSrc.'/jpgraph.php');
            require_once ($JPGraphSrc.'/jpgraph_log.php');
            require_once ($JPGraphSrc.'/jpgraph_line.php');
            require_once ($JPGraphSrc.'/jpgraph_scatter.php');
    
            // Graph output path
            $img_path = dirname(__FILE__) . "/../../../../../web";

            // Set plugin options
            // All graphs this format
            if(!isset($plugin_options['graph_height'])) {
                $plugin_options['graph_height'] = 500;
                $plugin_options['graph_width'] = 1800;
            }
            else {
                // Attempt to preserve aspect ratio of size user wants
//                $plugin_options['graph_height'] = 1800/$plugin_options['graph_width'] * $plugin_options['graph_height'];
                $plugin_options['graph_height'] = 1800 * ( $plugin_options['graph_height'] / $plugin_options['graph_width'] );
                $plugin_options['graph_width'] = 1800;
            }


            // Create a new graph
            $chart_id = "Chart_";
            $graph = new \Graph($plugin_options['graph_width'], $plugin_options['graph_height']);
//            $theme_class = new \UniversalTheme;


            // ------------------------------
            // Need to save min/max values of all graphs read
            $combined_x_max = null;
            $combined_y_max = null;
            $combined_x_min = null;
            $combined_y_min = null;

            // Read min/max values from plugin options
            $x_max_plugin = 'auto';
            $y_max_plugin = 'auto';
            $x_min_plugin = 'auto';
            $y_min_plugin = 'auto';
            if ( !preg_match("/auto/i", trim($plugin_options['x_axis_min'])) ) {
                $x_min_plugin = intval($plugin_options['x_axis_min']);
                $combined_x_min = $x_min_plugin;
            }

            if ( !preg_match("/auto/i", trim($plugin_options['x_axis_max'])) ) {
                $x_max_plugin = intval($plugin_options['x_axis_max']);
                $combined_x_max = $x_max_plugin;
            }

            if ( !preg_match("/auto/i", trim($plugin_options['y_axis_min'])) ) {
                $y_min_plugin = intval($plugin_options['y_axis_min']);
                $combined_y_min = $y_min_plugin;
            }

            if ( !preg_match("/auto/i", trim($plugin_options['y_axis_max'])) ) {
                $y_max_plugin = intval($plugin_options['y_axis_max']);
                $combined_y_max = $y_max_plugin;
            }


            // ------------------------------
            // Read the graph files
            ini_set('auto_detect_line_endings', TRUE);

            $debug_txt = "";
            $counter = 0;
            $valid_data = false;
            $lines = array();
            foreach ($jp_files as $jp_id => $jp_filename) {
                $graph_data = array();
                $file_path = dirname(__FILE__) . "/../../../../../web/" . $jp_filename;
                // $graph_file_data = preg_split("/\s\s/", self::file_get_contents_utf8($file_path));
                $graph_file_data = array();
                if(file_exists($file_path)) {
                    $graph_file_data = file($file_path); 
                }

                $x_data = array();
                $y_data = array();
                if (isset($graph_file_data) && count($graph_file_data) > 0) {
                    foreach ($graph_file_data as $line) {
                        if (!preg_match("/^#/",$line)) {
                            // $this->logger->info('GraphPlugin :: ' . preg_replace("/\t/",',',trim($line)) . " :::");
                            $data = array();
                            if (preg_match("/\t/", $line)) {
                                $data = preg_split("/\t/", $line);
                            }
                            else {
                                $data = preg_split("/,/", $line);
                            }
                            // $this->logger->info('GraphPlugin :: ' . implode(' -- ', $data) . ' :::' );
                            if (isset($data[0]) && isset($data[1])) {
                                if (self::is_all_multibyte($data[0])) {
                                    $x = $data[0];  // Adding 0 converts Scientific Notation
                                    $y = $data[1];  // Adding 0 converts Scientific Notation
                                }
                                else {
                                    $x = 0 + trim($data[0]);  // Adding 0 converts Scientific Notation
                                    $y = 0 + trim($data[1]);  // Adding 0 converts Scientific Notation
                                }
                                if (preg_match("/[\d\.]+/", $x) && preg_match("/[\d\.]+/", $y)) {

                                    // $this->logger->info('GraphPlugin :: x1=' . $x . ", y=". $y );
//                                    array_push($graph_data, array("x" => $x, "y" => $y));
                                    array_push($x_data, $x);
                                    array_push($y_data, $y);

                                }
                            }
                        }
                    }
                }

                // Ensure values are in bounds
                if(count($x_data) > 0 && count($y_data) > 0) {
                    // 
                    foreach ($x_data as $num => $x_value) {
                        if ( ($x_max_plugin !== 'auto' && $x_value > $x_max_plugin) || ($x_min_plugin !== 'auto' && $x_value < $x_min_plugin) ) {
                            // If the x value is out of bounds, get rid of that point entirely so jpgraph doesn't try to draw it
                            unset( $x_data[$num] );
                            unset( $y_data[$num] );
                        }
                    }

                    //
                    foreach ($y_data as $num => $y_value) {
                        if ( ($y_max_plugin !== 'auto' && $y_value > $y_max_plugin) || ($y_min_plugin !== 'auto' && $y_value < $y_min_plugin) ) {
                            // If the y values are out of bounds, the x coordinate is still useful...but null out the y value so jpgraph doesn't try to draw it
                            $y_data[$num] = '';
                        }
                    }

                    // Re-index the data arrays
                    $x_data = array_values($x_data);
                    $y_data = array_values($y_data);
                }


                // Save the remaining data
                if ( count($x_data) > 0 && count($y_data) > 0 ) {

                    // Calculate combined x/y min/max...don't override values set via plugin
                    if ( $x_min_plugin == 'auto' ) {
                        $graph_x_min = min($x_data);

                        if ($combined_x_min == null)
                            $combined_x_min = $graph_x_min;
                        else if ($graph_x_min < $combined_x_min )
                            $combined_x_min = $graph_x_min;
                    }
                    if ( $x_max_plugin == 'auto' ) {
                        $graph_x_max = max($x_data);

                        if ($combined_x_max == null) 
                            $combined_x_max = $graph_x_max;
                        else if ($graph_x_max > $combined_x_max )
                            $combined_x_max = $graph_x_max;
                    }
                    if ( $y_min_plugin == 'auto' ) {
                        $graph_y_min = min($y_data);

                        if ($combined_y_min == null) 
                            $combined_y_min = $graph_y_min;
                        else if ($graph_y_min < $combined_y_min )
                            $combined_y_min = $graph_y_min;
                    }
                    if ( $y_max_plugin == 'auto' ) {
                        $graph_y_max = max($y_data);

                        if ($combined_y_max == null)
                            $combined_y_max = $graph_y_max;
                        else if ($graph_y_max > $combined_y_max )
                            $combined_y_max = $graph_y_max;
                    }


                    // Create a new LinePlot from the data and add to graph
                    $lines[$counter] = new \LinePlot($y_data, $x_data);
                    $graph->Add($lines[$counter]);

                    // Set LinePlot options
                    $lines[$counter]->SetWeight( $plugin_options['line_stroke'] );
                    $lines[$counter]->SetColor($jpgraph_line_colors[$counter]);
                    $lines[$counter]->SetLegend($nv_pivot[$jp_id]);
//                    $lines[$counter]->SetFastStroke();

                    $debug_txt .= $counter . " -- " . count($y_data) . " - " . count($x_data) . "\n";
                    $chart_id .= "_" . $jp_id;
                    $valid_data = true;
                    $counter++;
                }
            }

/*
print $combined_x_min."\n";
print $combined_x_max."\n";
print $combined_y_min."\n";
print $combined_y_max."\n";
print '-----'."\n";
*/
/*
            // ------------------------------
            // Convert combined x/y min/max into whole numbers divisible by 10
            $combined_x_min = floor($combined_x_min / 10) * 10;
            $combined_y_min = floor($combined_y_min / 10) * 10;

            $combined_x_max = floor($combined_x_max / 10) * 10 * 1.05;  // add 5% for padding?
            $combined_y_max = floor($combined_y_max / 10) * 10 * 1.05;
*/

/*
            if ($combined_x_min > 0)
                $combined_x_min *= 0.95;
            else
                $combined_x_min *= 1.05;
            if ($combined_x_max > 0)
                $combined_x_max *= 1.05;
            else
                $combined_x_max *= 0.95;

            if ($combined_y_min > 0)
                $combined_y_min *= 0.95;
            else
                $combined_y_min *= 1.05;
            if ($combined_y_max > 0)
                $combined_y_max *= 1.05;
            else
                $combined_y_max *= 0.95;
*/

            $combined_x_min = floor($combined_x_min);
            $combined_x_max = ceil($combined_x_max);
            $combined_y_min = floor($combined_y_min);
            $combined_y_max = ceil($combined_y_max);

/*
print $combined_x_min."\n";
print $combined_x_max."\n";
print $combined_y_min."\n";
print $combined_y_max."\n";
*/

            // TODO - logarithm scales?
            // Set the scale of the graph
//print '-- jp_id: '.$jp_id.' jp_filename: '.$jp_filename.' y_min: '.$combined_y_min.' x_min: '.$combined_x_min.' y_max: '.$combined_y_max.' x_max: '.$combined_x_max."\n";
            $graph->SetScale("linlin", $combined_y_min, $combined_y_max, $combined_x_min, $combined_x_max);
//            $graph->SetScale("linlin",0,0,0,0);


            // Required - no anti aliasing module
            $graph->img->SetAntiAliasing(false);
            // Use built in font
            $graph->title->SetFont(FF_DV_SANSSERIF,FS_NORMAL,24);


            // ------------------------------  
            // Graph margins
            $left_margin = 120;
            $right_margin = 60;
            $top_margin = 60;
            $bottom_margin = 120;

            if ( $plugin_options['y_axis_labels'] == 'no' )
                $left_margin = 60;
            if ( $plugin_options['x_axis_labels'] == 'no' )
                $bottom_margin = 80;

            $graph->img->SetMargin($left_margin, $right_margin, $top_margin, $bottom_margin);
            $graph->SetMarginColor("#ffffff");


            // ------------------------------  
            // y-axis options
            if($plugin_options['y_axis_caption'] != "") {
                $graph->yaxis->SetTitle($plugin_options['y_axis_caption'],'middle');
                $graph->yaxis->title->SetFont(FF_DV_SANSSERIF,FS_NORMAL, 20);
            }

            // y-axis labels/ticks
            if($plugin_options['y_axis_labels'] == "no") {
                $graph->yaxis->HideLabels();
                $graph->yaxis->SetTitleMargin(12);
            }
            else {
                $graph->yaxis->SetTitleMargin(60);
                $graph->yaxis->SetFont(FF_DV_SANSSERIF,FS_NORMAL,12);
                $graph->yaxis->SetLabelAngle(45);
                $graph->yaxis->SetColor('#DDD7A6','#3C3930');

                // Set Ticks
                $graph->yaxis->SetTickSide(SIDE_BOTTOM);
                if(!preg_match("/auto/i", $plugin_options['y_axis_tick_interval'])) {
                    $interval = intval($plugin_options['y_axis_tick_interval']);
                    $graph->yscale->ticks->Set($interval, $interval/2);
                }
            }

            $graph->yaxis->scale->SetGrace(1,2);
            $graph->yaxis->SetPos('min');


            // ------------------------------  
            // x-axis options
            if($plugin_options['x_axis_caption'] != "") {
                $graph->xaxis->SetTitle($plugin_options['x_axis_caption'],'middle');
                $graph->xaxis->title->SetFont(FF_DV_SANSSERIF,FS_NORMAL, 20);
            }


            // x-axis labels/ticks
            if($plugin_options['x_axis_labels'] == "no") {
                $graph->xaxis->HideLabels();
                $graph->xaxis->SetTitleMargin(12);
            }
            else {
                $graph->xaxis->SetTitleMargin(50);
                $graph->xaxis->SetFont(FF_DV_SANSSERIF,FS_NORMAL,12);
                $graph->xaxis->SetLabelAngle(45);
                $graph->xaxis->SetColor('#DDD7A6','#3C3930');

                // Set Ticks
                $graph->xaxis->SetTickSide(SIDE_BOTTOM);
                if(!preg_match("/auto/i", $plugin_options['x_axis_tick_interval'])) {
                    $interval = intval($plugin_options['x_axis_tick_interval']);
                    $graph->xscale->ticks->Set($interval, $interval/2);
                }
            }

            $graph->xaxis->scale->SetGrace(0,0);
            $graph->xaxis->SetPos('min');


            // ------------------------------  
            // Graph Legend
            $graph->legend->SetFrameWeight(1);
            // $graph->legend->SetShadow('gray', 1);
            $graph->legend->SetLayout();
            $graph->legend->Pos('0.08','0.15',"right");
            $graph->legend->SetFont(FF_DV_SANSSERIF,FS_NORMAL,18);
            $graph->legend->SetMarkAbsHSize(20);
            $graph->legend->SetMarkAbsVSize(20);


            // Output line
            $graph->img->SetImgFormat('png');

            $chart_id .= "_" . rand(1000000,9999999);
            $img_name = "/uploads/files/graphs/" . $chart_id . ".png";
            if($valid_data) {
                // http://stackoverflow.com/questions/6825959/jpgraph-bottom-margin-with-legend-on-off
                $graph->graph_theme = null;

                $graph->Stroke($img_path."/".$img_name);
            }
            else {
                // Write No Data Image
throw new \Exception("invalid data?");
            }


            $jp_output_files['rollup'] = $img_name;
            $nv_pivot['rollup'] = 'Combined Chart';

            // NVD3 Graph Instance
            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Graph:graph.html.twig', 
                array(
                    'datarecordchild' => $drc_group[0],
                    'line_colors' => $line_colors,
                    'jpgraph_line_colors' => $jpgraph_line_colors,
                    'plugin_options' => $plugin_options,
                    'template_fields' => $template_fields,
                    'nv_chart_id' => $nv_chart_id,
                    'childtype_id' => $childtype_id,
                    'parent_id' => $parent_id,
                    'unique_id' => $unique_id,
                    'nv_pivot' => $nv_pivot,
                    'nv_files' => $nv_files,
                    'jp_output_files' => $jp_output_files
                )
            );

//return 'graph plugin';

            // $output = count($drc_group) . " --- " . $output;
            return $output;

        }
        catch ( \JpGraphException $e ) {
            // TODO - need a way to get errors back
//            print $e->getMessage();
            return "<h2>JpGraphException:</h2><p>" . $e->getMessage() . "</p>";
        }
        catch (\Exception $e) {
            return "<h2>Exception:</h2><p>" . $e->getMessage() . "</p>";
        }
    }

}
