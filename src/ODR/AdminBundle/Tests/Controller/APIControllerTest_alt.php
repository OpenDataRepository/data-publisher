<?php

namespace ODR\AdminBundle\Tests\Controller;

use ODR\AdminBundle\Component\Service\CacheService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use ODR\AdminBundle\Component\Utility\CurlUtility;

class APIControllerTest_alt extends WebTestCase
{
    public static $debug = false;
    public static $force_skip = false;

    public static $api_baseurl = '';
    public static $api_username = '';
    public static $api_user_password = '';

    public static $token = '';
    public static $headers = array();

    public static $file_upload_basepath = '';

    public static $database_list = array();
    public static $database_uuid = '';
    public static $template_uuid = '';
    public static $database_structure = array();
    public static $field_uuids = array();
    public static $descendant_database_uuids = array();
    public static $descendant_datarecord_uuids = array();
    public static $record_list = array();
    public static $record_uuid = '';
    public static $record_structure = array();

    public static $other_database_uuid = '';
    public static $other_record_uuid = '';

    /**
     * Since assertEqualsCanonicalizing() and assertJsonStringEqualsJsonString() can't deal with
     * the multi-dimensional arrays returned by ODR's API...
     *
     * @param array $expected
     * @param array $actual
     * @param array $rootPath
     * @return void
     */
    public function assertArrayEquals($expected, $actual, $rootPath = array("/"))
    {
        foreach ($expected as $key => $value)
        {
            // Ignore the metadata blocks, because created/updated dates rarely match
            if ( $key === '_record_metadata' || $key === '_field_metadata' || $key === '_file_metadata' )
                continue;

            // These subarrays should be canonicalized beforehand...
            if ( $key === 'fields' || $key === 'records' || $key === 'files' || $key === 'tags' || $key === 'values' ) {
                $tmp = array();
                foreach ($value as $num => $item) {
                    if ( $key === 'records' )
                        $tmp[ $item['internal_id'] ] = $item;
                    else
                        $tmp[ $item['id'] ] = $item;
                }
                $value = $tmp;
            }

            // Intentionally only asserting when it would fail...
            if ( !isset($actual[$key]) )
                $this->assertArrayHasKey($key, $actual, 'Failed asserting that $actual['.$key.'] exists for path "'.implode(" > ", $rootPath).'"');

            $keyPath = $rootPath;
            $keyPath[] = $key;

            if ( isset($actual[$key]) ) {
                if ( is_array($value) ) {
                    // These subarrays should be canonicalized beforehand...
                    if ( $key === 'fields' || $key === 'records' || $key === 'files' || $key === 'tags' || $key === 'values' ) {
                        $tmp = array();
                        foreach ($actual[$key] as $num => $item) {
                            if ( $key === 'records' )
                                $tmp[ $item['internal_id'] ] = $item;
                            else
                                $tmp[ $item['id'] ] = $item;
                        }
                        $actual[$key] = $tmp;
                    }

                    $this->assertArrayEquals($value, $actual[$key], $keyPath);
                }
                else if ( $value != $actual[$key] )
                    $this->assertEquals($value, $actual[$key], 'Failed asserting that $actual value "'.$actual[$key].'" matches expected "'.$value.'" for path "'.implode(" > ", $keyPath).'"');
            }
        }


        // ...and need to run the inverse for when $actual has a key $expected doesn't
        foreach ($actual as $key => $value)
        {
            // Ignore the metadata blocks, because created/updated dates rarely match
            if ( $key === '_record_metadata' || $key === '_field_metadata' || $key === '_file_metadata' )
                continue;

            // These subarrays should be canonicalized beforehand...
            if ( $key === 'fields' || $key === 'records' || $key === 'files' || $key === 'tags' || $key === 'values' ) {
                $tmp = array();
                foreach ($value as $num => $item) {
                    if ( $key === 'records' )
                        $tmp[ $item['internal_id'] ] = $item;
                    else
                        $tmp[ $item['id'] ] = $item;
                }
                $value = $tmp;
            }

            // Intentionally only asserting when it would fail...
            if ( !isset($expected[$key]) )
                $this->assertArrayHasKey($key, $actual, 'Failed asserting that $expected['.$key.'] exists for path "'.implode(" > ", $rootPath).'"');

            $keyPath = $rootPath;
            $keyPath[] = $key;

            if ( isset($expected[$key]) ) {
                if ( is_array($value) ) {
                    // These subarrays should be canonicalized beforehand...
                    if ( $key === 'fields' || $key === 'records' || $key === 'files' || $key === 'tags' || $key === 'values' ) {
                        $tmp = array();
                        foreach ($expected[$key] as $num => $item) {
                            if ( $key === 'records' )
                                $tmp[ $item['internal_id'] ] = $item;
                            else
                                $tmp[ $item['id'] ] = $item;
                        }
                        $expected[$key] = $tmp;
                    }

                    $this->assertArrayEquals($value, $expected[$key], $keyPath);
                }
                else if ( $value != $expected[$key] )
                    $this->assertEquals($value, $expected[$key], 'Failed asserting that $expected value "'.$expected[$key].'" matches $actual value "'.$value.'" for path "'.implode(" > ", $keyPath).'"');
            }
        }
    }

    /**
     * Might as well have this in its own function since it's going to be used so much...
     *
     * @param string $record_uuid
     * @return array
     */
    public function getRecord($record_uuid)
    {
        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record/'.$record_uuid,
            self::$headers
        );

        $response = $curl->get();
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
        if ( self::$debug )
            fwrite(STDERR, 'modified dr structure: '.print_r($content, true)."\n");

