<?php

/**
 * Open Data Repository Data Publisher
 * CSVImport Helper Service Test
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * One of the tasks performed by the CSVImport validation process is to ensure that importing the
 * data from some arbitary csv file won't end up violating uniqueness constraints of the database
 * that's getting imported into.
 *
 * The only accurate method to accomplish this seems to be to determine the "end state" of the
 * database prior to actually running the import process...this is rather tricky to get correct due
 * to the wide range of possible ODR databases, csv files, and user decisions...
 */

namespace ODR\AdminBundle\Tests\Component\Service;

// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\CSVImportHelperService;
// Symfony
use ODR\AdminBundle\Component\Utility\ODRCsvReader as CsvReader;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;


class CSVImportHelperServiceTest extends WebTestCase
{

    /**
     * Ensures we clean up the error handler while shutdown.
     */
    #[After]
    public function __internalDisableErrorHandler(): void
    {
        restore_exception_handler();
    }


    #[DataProvider('provideUniquenessCheckParams')]
    public function testFindUniquenessErrors($post_data, $csv_filename, $import_type, $expected_error_count)
    {
        $errors = self::runUniquenessChecks($post_data, $csv_filename, $import_type);

//        if ( $expected_error_count !== count($errors) )
//            throw new ODRException(print_r($errors, true));

        $this->assertEquals( $expected_error_count, count($errors) );
    }


    /**
     * @param array $post
     * @param string $csv_filename filename of the csv file to use for testing purposes
     * @param string $import_type
     *
     * @return array
     */
    private function runUniquenessChecks($post, $csv_filename, $import_type)
    {
        exec('redis-cli flushall');
        $client = static::createClient();
        if ( $client->getContainer()->getParameter('database_name') !== $_ENV['PHPUNIT_TESTING_DB'] )
            $this->markTestSkipped('Wrong database');

        /** @var CSVImportHelperService $csv_helper_service */
        $csv_helper_service = $client->getContainer()->get('odr.csv_import_helper_service');


        // ----------------------------------------
        // Load the csv file
//        ini_set('auto_detect_line_endings', TRUE);

        $csv_filepath = $client->getContainer()->getParameter('odr_tmp_directory');
        $csv_filepath .= '/../../src/ODR/AdminBundle/TestResources/'.$csv_filename;
        $csv_filepath = realpath($csv_filepath);

        if ( !file_exists($csv_filepath) )
            throw new ODRException('Target CSV File at "'.$csv_filepath.'" does not exist');

        $csv_file = new \SplFileObject($csv_filepath);
        $reader = new CsvReader($csv_file, "\t");

        $reader->setHeaderRowNumber(0);     // want associative array for the column names
        $file_headers = $reader->getColumnHeaders();

        // Reload the CsvReader object so that columns are identified by number instead of by name
        unset( $reader );
        $reader = new CsvReader($csv_file, "\t");


        // ----------------------------------------
        // IMPORTANT: this is from CSVImportController::startvalidateAction(), shortly before the tracked job stuff

        // Convert the post data into a form that's more useful for verifying uniquness
        $uniqueness_check_data = $csv_helper_service->getUniquenessCheckData($post, $file_headers, $import_type);

        $errors = [];

        // If there's at least one column in the CSV file that's going to a datafield marked
        //  as unique...
        if ( !empty($uniqueness_check_data['unique_mapping']) ) {
            // Load all values from the existing unique datafields that are going to be affected
            $values = $csv_helper_service->getExistingUniqueValues($uniqueness_check_data);

            // Read through the CSV file and apply any changes it'll make to the existing values
            // This will also create entries for the new datafields that will be created by the
            //  CSV Import process
            $errors = $csv_helper_service->getFutureUniqueValues($uniqueness_check_data, $reader, $values);

            // Locate and complain of every instance where uniqueness constraints are going to
            //  be violated if this CSV file gets imported
            $uniqueness_errors = $csv_helper_service->findUniquenessErrors($uniqueness_check_data, $values);
            $errors = array_merge($errors, $uniqueness_errors);
        }

        // Also need to check for whether the import is going to create/link multiple descendant
        //  datarecords in a child/linked type that only allows a single record
        $datarecord_number_errors = $csv_helper_service->findDatarecordNumberErrors($uniqueness_check_data, $reader);
        if ( !empty($datarecord_number_errors) )
            $errors = array_merge($errors, $datarecord_number_errors);

        // Done reading the csv file
        unset( $csv_file );

        // Return any errors encountered
        return $errors;
    }


