<?php

/**
 * Open Data Repository Data Publisher
 * Graph Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The CSVTable Plugin reads a single file uploaded into a file datafield, and uses a javascript library
 * to render a nice table view of the contents of that file.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Bridge\Monolog\Logger;


class CSVTablePlugin
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
     * GraphPlugin constructor.
     *
     * @param $templating
     * @param $logger
     */
    public function __construct(EngineInterface $templating, Logger $logger) {
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Executes the CSVTable Plugin on the provided datafield
     *
     * @param array $datafield
     * @param array $datarecord
     * @param array $render_plugin
     * @param string $themeType     One of 'master', 'search_results', 'table', TODO?
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin, $themeType = 'master')
    {

        try {
            // ----------------------------------------
//            $str = '<pre>'.print_r($datafield, true)."\n".print_r($datarecord, true)."\n".print_r($render_plugin, true)."\n".'</pre>';
//            return $str;

            // Grab various properties from the render plugin array
            $render_plugin_options = $render_plugin['renderPluginInstance'][0]['renderPluginOptions'];

            // Remap render plugin by name => value
            $options = array();
            foreach($render_plugin_options as $option) {
                if ( $option['active'] == 1 )
                    $options[ $option['optionName'] ] = $option['optionValue'];
            }


            // ----------------------------------------
            // Only execute the plugin if a file has been uploaded to this datafield
            $data_array = array();
            if ( isset($datarecord['dataRecordFields'][$datafield['id']]['file']['0']) ) {

                // Check that the file exists...
                $file = $datarecord['dataRecordFields'][$datafield['id']]['file']['0'];
                $local_filepath = realpath(dirname(__FILE__).'/../../../../../web/'.$file['localFileName']);
                if (!$local_filepath) {
                    // File does not exist for some reason...probably due to being non-public TODO - FIX THIS
                    throw new \Exception('Unable to open file');
                }

                // TODO - test whether this'll work on stupid csv files like CSVImport has to deal with
                // Load file and parse into array
                $data_array = array();
                if (($handle = fopen($local_filepath, "r")) !== FALSE) {
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        array_push($data_array, $data);
                    }
                    fclose($handle);
                }

                $data_array = json_encode($data_array);
            }

            // ----------------------------------------
            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:CSVTable:csv_table.html.twig',
                array(
                    'datafield' => $datafield,
                    'datarecord' => $datarecord,
                    'data_array' => $data_array,
                )
            );

            return $output;
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

}