        return $content;
    }

    /**
     * Might as well have this in its own function since it's going to be used so much...
     *
     * @param array $data
     * @return array
     */
    public function submitRecord_valid($data)
    {
        if ( self::$debug )
            fwrite(STDERR, 'submitting valid dr structure: '.print_r($data, true)."\n");

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $post_data = json_encode($data);
        $response = $curl->post($post_data);
        $response_code = $response['code'];
        if ( $response_code !== 200 )
            fwrite(STDERR, 'response: '.print_r($response, true)."\n");

        $this->assertEquals(200, $response_code);

        $content = json_decode($response['response'], true);
        if ( self::$debug )
            fwrite(STDERR, 'returned dr structure: '.print_r($content, true)."\n");
        return $content;
    }


    /**
     * Might as well have this in its own function since it's going to be used so much...
     *
     * @param array $data
     * @param int $expected_code
     * @return void
     */
    public function submitRecord_invalid($data, $expected_code)
    {
        if ( self::$debug )
            fwrite(STDERR, 'submitting invalid dr structure: '.print_r($data, true)."\n");

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $post_data = json_encode($data);
        $response = $curl->post($post_data);
        $response_code = $response['code'];
        if ( $response_code !== $expected_code )
            fwrite(STDERR, 'response: '.print_r($response, true)."\n");

        $this->assertEquals($expected_code, $response_code);
    }

    /**
     * Might as well have file uploading in its own function, since it's used at least half a dozen
     * times...
     *
     * @param string $filepath
     * @param string $database_uuid
     * @param string $field_uuid
     * @param string $record_uuid
     * @param string $uploaded_by
     * @param string $create_date
     * @param string $public_date
     * @param string $quality
     * @param string $display_order
     * @return array
     */
    public function uploadFile($filepath, $database_uuid, $field_uuid, $record_uuid, $uploaded_by, $create_date = '', $public_date = '', $quality = '', $display_order = '')
    {
        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/file',
            self::$headers
        );

        $data = array(
            'name' => '',
            'dataset_uuid' => $database_uuid,
            'template_field_uuid' => '',
            'field_uuid' => $field_uuid,
            'record_uuid' => $record_uuid,
            'user_email' => $uploaded_by,
        );
        if ( $create_date !== '' )
            $data['created'] = $create_date;
        if ( $public_date !== '' )
            $data['public_date'] = $public_date;
        if ( $quality !== '' )
            $data['quality'] = $quality;
        if ( $display_order !== '' )
            $data['display_order'] = $display_order;

        $response = $curl->post($data, $filepath);
        $code = $response['code'];
        if ( $code !== 200 )
            fwrite(STDERR, 'response: '.print_r($response, true)."\n");
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
//        if ( self::$debug )
//            fwrite(STDERR, 'file upload return: '.print_r($content, true)."\n");

        return $content;
    }

    /**
     * {@inheritDoc}
     */
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        $argv = $GLOBALS['argv'];
        if ( in_array('--debug', $argv) )
            self::$debug = true;

        self::$api_baseurl = getenv('API_BASEURL');
        self::$api_username = getenv('API_USERNAME');
        self::$api_user_password = getenv('API_USER_PASSWORD');

        self::$headers = array('Content-type: application/json');

        $client = static::createClient();
        if ( $client->getContainer()->getParameter('database_name') !== 'odr_theta_2' )
            self::$force_skip = true;

        $basepath = $client->getContainer()->getParameter('odr_tmp_directory').'/../..';
        if ( !file_exists($basepath.'/phpunit_testing.dmp') )
            self::$force_skip = true;
        if ( filesize($basepath.'/phpunit_testing.dmp') === 0 )
            self::$force_skip = true;

        self::$file_upload_basepath = $basepath.'/src/ODR/AdminBundle/TestResources/';
    }

    /**
     * {@inheritDoc}
     */
    public static function tearDownAfterClass()
    {
        if ( !self::$force_skip )
            exec('mysql --login-path=testing '.getenv('API_TESTING_DB').' < phpunit_testing.dmp');
        exec('redis-cli flushall');
    }

    public function testLogin()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $post_data = json_encode(
            array(
                'username' => self::$api_username,
                'password' => self::$api_user_password,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/token',
            self::$headers
        );

        $response = $curl->post($post_data);
//        if ( self::$debug )
//            fwrite(STDERR, print_r($response, true)."\n");

        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = $response['response'];

        // Token value should be set
        $token = json_decode($content, true);
        $this->assertArrayHasKey('token', $token);
        $this->assertNotEquals('', $token['token']);

        self::$token = $token['token'];
        if ( self::$debug )
            fwrite(STDERR, 'Token: '.self::$token."\n");

        // Replace the headers to use the received token
        self::$headers = array('Authorization: Bearer '.self::$token);
//        if ( self::$debug )
//            fwrite(STDERR, print_r(self::$headers, true)."\n");
    }

    public function testUser()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $post_data = array(
            'user_email' => self::$api_username,
            'first_name' => 'foo',
            'last_name' => 'bar',
        );

