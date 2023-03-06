<?php

/**
 * Open Data Repository Data Publisher
 * ODR Graph Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This abstract class contains the code so PhantomJS can properly pre-render graphs, among other
 * assorted things that all graph plugins should have.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Entities
use ODR\AdminBundle\Entity\DataFields;
// Symfony
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;

abstract class ODRGraphPlugin
{
    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var string
     */
    private $odr_tmp_directory;

    /**
     * @var string
     */
    private $odr_web_directory;


    /**
     * GraphPlugin constructor.
     *
     * @param EngineInterface $templating
     * @param string $odr_tmp_directory
     * @param string $odr_web_directory
     */
    public function __construct(
        EngineInterface $templating,
        string $odr_tmp_directory,
        string $odr_web_directory
    ) {
        $this->templating = $templating;
        $this->odr_tmp_directory = $odr_tmp_directory;
        $this->odr_web_directory = $odr_web_directory;
    }


    /**
     * Gets phantomJS to build a static graph, and moves the resulting SVG into the proper directory.
     *
     * @param array $page_data A Map holding all the data that is needed for creating the graph
     *                          html, and for the phantomjs js server to render it.
     * @param string $filename The name that the svg file should have.
     *
     * @throws \Exception
     *
     * @return string
     */
    protected function buildGraph($page_data, $filename)
    {
        // Going to use Symfony to write files
        $fs = new \Symfony\Component\Filesystem\Filesystem();

        // Files written by this function must be in web folder, otherwise phantomJS can't find them
        $output_path = $this->odr_web_directory.'/uploads/files/';

        // Prepare other variables needed for the graph file's name
        $datatype_folder = 'datatype_'.$page_data['target_datatype_id'].'/';
        $file_id_list = implode('_', $page_data['odr_chart_file_ids']);


        // The HTML file that generates the svg graph that will be saved to the server by Phantomjs.
        $output1 = $this->templating->render(
            $page_data['template_name'], $page_data
        );
        $fs->dumpFile($output_path."Chart__".$file_id_list.'.html', $output1);
        // Note that this will save graphs with data from non-public files in the web-accessible space
        // TODO - is there a way of keeping them outside of web-accessible space?


        // phantomJS's temporary output needs to be in ODR's tmp directory, otherwise ODR isn't
        //  guaranteed to be able to move it to the web-accessible directory
        $output_tmp_svg = $this->odr_tmp_directory."/graph_" . Uuid::uuid4()->toString();
        // The final svg needs to be in a web-accessible directory
        $output_svg = $output_path.'graphs/'.$datatype_folder.$filename;

        // JSON data to be passed to the phantom js server
        $json_data = array(
            "data" => array(
                'URL' => $output_path."Chart__".$file_id_list.'.html',
                'selector' => $page_data['odr_chart_id'],
                'output' => $output_tmp_svg
            )
        );

        $data_string = json_encode($json_data);

        // Curl request to the PhantomJS server
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

            // Remove the svg file in the temporary directory
            unlink($output_tmp_svg);

            // Remove the HTML file
            unlink($output_path."Chart__".$file_id_list.'.html');
            // Return the relative path to the final svg file so the browser can download it
            return '/uploads/files/graphs/'.$datatype_folder.$filename;
        }
        else {
            if ( strlen($output_svg) > 40 ) {
                $output_svg = "..." . substr($output_svg,(strlen($output_svg) - 40), strlen($output_svg));
            }

            throw new \Exception('The file "'. $output_svg .'" does not exist');
        }
    }


    /**
     * Locates and deletes all static graphs that are built from a given file.
     *
     * @param int $file_id
     * @param DataFields $datafield
     */
    protected function deleteCachedGraphs($file_id, $datafield)
    {
        // Need to try to locate the filenames on the server
        $filename_fragment = '';
        if ($file_id !== 0) {
            // If a file_id was passed in, then attempt to find graphs that use just that file
            $filename_fragment = '_'.$file_id.'_';
        }
        else {
            // If a file_id wasn't passed in, then attempt to find all graphs for the given datafield
            $filename_fragment = '__'.$datafield->getId().'.svg';
        }

        // Graphs are organized into subdirectories by datatype id
        $datatype_id = $datafield->getDataType()->getId();
        $graph_filepath = $this->odr_web_directory.'/uploads/files/graphs/datatype_'.$datatype_id.'/';
        if ( file_exists($graph_filepath) ) {
            $files = scandir($graph_filepath);
            foreach ($files as $filename) {
                // TODO - assumes linux?
                if ($filename === '.' || $filename === '..')
                    continue;

                // If this cached graph used this file, unlink it to force a rebuild later on
                if ( strpos($filename, $filename_fragment) !== false )
                    unlink($graph_filepath.'/'.$filename);
            }
        }
    }

    // TODO - is this even useful to have anymore?  it was in DatabaseInfoService, under the function resetDatatypeSortOrder()
//    public static function deleteCachedGraphsByDatatype($datatype_id)
//    {
//        $graph_filepath = $this->odr_web_directory.'/uploads/files/graphs/datatype_'.$datatype_id.'/';
//        if ( file_exists($graph_filepath) ) {
//            $files = scandir($graph_filepath);
//            foreach ($files as $filename) {
//                // TODO - assumes linux?
//                if ($filename === '.' || $filename === '..')
//                    continue;
//
//                unlink($graph_filepath.'/'.$filename);
//            }
//        }
//    }
}