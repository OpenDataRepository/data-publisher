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


// Services
use ODR\AdminBundle\Component\Service\CryptoService;
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
     * @var CryptoService
     */
    private $crypto_service;

    /**
     * @var string
     */
    private $odr_web_directory;


    /**
     * CSVTablePlugin constructor.
     *
     * @param EngineInterface $templating
     * @param Logger $logger
     * @param CryptoService $crypto_service
     * @param $odr_web_directory
     */
    public function __construct(EngineInterface $templating, Logger $logger, CryptoService $crypto_service, $odr_web_directory)
    {
        $this->templating = $templating;
        $this->logger = $logger;
        $this->crypto_service = $crypto_service;
        $this->odr_web_directory = $odr_web_directory;
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
                $files_to_delete = array();

                // Check that the file exists...
                $file = $datarecord['dataRecordFields'][$datafield['id']]['file']['0'];
                $local_filepath = realpath( $this->odr_web_directory.'/'.$file['localFileName']);
                if (!$local_filepath) {
                    // File does not exist, decrypt it
                    $local_filepath = $this->crypto_service->decryptFile($file['id']);

                    // If file is not public, make sure it gets deleted later
                    $public_date = $file['fileMeta']['publicDate'];
                    $now = new \DateTime();
                    if ($now < $public_date)
                        array_push($files_to_delete, $local_filepath);
                }

                // Only allow this action for files smaller than 5Mb?
                $filesize = $file['filesize'] / 1024 / 1024;
                if ($filesize > 5)
                    throw new \Exception('Currently not permitted to execute on files larger than 5Mb');


                // TODO - test whether this'll work on stupid csv files like CSVImport has to deal with
                // Load file and parse into array
                $data_array = array();
                if (($handle = fopen($local_filepath, "r")) !== FALSE) {
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        array_push($data_array, $data);
                    }
                    fclose($handle);
                }


                // Delete previously encrypted non-public files
                foreach ($files_to_delete as $file_path)
                    unlink($file_path);

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