//        if ( self::$debug )
//            fwrite(STDERR, print_r(self::$headers, true)."\n");

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/user',
            self::$headers
        );

        $response = $curl->post($post_data);
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
//        if ( self::$debug )
//            fwrite(STDERR, print_r($content, true)."\n");

        $this->assertArrayHasKey('user_email', $content);
        $this->assertArrayHasKey('datasets', $content);
    }

    public function testGetDatabaseList()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $curl = new CurlUtility(
            self::$api_baseurl.'/v1/search/databases',
            self::$headers
        );

        $response = $curl->get();
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
        $this->assertArrayHasKey('databases', $content);

        self::$database_list = $content['databases'];
        foreach (self::$database_list as $num => $dt) {
            if ( $dt['database_name'] === 'API Test' ) {
                // This is the database we actually want to test against...
                self::$database_uuid = $dt['unique_id'];
                self::$template_uuid = $dt['template_id'];
            }
            else if ( $dt['database_name'] === 'IMA List' ) {
                // ...but also need another unrelated database to verify API won't create child/links
                //  to unrelated databases
                self::$other_database_uuid = $dt['unique_id'];
            }
        }
        if ( self::$debug )
            fwrite(STDERR, 'dt uuid: '.self::$database_uuid."\n");
    }

    public function testGetDatabaseStructure()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/search/database/'.self::$database_uuid,
            self::$headers
        );

        $response = $curl->get();
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
        $this->assertArrayHasKey('name', $content);

        self::$database_structure = $content;
        if ( self::$debug )
            fwrite(STDERR, 'database structure: '.print_r(self::$database_structure, true)."\n");


        // Going to be using these fields...
        foreach (self::$database_structure['fields'] as $num => $df)
            self::$field_uuids[ $df['name'] ] = $df['field_uuid'];

        // Also going to be using several descendant datatypes...
        foreach (self::$database_structure['related_databases'] as $num => $dt) {
            self::$descendant_database_uuids[ $dt['name'] ] = $dt['template_uuid'];

            foreach ($dt['fields'] as $num => $df)
                self::$field_uuids[ $df['name'] ] = $df['field_uuid'];
        }

        if ( self::$debug )
            fwrite(STDERR, 'fields: '.print_r(self::$field_uuids, true)."\n");
        if ( self::$debug )
            fwrite(STDERR, 'descendant datatypes: '.print_r(self::$descendant_database_uuids, true)."\n");
    }

    public function testGetRecordList()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $curl = new CurlUtility(
            self::$api_baseurl.'/v1/search/database/'.self::$database_uuid.'/records',
            self::$headers
        );

        $response = $curl->get();
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
        $this->assertArrayHasKey('records', $content);

        self::$record_list = $content['records'];
        foreach (self::$record_list as $num => $dr_info)
            self::$record_uuid = $dr_info['unique_id'];

        if ( self::$debug )
            fwrite(STDERR, 'dr uuid: '.self::$record_uuid."\n");


        // ----------------------------------------
        // Also need another record in an unrelated database to verify the API won't create
        //  child/links in unrelated databases
        $curl = new CurlUtility(
            self::$api_baseurl.'/v1/search/database/'.self::$other_database_uuid.'/records',
            self::$headers
        );

        $response = $curl->get();
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
        $this->assertArrayHasKey('records', $content);

        self::$record_list = $content['records'];
        foreach (self::$record_list as $num => $dr_info) {
            // Only need one record
            self::$other_record_uuid = $dr_info['unique_id'];
            break;
        }
    }

    public function testGetRecord()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        // Get the current version of the record...
        self::$record_structure = self::getRecord( self::$record_uuid );

        // Don't want any selections at this point...
        $this->assertEmpty(self::$record_structure['fields']);
    }

    public function testRecordSave_NoPerms()
    {
        $this->markTestSkipped('multiple different ways a user could have no permissions...');
    }

    public function testRecordSave_InvalidUUIDs()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        // Submitting a top-level record without a uuid should result in a 404 error
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['record_uuid'] = '';

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 404);


        // ----------------------------------------
        // Submitting a top-level record with an invalid uuid should also result in a 404 error
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['record_uuid'] = substr($tmp_dataset['record_uuid'], 0, -1);

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 404);
    }

    public function testRecordSave_NoModification()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        // Submitting a record without modifications should result in no modifications
        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => self::$record_structure,
        );
        $content = self::submitRecord_valid($post_data);

        $this->assertEqualsCanonicalizing(self::$record_structure, $content);
    }

    public function testFieldSave_NoPerms()
    {
        $this->markTestSkipped('multiple different ways a user could have no permissions...');
    }

    public function testFieldSave_InvalidUUIDs()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        // ----------------------------------------
        // Entries without a uuid identifier are invalid...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'] = array(
            0 => array(
                'value' => '',
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);


        // ----------------------------------------
        // Entries with a blank uuid are invalid...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'] = array(
            0 => array(
                'field_uuid' => '',
                'value' => '',
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 404);


        // ----------------------------------------
        // Entries with an invalid uuid are also invalid...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'] = array(
            0 => array(
                'field_uuid' => substr(self::$field_uuids['Short Text'], 0, -1),
                'value' => '',
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 404);
    }

    public function testSingleSelect_OneSelection()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $option_uuids = array();
        foreach (self::$database_structure['fields'] as $df_num => $df) {
            if ( $df['name'] === 'Single Select' ) {
                foreach ($df['radio_options'] as $ro_num => $ro)
                    $option_uuids[ $ro['name'] ] = $ro['template_radio_option_uuid'];
                break;
            }
        }

        // ----------------------------------------
        // Ensure that an invalid array is an error
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][0] = array(
            'field_uuid' => self::$field_uuids['Single Select'],
            'values' => array(
                'template_radio_option_uuid' => $option_uuids['Existing Option A'],
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);

        // ----------------------------------------
        // Specifying one existing option should work...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'] = array(
            0 => array(
                'field_uuid' => self::$field_uuids['Single Select'],
                'values' => array(
                    0 => array(
                        'template_radio_option_uuid' => $option_uuids['Existing Option A'],
                    )
                )
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);


        // ----------------------------------------
        // Repeat by creating a new radio option
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'] = array(
            0 => array(
                'field_uuid' => self::$field_uuids['Single Select'],
                'values' => array(
                    0 => array(
                        'template_radio_option_uuid' => 'New Option C',
                    )
                )
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);
    }

    public function testSingleSelect_MoreThanOneSelection()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $option_uuids = array();
        foreach (self::$database_structure['fields'] as $df_num => $df) {
            if ( $df['name'] === 'Single Select' ) {
                foreach ($df['radio_options'] as $ro_num => $ro)
                    $option_uuids[ $ro['name'] ] = $ro['template_radio_option_uuid'];
                break;
            }
        }

        // Selecting more than one option should fail
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'] = array(
            0 => array(
                'field_uuid' => self::$field_uuids['Single Select'],
                'values' => array(
                    0 => array(
                        'template_radio_option_uuid' => $option_uuids['Existing Option A'],
                    ),
                    1 => array(
                        'template_radio_option_uuid' => $option_uuids['Existing Option B'],
                    )
                )
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);


        // ----------------------------------------
        // ...as should creating more than one option at a time, because they would both be
        //  selected
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'] = array(
            0 => array(
                'field_uuid' => self::$field_uuids['Single Select'],
                'values' => array(
                    0 => array(
                        'template_radio_option_uuid' => 'New Option X',
                    ),
                    1 => array(
                        'template_radio_option_uuid' => 'New Option Y',
                    ),
                    2 => array(
                        'template_radio_option_uuid' => 'New Option Z',
                    )
                )
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);


        // ----------------------------------------
        // The request should not have created any options, so the field should have three radio
        //  options total by now...two that existed before the test, plus the one created in the
        //  previous test
        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/search/database/'.self::$database_uuid,
            self::$headers
        );

        $response = $curl->get();
        $content = json_decode($response['response'], true);

        foreach ($content['fields'] as $num => $df) {
            if ( $df['name'] === 'Single Select' ) {
                $this->assertCount(3, $df['radio_options']);
                break;
            }
        }
    }

    public function testMultipleSelect()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $option_uuids = array();
        foreach (self::$database_structure['fields'] as $df_num => $df) {
            if ( $df['name'] === 'Multiple Select' ) {
                foreach ($df['radio_options'] as $ro_num => $ro)
                    $option_uuids[ $ro['name'] ] = $ro['template_radio_option_uuid'];
                break;
            }
        }

        // ----------------------------------------
        // Specifying one existing option should work...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][1] = array(    // index 0 is occupied by 'Single Select' now
            'field_uuid' => self::$field_uuids['Multiple Select'],
            'values' => array(
                0 => array(
                    'template_radio_option_uuid' => $option_uuids['Existing Option A'],
                )
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);


        // ----------------------------------------
        // Specifying more than one existing option should also work...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][1] = array(    // index 0 is occupied by 'Single Select' now
            'field_uuid' => self::$field_uuids['Multiple Select'],
            'values' => array(
                0 => array(
                    'template_radio_option_uuid' => $option_uuids['Existing Option A'],
                ),
                1 => array(
                    'template_radio_option_uuid' => $option_uuids['Existing Option B'],
                )
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // ----------------------------------------
        // Deselecting an existing option should also work...
        $tmp_dataset = self::$record_structure;
        unset( $tmp_dataset['fields'][1]['values'][1] );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);
    }

    public function testShortText()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][2] = array(    // 'Multiple Select' is occupying index 1...
            'field_uuid' => self::$field_uuids['Short Text'],
            'value' => 'foobar',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);
    }

    public function testBoolean()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        // ----------------------------------------
        // Boolean should use 'selected', not 'value'...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][3] = array(    // 'Short Text' is occupying index 2...
            'field_uuid' => self::$field_uuids['Boolean'],
            'value' => '1',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);


        // ----------------------------------------
        // Submit it with the correct key this time
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][3] = array(    // 'Short Text' is occupying index 2...
            'field_uuid' => self::$field_uuids['Boolean'],
            'selected' => '1',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);


        // ----------------------------------------
        // Ensure it can be set to unselected as well
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][3] = array(    // 'Short Text' is occupying index 2...
            'field_uuid' => self::$field_uuids['Boolean'],
            'selected' => '0',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // Extra assert to ensure the return is '0' instead of ''
        $this->assertSame(0, $api_response_content['fields'][3]['selected']);
    }

    public function testDatetime()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][4] = array(    // 'Boolean' is occupying index 3...
            'field_uuid' => self::$field_uuids['Datetime'],
            'value' => '2024-01-01',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);
    }

    public function testTags_Flat()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $tag_uuids = array();
        foreach (self::$database_structure['fields'] as $df_num => $df) {
            if ( $df['name'] === 'Flat Tags' ) {
                foreach ($df['tags'] as $t_num => $t)
                    $tag_uuids[ $t['name'] ] = $t['template_tag_uuid'];
                break;
            }
        }

        // ----------------------------------------
        // 'value' isn't a valid key to use with tag fields
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][5] = array(    // 'DateTime' is occupying index 4...
            'field_uuid' => self::$field_uuids['Flat Tags'],
            'value' => '2024-01-01',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);

        // Ensure that an invalid array is an error
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][5] = array(
            'field_uuid' => self::$field_uuids['Flat Tags'],
            'tags' => array(
                'template_tag_uuid' => 'New Tag AA',
                'tag_parent_uuid' => $tag_uuids['Existing Tag A'],
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);

        // ----------------------------------------
        // Ensure that a tag field that does not allow multiple levels won't accept a tag with a parent
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][5] = array(
            'field_uuid' => self::$field_uuids['Flat Tags'],
            'tags' => array(
                0 => array(
                    'template_tag_uuid' => 'New Tag AA',
                    'tag_parent_uuid' => $tag_uuids['Existing Tag A'],
                )
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);

        // ----------------------------------------
        // Tag fields should allow selections...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][5] = array(    // 'DateTime' is occupying index 4...
            'field_uuid' => self::$field_uuids['Flat Tags'],
            'tags' => array(
                0 => array(
                    'template_tag_uuid' => $tag_uuids['Existing Tag A']
                ),
            ),
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // Tag fields should always allow multiple selections
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][5] = array(    // 'DateTime' is occupying index 4...
            'field_uuid' => self::$field_uuids['Flat Tags'],
            'tags' => array(
                0 => array(
                    'template_tag_uuid' => $tag_uuids['Existing Tag A']
                ),
                1 => array(
                    'template_tag_uuid' => $tag_uuids['Existing Tag B']
                ),
            ),
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // ----------------------------------------
        // Should also be able to create new tags
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][5]['tags'][2] = array(
            'template_tag_uuid' => 'New Tag C'
        );
        $tmp_dataset['fields'][5]['tags'][3] = array(
            'template_tag_uuid' => 'New Tag D'
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // ----------------------------------------
        // Should also be able to deselect tags
        $tmp_dataset = self::$record_structure;
        unset( $tmp_dataset['fields'][5]['tags'][3] );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);
    }

    public function testTags_Stacked()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $tag_uuids = array();
        $other_tag_uuids = array();
        foreach (self::$database_structure['fields'] as $df_num => $df) {
            if ( $df['name'] === 'Stacked Tags' ) {
                foreach ($df['tags'] as $t_num => $t) {
                    $tag_uuids[ $t['name'] ] = $t['template_tag_uuid'];
                    // Need to get this tag's children too
                    foreach ($t['tags'] as $t2_num => $t2)
                        $tag_uuids[ $t2['name'] ] = $t2['template_tag_uuid'];
                }
                break;
            }
            else if ( $df['name'] === 'Flat Tags' ) {
                foreach ($df['tags'] as $t_num => $t)
                    $other_tag_uuids[ $t['name'] ] = $t['template_tag_uuid'];
            }
        }

        // ----------------------------------------
        // Tag fields should allow selections...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][6] = array(    // 'Flat Tags' is occupying index 5...
            'field_uuid' => self::$field_uuids['Stacked Tags'],
            'tags' => array(
                0 => array(
                    'template_tag_uuid' => $tag_uuids['Existing Tag A']
                ),
            ),
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // Should be able to select a child tag now that the parent is selected...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][6]['tags'][] = array(
            'template_tag_uuid' => $tag_uuids['Existing Tag AA']
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // Should be able to select a child tag without having its parent selected first...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][6]['tags'][] = array(
            'template_tag_uuid' => $tag_uuids['Existing Tag BB']
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);


        // ----------------------------------------
        // Should be able to create a top-level tag
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][6]['tags'][] = array(
            'template_tag_uuid' => 'New Tag C',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // Should be able to create a new child tag
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][6]['tags'][] = array(
            'template_tag_uuid' => 'New Tag AB',
            'parent_tag_uuid' => $tag_uuids['Existing Tag A']
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // Should not be able to create a new child tag with an invalid uuid
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][6]['tags'][] = array(
            'template_tag_uuid' => 'New Tag X',
            'parent_tag_uuid' => $other_tag_uuids['Existing Tag A']
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 404);


        // ----------------------------------------
        // Should be able to deselect a child tag...
        $tmp_dataset = self::$record_structure;
        foreach ($tmp_dataset['fields'][6]['tags'] as $t_num => $t) {
            if ( $t['name'] === 'Existing Tag AA' )
                unset( $tmp_dataset['fields'][6]['tags'][$t_num] );
        }

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // Ensure 'Existing Tag A' is still selected
        $found = false;
        foreach (self::$record_structure['fields'][6]['tags'] as $t_num => $t) {
            if ( $t['name'] === 'Existing Tag A' )
                $found = true;
        }
        if ( !$found )
            $this->fail('"Existing Tag A" should still be selected, since "New Tag AB" is still selected');

        // Deselecting all child tags should also deselect the parent
        $tmp_dataset = self::$record_structure;
        foreach ($tmp_dataset['fields'][6]['tags'] as $t_num => $t) {
            if ( $t['name'] === 'New Tag AB' )
                unset( $tmp_dataset['fields'][6]['tags'][$t_num] );
        }

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // Ensure 'Existing Tag A' got unselected
        $found = false;
        foreach (self::$record_structure['fields'][6]['tags'] as $t_num => $t) {
            if ( $t['name'] === 'Existing Tag A' )
                $found = true;
        }
        if ( $found )
            $this->fail('"Existing Tag A" should not be selected, since none of its children are selected');


        // ----------------------------------------
        // Should also be able to deselect a parent tag, triggering deselection of its children
        $tmp_dataset = self::$record_structure;
        foreach ($tmp_dataset['fields'][6]['tags'] as $t_num => $t) {
            if ( $t['name'] === 'Existing Tag B' )
                unset( $tmp_dataset['fields'][6]['tags'][$t_num] );
        }

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // Ensure 'Existing Tag BB' got unselected
        $found = false;
        foreach (self::$record_structure['fields'][6]['tags'] as $t_num => $t) {
            if ( $t['name'] === 'Existing Tag BB' )
                $found = true;
        }
        if ( $found )
            $this->fail('"Existing Tag BB" should not be selected, since its parent got deselected');
    }

    public function testFileImageUpload_Single()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        // ----------------------------------------
        // Test basic file upload...
        $api_response_content = $this->uploadFile(
            self::$file_upload_basepath.'Image_14044.jpeg',
            self::$database_uuid,
            self::$field_uuids['Single File'],
            self::$record_uuid,
            self::$api_username,
            '',   // not testing create date here
            '',   // not testing public date here
            0,    // quality of 0, to distinguish when the uploader overwrites later on
            ''    // files don't have display order
        );

        // Don't need to compare against the new version of the actual record, since that's what
        //  ODR returned...verify that the file exists instead
        $first_file_uuid = '';
        foreach ($api_response_content['fields'] as $num => $df) {
            if ( $df['field_uuid'] == self::$field_uuids['Single File'] ) {
                if ( !isset($df['files'][0]['file_uuid']) )
                    $this->fail('File upload attempt #1 failed');
                $first_file_uuid = $df['files'][0]['file_uuid'];

                // Don't continue to look
                break;
            }
        }

        // Test file upload overwriting the file in a field that doesn't allow multiple uploads
        $api_response_content = $this->uploadFile(
            self::$file_upload_basepath.'Image_14044.jpeg',
            self::$database_uuid,
            self::$field_uuids['Single File'],
            self::$record_uuid,
            self::$api_username,
            '',   // not testing create date here
            '',   // not testing public date here
            1,    // quality of 1, to distinguish when the uploader overwrites later on
            ''    // files don't have display order
        );

        // Don't need to compare against the new version of the actual record, since that's what
        //  ODR returned...verify that the file exists instead
        $second_file_uuid = '';
        foreach ($api_response_content['fields'] as $num => $df) {
            if ( $df['field_uuid'] == self::$field_uuids['Single File'] ) {
                if ( !isset($df['files'][0]['file_uuid']) )
                    $this->fail('File upload attempt #2 failed');
                $second_file_uuid = $df['files'][0]['file_uuid'];

                if ( !isset($df['files'][0]['_file_metadata']['_quality']) || $df['files'][0]['_file_metadata']['_quality'] != 1 )
                    $this->fail('File upload #2 did not change quality value');

                // Don't continue to look
                break;
            }
        }
        if ( $first_file_uuid === $second_file_uuid )
            $this->fail('File upload #2 did not replace upload #1');


        // ----------------------------------------
        // Test basic image upload...
        $api_response_content = $this->uploadFile(
            self::$file_upload_basepath.'Image_14044.jpeg',
            self::$database_uuid,
            self::$field_uuids['Single Image'],
            self::$record_uuid,
            self::$api_username,
            '',   // not testing create date here
            '',   // not testing public date here
            0,    // quality of 0, to distinguish when the uploader overwrites later on
            ''    // not testing display order here
        );

        // Don't need to compare against the new version of the actual record, since that's what
        //  ODR returned...verify that the file exists instead
        $first_image_uuid = '';
        foreach ($api_response_content['fields'] as $num => $df) {
            if ( $df['field_uuid'] == self::$field_uuids['Single Image'] ) {
                if ( !isset($df['files'][0]['file_uuid']) )
                    $this->fail('Image upload attempt #1 failed');
                $first_image_uuid = $df['files'][0]['file_uuid'];

                if ( !isset($df['files'][1]['parent_image_id']) || $df['files'][1]['parent_image_id'] != $df['files'][0]['id'] )
                    $this->fail('Image upload attempt #1 did not create a thumbnail');

                // Don't continue to look
                break;
            }
        }

        // Test file upload overwriting the file in a field that doesn't allow multiple uploads
        $api_response_content = $this->uploadFile(
            self::$file_upload_basepath.'Image_14044.jpeg',
            self::$database_uuid,
            self::$field_uuids['Single Image'],
            self::$record_uuid,
            self::$api_username,
            '',   // not testing create date here
            '',   // not testing public date here
            1,    // quality of 1, to distinguish when the uploader overwrites later on
            ''    // not testing display order here
        );

        // Don't need to compare against the new version of the actual record, since that's what
        //  ODR returned...verify that the file exists instead
        $second_image_uuid = '';
        foreach ($api_response_content['fields'] as $num => $df) {
            if ( $df['field_uuid'] == self::$field_uuids['Single Image'] ) {
                if ( !isset($df['files'][0]['file_uuid']) )
                    $this->fail('Image upload attempt #2 failed');
                $second_image_uuid = $df['files'][0]['file_uuid'];

                if ( !isset($df['files'][1]['parent_image_id']) || $df['files'][1]['parent_image_id'] != $df['files'][0]['id'] )
                    $this->fail('Image upload attempt #2 did not create a thumbnail');

                // Don't continue to look
                break;
            }
        }
        if ( $first_image_uuid === $second_image_uuid )
            $this->fail('Image upload #2 did not replace upload #1');
    }

    public function testFileImageUpload_Multiple()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $early_date = '1970-01-01 00:00:00';
        $late_date = '2100-01-01 00:00:00';

        // ----------------------------------------
        // Upload two files to the Multiple File field...
        $api_response_content = $this->uploadFile(
            self::$file_upload_basepath.'Image_14044.jpeg',
            self::$database_uuid,
            self::$field_uuids['Multiple File'],
            self::$record_uuid,
            self::$api_username,
            $early_date,   // might as well test create/public date here
            $early_date,
            '',    // already effectively tested quality
            0      // files should silently ignore display order
        );

        $api_response_content = $this->uploadFile(
            self::$file_upload_basepath.'Image_14044.jpeg',
            self::$database_uuid,
            self::$field_uuids['Multiple File'],
            self::$record_uuid,
            self::$api_username,
            $late_date,   // might as well test create/public date here
            $late_date,
            '',    // already effectively tested quality
            0      // files should silently ignore display order
        );

        // Don't need to compare against the new version of the actual record, since that's what
        //  ODR returned...verify that the file and its properties match instead
        foreach ($api_response_content['fields'] as $num => $df) {
            if ( $df['field_uuid'] == self::$field_uuids['Multiple File'] ) {
                if ( !isset($df['files'][0]['file_uuid']) )
                    $this->fail('File upload attempt #3 failed');
                if ( !isset($df['files'][1]['file_uuid']) )
                    $this->fail('File upload attempt #4 failed');

                if ( !isset($df['files'][0]['_file_metadata']['_create_date']) || $df['files'][0]['_file_metadata']['_create_date'] !== $early_date )
                    $this->fail('File upload #3 has wrong create date');
                if ( !isset($df['files'][0]['_file_metadata']['_public_date']) || $df['files'][0]['_file_metadata']['_public_date'] !== $early_date )
                    $this->fail('File upload #3 has wrong public date');

                if ( !isset($df['files'][1]['_file_metadata']['_create_date']) || $df['files'][1]['_file_metadata']['_create_date'] !== $late_date )
                    $this->fail('File upload #4 has wrong create date');
                if ( !isset($df['files'][1]['_file_metadata']['_public_date']) || $df['files'][1]['_file_metadata']['_public_date'] !== $late_date )
                    $this->fail('File upload #4 has wrong public date');

                // Don't continue to look
                break;
            }
        }


        // ----------------------------------------
        // Upload two files to the Multiple Image field...
        $api_response_content = $this->uploadFile(
            self::$file_upload_basepath.'Image_14044.jpeg',
            self::$database_uuid,
            self::$field_uuids['Multiple Image'],
            self::$record_uuid,
            self::$api_username,
            $early_date,   // might as well test create/public date here
            $early_date,
            '',    // already effectively tested quality
            1      // images should not silently ignore display order
        );

        $api_response_content = $this->uploadFile(
            self::$file_upload_basepath.'Image_14044.jpeg',
            self::$database_uuid,
            self::$field_uuids['Multiple Image'],
            self::$record_uuid,
            self::$api_username,
            $late_date,   // might as well test create/public date here
            $late_date,
            '',    // already effectively tested quality
            2      // images should not silently ignore display order
        );

        // Don't need to compare against the new version of the actual record, since that's what
        //  ODR returned...verify that the file and its properties match instead
        foreach ($api_response_content['fields'] as $num => $df) {
            if ( $df['field_uuid'] == self::$field_uuids['Multiple Image'] ) {
                if ( !isset($df['files'][0]['file_uuid']) )
                    $this->fail('Image upload attempt #3 failed');
                if ( !isset($df['files'][2]['file_uuid']) )    // thumbnail for the first image occupies indice 1
                    $this->fail('Image upload attempt #4 failed');

                if ( !isset($df['files'][0]['_file_metadata']['_create_date']) || $df['files'][0]['_file_metadata']['_create_date'] !== $early_date )
                    $this->fail('Image upload #3 has wrong create date');
                if ( !isset($df['files'][0]['_file_metadata']['_public_date']) || $df['files'][0]['_file_metadata']['_public_date'] !== $early_date )
                    $this->fail('Image upload #3 has wrong public date');
                if ( !isset($df['files'][0]['_file_metadata']['_display_order']) || $df['files'][0]['_file_metadata']['_display_order'] != 1 )
                    $this->fail('Image upload #3 has wrong display order');

                if ( !isset($df['files'][2]['_file_metadata']['_create_date']) || $df['files'][2]['_file_metadata']['_create_date'] !== $late_date )
                    $this->fail('Image upload #4 has wrong create date');
                if ( !isset($df['files'][2]['_file_metadata']['_public_date']) || $df['files'][2]['_file_metadata']['_public_date'] !== $late_date )
                    $this->fail('Image upload #4 has wrong public date');
                if ( !isset($df['files'][2]['_file_metadata']['_display_order']) || $df['files'][2]['_file_metadata']['_display_order'] != 2 )
                    $this->fail('Image upload #4 has wrong display order');

                // Don't continue to look
                break;
            }
        }
    }

    public function testFileModify()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        // ----------------------------------------
        // The file uploading tests didn't have a modified structure to save...so update it now
        self::$record_structure = self::getRecord( self::$record_uuid );
        $tmp_dataset = self::$record_structure;

        $field_num = null;
        foreach ($tmp_dataset['fields'] as $num => $df) {
            if ( $df['field_uuid'] == self::$field_uuids['Single File'] ) {
                $field_num = $num;
                break;
            }
        }

        // Might as well just change all three properties at once
        $tmp_dataset['fields'][$field_num]['files'][0]['created'] = '2222-02-22 22:22:22';
        $tmp_dataset['fields'][$field_num]['files'][0]['public_date'] = '2222-02-22 22:22:22';
        $tmp_dataset['fields'][$field_num]['files'][0]['quality'] = 0;    // was uploaded with a 1

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // ...also check inside _file_metadata, since that doesn't get checked by assertArrayEquals()
        if ( $api_response_content['fields'][$field_num]['files'][0]['_file_metadata']['_create_date'] !== '2222-02-22 22:22:22' )
            $this->fail('Attempt to update a file with create_date "2222-02-22 22:22:22" failed');
        if ( $api_response_content['fields'][$field_num]['files'][0]['_file_metadata']['_public_date'] !== '2222-02-22 22:22:22' )
            $this->fail('Attempt to update a file with public_date "2222-02-22 22:22:22" failed');
        if ( $api_response_content['fields'][$field_num]['files'][0]['_file_metadata']['_quality'] != 0 )
            $this->fail('Attempt to update the quality of a file failed');

        if ( self::$record_structure['fields'][$field_num]['files'][0]['_file_metadata']['_create_date'] !== '2222-02-22 22:22:22' )
            $this->fail('API call did not update a file to create_date "2222-02-22 22:22:22"');
        if ( self::$record_structure['fields'][$field_num]['files'][0]['_file_metadata']['_public_date'] !== '2222-02-22 22:22:22' )
            $this->fail('API call did not update a file to public_date "2222-02-22 22:22:22"');
        if ( self::$record_structure['fields'][$field_num]['files'][0]['_file_metadata']['_quality'] != 0 )
            $this->fail('API call did not update a file to quality 0');


        // ----------------------------------------
        // Files aren't allowed to set the display_order property
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][$field_num]['files'][0]['display_order'] = 999;

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);

        // Also ensure the structure is correct
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][$field_num]['files'] = array(
            'file_uuid' => 'asdf',
            'created' => 'zxcv',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);
    }

    public function testImageModify()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        // ----------------------------------------
        // Ensure a valid version of the record structure exists
        self::$record_structure = self::getRecord( self::$record_uuid );
        $tmp_dataset = self::$record_structure;

        $field_num = null;
        foreach ($tmp_dataset['fields'] as $num => $df) {
            if ( $df['field_uuid'] == self::$field_uuids['Single Image'] ) {
                $field_num = $num;
                break;
            }
        }

        // ----------------------------------------
        // Not allowed to change the thumbnail's properties
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'][$field_num]['files'][1]['display_order'] = 0;

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);


        // ----------------------------------------
        // Going to test two different uploads for images...
        $tmp_dataset = self::$record_structure;

        // The first is with the thumbnail in there...it won't be getting changed, so should have
        //  no error...
        $tmp_dataset['fields'][$field_num]['files'][0]['created'] = '2222-02-22 22:22:22';
        $tmp_dataset['fields'][$field_num]['files'][0]['public_date'] = '2222-02-22 22:22:22';
