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

            // Convert the contents of the file/field into a format that Handsontable can use
            $data_array = json_encode( $ret['data'] );
            $num_columns = $ret['num_columns'];


            // ----------------------------------------
            $output = '';
            if ( $rendering_options['context'] === 'display' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:CSVTable/csvtable_display_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'is_datatype_admin' => $is_datatype_admin,

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
            $handle = fopen($local_filepath, "r");
            if ( !$handle )
                throw new \Exception('Could not open "'.$local_filepath.'"');

            $content = file_get_contents($local_filepath);
            $content = str_replace("\r", '', $content);
            $all_lines = explode("\n", $content);    // TODO - this won't work on csv files with newlines inside doublequotes...

            $ret = self::guessFileProperties($all_lines);
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

            $csv_data = fgetcsv($handle, 1000, $delimiter);
            while ( $csv_data !== false ) {
                $file_data[] = $csv_data;
                $csv_data = fgetcsv($handle, 1000, $delimiter);
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
     * There is a javascript version of this logic in /web/js/mylibs/odr_plotly_graphs.js...changes
     * or fixes made here should also be made there.
     *
     * @param string[] $lines
     * @return array
     */
    private function guessFileProperties($lines)
    {
        // Since these are (hopefully) scientific data files, the set of valid delimiters is (hopefully)
        //  pretty small
        $valid_delimiters = array(
            "\t" => 0,
            "," => 0,
            ";" => 0,
        );
        // NOTE: do not put the space character in there...if the file is using the space character as
        //  a delimiter, then it's safer for the graph code to split the line apart into "words" instead
        //  of splitting by a specific character sequence

        // Read the first couple non-comment lines in the file...
        $max_line_count = 10;
        $current_line = 0;
        $delimiters_by_line = array();
        foreach ($lines as $line) {
            if ( strpos($line, '#') === 0 ) {
                // Ignore comment lines
                continue;
            }
            else {
                $delimiters_by_line[$current_line] = array();

                // ...and count how many of each character is encountered
                for ($j = 0; $j < strlen($line); $j++) {
                    $char = $line[$j];

                    // If the line contains a valid delimiter, then store how many times it occurs
                    if ( isset($valid_delimiters[$char]) ) {
                        if ( !isset($delimiters_by_line[$current_line][$char]) )
                            $delimiters_by_line[$current_line][$char] = 0;
                        $delimiters_by_line[$current_line][$char]++;
                    }
                    else if ( $char === "\"" || $char === "\'" ) {
                        // If the line contained a singlequote or a doublequote, then ignore it completely
                        unset( $delimiters_by_line[$current_line] );
                        break;
                    }
                }

                $current_line++;
                if ( $current_line >= $max_line_count )
                    break;
            }
        }

        // Filter out delimiters that don't occur the same number of times on each line
        $delimiter_count = array();
        foreach ($valid_delimiters as $delimiter => $num) {
            $delimiter_count[$delimiter] = -1;

            foreach ($delimiters_by_line as $line_num => $occurrences) {
                if ( isset($occurrences[$delimiter]) ) {
                    if ( $delimiter_count[$delimiter] === -1 ) {
                        // Store how many times this delimiter occurs on the first valid line of data
                        //  in the file
                        $delimiter_count[$delimiter] = $occurrences[$delimiter];
                    }
                    else if ( $delimiter_count[$delimiter] !== $occurrences[$delimiter] ) {
                        // This line has a different number of this delimiter than the earlier lines in
                        //  the file...it's probably not safe to call this a delimiter
                        $delimiter_count[$delimiter] = -1;
                        break;
                    }
                }
            }
        }

        // To counter the (hopefully) rare case where more than one "valid" delimiter character exists
        //  in the file, count how many of the lines have each delimiter
        $lines_with_delimiters = array();
        foreach ($valid_delimiters as $delimiter => $num) {
            // Ignore delimiters that the previous step believes aren't safe
            if ( $delimiter_count[$delimiter] === -1 )
                continue;

            $lines_with_delimiters[$delimiter] = 0;
            foreach ($delimiters_by_line as $line_num => $occurrences) {
                if ( isset($occurrences[$delimiter]) )
                    $lines_with_delimiters[$delimiter] += 1;
            }
        }

        // Guess which of the remaining delimiters is most likely for the file
        $delimiter_guess = null;
        $columns_guess = null;
        foreach ($valid_delimiters as $delimiter => $num) {

            if ( isset($delimiter_count[$delimiter]) && $delimiter_count[$delimiter] !== -1 ) {
                if ( is_null($delimiter_guess) ) {
                    // Ideally, the first delimiter found will be the only one...
                    $delimiter_guess = $delimiter;
                    $columns_guess = $delimiter_count[$delimiter] + 1;
                }
                else {
                    // ...but if a second delimiter could be valid...
                    if ( isset($lines_with_delimiters[$delimiter_guess]) ) {
                        $previous_guess_count = $lines_with_delimiters[$delimiter_guess];
                        $current_guess_count = $lines_with_delimiters[$delimiter];
                        // ...then try to use the delimiter that appears more often in the file
                        if ( $current_guess_count > $previous_guess_count ) {
                            $delimiter_guess = $delimiter;
                            $columns_guess = $delimiter_count[$delimiter] + 1;
                        }
                    }
                }

                // I think the "earlier" delimiters in valid_delimiters will be preferred over "later"
                //  delimiters in the case of a tie...TODO
            }
        }

        $ret = array(
            'delimiter' => $delimiter_guess,
            'num_columns' => $columns_guess,
        );
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
