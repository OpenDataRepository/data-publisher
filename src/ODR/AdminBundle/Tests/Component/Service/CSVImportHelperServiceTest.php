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
use Ddeboer\DataImport\Reader\CsvReader;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;


class CSVImportHelperServiceTest extends WebTestCase
{

    /**
     * The functions inside CSVImportHelperService are all
     *
     * @param array $post
     * @param string $csv_filename filename of the csv file to use for testing purposes
     *
     * @return array
     */
    private function runUniquenessChecks($post, $csv_filename)
    {
        exec('redis-cli flushall');
        $client = static::createClient();
        if ( $client->getContainer()->getParameter('database_name') !== 'odr_theta_2' )
            $this->markTestSkipped('Wrong database');

        /** @var CSVImportHelperService $csv_helper_service */
        $csv_helper_service = $client->getContainer()->get('odr.csv_import_helper_service');


        // ----------------------------------------
        // Load the csv file
        ini_set('auto_detect_line_endings', TRUE);

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
        // Convert the post data into a form that's more useful for verifying uniquness
        $uniqueness_check_data = $csv_helper_service->getUniquenessCheckData($post, $file_headers);

        $errors = array();

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


    /**
     * @covers \ODR\AdminBundle\Component\Service\CSVImportHelperService::getUniquenessCheckData
     * @covers \ODR\AdminBundle\Component\Service\CSVImportHelperService::getExistingUniqueValues
     * @covers \ODR\AdminBundle\Component\Service\CSVImportHelperService::getFutureUniqueValues
     * @covers \ODR\AdminBundle\Component\Service\CSVImportHelperService::findUniquenessErrors
     * @dataProvider provideUniquenessCheckParams
     *
     * @param array $post_data POST data sent to CSVImportController::startvalidateAction()
     * @param string $csv_filename filename of the csv file to use for testing purposes
     * @param int $expected_error_count
     */
    public function testFindUniquenessErrors($post_data, $csv_filename, $expected_error_count)
    {
        $errors = self::runUniquenessChecks($post_data, $csv_filename);

//        if ( $expected_error_count !== count($errors) )
//            throw new ODRException(print_r($errors, true));

        $this->assertEquals( $expected_error_count, count($errors) );
    }


    /**
     * @return array
     */
    public function provideUniquenessCheckParams()
    {
        return [
            // ----------------------------------------
            // Testing imports into top-level datatypes

            // Import into RRUFF Sample...not trying to cause a problem
            'import into good database with good csv file' => [
                array(
                    'datatype_id' => "3",
                    'datafield_mapping' => array(
                        0 => "29",  // sample_id
                        1 => "30",  // rruff_id
                        2 => "34",  // owner
                    ),
                    'unique_columns' => array(
                        0 => "1",
                        1 => "1",
                    ),
                ),
                'csv_import_test_1.csv',
                0
            ],

            // Import into RRUFF Sample...csv file has duplicate value in external_id column
            'duplicate value in csv file in external id column' => [
                array(
                    'datatype_id' => "3",
                    'datafield_mapping' => array(
                        0 => "29",  // sample_id
                        1 => "30",  // rruff_id
                        2 => "34",  // owner
                    ),
                    'unique_columns' => array(
                        0 => "1",
                        1 => "1",
                    ),
                ),
                'csv_import_test_2.csv',
                1
            ],
            // Import into RRUFF Sample...csv creates duplicate value in rruff_id column
            'import creates duplicate in unique column' => [
                array(
                    'datatype_id' => "3",
                    'datafield_mapping' => array(
                        0 => "29",  // sample_id
                        1 => "30",  // rruff_id
                    ),
                    'unique_columns' => array(
                        0 => "1",
                        1 => "1",
                    ),
                ),
                'csv_import_test_3.csv',
                1
            ],

            // Import without external id into RRUFF Sample...file happens to not create duplicates in rruff_id
            'import without external id, no duplicates created in unique field by luck' => [
                array(
                    'datatype_id' => "3",
                    'datafield_mapping' => array(
                        1 => "30",  // rruff_id
                    ),
                    'unique_columns' => array(
                        1 => "1",
                    ),
                ),
                'csv_import_test_4.csv',
                0
            ],
            // Import without external id into RRUFF Sample...file creates multiple duplicates in rruff_id column
            'import without external id, creates multiple duplicates in unique field' => [
                array(
                    'datatype_id' => "3",
                    'datafield_mapping' => array(
                        1 => "30",  // rruff_id
                    ),
                    'unique_columns' => array(
                        1 => "1",
                    ),
                ),
                'csv_import_test_3.csv',
                3
            ],

            // Import into IMA without external id...file is fine, but should find existing duplicates in locality_country
            'non-unique field marked as unique for import...has duplicates already' => [
                array(
                    'datatype_id' => "2",
                    'datafield_mapping' => array(
                        3 => "17",  // mineral_name
                        4 => "24",  // locality_country
                    ),
                    'unique_columns' => array(
                        4 => "1",
                    ),
                ),
                'csv_import_test_4.csv',
                1
            ],
            // Import into IMA without external id...existing duplicates in locality_country field, more created by csv file
            'non-unique field marked as unique for import...has duplicates already, import creates more' => [
                array(
                    'datatype_id' => "2",
                    'datafield_mapping' => array(
                        3 => "17",  // mineral_name
                        4 => "24",  // locality_country
                    ),
                    'unique_columns' => array(
                        4 => "1",
                    ),
                ),
                'csv_import_test_1.csv',
                4    // one for existing duplicate, one for "USA", two for "Sweden"
            ],
            // Import into IMA without external id...file is fine, should end up with no duplicates in mineral_name
            'non-unique field marked as unique for import...no existing duplicates' => [
                array(
                    'datatype_id' => "2",
                    'datafield_mapping' => array(
                        3 => "17",  // mineral_name
                    ),
                    'unique_columns' => array(
                        3 => "1",
                    ),
                ),
                'csv_import_test_4.csv',
                0
            ],
            // Import into IMA without external id...should end up with multiple duplicates in mineral_name
            'non-unique field marked as unique for import...no existing duplicates, import creates several' => [
                array(
                    'datatype_id' => "2",
                    'datafield_mapping' => array(
                        3 => "17",  // mineral_name
                    ),
                    'unique_columns' => array(
                        3 => "1",
                    ),
                ),
                'csv_import_test_1.csv',
                3
            ],


            // ----------------------------------------
            // Testing imports into child datatypes

            // Import into "Import Test - Sample"
            'import into good child database with good csv file' => [
                array(
                    'datatype_id' => "6",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => array(
                        0 => "1",
                        1 => "1",
                    ),
                    'datafield_mapping' => array(
                        0 => "47",    // sample_id
                        1 => "48",    // rruff_id
                        2 => "49",    // owner
                    ),
                ),
                'csv_import_test_1.csv',
                0
            ],

            // Import into "Import Test - Sample"
            'duplicate value in csv file in child external id column' => [
                array(
                    'datatype_id' => "6",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => array(
                        0 => "1",
                        1 => "1"
                    ),
                    'datafield_mapping' => array(
                        0 => "47",    // sample_id
                        1 => "48",    // rruff_id
                    ),
                ),
                'csv_import_test_2.csv',
                1
            ],
            // Import into "Import Test - Sample", set owner field to unique
            'non-unique field in child marked as unique for import...has duplicates already' => [
                array(
                    'datatype_id' => "6",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => array(
                        0 => "1",
                        2 => "1",
                    ),
                    'datafield_mapping' => array(
                        0 => "47",    // sample_id
                        2 => "49",    // owner
                    ),
                ),
                'csv_import_test_1.csv',
                1
            ],

            // Import into "Import Test - Sample"...despite reusing "R070007" under "TotallyNotAbelsonite",
            //  no uniqueness constraints are violated
            'import creates duplicates in unique field of child record, different parent' => [
                array(
                    'datatype_id' => "6",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => array(
                        0 => "1",
                        1 => "1"
                    ),
                    'datafield_mapping' => array(
                        0 => "47",    // sample_id
                        1 => "48",    // rruff_id
                    ),
                ),
                'csv_import_test_5.csv',
                0
            ],
            // Import into "Import Test - Sample", should get an error on rruff_id column due to
            //  sample_id 99999 attempting to reuse "R060687"
            'import creates duplicates in unique field of child record, same parent' => [
                array(
                    'datatype_id' => "6",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => array(
                        0 => "1",
                        1 => "1"
                    ),
                    'datafield_mapping' => array(
                        0 => "47",    // sample_id
                        1 => "48",    // rruff_id
                    ),
                ),
                'csv_import_test_3.csv',
                1
            ],


            // ----------------------------------------
            // Catching ways that imports can create multiple descendant records when only a single
            //  descendant is allowed

            // Import into "Import Test - Mineral ID"...this also tests updating/creating a single
            //  child record
            'import creates more than one child record in single-allowed childtype' => [
                array(
                    'datatype_id' => "7",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => array(
                        5 => "1",
                    ),
                    'datafield_mapping' => array(
                        5 => "50",    // mineral_id
                    ),
                ),
                'csv_import_test_1.csv',
                2    // one for a duplicate in the external id column, another because it would
                     //  create more than one child record
            ],
            // Import into "Import Test - locality_country"
            'csv file has duplicate external id for single-allowed childtype' => [
                array(
                    'datatype_id' => "7",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'unique_columns' => array(
                        5 => "1",
                    ),
                    'datafield_mapping' => array(
                        5 => "50",    // mineral_id
                    ),
                ),
                'csv_import_test_2.csv',
                3    // two for duplicates in the child external id, and a third because it would
                     //  create a duplicate
            ],
            // Import into "Import Test - locality_country"
            'import overwrites child records in single-allowed childtype' => [
                array(
                    'datatype_id' => "8",
                    'parent_datatype_id' => "5",
                    'parent_external_id_column' => "3",
                    'datafield_mapping' => array(
                        4 => "51",    // locality_country
                    ),
                ),
                'csv_import_test_2.csv',
                3    // one warning for overwriting an existing child record, and two more for
                     //  overwriting child records that the import will create
            ],

            // Create links between "RRUFF Sample" and "IMA List"
            'import attempts to link to second remote record in single-allowed linked datatype' => [
                array(
                    'datatype_id' => "2",
                    'parent_datatype_id' => "3",
                    'parent_external_id_column' => "0",
                    'remote_external_id_column' => "5",
                ),
                'csv_import_test_5.csv',
                1    // one error because sample 3082 already links to mineral 777
            ],

            // Create links between "RRUFF Sample" and "IMA List"...csv file doesn't have duplicates
            'csv file does not have duplicates in columns when creating links' => [
                array(
                    'datatype_id' => "2",
                    'parent_datatype_id' => "3",
                    'parent_external_id_column' => "0",
                    'remote_external_id_column' => "5",
                ),
                'csv_import_test_1.csv',
                0
            ],
            // Create links between "RRUFF Sample" and "IMA List"...csv file does have duplicates,
            //  but the csv linker merely ensures the link exists more than once...so there are
            //  no errors to find here
            'csv file does has duplicates in columns when creating links' => [
                array(
                    'datatype_id' => "2",
                    'parent_datatype_id' => "3",
                    'parent_external_id_column' => "0",
                    'remote_external_id_column' => "5",
                ),
                'csv_import_test_2.csv',
                0
            ],


            // ----------------------------------------
            // Don't need to test:
            //  - duplicate filenames in a file/image column of the csv file
            //  - ancestors that don't exist when importing into a child/linked datatype
            //  - remote datarecords not existing when importing into a linked datatype
        ];
    }
}
