<?php

namespace ODR\AdminBundle\Tests\Controller;

use ODR\AdminBundle\Component\Service\CacheService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use ODR\AdminBundle\Component\Utility\CurlUtility;

class APIControllerTest_alt extends WebTestCase
{
    /** @var CacheService $cache_service */
    private static $cache_service;

    public static $debug = false;
    public static $force_skip = false;

    public static $api_baseurl = '';
    public static $api_username = '';
    public static $api_user_password = '';

    public static $token = '';
    public static $headers = array();

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
            if ( $key === '_record_metadata' || $key === '_field_metadata' )
                continue;

            // Intentionally only asserting when it would fail...
            if ( !isset($actual[$key]) )
                $this->assertArrayHasKey($key, $actual, 'Failed asserting that $actual['.$key.'] exists for path "'.implode(" > ", $rootPath).'"');

            $keyPath = $rootPath;
            $keyPath[] = $key;

            if ( isset($actual[$key]) ) {
                if ( is_array($value) )
                    $this->assertArrayEquals($value, $actual[$key], $keyPath);
                else if ( $value != $actual[$key] )
                    $this->assertEquals($value, $actual[$key], 'Failed asserting that $actual value "'.$actual[$key].'" matches expected "'.$value.'" for path "'.implode(" > ", $keyPath).'"');
            }
        }

        // ...and need to run the inverse for when $actual has a key $expected doesn't
        foreach ($actual as $key => $value)
        {
            if ( $key === '_record_metadata' || $key === '_field_metadata' )
                continue;

            // Intentionally only asserting when it would fail...
            if ( !isset($expected[$key]) )
                $this->assertArrayHasKey($key, $actual, 'Failed asserting that $expected['.$key.'] exists for path "'.implode(" > ", $rootPath).'"');

            $keyPath = $rootPath;
            $keyPath[] = $key;

            if ( isset($expected[$key]) ) {
                if ( is_array($value) )
                    $this->assertArrayEquals($value, $expected[$key], $keyPath);
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
//        if ( self::$debug )
//            fwrite(STDERR, 'returned dr structure: '.print_r($content, true)."\n");
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
        self::$cache_service = $client->getContainer()->get('odr.cache_service');

        if ( $client->getContainer()->getParameter('database_name') !== 'odr_theta_2' )
            self::$force_skip = true;

        $basepath = $client->getContainer()->getParameter('odr_tmp_directory').'/../..';
        if ( !file_exists($basepath.'/phpunit_testing.dmp') )
            self::$force_skip = true;
        if ( filesize($basepath.'/phpunit_testing.dmp') === 0 )
            self::$force_skip = true;
    }

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

    public function testCreateRecord()
    {
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

        if ( $api_response_content['records'][1]['_record_metadata']['_create_date'] !== '1970-01-01 00:00:00' )
            $this->fail('Attempt to create a record with create_date "1970-01-01 00:00:00" failed');
        if ( self::$record_structure['records'][1]['_record_metadata']['_create_date'] !== '1970-01-01 00:00:00' )
            $this->fail('Attempt to create a record with create_date "1970-01-01 00:00:00" failed');

        // Might as well ensure the new record's public_date is default, since it wasn't set
        if ( $api_response_content['records'][1]['_record_metadata']['_public_date'] !== '2200-01-01 00:00:00' )
            $this->fail('Creating a record without specifying a public date did not result in "2200-01-01 00:00:00"');
        if ( self::$record_structure['records'][1]['_record_metadata']['_public_date'] !== '2200-01-01 00:00:00' )
            $this->fail('Creating a record without specifying a public date did not result in "2200-01-01 00:00:00"');

        if ( $api_response_content['records'][1]['fields'][0]['_field_metadata']['_create_date'] !== '2070-01-01 00:00:00' )
            $this->fail('Attempt to change a field with create_date "2070-01-01 00:00:00" failed');
        if ( self::$record_structure['records'][1]['fields'][0]['_field_metadata']['_create_date'] !== '2070-01-01 00:00:00' )
            $this->fail('Attempt to change a field with create_date "2070-01-01 00:00:00" failed');


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

        if ( $api_response_content['records'][2]['_record_metadata']['_public_date'] !== '2000-01-01 00:00:00' )
            $this->fail('Attempt to create a record with public_date "2000-01-01 00:00:00" failed');
        if ( self::$record_structure['records'][2]['_record_metadata']['_public_date'] !== '2000-01-01 00:00:00' )
            $this->fail('Attempt to create a record with public_date "2000-01-01 00:00:00" failed');


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
