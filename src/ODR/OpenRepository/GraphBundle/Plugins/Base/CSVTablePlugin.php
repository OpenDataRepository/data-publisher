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
     * @param array|null $datarecord
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // The CSVTable Plugin should work in the 'display' context
            if ( $context === 'display' )
                return true;

            // The CSVTable Plugin can't work in the 'text' context, since it's based off a file field
        }

        return false;
    }


    /**
     * Executes the CSVTable Plugin on the provided datafield
     *
     * @param array $datafield
     * @param array|null $datarecord
     * @param array $render_plugin_instance
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $rendering_options)
    {
        try {
            // Need this to determine whether to throw an error or not
            $is_datatype_admin = $rendering_options['is_datatype_admin'];

            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Get all the options of the plugin...
            $use_first_row_as_header = true;
            if ( isset($options['use_first_row_as_header']) && $options['use_first_row_as_header'] === 'no' )
                $use_first_row_as_header = false;

            // If the file doesn't actually have a header row, then we're going to use letters instead
            $column_letters = range('A', 'Z');


            // ----------------------------------------
            // The method of data extraction depends on the type of field...
            $is_file = false;
            if ( $datafield['dataFieldMeta']['fieldType']['typeClass'] === 'File' )
                $is_file = true;

            // Extract the data from the field
            $ret = array();
            if ( $is_file )
                $ret = self::readFile($datarecord, $datafield);
            else
                $ret = self::readXYZData($datarecord, $datafield);

            // If either function couldn't finish, don't execute the plugin
            if ( is_null($ret) )
                return '';


            // ----------------------------------------
            // Convert the contents of the file/field into a format that Handsontable can use
            $data_array = $ret['data'];
            $num_columns = $ret['num_columns'];

            $column_names = array();
            foreach ($data_array as $row_num => $row) {
                foreach ($row as $col_num => $col) {
                    if ( $use_first_row_as_header )
                        $column_names[] = $col;
                    else
                        $column_names[] = $column_letters[$col_num];
                }
                break;
            }


            // ----------------------------------------
            $output = '';
            if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:CSVTable/csvtable_display_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'is_datatype_admin' => $is_datatype_admin,

                        'use_first_row_as_header' => $use_first_row_as_header,
                        'column_names' => $column_names,
                        'data_array' => $data_array,
                        'num_columns' => $num_columns,
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


    /**
     * Converts a file's contents into an array of lines.
     *
     * @param array $datarecord
     * @param array $datafield
     *
     * @return array|null
     */
    private function readFile($datarecord, $datafield)
    {
        $file_data = array();
        $num_columns = 0;

        $datafield_id = $datafield['id'];

        if ( isset($datarecord['dataRecordFields'][$datafield_id]['file']['0']) ) {
            $files_to_delete = array();

            // Check that the file exists...
            $file = $datarecord['dataRecordFields'][$datafield_id]['file']['0'];
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


            // Load file and parse into array
            ini_set('auto_detect_line_endings', true);
            $handle = fopen($local_filepath, "r");
            if ( !$handle )
                throw new \Exception('Could not open "'.$local_filepath.'"');

            $ret = self::guessFileProperties($handle);
            $delimiter = $ret['delimiter'];
            $num_columns = $ret['num_columns'];

            if ( is_null($delimiter) || is_null($num_columns) ) {
                // Some sort of error...abort the plugin execution so it doesn't throw an error
                fclose($handle);
                // Delete any non-public files that got decrypted
                foreach ($files_to_delete as $file_path)
                    unlink($file_path);

                return null;
            }

            // Re-read the file with the determined delimiter
            fseek($handle, 0, SEEK_SET);
            $csv_data = fgetcsv($handle, 1024, $delimiter);
            while ( $csv_data !== false ) {
                $file_data[] = $csv_data;
                $csv_data = fgetcsv($handle, 1024, $delimiter);
            }


            // ----------------------------------------
            // Done reading the file
            fclose($handle);

            // Delete any non-public files that got decrypted
            foreach ($files_to_delete as $file_path)
                unlink($file_path);
        }

        return array(
            'data' => $file_data,
            'num_columns' => $num_columns
        );
    }


    /**
     * Attempts to guess the delimiter and the number of columns from the first several lines of the
     * given file.
     *
     * @param resource $handle
     * @return array
     */
    private function guessFileProperties($handle)
    {
        // Since these are (hopefully) scientific data files, the set of valid delimiters is (hopefully)
        //  pretty small
        $valid_delimiters = array(
            'tab' => "\t",
            'comma' => ",",
            'semicolon' => ";",
        );
        // NOTE: do not put the space character in there

        $ret = array(
            'delimiter' => null,
            'num_columns' => null,
        );

        // Read the first couple non-comment lines in the file...
        $lines = array();
        for ($i = 0; $i < 5; ) {
            $line = trim(fgets($handle));
            if ( !feof($handle) && $line !== '' && strpos($line, '#') === 0 ) {
                // empty line or comment line, skip over if possible
            }
            else if ( $line !== '' ) {
                // should be a line of data...store for further parsing
                $lines[] = $line;
                $i++;
            }

            if ( feof($handle) )
                break;
        }

        // If the file was blank or entirely composed of comments, then there's nothing to check
        if ( empty($lines) )
            return $ret;

        // Otherwise, try to parse each line with a different delimiter
        $delimiters_by_line = array();
        foreach ($lines as $line_num => $line) {
            $delimiters_by_line[$line_num] = array();
            foreach ($valid_delimiters as $label => $delim) {
                // Store how many columns str_getcsv() returned
                $delimiters_by_line[$line_num][$label] = count( str_getcsv($line, $delim) );
            }
        }

        // Filter out delimiters that don't occur the same number of times on each line
        $delimiter_count = array();
        foreach ($delimiters_by_line as $line_num => $data) {
            foreach ($data as $label => $occurrences) {
                if ( !isset($delimiter_count[$label]) ) {
                    // Theoretically this will be the header line...
                    $delimiter_count[$label] = $occurrences;
                }
                else if ( $occurrences !== $delimiter_count[$label] ) {
                    // ...if any line does not match the previous line's number of columns with
                    //  the given delimiter, then it's probably not safe to call this a delimiter
                    $delimiter_count[$label] = -1;
                }
            }
        }

        // Guess which of the remaining delimiters is most likely for the file
        $delimiter_guess = null;
        $num_columns_guess = null;
        foreach ($valid_delimiters as $label => $delimiter) {
            if ( isset($delimiter_count[$label]) && $delimiter_count[$label] > 1 ) {
                if ( is_null($delimiter_guess) ) {
                    // Ideally, the first delimiter found will be the only one...
                    $delimiter_guess = $delimiter;
                    $num_columns_guess = $delimiter_count[$label];
                }
                else {
                    // ...but if a second delimiter could be valid, then try to use the delimiter
                    //  that appears more often in the file
                    if ( $delimiter_count[$label] > $delimiter_guess ) {
                        $delimiter_guess = $delimiter;
                        $num_columns_guess = $delimiter_count[$label];
                    }
                }

                // I think the "earlier" delimiters in valid_delimiters will be preferred over "later"
                //  delimiters in the case of a tie...TODO
            }
        }

        $ret['delimiter'] = $delimiter_guess;
        $ret['num_columns'] = $num_columns_guess;
        return $ret;
    }


    /**
     * Converts an XYZData field's contents into an array of lines.
     *
     * @param array $datarecord
     * @param array $datafield
     *
     * @return array|null
     */
    private function readXYZData($datarecord, $datafield)
    {
        // Need to locate the names for the columns...
        $xyz_data_column_names = explode(',', $datafield['dataFieldMeta']['xyz_data_column_names']);
        $num_columns = count($xyz_data_column_names);

        // ...and splice them into the array of data
        $xyz_data = array();
        $xyz_data[] = $xyz_data_column_names;

        $datafield_id = $datafield['id'];
        if ( isset($datarecord['dataRecordFields'][$datafield_id]['xyzData']) ) {
            foreach ($datarecord['dataRecordFields'][$datafield_id]['xyzData'] as $num => $datum) {
                // Only output the pieces of data that match the number of columns
                $line = array(0 => $datum['x_value']);
                if ( $num_columns > 1 )
                    $line[1] = $datum['y_value'];
                if ( $num_columns > 2 )
                    $line[2] = $datum['z_value'];

                $xyz_data[] = $line;
            }
        }

        return array(
            'data' => $xyz_data,
            'num_columns' => $num_columns
        );
    }
}
