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


// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Bridge\Monolog\Logger;
// Other
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
    private $site_baseurl;

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
     * ODRGraph Plugin constructor.
     *
     * @param EngineInterface $templating
     * @param Pheanstalk $pheanstalk
     * @param string $site_baseurl
     * @param string $odr_web_directory
     * @param string $odr_files_directory
     * @param Logger $logger
     */
    public function __construct(
        EngineInterface $templating,
        Pheanstalk $pheanstalk,
        string $site_baseurl,
        string $odr_web_directory,
        string $odr_files_directory,
        Logger $logger
    ) {
        $this->templating = $templating;
        $this->pheanstalk = $pheanstalk;
        $this->site_baseurl = $site_baseurl;
        $this->odr_web_directory = $odr_web_directory;
        $this->odr_files_directory = $odr_files_directory;
        $this->logger = $logger;
    }


    /**
     * Gets Puppeteer to build a static graph, and moves the resulting SVG into the proper directory.
     *
     * @param array $page_data An array of data about the graph to be created
     * @param string $graph_filepath The absolute path where the finalized svg file should be saved
     * @param string $builder_filepath A partial filepath to the html file read by puppeteer
     * @param array $files_to_delete The list of non-public files puppeteer needs to delete off the
     *                               server when it's done building the graph
     *
     * @throws \Exception
     */
    protected function buildGraph($page_data, $graph_filepath, $builder_filepath, $files_to_delete)
    {
        // Going to use Symfony to write files...
        $fs = new \Symfony\Component\Filesystem\Filesystem();

        // The HTML file that generates the svg graph needs to be created so puppeteer can use it
        $builder_html = $this->templating->render(
            $page_data['template_name'], $page_data
        );
        $fs->dumpFile($this->odr_web_directory.$builder_filepath, $builder_html);
        // Note that this will save graphs with data from non-public files in the web-accessible space
        //  ...but this is unavoidable since puppeteer needs to access them over https

        // Create the JSON data to be passed to the puppeteer server...
        $json_data = array(
            'site_baseurl' => $this->site_baseurl,
            'odr_web_dir' => $this->odr_web_directory,
//            'odr_files_dir' => $this->odr_files_directory,

            // This one doesn't have a full filepath, because puppeteer needs to load it via https
            'builder_filepath' => $builder_filepath,
            // These two already use absolute paths
            'graph_filepath' => $graph_filepath,
            'files_to_delete' => $files_to_delete,

            'selector' => $page_data['odr_chart_id'],
        );
        $payload = json_encode($json_data);

        // ...and send it off
        $this->pheanstalk->useTube('create_graph_preview')->put($payload, 1, 0); // , $priority, $delay);
    }


    /**
     * Locates and deletes all static graphs for the given datatype that have been built from the
     * given datafield.
     *
     * @param int $datatype_id
     * @param int $datafield_id
     */
    protected function deleteCachedGraphs($datatype_id, $datafield_id)
    {
        // All the graph files have the primary datafield id immediately before the extension
        $filename_fragment = '_'.$datafield_id.'.svg';

        // Graphs are organized into subdirectories by datatype id
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