//        $tmp_dataset['fields'][$field_num]['files'][0]['quality'] = 0;    // was uploaded with a 1
//        $tmp_dataset['fields'][$field_num]['files'][0]['display_order'] = 999;

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // ...also check inside _file_metadata, since that doesn't get checked by assertArrayEquals()
        if ( $api_response_content['fields'][$field_num]['files'][0]['_file_metadata']['_create_date'] !== '2222-02-22 22:22:22' )
            $this->fail('Attempt to update an image with create_date "2222-02-22 22:22:22" failed');
        if ( $api_response_content['fields'][$field_num]['files'][0]['_file_metadata']['_public_date'] !== '2222-02-22 22:22:22' )
            $this->fail('Attempt to update an image with public_date "2222-02-22 22:22:22" failed');
        if ( $api_response_content['fields'][$field_num]['files'][1]['_file_metadata']['_create_date'] !== '2222-02-22 22:22:22' )
            $this->fail('Attempt to update an image with create_date "2222-02-22 22:22:22" failed in the thumbnail');
        if ( $api_response_content['fields'][$field_num]['files'][1]['_file_metadata']['_public_date'] !== '2222-02-22 22:22:22' )
            $this->fail('Attempt to update an image with public_date "2222-02-22 22:22:22" failed in the thumbnail');

        if ( self::$record_structure['fields'][$field_num]['files'][0]['_file_metadata']['_create_date'] !== '2222-02-22 22:22:22' )
            $this->fail('API call did not update an image to create_date "2222-02-22 22:22:22"');
        if ( self::$record_structure['fields'][$field_num]['files'][0]['_file_metadata']['_public_date'] !== '2222-02-22 22:22:22' )
            $this->fail('API call did not update an image to public_date "2222-02-22 22:22:22"');
        if ( self::$record_structure['fields'][$field_num]['files'][1]['_file_metadata']['_create_date'] !== '2222-02-22 22:22:22' )
            $this->fail('API call did not update an image thumbnail to create_date "2222-02-22 22:22:22"');
        if ( self::$record_structure['fields'][$field_num]['files'][1]['_file_metadata']['_public_date'] !== '2222-02-22 22:22:22' )
            $this->fail('API call did not update an image thumbnail to public_date "2222-02-22 22:22:22"');


        // ----------------------------------------
        // ...the second test will be to submit with the thumbnail removed
        unset( $tmp_dataset['fields'][$field_num]['files'][1] );