    #[DataProvider('provideValidateLineParams')]
    public function testFindValidateLineErrors($post_data, $expected_error_count)
    {
        exec('redis-cli flushall');
        $client = static::createClient();
        if ( $client->getContainer()->getParameter('database_name') !== $_ENV['PHPUNIT_TESTING_DB'] )
            $this->markTestSkipped('Wrong database');

        /** @var CSVImportHelperService $csv_helper_service */
        $csv_helper_service = $client->getContainer()->get('odr.csv_import_helper_service');

        $default_params = [
            'import_type' => '',

            'tracked_job_id' => -1,
            'user_id' => 2,
            'column_names' => [],
            'line_num' => 0,
            'line' => [],

//            'api_key' => $data->api_key,

            // Only used when importing into a top-level or child datatype
            'unique_columns' => [],
            'datafield_mapping' => [],
            'fieldtype_mapping' => [],
            'column_delimiters' => [],
            'hierarchy_delimiters' => [],
            'synch_columns' => [],

            'grandparent_datatype_id' => '',
            'parent_datatype_id' => '',
            'target_datatype_id' => '',

            'grandparent_external_id_column' => '',
            'parent_external_id_column' => '',
            'target_external_id_column' => '',
        ];

        // Update the defaults with the test parameters
        foreach ($post_data as $key => $value)
            $default_params[$key] = $value;

        // Don't need to test anything related to this variable
        $new_tags = [1 =>[]];
        $validation_params = $client->getContainer()->getParameter('file_validation');
        $errors = $csv_helper_service->validateImportFileLine($default_params, $new_tags, $validation_params);

        $this->assertEquals( $expected_error_count, count($errors) );
    }


