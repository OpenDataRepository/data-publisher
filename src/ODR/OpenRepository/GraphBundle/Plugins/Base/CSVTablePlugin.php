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

// Services
use ODR\AdminBundle\Component\Service\CryptoService;
// ODR
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
     * @param string $odr_web_directory
     */
    public function __construct(
        EngineInterface $templating,
        CryptoService $crypto_service,
        $odr_web_directory
    ) {
        $this->templating = $templating;
        $this->crypto_service = $crypto_service;
        $this->odr_web_directory = $odr_web_directory;
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datafield
     * @param array $datarecord
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        // The CSVTable Plugin can't work in the 'text' context, since it's based off a file field
        // The CSVTable Plugin should work in the 'display' context
        if ( $rendering_options['context'] === 'display' )
            return true;

        return false;
    }


    /**
     * Executes the CSVTable Plugin on the provided datafield
     *
     * @param array $datafield
     * @param array $datarecord
     * @param array $render_plugin_instance
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $rendering_options)
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
                    // File does not exist, decryption depends on whether the file is
                    //  public or not...
                    $public_date = $file['fileMeta']['publicDate'];
                    $now = new \DateTime();
                    if ($now < $public_date) {
                        // File is not public...decrypt to something hard to guess
                        $non_public_filename = md5($file['original_checksum'].'_'.$file['id'].'_'.random_int(2500,10000)).'.'.$file['ext'];
                        $local_filepath = $this->crypto_service->decryptFile($file['id'], $non_public_filename);

                        // Ensure the decrypted version gets deleted later
                        array_push($files_to_delete, $local_filepath);
                    }
                    else {
                        // File is public, but not decrypted for some reason
                        $local_filepath = $this->crypto_service->decryptFile($file['id']);
                    }
                }

                // Only allow this action for files smaller than 5Mb?
                $filesize = $file['filesize'] / 1024 / 1024;
                if ($filesize > 5)
                    throw new \Exception('Currently not permitted to execute on files larger than 5Mb');


                // TODO - test whether this'll work on stupid csv files like CSVImport has to deal with
                // Load file and parse into array
                $data_array = array();

                $handle = fopen($local_filepath, "r");
                if ( !$handle )
                    throw new \Exception('Could not open "'.$local_filepath.'"');

                // TODO - modify so the plugin can effectively "auto-detect" separators like web/js/mylibs/odr_plotly_graphs.js does?
                // TODO - ...the afformentioned js file uses regex to break lines into "words", instead of a pre-defined separator
                $separator = ",";

                $data = fgetcsv($handle, 1000, $separator);
                while ( $data !== false ) {
                    array_push($data_array, $data);
                    $data = fgetcsv($handle, 1000, $separator);
                }


                // ----------------------------------------
                // Done reading the file
                fclose($handle);

                // Delete any non-public files that got decrypted
                foreach ($files_to_delete as $file_path)
                    unlink($file_path);

                $data_array = json_encode($data_array);
            }


            // ----------------------------------------
            $output = '';
            if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:CSVTable/csvtable_display_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'data_array' => $data_array,
                    )
                );
            }

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