//        $tmp_dataset['fields'][$field_num]['files'][0]['created'] = '2222-02-22 22:22:22';
//        $tmp_dataset['fields'][$field_num]['files'][0]['public_date'] = '2222-02-22 22:22:22';
        $tmp_dataset['fields'][$field_num]['files'][0]['quality'] = 0;    // was uploaded with a 1
        $tmp_dataset['fields'][$field_num]['files'][0]['display_order'] = 999;

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );

        // ...but locally tweaked to also remove the thumbnail so the assertion doesn't complain
        $tmp_dataset = self::$record_structure;
        unset( $tmp_dataset['fields'][$field_num]['files'][1] );

        $this->assertArrayEquals($tmp_dataset, $api_response_content);

        // ...also check inside _file_metadata, since that doesn't get checked by assertArrayEquals()
        if ( $api_response_content['fields'][$field_num]['files'][0]['_file_metadata']['_quality'] != 0 )
            $this->fail('Attempt to update an image with quality 0 failed');
        if ( $api_response_content['fields'][$field_num]['files'][0]['_file_metadata']['_display_order'] != 999 )
            $this->fail('Attempt to update an image with display_order 999 failed');
        // No thumbnail to check
//        if ( $api_response_content['fields'][$field_num]['files'][1]['_file_metadata']['_quality'] != 0 )
//            $this->fail('Attempt to update an image with quality 0 failed for the thumbnail');
//        if ( $api_response_content['fields'][$field_num]['files'][1]['_file_metadata']['_display_order'] != 999 )
//            $this->fail('Attempt to update an image with display_order 999 failed for the thumbnail');

        if ( self::$record_structure['fields'][$field_num]['files'][0]['_file_metadata']['_quality'] != 0 )
            $this->fail('API call did not update an image to quality 0');
        if ( self::$record_structure['fields'][$field_num]['files'][0]['_file_metadata']['_display_order'] != 999 )
            $this->fail('API call did not update an image to display_order 999');
        // A fresh call will get the thumbnail though
        if ( self::$record_structure['fields'][$field_num]['files'][1]['_file_metadata']['_quality'] != 0 )
            $this->fail('API call did not update an image thumbnail to quality 0');
        if ( self::$record_structure['fields'][$field_num]['files'][1]['_file_metadata']['_display_order'] != 999 )
            $this->fail('API call did not update an image thumbnail to display_order 999');
    }

    public function testCreateRecord()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $count = 0;
        for ($i = 0; $i < 4; $i++) {
            if ( $count < 2 )
                $database_uuid = self::$descendant_database_uuids['API Test Single-allowed Link'];
            else
                $database_uuid = self::$descendant_database_uuids['API Test Multiple-allowed Link'];

            $post_data = array(
                'user_email' => self::$api_username,
            );

            $curl = new CurlUtility(
                self::$api_baseurl.'/v3/dataset/'.$database_uuid.'/record',
                self::$headers
            );

            $response = $curl->post($post_data);
            $code = $response['code'];
            $this->assertEquals(200, $code);
            $api_response_content = json_decode($response['response'], true);
//            if ( self::$debug )
//                fwrite(STDERR, 'api response: '.print_r($api_response_content, true)."\n");

            // Response should be a properly formed datarecord, though can't check everything due
            //  to it being new
            $this->assertArrayHasKey('database_uuid', $api_response_content);
            $this->assertEquals($database_uuid, $api_response_content['database_uuid']);
            $this->assertArrayHasKey('record_uuid', $api_response_content);

            // Store the uuids for later
            if ( !isset(self::$descendant_datarecord_uuids[$database_uuid]) )
                self::$descendant_datarecord_uuids[$database_uuid] = array();
            self::$descendant_datarecord_uuids[$database_uuid][] = $api_response_content['record_uuid'];
            $count++;
        }

        if ( self::$debug )
            fwrite(STDERR, 'descendant datarecords: '.print_r(self::$descendant_datarecord_uuids, true)."\n");
    }

    public function testMultipleAllowed_SingleChild()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $database_uuid = self::$descendant_database_uuids['API Test Single-allowed Child'];

        // ----------------------------------------
        // Should fail due to attempting to create two records in a single-allowed child descendant
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][0] = array(
            'database_uuid' => $database_uuid,
            'record_uuid' => '',    // Empty record uuid means create a new child record
        );
        $tmp_dataset['records'][2] = array(
            'database_uuid' => $database_uuid,
            'record_uuid' => '',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);


        // ----------------------------------------
        // Should succeed due to only creating a single child record
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][0] = array(
            'database_uuid' => $database_uuid,
            'record_uuid' => '',
        );
