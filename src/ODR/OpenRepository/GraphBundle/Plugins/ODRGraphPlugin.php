<?php

/**
 * Open Data Repository Data Publisher
 * ODR Graph Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This abstract class contains the code so Puppeteer can properly pre-render graphs, among other
 * assorted things that most graph plugins should have.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Entities
use ODR\AdminBundle\Entity\DataFields;
// Symfony
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Bridge\Monolog\Logger;
use Pheanstalk\Pheanstalk;

abstract class ODRGraphPlugin
{
    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Pheanstalk
     */
    private $pheanstalk;

    /**
     * @var string
     */
    private $odr_tmp_directory;

    /**
     * @var string
     */
    private $odr_web_directory;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * ODRGraph Plugin constructor.
     *
     * @param EngineInterface $templating
     * @param string $odr_tmp_directory
     * @param string $odr_web_directory
     * @param Logger $logger
     */
    public function __construct(
        EngineInterface $templating,
        Pheanstalk $pheanstalk,
        string $odr_tmp_directory,
        string $odr_web_directory,
        Logger $logger
    ) {
        $this->templating = $templating;
        $this->pheanstalk = $pheanstalk;
        $this->odr_tmp_directory = $odr_tmp_directory;
        $this->odr_web_directory = $odr_web_directory;
        $this->logger = $logger;
    }


    /**
     * Gets Puppeteer to build a static graph, and moves the resulting SVG into the proper directory.
     *
     * @param array $page_data A Map holding all the data that is needed for creating the graph
     *                          html, and for the phantomjs js server to render it.
     * @param string $filename The name that the svg file should have.
     *
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


        // The temporary output needs to be in ODR's tmp directory, otherwise ODR isn't guaranteed
        //  to be able to move it to the web-accessible directory
        $output_tmp_svg = $this->odr_tmp_directory."/graph_" . Uuid::uuid4()->toString();
        // The final svg needs to be in a web-accessible directory
        $output_svg = $output_path.'graphs/'.$datatype_folder.$filename;

        // Create node call
        $this->logger->debug('ODRGraphPlugin:: IN THE GRAPH RENDERER');
        $this->logger->debug('ODRGraphPlugin:: ' . "Chart__".$file_id_list.'.html');
        $this->logger->debug('ODRGraphPlugin:: ' . $page_data['odr_chart_id']);
        $this->logger->debug('ODRGraphPlugin:: ' . $output_tmp_svg);
        $this->logger->debug('ODRGraphPlugin:: ' . __DIR__);

        // JSON data to be passed to the phantom js server
        $json_data = array(
            'input_html' => "Chart__".$file_id_list.'.html',
            'output_svg' => $output_tmp_svg,
            'selector' => $page_data['odr_chart_id']
        );
        $this->logger->debug('ODRGraphPlugin:: JSON Encode');
        $payload = json_encode($json_data);
        $this->logger->debug('ODRGraphPlugin:: Get Pheanstalk');
        $this->logger->debug('ODRGraphPlugin:: Pheanstalk Put');
        $this->pheanstalk->useTube('create_graph_preview')->put($payload, 1, 0); // , $priority, $delay);

        $this->logger->debug('ODRGraphPlugin:: Start waiting.');
        // Wait for JobID for 2 seconds
        $wait_time = 0;
        for($i = 0; $i < 50; $i++) {
            usleep(40000);
            if ( file_exists($output_tmp_svg) ) {
                // go on to processing if file exists.
                $wait_time = $i;
                break;
            }
        }
        $this->logger->debug('ODRGraphPlugin:: Done waiting: ' . $wait_time * 40 . "ms");

        // Parse output to fix CamelCase in SVG element
        if ( file_exists($output_tmp_svg) ) {
            $this->logger->debug('ODRGraphPlugin:: Output File Exists');
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
            $this->logger->debug('ODRGraphPlugin:: Output File Does Not Exist');
            if ( strlen($output_svg) > 40 ) {
                $output_svg = "..." . substr($output_svg,(strlen($output_svg) - 40), strlen($output_svg));
            }

            throw new \Exception('The file "'. $output_svg .'" does not exist');
        }
    }


    /**
     * Locates and deletes all static graphs that are built from a given file.
     *
     * @param int $file_id If zero, then delete all graphs created for this datafield.
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
}