    /**
     * @return array
     */
    public static function provideUniquenessCheckParams()
    {
        return [
            // ----------------------------------------
            // Testing imports into top-level datatypes

            // Import into RRUFF Sample...not trying to cause a problem
            'import into good database with good csv file' => [
                [
                    'target_datatype_id' => "3",
                    'datafield_mapping' => [
                        0 => "29",  // sample_id
                        1 => "30",  // rruff_id
                        2 => "34",  // owner
                    ],
                    'unique_columns' => [
                        0 => "1",
                        1 => "1",
                    ],
                ],
                'csv_import_test_1.csv',
                'top-level',
                0
            ],

            // Import into RRUFF Sample...csv file has duplicate value in external_id column
            'duplicate value in csv file in external id column' => [
                [
                    'target_datatype_id' => "3",
                    'datafield_mapping' => [
                        0 => "29",  // sample_id
                        1 => "30",  // rruff_id
                        2 => "34",  // owner
                    ],
                    'unique_columns' => [
                        0 => "1",
                        1 => "1",
                    ],
                ],
                'csv_import_test_2.csv',
                'top-level',
                1
            ],
            // Import into RRUFF Sample...csv creates duplicate value in rruff_id column
            'import creates duplicate in unique column' => [
                [
                    'target_datatype_id' => "3",
                    'datafield_mapping' => [
                        0 => "29",  // sample_id
                        1 => "30",  // rruff_id
                    ],
                    'unique_columns' => [
                        0 => "1",
                        1 => "1",
                    ],
                ],
                'csv_import_test_3.csv',
                'top-level',
                1
            ],

            // Import without external id into RRUFF Sample...file happens to not create duplicates in rruff_id
            'import without external id, no duplicates created in unique field by luck' => [
                [
                    'target_datatype_id' => "3",
                    'datafield_mapping' => [
                        1 => "30",  // rruff_id
                    ],
                    'unique_columns' => [
                        1 => "1",
                    ],
                ],
                'csv_import_test_4.csv',
                'top-level',
                0
            ],
            // Import without external id into RRUFF Sample...file creates multiple duplicates in rruff_id column
            'import without external id, creates multiple duplicates in unique field' => [
                [
                    'target_datatype_id' => "3",
                    'datafield_mapping' => [
                        1 => "30",  // rruff_id
                    ],
                    'unique_columns' => [
                        1 => "1",
                    ],
                ],
                'csv_import_test_3.csv',
                'top-level',
                3
            ],

            // Import into IMA without external id...file is fine, but should find existing duplicates in locality_country
            'non-unique field marked as unique for import...has duplicates already' => [
                [
                    'target_datatype_id' => "2",
                    'datafield_mapping' => [
                        3 => "17",  // mineral_name
                        4 => "24",  // locality_country
                    ],
                    'unique_columns' => [
                        4 => "1",
                    ],
                ],
                'csv_import_test_4.csv',
                'top-level',
                1
            ],
            // Import into IMA without external id...existing duplicates in locality_country field, more created by csv file
            'non-unique field marked as unique for import...has duplicates already, import creates more' => [
                [
                    'target_datatype_id' => "2",
                    'datafield_mapping' => [
                        3 => "17",  // mineral_name
                        4 => "24",  // locality_country
                    ],
                    'unique_columns' => [
                        4 => "1",
                    ],
                ],
                'csv_import_test_1.csv',
                'top-level',
                4    // one for existing duplicate, one for "USA", two for "Sweden"
            ],
            // Import into IMA without external id...file is fine, should end up with no duplicates in mineral_name
            'non-unique field marked as unique for import...no existing duplicates' => [
                [
                    'target_datatype_id' => "2",
                    'datafield_mapping' => [
                        3 => "17",  // mineral_name
                    ],
                    'unique_columns' => [
                        3 => "1",
                    ],
                ],
                'csv_import_test_4.csv',
                'top-level',
                0
            ],
            // Import into IMA without external id...should end up with multiple duplicates in mineral_name
            'non-unique field marked as unique for import...no existing duplicates, import creates several' => [
                [
                    'target_datatype_id' => "2",
                    'datafield_mapping' => [
                        3 => "17",  // mineral_name
                    ],
                    'unique_columns' => [
                        3 => "1",
                    ],
                ],
                'csv_import_test_1.csv',
                'top-level',
                3
            ],


            // ----------------------------------------
            // Testing imports into child datatypes

            // Import into "Import Test - Sample"
            'import into good child database with good csv file' => [
                [
                    'target_datatype_id' => "6",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => [
                        0 => "1",
                        1 => "1",
                    ],
                    'datafield_mapping' => [
                        0 => "47",    // sample_id
                        1 => "48",    // rruff_id
                        2 => "49",    // owner
                    ],
                ],
                'csv_import_test_1.csv',
                'child',
                0
            ],

            // Import into "Import Test - Sample"
            'duplicate value in csv file in child external id column' => [
                [
                    'target_datatype_id' => "6",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => [
                        0 => "1",
                        1 => "1"
                    ],
                    'datafield_mapping' => [
                        0 => "47",    // sample_id
                        1 => "48",    // rruff_id
                    ],
                ],
                'csv_import_test_2.csv',
                'child',
                1
            ],
            // Import into "Import Test - Sample", set owner field to unique
            'non-unique field in child marked as unique for import...has duplicates already' => [
                [
                    'target_datatype_id' => "6",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => [
                        0 => "1",
                        2 => "1",
                    ],
                    'datafield_mapping' => [
                        0 => "47",    // sample_id
                        2 => "49",    // owner
                    ],
                ],
                'csv_import_test_1.csv',
                'child',
                1
            ],

            // Import into "Import Test - Sample"...despite reusing "R070007" under "TotallyNotAbelsonite",
            //  no uniqueness constraints are violated
            'import creates duplicates in unique field of child record, different parent' => [
                [
                    'target_datatype_id' => "6",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => [
                        0 => "1",
                        1 => "1"
                    ],
                    'datafield_mapping' => [
                        0 => "47",    // sample_id
                        1 => "48",    // rruff_id
                    ],
                ],
                'csv_import_test_5.csv',
                'child',
                0
            ],
            // Import into "Import Test - Sample", should get an error on rruff_id column due to
            //  sample_id 99999 attempting to reuse "R060687"
            'import creates duplicates in unique field of child record, same parent' => [
                [
                    'target_datatype_id' => "6",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => [
                        0 => "1",
                        1 => "1"
                    ],
                    'datafield_mapping' => [
                        0 => "47",    // sample_id
                        1 => "48",    // rruff_id
                    ],
                ],
                'csv_import_test_3.csv',
                'child',
                1
            ],


            // ----------------------------------------
            // Catching ways that imports can create multiple descendant records when only a single
            //  descendant is allowed

            // Import into "Import Test - Mineral ID"...this also tests updating/creating a single
            //  child record
            'import creates more than one child record in single-allowed childtype' => [
                [
                    'target_datatype_id' => "7",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => [
                        5 => "1",
                    ],
                    'datafield_mapping' => [
                        5 => "50",    // mineral_id
                    ],
                ],
                'csv_import_test_1.csv',
                'child',
                2    // one for a duplicate in the external id column, another because it would
                     //  create more than one child record
            ],
            // Import into "Import Test - locality_country"
            'csv file has duplicate external id for single-allowed childtype' => [
                [
                    'target_datatype_id' => "7",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => [
                        5 => "1",
                    ],
                    'datafield_mapping' => [
                        5 => "50",    // mineral_id
                    ],
                ],
                'csv_import_test_2.csv',
                'child',
                3    // two for duplicates in the child external id, and a third because it would
                     //  create a duplicate
            ],
            // Import into "Import Test - locality_country"
            'import overwrites child records in single-allowed childtype' => [
                [
                    'target_datatype_id' => "8",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'datafield_mapping' => [
                        4 => "51",    // locality_country
                    ],
                ],
                'csv_import_test_2.csv',
                'child',
                3    // one warning for overwriting an existing child record, and two more for
                     //  overwriting child records that the import will create
            ],


            // ----------------------------------------
            // Create links between "RRUFF Sample" and "IMA List"
            'import attempts to link to second remote record in single-allowed linked datatype' => [
                [
                    'target_datatype_id' => "2",
                    'parent_datatype_id' => "3",
                    'parent_external_id_column' => "0",
                    'target_external_id_column' => "5",
                ],
                'csv_import_test_5.csv',
                'link',
                1    // one error because sample 3082 already links to mineral 777
            ],

            // Create links between "RRUFF Sample" and "IMA List"...csv file doesn't have duplicates
            'csv file does not have duplicates in columns when creating links' => [
                [
                    'target_datatype_id' => "2",
                    'parent_datatype_id' => "3",
                    'parent_external_id_column' => "0",
                    'target_external_id_column' => "5",
                ],
                'csv_import_test_1.csv',
                'link',
                0
            ],
            // Create links between "RRUFF Sample" and "IMA List"...csv file does have duplicates,
            //  but the csv linker merely ensures the link exists more than once...so there are
            //  no errors to find here
            'csv file does has duplicates in columns when creating links' => [
                [
                    'target_datatype_id' => "2",
                    'parent_datatype_id' => "3",
                    'parent_external_id_column' => "0",
                    'target_external_id_column' => "5",
                ],
                'csv_import_test_2.csv',
                'link',
                0
            ],


            // ----------------------------------------
            // Don't need to test:
            //  - duplicate filenames in a file/image column of the csv file
            //  - ancestors that don't exist when importing into a child/linked datatype
            //  - remote datarecords not existing when importing into a linked datatype
        ];
    }