//        $tmp_dataset['records'][1] = array(
//            'database_uuid' => $database_uuid,
//            'record_uuid' => '',
//        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // Save the uuid in case it's needed later...
        self::$descendant_datarecord_uuids[$database_uuid] = array(
            0 => $api_response_content['records'][0]['record_uuid']
        );


        // ----------------------------------------
        // Should fail due to attempting to create a second records in a single-allowed child descendant
        $tmp_dataset = self::$record_structure;
//        $tmp_dataset['records'][0] = array(
//            'database_uuid' => $database_uuid,
//            'record_uuid' => '',
//        );
        $tmp_dataset['records'][1] = array(
            'database_uuid' => $database_uuid,
            'record_uuid' => '',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);
    }

    public function testMultipleAllowed_MultipleChild()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        // ----------------------------------------
        // Going to use this first one to also test
        // 1) adding a field value directly into a new child record
        // 2) setting the 'created' parameter for both a record and a field change
        $database_uuid = self::$descendant_database_uuids['API Test Multiple-allowed Child'];
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][1] = array(    // index 0 is occupied by the single-allowed child record
            'database_uuid' => $database_uuid,
            'record_uuid' => '',    // Empty record uuid means create a new child record
            'created' => '1970-01-01 00:00:00',
            'fields' => array(
                0 => array(
                    'field_uuid' => self::$field_uuids['Multiple Child Field'],
                    'value' => 'asdf',
                    'created' => '2070-01-01 00:00:00'    // the actual date is irrelevant
                )
            )
        );
