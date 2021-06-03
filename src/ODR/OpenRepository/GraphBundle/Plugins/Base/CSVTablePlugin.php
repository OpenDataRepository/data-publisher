<?php

/**
 * Open Data Repository Data Publisher
 * CSVTable Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The CSVTable Plugin reads a single file uploaded into a file datafield, and uses a javascript
 * library to render a nice table view of the contents of that file.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// ODR
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
// Symfony
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class CSVTablePlugin implements DatafieldPluginInterface
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
     * CSVTablePlugin constructor.
     *
     * @param EngineInterface $templating
     * @param CryptoService $crypto_service
     * @param $odr_web_directory
     */
    public function __construct(EngineInterface $templating, CryptoService $crypto_service, $odr_web_directory)
    {
        $this->templating = $templating;
        $this->crypto_service = $crypto_service;
        $this->odr_web_directory = $odr_web_directory;
    }


    /**
     * Executes the CSVTable Plugin on the provided datafield
     *
     * @param array $datafield
     * @param array $datarecord
     * @param array $render_plugin_instance
     * @param string $themeType     One of 'master', 'search_results', 'table', TODO?
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $themeType = 'master')
    {
        try {
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
                'ODROpenRepositoryGraphBundle:Base:CSVTable/csv_table.html.twig',
                array(
                    'datafield' => $datafield,
                    'datarecord' => $datarecord,
                    'data_array' => $data_array,
                )
            );

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
