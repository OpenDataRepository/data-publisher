<?php

namespace ODR\AdminBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use ODR\AdminBundle\Component\Utility\CurlUtility;

class APIControllerTest_alt extends WebTestCase
{
    public static $debug = false;

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
    public static $record_list = array();
    public static $record_uuid = '';
    public static $record_structure = array();

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
    }

    public static function tearDownAfterClass()
    {
        exec('mysql --login-path=testing '.getenv('API_TESTING_DB').' < phpunit_testing.dmp');
        exec('redis-cli flushall');
    }

    public function testLogin()
    {
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
                self::$database_uuid = $dt['unique_id'];
                self::$template_uuid = $dt['template_id'];
                break;
            }
        }
        if ( self::$debug )
            fwrite(STDERR, 'dt uuid: '.self::$database_uuid."\n");
    }

    public function testGetDatabaseStructure()
    {
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

        foreach (self::$database_structure['fields'] as $num => $df)
            self::$field_uuids[ $df['name'] ] = $df['field_uuid'];
        if ( self::$debug )
            fwrite(STDERR, 'fields: '.print_r(self::$field_uuids, true)."\n");

        // Going to be using these fields...
        $this->assertArrayHasKey('Single Select', self::$field_uuids);
        $this->assertArrayHasKey('Multiple Select', self::$field_uuids);
        $this->assertArrayHasKey('Short Text', self::$field_uuids);
    }

    public function testGetRecordList()
    {
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
    }

    public function testGetRecord()
    {
        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record/'.self::$record_uuid,
            self::$headers
        );

        $response = $curl->get();
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
        self::$record_structure = $content;
        if ( self::$debug )
            fwrite(STDERR, 'dr structure: '.print_r(self::$record_structure, true)."\n");

        // Don't want any selections at this point...
        $this->assertEmpty(self::$record_structure['fields']);
    }

    public function testRecordSave_Invalid()
    {
        $this->markTestSkipped('no verification implemented yet...');
    }

    public function testRecordSave_NoPerms()
    {
        $this->markTestSkipped('multiple different ways a user could have no permissions...');
    }

    public function testRecordSave_Missing()
    {
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['record_uuid'] = '';

        $post_data = json_encode(
            array(
                'user_email' => self::$api_username,
                'dataset' => $tmp_dataset,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $response = $curl->post($post_data);
//        if ( self::$debug )
//            fwrite(STDERR, 'response: '.print_r($response, true)."\n");
        $code = $response['code'];

        // Submitting a record with an invalid uuid should result in a 404 error
        $this->assertEquals(404, $code);
    }

    public function testRecordSave_NoModification()
    {
        $post_data = json_encode(
            array(
                'user_email' => self::$api_username,
                'dataset' => self::$record_structure,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $response = $curl->post($post_data);
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);

        $this->assertEqualsCanonicalizing(self::$record_structure, $content);
    }

    public function testFieldSave_NoPerms()
    {
        $this->markTestSkipped('multiple different ways a user could have no permissions...');
    }

    public function testFieldSave_Missing()
    {
        // ----------------------------------------
        // Entries without a uuid identifier are invalid...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'] = array(
            0 => array(
                'value' => '',
            )
        );

        $post_data = json_encode(
            array(
                'user_email' => self::$api_username,
                'dataset' => $tmp_dataset,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $response = $curl->post($post_data);
        $code = $response['code'];
        $this->assertEquals(400, $code, 'array is missing a field uuid');


        // ----------------------------------------
        // Entries with a blank uuid are invalid...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'] = array(
            0 => array(
                'field_uuid' => '',
                'value' => '',
            )
        );

        $post_data = json_encode(
            array(
                'user_email' => self::$api_username,
                'dataset' => $tmp_dataset,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $response = $curl->post($post_data);
        $code = $response['code'];
        $this->assertEquals(404, $code, 'array has a blank field uuid');

        // Entries with an invalid uuid are also invalid...
        $tmp_dataset = self::$record_structure;
        $tmp_dataset['fields'] = array(
            0 => array(
                'field_uuid' => substr(self::$field_uuids['Short Text'], 0, -1),
                'value' => '',
            )
        );

        $post_data = json_encode(
            array(
                'user_email' => self::$api_username,
                'dataset' => $tmp_dataset,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $response = $curl->post($post_data);
        $code = $response['code'];
        $this->assertEquals(404, $code, 'array has a non-existent field uuid');
    }

    public function testSingleSelect_OneSelection()
    {
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

        $post_data = json_encode(
            array(
                'user_email' => self::$api_username,
                'dataset' => $tmp_dataset,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $response = $curl->post($post_data);
        $code = $response['code'];
        $this->assertEquals(200, $code);
        $api_response_content = json_decode($response['response'], true);
        unset( $api_response_content['_record_metadata'] );
//        if ( self::$debug )
//            fwrite(STDERR, 'api return structure: '.print_r($api_response_content, true)."\n");

        // Compare against the new version of the actual record...
        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record/'.self::$record_uuid,
            self::$headers
        );

        $response = $curl->get();
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
        unset( $content['_record_metadata'] );
        self::$record_structure = $content;
//        if ( self::$debug )
//            fwrite(STDERR, 'modified dr structure: '.print_r(self::$record_structure, true)."\n");

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

        $post_data = json_encode(
            array(
                'user_email' => self::$api_username,
                'dataset' => $tmp_dataset,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $response = $curl->post($post_data);
        $code = $response['code'];
        $this->assertEquals(200, $code);
        $api_response_content = json_decode($response['response'], true);
        unset( $api_response_content['_record_metadata'] );
//        if ( self::$debug )
//            fwrite(STDERR, 'api return structure: '.print_r($api_response_content, true)."\n");

        // Compare against the new version of the actual record...
        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record/'.self::$record_uuid,
            self::$headers
        );

        $response = $curl->get();
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
        unset( $content['_record_metadata'] );
        self::$record_structure = $content;
//        if ( self::$debug )
//            fwrite(STDERR, 'modified dr structure: '.print_r(self::$record_structure, true)."\n");

        $this->assertArrayEquals(self::$record_structure, $api_response_content);
    }

    public function testSingleSelect_MoreThanOneSelection()
    {
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

        $post_data = json_encode(
            array(
                'user_email' => self::$api_username,
                'dataset' => $tmp_dataset,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $response = $curl->post($post_data);
        $code = $response['code'];
        $this->assertEquals(400, $code);

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

        $post_data = json_encode(
            array(
                'user_email' => self::$api_username,
                'dataset' => $tmp_dataset,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $response = $curl->post($post_data);
        $code = $response['code'];
        $this->assertEquals(400, $code);


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

        $post_data = json_encode(
            array(
                'user_email' => self::$api_username,
                'dataset' => $tmp_dataset,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $response = $curl->post($post_data);
        $code = $response['code'];
        $this->assertEquals(200, $code);
        $api_response_content = json_decode($response['response'], true);
        unset( $api_response_content['_record_metadata'] );
//        if ( self::$debug )
//            fwrite(STDERR, 'api return structure: '.print_r($api_response_content, true)."\n");

        // Compare against the new version of the actual record...
        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record/'.self::$record_uuid,
            self::$headers
        );

        $response = $curl->get();
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
        unset( $content['_record_metadata'] );
        self::$record_structure = $content;
//        if ( self::$debug )
//            fwrite(STDERR, 'modified dr structure: '.print_r(self::$record_structure, true)."\n");

        $this->assertArrayEquals(self::$record_structure, $api_response_content);


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

        $post_data = json_encode(
            array(
                'user_email' => self::$api_username,
                'dataset' => $tmp_dataset,
            )
        );

        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record',
            self::$headers
        );

        $response = $curl->post($post_data);
        $code = $response['code'];
        $this->assertEquals(200, $code);
        $api_response_content = json_decode($response['response'], true);
        unset( $api_response_content['_record_metadata'] );
//        if ( self::$debug )
//            fwrite(STDERR, 'api return structure: '.print_r($api_response_content, true)."\n");

        // Compare against the new version of the actual record...
        $curl = new CurlUtility(
            self::$api_baseurl.'/v3/dataset/record/'.self::$record_uuid,
            self::$headers
        );

        $response = $curl->get();
        $code = $response['code'];
        $this->assertEquals(200, $code);

        $content = json_decode($response['response'], true);
        unset( $content['_record_metadata'] );
        self::$record_structure = $content;
//        if ( self::$debug )
//            fwrite(STDERR, 'modified dr structure: '.print_r(self::$record_structure, true)."\n");

        $this->assertArrayEquals(self::$record_structure, $api_response_content);
    }
}