//        $tmp_dataset['records'][2] = array(
//            'database_uuid' => $database_uuid,
//            'record_uuid' => '',
//        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // ...also check inside _record_metadata, since that doesn't get checked by assertArrayEquals()
        if ( $api_response_content['records'][1]['_record_metadata']['_create_date'] !== '1970-01-01 00:00:00' )
            $this->fail('Attempt to create a record with create_date "1970-01-01 00:00:00" failed');
        if ( self::$record_structure['records'][1]['_record_metadata']['_create_date'] !== '1970-01-01 00:00:00' )
            $this->fail('API call did not change a record to create_date "1970-01-01 00:00:00"');

        // Might as well ensure the new record's public_date is default, since it wasn't set
        if ( $api_response_content['records'][1]['_record_metadata']['_public_date'] !== '2200-01-01 00:00:00' )
            $this->fail('Creating a record without specifying a public date did not result in "2200-01-01 00:00:00"');
        if ( self::$record_structure['records'][1]['_record_metadata']['_public_date'] !== '2200-01-01 00:00:00' )
            $this->fail('API call did not change a record to public_date "2200-01-01 00:00:00"');

        if ( $api_response_content['records'][1]['fields'][0]['_field_metadata']['_create_date'] !== '2070-01-01 00:00:00' )
            $this->fail('Attempt to change a field to create_date "2070-01-01 00:00:00" failed');
        if ( self::$record_structure['records'][1]['fields'][0]['_field_metadata']['_create_date'] !== '2070-01-01 00:00:00' )
            $this->fail('API call did not change a field to create_date "2070-01-01 00:00:00"');


        // ----------------------------------------
        // Going to use the second one to test an invalid field in a child record...
        $database_uuid = self::$descendant_database_uuids['API Test Multiple-allowed Child'];
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][2] = array(    // index 0 is occupied by the single-allowed child record
            'database_uuid' => $database_uuid,
            'record_uuid' => '',    // Empty record uuid means create a new child record
            'fields' => array(
                0 => array(
                    'field_uuid' => substr(self::$field_uuids['Multiple Child Field'], 0, -1),
                    'value' => 'qwer',
                )
            )
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 404);


        // ----------------------------------------
        // The third one will actually test the multiple-allowed part, as well as testing the
        //  public_date flag
        $tmp_dataset = self::$record_structure;