    /**
     * @return array
     */
    public static function provideValidateLineParams()
    {
        return [
            // ----------------------------------------
            // Test notifications about locating datarecords
            'missing grandparent warning' => [
                [
                    'import_type' => 'child-link',
                    'column_names' => ["grandparent_field", "parent_field", "reference_id"],
                    'line' => ["999", "2", "16997"],

                    'grandparent_datatype_id' => "22",
                    'parent_datatype_id' => "23",
                    'target_datatype_id' => "1",

                    'grandparent_external_id_column' => "0",
                    'parent_external_id_column' => "1",
                    'target_external_id_column' => "2",
                ],
                1  // only warn about missing grandparent...its lack of children is ignored
            ],

            'missing parent warning v1' => [
                [
                    'import_type' => 'child',
                    'column_names' => ["mineral_name", "sample_id"],
                    'line' => ["asdf", "3082"],
                    'datafield_mapping' => [
                        1 => "47",  // sample_id
                    ],
                    'unique_columns' => [
                        1 => "1",
                    ],
                    'parent_datatype_id' => "5",
                    'target_datatype_id' => "6",

                    'parent_external_id_column' => "0",
                ],
                1  // no parent means it'll get skipped
            ],
            'missing parent warning v2' => [
                [
                    'import_type' => 'link',
                    'column_names' => ["mineral_id", "reference_id"],
                    'line' => ["999", "18422"],

                    'parent_datatype_id' => "2",
                    'target_datatype_id' => "1",

                    'parent_external_id_column' => "0",
                    'target_external_id_column' => "1",
                ],
                1  // no parent means it'll get skipped
            ],
            'missing parent warning v3' => [
                [
                    'import_type' => 'child-link',
                    'column_names' => ["grandparent_field", "parent_field", "reference_id"],
                    'line' => ["1", "999", "16997"],

                    'grandparent_datatype_id' => "22",
                    'parent_datatype_id' => "23",
                    'target_datatype_id' => "1",

                    'grandparent_external_id_column' => "0",
                    'parent_external_id_column' => "1",
                    'target_external_id_column' => "2",
                ],
                1  // one warning for missing parent
            ],

            'missing link target v1' => [
                [
                    'import_type' => 'child-link',
                    'column_names' => ["grandparent_field", "parent_field", "reference_id"],
                    'line' => ["1", "2", "999999"],

                    'grandparent_datatype_id' => "22",
                    'parent_datatype_id' => "23",
                    'target_datatype_id' => "1",

                    'grandparent_external_id_column' => "0",
                    'parent_external_id_column' => "1",
                    'target_external_id_column' => "2",
                ],
                1  // one warning for missing reference
            ],
            'missing link target v2' => [
                [
                    'import_type' => 'link',
                    'column_names' => ["mineral_id", "reference_id"],
                    'line' => ["777", "999999"],

                    'parent_datatype_id' => "2",
                    'target_datatype_id' => "1",

                    'parent_external_id_column' => "0",
                    'target_external_id_column' => "1",
                ],
                1  // no reference with that id exists
            ],

            'missing top-level' => [
                [
                    'import_type' => 'top-level',
                    'column_names' => ["mineral_name"],
                    'line' => ["not in database"],
                    'datafield_mapping' => [
                        0 => "46",  // sample_id
                    ],
                    'unique_columns' => [
                        0 => "1",
                    ],
                    'target_datatype_id' => "5",
                ],
                0  // this doesn't generate a warning...CSVImport will just create the missing top-level record
            ],
            'missing child' => [
                [
                    'import_type' => 'child',
                    'column_names' => ["mineral_name", "sample_id"],
                    'line' => ["Abelsonite", "9999"],
                    'datafield_mapping' => [
                        1 => "47",  // sample_id
                    ],
                    'unique_columns' => [
                        1 => "1",
                    ],
                    'parent_datatype_id' => "5",
                    'target_datatype_id' => "6",

                    'parent_external_id_column' => "0",
                ],
                0  // this doesn't generate a warning...CSVImport will just create the missing child record
            ],


            // ----------------------------------------
            // Test notifications about field contents
            'line without validation warnings' => [
                [
                    'import_type' => 'top-level',
                    'column_names' => ["sample_id", "rruff_id", "owner", "mineral_name", "locality_country", "mineral_id"],
                    'line' => ["3082", "R070007", "RRUFF", "Abelsonite", "USA", "777"],
                    'datafield_mapping' => [
                        0 => "29",  // sample_id
                        1 => "30",  // rruff_id
                        2 => "34",  // owner
                    ],
                    'unique_columns' => [
                        0 => "1",
                        1 => "1",
                    ],
                    'target_datatype_id' => "3",
                ],
                0
            ],
            'line with integer/shortvarchar validation warnings' => [
                [
                    'import_type' => 'top-level',
                    'column_names' => ["sample_id", "rruff_id"],
                    'line' => ["a", "0123456789012345678901234567890123456789"],
                    'datafield_mapping' => [
                        0 => "29",  // sample_id
                        1 => "30",  // rruff_id
                    ],
                    'unique_columns' => [
                        0 => "1",
                        1 => "1",
                    ],
                    'target_datatype_id' => "3",
                ],
                2  // one error for invalid integer, another for invalid shortvarchar
            ],
            // don't really need to test decimal or the other text fields if these work

            'file/image field only allows single upload (and file does not exist)' => [
                [
                    'import_type' => 'top-level',
                    'column_names' => ["reference_id", "filename"],
                    'line' => ["13283", "a_nonexistent_file.pdf"],
                    'datafield_mapping' => [
                        0 => "15",  // reference_id
                        1 => "13",  // file
                    ],
                    'unique_columns' => [
                        0 => "1",
                    ],
                    'column_delimiters' => [
                        1 => "|",
                    ],
                    'target_datatype_id' => "1",
                ],
                2  // should have one error for file not existing (can't really test upload here), and another for violating the constraint
            ],
            'radio field does not allow blank option name' => [
                [
                    'import_type' => 'top-level',
                    'column_names' => ["sample_id", "sample_status"],
                    'line' => ["5882", "unconfirmed|"],
                    'datafield_mapping' => [
                        0 => "29",  // sample_id
                        1 => "31",  // sample_status
                    ],
                    'unique_columns' => [
                        0 => "1",
                    ],
                    'column_delimiters' => [
                        1 => "|",  // technically, the field is a single select, so it shouldn't have a column_delimiter...but the test database doesn't have a multiple select field handy
                    ],
                    'target_datatype_id' => "3",
                ],
                1  // the given field is exploded into 'unconfirmed' and '', so one error
            ],
            'tag field does not allow blank tag name v1' => [
                [
                    'import_type' => 'top-level',
                    'column_names' => ["mineral_id", "tags"],
                    'line' => ["5882", "Origins >> biomineral|Origins >>"],
                    'datafield_mapping' => [
                        0 => "16",  // mineral_id
                        1 => "28",  // tags
                    ],
                    'unique_columns' => [
                        0 => "1",
                    ],
                    'column_delimiters' => [
                        1 => "|",
                    ],
                    'hierarchy_delimiters' => [
                        1 => ">>",
                    ],
                    'target_datatype_id' => "2",
                ],
                1
            ],
            'tag field does not allow blank tag name v2' => [
                [
                    'import_type' => 'top-level',
                    'column_names' => ["mineral_id", "tags"],
                    'line' => ["5882", "Origins >> asdf|"],
                    'datafield_mapping' => [
                        0 => "16",  // mineral_id
                        1 => "28",  // tags
                    ],
                    'unique_columns' => [
                        0 => "1",
                    ],
                    'column_delimiters' => [
                        1 => "|",
                    ],
                    'hierarchy_delimiters' => [
                        1 => ">>",
                    ],
                    'target_datatype_id' => "2",
                ],
                1
            ],
        ];
    }
}
