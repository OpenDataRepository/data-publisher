<?php

namespace ODR\AdminBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use ODR\AdminBundle\Component\Utility\CurlUtility;

class APIControllerTest extends WebTestCase
{
    public static $client = "";

    public static $token = "";
    public static $headers = array();

    // public static $base_url = "https://ahed-dev.nasawestprime.com/ahed-api/api/v3";
    // public static $base_url = "http://office-dev/app_dev.php/api/v3";
    // public static $base_url = "http://localhost:8000/app_dev.php/api/v3";
    public static $base_url = "http://eta.odr.io/api/v3";

    public static $template_uuid = "2ea627b";

    public static $created_dataset = [];
    public static $created_datarecord = [];

    public static $template_data = [];

    /**
     *
     * Retrieve a token for data access
     *
     */
    public function testToken()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);
        // $timer = (getenv("TIMER") == "1" ? true : false);

        $post_data = json_encode(array(
            'username' => 'nate@opendatarepository.org',
            'password' => 'n518se'
        ));

        $cp = new CurlUtility(
            self::$base_url . '/token',
            array('Content-type: application/json'),
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->post($post_data);
        $content = $response['response'];

        // Show the actual content if debug enabled.
        ($debug ? fwrite(STDERR, 'Token Data:' . $content) : '');

        $token = json_decode($content, true);

        if (!is_array($token)) {
            ($debug ? fwrite(STDERR, $token) . "\n" : '');
        }

        // Token value should be set
        $this->assertTrue(isset($token['token']));

        self::$token = $token['token'];
        self::$headers = array(
            'HTTP_AUTHORIZATION' => "Bearer {$token['token']}",
        );

        ($debug ? fwrite(STDERR, print_r(self::$headers, true) . "\n") : '');
    }

    /**
     *
     * Retrieve the template schema
     *
     */
    public function testTemplate()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;
        $headers[] = 'Content-type: application/json';

        ($debug ? fwrite(STDERR, "Getting template.\n") : '');
        $cp = new CurlUtility(
            self::$base_url . '/search/template/' . self::$template_uuid,
            $headers,
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->get();
        $content = $response['response'];

        // Show the actual content if debug enabled.
        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n") : '');

        $template = json_decode($content, true);

        if (!is_array($template)) {
            ($debug ? fwrite(STDERR, $content) . "\n" : '');
        }

        // Should redirect to login
        $this->assertTrue(isset($template['name']));

        if ($debug && isset($template['name'])) {
            self::$template_data = $template;
            fwrite(STDERR, $template['name'] . "\n");
        }
    }

    /**
     * Check a user's login and dataset tree
     */
    public function testUser()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;

        ($debug ? fwrite(STDERR, 'Content: ' . print_r($headers, true) . "\n") : '');

        $post_data = array(
            'user_email' => 'nathan.a.stone@nasa.gov',
            'first_name' => 'Nathan',
            'last_name' => 'Stone',
        );

        $cp = new CurlUtility(
            self::$base_url . '/user',
            $headers,
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->post($post_data);
        ($debug ? fwrite(STDERR, 'Content: ' . print_r($response, true) . "\n") : '');

        $user = json_decode($response['response'], true);
        ($debug ? fwrite(STDERR, 'User: ' . print_r($user, true) . "\n") : '');

        // Should have the user_email at least
        $this->assertTrue(isset($user['user_email']));
    }

    /**
     * Create a database from template
     */
    public function testCreate()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;

        $post_data = array(
            'user_email' => 'nathan.a.stone@nasa.gov',
            'first_name' => 'Nathan',
            'last_name' => 'Stone',
            'template_uuid' => self::$template_uuid,
        );

        $cp = new CurlUtility(
            self::$base_url . '/dataset',
            $headers,
            false,
            true
        );

        $response = $cp->post($post_data);
        $code = $response['code'];
        ($debug ? fwrite(STDERR, 'Response Code: ' . $code . "\n") : '');
        ($debug ? fwrite(STDERR, 'Dataset: ' . $response['response']) : '');
        $created_dataset = json_decode($response['response'], true);
        ($debug ? fwrite(STDERR, 'Dataset: ' . print_r($created_dataset, true) . "\n") : '');
        self::$created_dataset = array(
            'user_email' => 'nathan.a.stone@nasa.gov',
            'dataset' => $created_dataset
        );
        ($debug ? fwrite(STDERR, 'Dataset UUID AA: ' . self::$created_dataset['dataset']['database_uuid'] . "\n") : '');

        // Should have the user_email at least
        $this->assertTrue($code == 200);
    }

    /**
     * Update the dataset name
     */
    public function testUpdateName()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;
        for ($i = 0; $i < count(self::$created_dataset['dataset']['fields']); $i++) {
            $field = self::$created_dataset['dataset']['fields'][$i];
            if ($field['template_field_uuid'] == '08088a9') {
                // Name field update name
                $field['value'] = "Test Dataset " . rand(1000000, 9999999);
                self::$created_dataset['dataset']['fields'][$i] = $field;
            }
        }

        $put_data = json_encode(self::$created_dataset);
        $cp = new CurlUtility(
            self::$base_url . '/dataset',
            $headers,
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->put($put_data);
        $code = json_decode($response['code'], true);
        /*
        $updated_dataset = json_decode($response['response'], true);
        self::$created_dataset['dataset'] = $updated_dataset;
        */
        ($debug ? fwrite(STDERR, 'Dataset UUID: ' . self::$created_dataset['dataset']['database_uuid'] . "\n") : '');

        // Should have the user_email at least
        $this->assertTrue($code == 302 || $code == 200);
    }


    public function testAddPerson()
    {

        $add_person = '{
                "template_uuid": "ce17e42",
                "fields": [
                    {
                        "field_name": "First Name",
                        "template_field_uuid": "0143860",
                        "value": "John_' . rand(100000, 999999) . '"
                    },
                    {
                        "field_name": "Last Name",
                        "template_field_uuid": "4d9ea52",
                        "value": "Doe_' . rand(100000, 999999) . '"
                    },
                    {
                        "field_name": "Contact Email",
                        "template_field_uuid": "e3dcbc9",
                        "value": "random_person_' . rand(100000, 999999) . '@nasa.gov"
                    },
                    {
                        "field_name": "Person Website",
                        "template_field_uuid": "9ba0f2f",
                        "value": ""
                    }
                ],
                "records": [
                    {
                        "name": "Institution",
                        "template_uuid": "870a2f7",
                        "fields": [
                            {
                                "name": "Sub unit name",
                                "template_field_uuid": "0b8a9f3",
                                "value": "Sub_Unit_' . rand(100000000, 999999999) . '"
                            }
                        ],
                        "records": [
                            {
                                "name": "Postal Address",
                                "template_uuid": "95f9363",
                                "fields": [
                                    {
                                        "name": "City",
                                        "template_field_uuid": "3503e92",
                                        "value": "City_' . rand(100000000, 999999999) . '"
                                    },
                                    {
                                        "name": "State/Province (Only USA and Canada)",
                                        "template_field_uuid": "062df8b",
                                        "value": [
                                            {
                                                "name": "South Carolina",
                                                "template_radio_option_uuid": "f9976ab",
                                                "updated_at": "2018-09-24 14:36:34"
                                            }
                                        ]
                                    },
                                    {
                                        "name": "Country",
                                        "template_field_uuid": "c7d1a2e",
                                        "value": [
                                            {
                                                "name": "Belgium",
                                                "template_radio_option_uuid": "d144c0b",
                                                "updated_at": "2018-09-24 14:38:45"
                                            }
                                        ]
                                    }
                                ]
                            }
                        ]
                    }
                ]
            }';

        $person_data = json_decode($add_person);

        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;
        self::$created_dataset['dataset']['records'][] = $person_data;

        $put_data = json_encode(self::$created_dataset);
        $cp = new CurlUtility(
            self::$base_url . '/dataset',
            $headers,
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->put($put_data);
        $code = json_decode($response['code'], true);
        /*
        $updated_dataset = json_decode($response['response'], true);
        self::$created_dataset['dataset'] = $updated_dataset;
        */
        ($debug ? fwrite(STDERR, 'Dataset UUID: ' . self::$created_dataset['dataset']['database_uuid'] . "\n") : '');

        // Should have the user_email at least
        $this->assertTrue($code == 302 || $code == 200);
    }


    /*
    public function testAddInstitution()
    {

        // Add Institution
        $institution_template = '{
            "name": "Institution",
            "template_uuid": "870a2f7",
            "fields": [
                {
                    "name": "Sub unit name",
                    "template_field_uuid": "0b8a9f3",
                    "value": "Sub_Unit_' . rand(100000000, 999999999) . '"
                }
            ],
            "records": [
                {
                    "name": "Postal Address",
                    "template_uuid": "95f9363",
                    "fields": [
                        {
                            "name": "City",
                            "template_field_uuid": "3503e92",
                            "value": "City_' . rand(100000000, 999999999) . '"
                        },
                        {
                            "name": "State/Province (Only USA and Canada)",
                            "template_field_uuid": "062df8b",
                            "value": [
                                {
                                    "name": "South Carolina",
                                    "template_radio_option_uuid": "f9976ab",
                                    "updated_at": "2018-09-24 14:36:34"
                                }
                            ]
                        },
                        {
                            "name": "Country",
                            "template_field_uuid": "c7d1a2e",
                            "value": [
                                {
                                    "name": "Belgium",
                                    "template_radio_option_uuid": "d144c0b",
                                    "updated_at": "2018-09-24 14:38:45"
                                }
                            ]
                        }
                    ]
                }
            ]
                
        }';


        $institution_data = json_decode($institution_template);

        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;
        self::$created_dataset['dataset']['records'][] = $institution_data;

        $put_data = json_encode(self::$created_dataset);
        $cp = new CurlUtility(
            self::$base_url . '/dataset',
            $headers,
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->put($put_data);
        $code = json_decode($response['code'], true);
        ($debug ? fwrite(STDERR, 'Code: ' . $code . ' -- Dataset UUID: ' . self::$created_dataset['dataset']['database_uuid'] . "\n") : '');
        ($debug ? fwrite(STDERR, 'Dataset (updated): ' . $response['response'] . "\n") : '');

        self::$created_dataset['dataset'] = json_decode($response['response'], true);

        // Should have the user_email at least
        $this->assertTrue($code == 302 || $code == 200);
    }
    */

    // get actual data record
    public function testGetDataset()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == "DataRecordFile" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;
        $headers[] = 'Content-type: application/json';

        ($debug ? fwrite(STDERR, "Getting data record.\n") : '');
        $url = self::$base_url . '/dataset/' . self::$created_dataset['dataset']['database_uuid'];
        ($debug ? fwrite(STDERR, "URL: " . $url . "\n") : '');
        $cp = new CurlUtility(
            $url,
            $headers,
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->get();
        $content = $response['response'];

        // Show the actual content if debug enabled.
        ($debug ? fwrite(STDERR, 'Dataset Content Pulled: ' . $content . "\n") : '');

        self::$created_dataset['dataset'] = json_decode($content, true);

        // Should redirect to login
        $this->assertTrue(isset(self::$created_dataset['dataset']['record_uuid']));
    }


    // get actual data record
    public function testGetDataRecord()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == "DataRecordFile" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;
        $headers[] = 'Content-type: application/json';

        ($debug ? fwrite(STDERR, "Getting data record.\n") : '');
        $url = self::$base_url . '/dataset/' . self::$created_dataset['dataset']['metadata_for_uuid'];
        ($debug ? fwrite(STDERR, "URL: " . $url . "\n") : '');
        $cp = new CurlUtility(
            $url,
            $headers,
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->get();
        $content = $response['response'];


        // Show the actual content if debug enabled.
        ($debug ? fwrite(STDERR, 'Content pulled: ' . $content . "\n") : '');

        self::$created_datarecord = self::$created_dataset;
        self::$created_datarecord['dataset'] = json_decode($content, true);

        if (!is_array(self::$created_datarecord['dataset'])) {
            ($debug ? fwrite(STDERR, $content) . "\n" : '');
        }

        // Should redirect to login
        $this->assertTrue(isset(self::$created_datarecord['dataset']['record_uuid']));

        if ($debug && isset(self::$created_datarecord['dataset']['record_uuid'])) {
            fwrite(STDERR, "Record UUID:: " . self::$created_datarecord['dataset']['record_uuid'] . "\n");
        }
    }

    public function testAddDataFile()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        // Add Data File Record
        $datafile_template = '
            {
                "template_uuid":"823bb3f",
                "fields":[
                    {
                        "template_field_uuid":"47f24cc0bd542e622657a433264a",
                        "value":"File Name Test"
                    }
                ]
            }';

        $datafile_data = json_decode($datafile_template, true);

        $headers[] = 'Authorization: Bearer ' . self::$token;
        self::$created_datarecord['dataset']['records'][] = $datafile_data;

        $put_data = json_encode(self::$created_datarecord);
        $url = self::$base_url . '/dataset';
        ($debug ? fwrite(STDERR, "URL: " . $url . "\n") : '');
        ($debug ? fwrite(STDERR, "PUT CONTENT: " . $put_data . "\n") : '');
        $cp = new CurlUtility(
            $url,
            $headers,
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->put($put_data);
        $updated_dataset = json_decode($response['response'], true);
        // self::$created_datarecord['dataset'] = $updated_dataset;
        ($debug ? fwrite(STDERR, 'Updated dataset: ' . var_export($updated_dataset, true) . "\n") : '');

        $code = json_decode($response['code'], true);
        ($debug ? fwrite(STDERR, 'Datarecord UUID: ' . self::$created_datarecord['dataset']['database_uuid'] . "\n") : '');

        $this->assertTrue($code == 302 || $code == 200);
    }

    // get actual data record
    public function testUpdateDataRecord()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;
        $headers[] = 'Content-type: application/json';

        ($debug ? fwrite(STDERR, "Getting Updated Data Record.\n") : '');
        $cp = new CurlUtility(
            self::$base_url . '/dataset/' . self::$created_dataset['dataset']['metadata_for_uuid'],
            $headers,
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->get();
        $content = $response['response'];

        // Show the actual content if debug enabled.
        ($debug ? fwrite(STDERR, 'Content pulled: ' . $content . "\n") : '');

        self::$created_datarecord['dataset'] = json_decode($content, true);

        if (!is_array(self::$created_datarecord['dataset'])) {
            ($debug ? fwrite(STDERR, $content) . "\n" : '');
        }

        // Should redirect to login
        $this->assertTrue(isset(self::$created_datarecord['dataset']['record_uuid']));

        if ($debug && isset(self::$created_datarecord['dataset']['record_uuid'])) {
            fwrite(STDERR, "Record UUID:: " . self::$created_datarecord['dataset']['record_uuid'] . "\n");
        }
    }

    /**
     * Retrieve the updated data
     */
    public function testDataRecordFile()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__ || getenv("DEBUG") == "DataRecordFile") ? true : false);


        // Figure out which record of datarecord is the new file placeholder

        // initialise the curl request
        $request = curl_init(self::$base_url . '/file?XDEBUG_SESSION_START=phpstorm_xdebug');
        // $request = curl_init(self::$base_url . '/file');

        // send a file
        curl_setopt($request, CURLOPT_POST, true);

        curl_setopt($request, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . self::$token
        ));

        $file_name = __DIR__  . '/../../TestResources/Image_14044.jpeg';
        ($debug ? fwrite(STDERR, $file_name) : '');

        $curl_file = '@' . realpath($file_name);
        if (function_exists('curl_file_create')) { // php 5.5+
            $curl_file = curl_file_create($file_name);
        }

        ($debug ? fwrite(STDERR, 'dataset_uuid => ' . self::$created_datarecord['dataset']['records'][0]['database_uuid']) : '');
        ($debug ? fwrite(STDERR, 'record_uuid => ' . self::$created_datarecord['dataset']['records'][0]['record_uuid']) : '');

        curl_setopt(
            $request,
            CURLOPT_POSTFIELDS,
            array(
                'name' => 'Test File Name',
                'dataset_uuid' => self::$created_datarecord['dataset']['records'][0]['database_uuid'],
                'record_uuid' => self::$created_datarecord['dataset']['records'][0]['record_uuid'],
                'template_field_uuid' => '3d51d4ca9d3fccd4f182a56c259e',
                'user_email' => 'nathan.a.stone@nasa.gov',
                'file' => $curl_file
            ));

        // output the response
        ($debug ? fwrite(STDERR, 'TESZ TEST TEST TSETSTE STE STET') : '');
        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($request);
        ($debug ? fwrite(STDERR, 'TESZ TEST TEST TSETSTE STE STET') : '');
        ($debug ? fwrite(STDERR, '\nHELLO: ' . print_r($response)) : '');

        $http_status = curl_getinfo($request, CURLINFO_HTTP_CODE);
        ($debug ? fwrite(STDERR, $http_status) : '');
        $this->assertTrue($http_status == 302 || $http_status == 200);

        // close the session
        curl_close($request);

    }

    /**
     * Post Image File with CURL
     */
    /*
    public function testDatasetImagePost()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);


        // initialise the curl request
        $request = curl_init(self::$base_url . '/file?XDEBUG_SESSION_START=phpstorm_xdebug');
        // $request = curl_init(self::$base_url . '/file');

        // send a file
        curl_setopt($request, CURLOPT_POST, true);

        curl_setopt($request, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . self::$token
        ));

        $file_name = '/home/nate/data-publisher/Henry_Fishing.jpg';
        ($debug ? fwrite(STDERR, $file_name) : '');

        $curl_file = '@' . realpath($file_name);
        if (function_exists('curl_file_create')) { // php 5.5+
            $curl_file = curl_file_create($file_name);
        }

        curl_setopt(
            $request,
            CURLOPT_POSTFIELDS,
            array(
                'name' => 'My File Name',
                'dataset_uuid' => self::$created_dataset['dataset']['database_uuid'],
                'template_field_uuid' => 'c135ef75e9684091f7a1436539b6',
                'user_email' => 'nathan.a.stone@nasa.gov',
                'file' => $curl_file
            ));

        // output the response
        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($request, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($request);
        ($debug ? fwrite(STDERR, print_r($response)) : '');

        $http_status = curl_getinfo($request, CURLINFO_HTTP_CODE);
        ($debug ? fwrite(STDERR, $http_status) : '');
        $this->assertTrue($http_status == 302 || $http_status == 200);

        // close the session
        curl_close($request);

    }

    /*
       Publish Record
    */
    /*
    public function testPublish()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;

        $post_data = array(
            'user_email' => 'nathan.a.stone@nasa.gov',
            'dataset_uuid' => self::$created_dataset['dataset']['database_uuid']
        );

        $cp = new CurlUtility(
            self::$base_url . '/dataset/publish',
            $headers,
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->post($post_data);
        ($debug ? fwrite(STDERR, 'Publish response: ' . print_r($response, true) . "\n") : '');

        $updated_dataset = json_decode($response['response'], true);
        self::$created_dataset['dataset'] = $updated_dataset;

        ($debug ? fwrite(STDERR, 'Dataset: ' . print_r($updated_dataset, true) . "\n") : '');

        // Should have the user_email at least
        $this->assertTrue(isset($updated_dataset['database_uuid']));
    }

    // Search (all)
    /*
    public function testGeneralSearch()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;

        $post_data = array(
            'search_key' => 'ew0KImZpZWxkcyI6IFtdLA0KImdlbmVyYWwiOiAiIiwNCiJzb3J0X2J5IjogWw0Kew0KImRpciI6ICJhc2MiLA0KInRlbXBsYXRlX2ZpZWxkX3V1aWQiOiAiMDgwODhhOSINCn0NCl0sDQoidGVtcGxhdGVfbmFtZSI6ICJBSEVEIENvcmUgMS4wIiwNCiJ0ZW1wbGF0ZV91dWlkIjogIjJlYTYyN2IiDQp9'
        );

        $cp = new CurlUtility(
            self::$base_url . '/search/1000/0',
            $headers,
            false,
            true,
            __FUNCTION__
        );

        $response = $cp->post($post_data);
        $results = json_decode($response['response'], true);
        // ($debug ? fwrite(STDERR, 'Results: ' . print_r($results, true) . "\n") : '');
        ($debug ? fwrite(STDERR, 'Result Count: ' . count($results['records']) . "\n") : '');
        // ($debug ? fwrite(STDERR, 'Result Count: ' . count($results) . "\n") : '');
        // Should have the user_email at least
        $this->assertTrue(count($results) > 0);
    }
    */

}