//        $tmp_dataset['records'][1] = array(    // index 0 is occupied by the single-allowed child record
//            'database_uuid' => $database_uuid,
//            'record_uuid' => '',    // Empty record uuid means create a new child record
//        );
        $tmp_dataset['records'][2] = array(
            'database_uuid' => $database_uuid,
            'record_uuid' => '',
            'public_date' => '2000-01-01 00:00:00'
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);
//        if ( self::$debug )
//            fwrite(STDERR, 'submitted dr: '.print_r($api_response_content, true)."\n");

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // ...also check inside _record_metadata, since that doesn't get checked by assertArrayEquals()
        if ( $api_response_content['records'][2]['_record_metadata']['_public_date'] !== '2000-01-01 00:00:00' )
            $this->fail('Attempt to create a record with public_date "2000-01-01 00:00:00" failed');
        if ( self::$record_structure['records'][2]['_record_metadata']['_public_date'] !== '2000-01-01 00:00:00' )
            $this->fail('API call did not create a record with public_date "2000-01-01 00:00:00"');


        // Save the uuids of the two newly created child records
        self::$descendant_datarecord_uuids[$database_uuid] = array(
            0 => $api_response_content['records'][1]['record_uuid'],
            1 => $api_response_content['records'][2]['record_uuid'],
        );


        // ----------------------------------------
        // The next test should fail because the API Test database isn't related to the IMA List
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][3] = array(
            'database_uuid' => self::$other_database_uuid,
            'record_uuid' => '',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 404);


        // ----------------------------------------
        // Should fail due to being a linked descendant instead of a child of this datatype
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][3] = array(
            'database_uuid' => self::$descendant_database_uuids['API Test Multiple-allowed Link'],
            'record_uuid' => '',
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 404);
    }

    public function testMultipleAllowed_SingleLink()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $database_uuid = self::$descendant_database_uuids['API Test Single-allowed Link'];

        // ----------------------------------------
        // Should not be allowed to link to two records in a single-allowed linked descendant
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][3] = array(    // indices 1/2 are occupied by the multiple child records
            'database_uuid' => $database_uuid,
            'record_uuid' => self::$descendant_datarecord_uuids[$database_uuid][0],
        );
        $tmp_dataset['records'][4] = array(
            'database_uuid' => $database_uuid,
            'record_uuid' => self::$descendant_datarecord_uuids[$database_uuid][1],
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);


        // ----------------------------------------
        // Link to just one of them for this test...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][3] = array(
            'database_uuid' => $database_uuid,
            'record_uuid' => self::$descendant_datarecord_uuids[$database_uuid][0],
        );
//        $tmp_dataset['records'][4] = array(
//            'database_uuid' => $database_uuid,
//            'record_uuid' => self::$descendant_datarecord_uuids[$database_uuid][1],
//        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);


        // ----------------------------------------
        // ...then attempt to link to a second, which should fail due to already having one
        $tmp_dataset = self::$record_structure;
//        $tmp_dataset['records'][3] = array(
//            'database_uuid' => $database_uuid,
//            'record_uuid' => self::$descendant_datarecord_uuids[$database_uuid][0],
//        );
        $tmp_dataset['records'][4] = array(
            'database_uuid' => $database_uuid,
            'record_uuid' => self::$descendant_datarecord_uuids[$database_uuid][1],
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);
    }

    public function testMultipleAllowed_MultipleLink()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        $database_uuid = self::$descendant_database_uuids['API Test Multiple-allowed Link'];

        // ----------------------------------------
        // Should be able to link to more than one record in a multiple-allowed descendant
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][4] = array(    // index 3 is occupied by the single-allowed link record
            'database_uuid' => $database_uuid,
            'record_uuid' => self::$descendant_datarecord_uuids[$database_uuid][0],
        );
        $tmp_dataset['records'][5] = array(
            'database_uuid' => $database_uuid,
            'record_uuid' => self::$descendant_datarecord_uuids[$database_uuid][1],
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );
        $this->assertArrayEquals(self::$record_structure, $api_response_content);


        // ----------------------------------------
        // The next test should fail because the API Test database isn't related to the IMA List
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][6] = array(
            'database_uuid' => self::$other_database_uuid,
            'record_uuid' => self::$other_record_uuid,
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 404);


        // ----------------------------------------
        // Unlike the test in testMultipleAllowed_MultipleChild(), where the user attempts to create
        //  a linked descendant like a child...there is no sensible method to create a child record
        //  like a linked descendant
    }

    public function testDuplicateRecords()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        // Ensure that defining the same child record more than once fails
        $child_database_uuid = self::$descendant_database_uuids['API Test Multiple-allowed Child'];
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][6] = array(    // index 5 was the "last good record"
            'database_uuid' => $child_database_uuid,
            'record_uuid' => self::$descendant_datarecord_uuids[$child_database_uuid][0],
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);

        // Ensure that attempting to link to the same record more than once fails
        $linked_database_uuid = self::$descendant_database_uuids['API Test Multiple-allowed Link'];
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['records'][6] = array(
            'database_uuid' => $linked_database_uuid,
            'record_uuid' => self::$descendant_datarecord_uuids[$linked_database_uuid][0],
        );

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        self::submitRecord_invalid($post_data, 400);
    }

    public function testRecordDeletion()
    {
        if ( self::$force_skip )
            $this->markTestSkipped('Wrong database');

        // ----------------------------------------
        // Going to delete the single-allowed child record...
        $tmp_dataset = self::$record_structure;
//        if ( self::$debug )
//            fwrite(STDERR, 'original data: '.print_r($tmp_dataset, true)."\n");

        foreach ($tmp_dataset['records'] as $num => $dr) {
            if ( $dr['database_uuid'] === self::$descendant_database_uuids['API Test Single-allowed Child'] ) {
                unset( $tmp_dataset['records'][$num] );
                break;
            }
        }
//        if ( self::$debug )
//            fwrite(STDERR, 'submitted data: '.print_r($tmp_dataset, true)."\n");

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );

        // The trick here is that the "submitted" record only had indices 1 through 4, due to deleting
        //  the child record at indice 0...while the "actual" record now has indices 0 through 3
        //  since it got rebuilt following the modification.

        // In order for self::assertArrayEquals() to not flip out, it's necessary to tweak the
        //  indices of the response...
        $tweaked_record_list = array();
        foreach ($api_response_content['records'] as $submitted_num => $submitted_dr) {
            $dr_uuid = $submitted_dr['record_uuid'];

            // ...find the same record in the newly-acquired "actual" data...
            foreach (self::$record_structure['records'] as $actual_num => $actual_dr) {
                // ...and then set the record from the "submitted" data to have the same indice as
                //  the "actual" data
                if ( $dr_uuid === $actual_dr['record_uuid'] )
                    $tweaked_record_list[$actual_num] = $submitted_dr;
            }
        }
        $api_response_content['records'] = $tweaked_record_list;
//        if ( self::$debug )
//            fwrite(STDERR, 'tweaked content 1: '.print_r($api_response_content, true)."\n");

        // Verify that the rest of the array is accurate after the fix
        $this->assertArrayEquals(self::$record_structure, $api_response_content);


        // ----------------------------------------
        // Going to also unlink the single-allowed linked record...
        $tmp_dataset = self::$record_structure;
        $linked_record_uuid = null;
        foreach ($tmp_dataset['records'] as $num => $dr) {
            if ( $dr['database_uuid'] === self::$descendant_database_uuids['API Test Single-allowed Link'] ) {
                $linked_record_uuid = $dr['record_uuid'];
                unset( $tmp_dataset['records'][$num] );
                break;
            }
        }

        $post_data = array(
            'user_email' => self::$api_username,
            'dataset' => $tmp_dataset,
        );
        $api_response_content = self::submitRecord_valid($post_data);

        // Compare against the new version of the actual record...
        self::$record_structure = self::getRecord( self::$record_uuid );

        // Unlinking a record has the same issue as deleting one...the indices no longer match
        $tweaked_record_list = array();
        foreach ($api_response_content['records'] as $submitted_num => $submitted_dr) {
            $dr_uuid = $submitted_dr['record_uuid'];

            // ...find the same record in the newly-acquired "actual" data...
            foreach (self::$record_structure['records'] as $actual_num => $actual_dr) {
                // ...and then set the record from the "submitted" data to have the same indice as
                //  the "actual" data
                if ( $dr_uuid === $actual_dr['record_uuid'] )
                    $tweaked_record_list[$actual_num] = $submitted_dr;
            }
        }
        $api_response_content['records'] = $tweaked_record_list;
//        if ( self::$debug )
//            fwrite(STDERR, 'tweaked content 2: '.print_r($api_response_content, true)."\n");

        $this->assertArrayEquals(self::$record_structure, $api_response_content);

        // Ensure the linked record still exists...do not want to have deleted it
        self::getRecord($linked_record_uuid);

    }

}
