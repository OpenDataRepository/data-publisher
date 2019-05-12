<?php

namespace ODR\AdminBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use ODR\AdminBundle\Component\Utility\CurlUtility;

class APIControllerTest extends WebTestCase
{
    public static $client = "";

    public static $token = "";
    public static $headers = array();

    public static $base_url = "http://office_dev/api/v3";

    public static $template_uuid = "2ea627b";

    public static $created_dataset = [];

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

        self::$client = static::createClient();

        // Test that the outer frame loaded
        ($debug ? fwrite(STDERR, "Test token loaded.\n") : '');
        self::$client->request(
            'POST',
            self::$base_url . '/token',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'username' => 'test@opendatarepository.org',
                'password' => '12345asdF**'
            ])
        );

        $content = self::$client->getResponse()->getContent();

        // Show the actual content if debug enabled.
        ($debug ? fwrite(STDERR, 'Token Data:') : '');

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
        self::$client = static::createClient();


        $headers = self::$headers;
        $headers['CONTENT_TYPE'] = 'application/json';

        // Test that the outer frame loaded
        ($debug ? fwrite(STDERR, "Getting template.\n") : '');
        self::$client->request(
            'GET',
            self::$base_url . '/search/template/' . self::$template_uuid,
            [],
            [],
            self::$headers
        );

        $content = self::$client->getResponse()->getContent();

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
        self::$client = static::createClient();

        $headers[] = 'Authorization: Bearer ' . self::$token;

        $post_data = array(
            'user_email' => 'nancy.drew@detectivemysteries.com',
            'first_name' => 'Nancy',
            'last_name' => 'Drew',
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
        self::$client = static::createClient();

        $headers[] = 'Authorization: Bearer ' . self::$token;

        $post_data = array(
            'user_email' => 'nancy.drew@detectivemysteries.com',
            'first_name' => 'Nancy',
            'last_name' => 'Drew',
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
        $created_dataset = json_decode($response['response'], true);
        self::$created_dataset = array(
            'user_email' => 'nancy.drew@detectivemysteries.com',
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
        self::$client = static::createClient();

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
        $this->assertTrue($code == 302);
    }


    public function testAddPerson()
    {

        $add_person = '{
                "template_uuid": "ce17e42",
                "fields": [
                    {
                        "field_name": "First Name",
                        "template_field_uuid": "0143860",
                        "value": "John_' .rand(100000,999999) . '"
                    },
                    {
                        "field_name": "Last Name",
                        "template_field_uuid": "4d9ea52",
                        "value": "Doe_' . rand(100000,999999) . '"
                    },
                    {
                        "field_name": "Contact Email",
                        "template_field_uuid": "e3dcbc9",
                        "value": "random_person_' . rand(100000,999999) . '@nasa.gov"
                    },
                    {
                        "field_name": "Person Website",
                        "template_field_uuid": "9ba0f2f",
                        "value": ""
                    },
                    {
                        "field_name": "ORCID Identifier",
                        "template_field_uuid": "2877316",
                        "value": "' . rand(100000000000,999999999999) . '"
                    }
                ],
                "records": [
                    {
                        "database_name": "Postal Address",
                        "template_uuid": "95f9363",
                        "fields": [
                            {
                                "template_field_uuid": "ed4f42c",
                                "value": "Institution_' . rand(10000,99999) . '"
                            },
                            {
                                "template_field_uuid": "2d1d105",
                                "value": "Mail-Stop ' . rand(10000,99999) . '" 
                            },
                            {
                                "template_field_uuid": "3503e92",
                                "value": "City_' . rand(10000,99999) . '"
                            },
                            {
                                "template_field_uuid": "062df8b",
                                "value": [
                                    {
                                        "template_radio_option_uuid": "96f65a3",
                                        "selected": "1"
                                    }
                                ]
                            },
                            {
                                "template_field_uuid": "79590b6",
                                "value": "94035-' . rand(10000,99999) . '"
                            },
                            {
                                "template_field_uuid": "c7d1a2e",
                                "value": [
                                    {
                                        "template_radio_option_uuid": "48f278b",
                                        "selected": "1"
                                    }
                                ]
                            }
                        ],
                        "records": []
                    }
                ]
            }';

        $person_data = json_decode($add_person);

        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);
        self::$client = static::createClient();

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
        $this->assertTrue($code == 302);
    }


    public function testAddInstitution()
    {

        // Add Institution
        $institution_template = '{
            "name": "Institution",
            "template_uuid": "870a2f7",
            "fields": [
                {
                    "name": "Institution ID",
                    "template_field_uuid": "ec0706f",
                    "value": "' . rand(100000000, 999999999) . '"
                },
                {
                    "name": "Institution name",
                    "template_field_uuid": "1df6df8",
                    "value": "Institution_' . rand(100000000, 999999999) . '"
                },
                {
                    "name": "Sub unit name",
                    "template_field_uuid": "0b8a9f3",
                    "value": "Sub_Unit_' . rand(100000000, 999999999) . '"
                },
                {
                    "name": "Sub unit website URL",
                    "template_field_uuid": "6712650",
                    "value": "URL_' . rand(100000000, 999999999) . '"
                },
                {
                    "name": "GRID Identifier (e.g.  grid.419075.e)",
                    "template_field_uuid": "c83b5ff",
                    "value": "GRID_ID_' . rand(100000000, 999999999) . '"
                }
            ],
            "related_databases": [
                {
                    "name": "Postal Address",
                    "template_uuid": "95f9363",
                    "fields": [
                        {
                            "name": "Address ID#",
                            "template_field_uuid": "a0c83b5",
                            "value": "Address_' . rand(100000000, 999999999) . '"
                        },
                        {
                            "name": "Address 1",
                            "template_field_uuid": "ed4f42c",
                            "value": "Address_Line_1' . rand(100000000, 999999999) . '"
                        },
                        {
                            "name": "Address 2",
                            "template_field_uuid": "2d1d105",
                            "value": "Address_Line_2' . rand(100000000, 999999999) . '"
                        },
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
                            "name": "Postal Code",
                            "template_field_uuid": "79590b6",
                            "value": "' . rand(100000000, 999999999) . '"
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
        self::$client = static::createClient();

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
        /*
        $updated_dataset = json_decode($response['response'], true);
        self::$created_dataset['dataset'] = $updated_dataset;
        */
        ($debug ? fwrite(STDERR, 'Dataset UUID: ' . self::$created_dataset['dataset']['database_uuid'] . "\n") : '');

        // Should have the user_email at least
        $this->assertTrue($code == 302);
    }

    // Publish Dataset
    public function testPublish()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);
        self::$client = static::createClient();

        $headers[] = 'Authorization: Bearer ' . self::$token;

        $post_data = array(
            'user_email' => 'nancy.drew@detectivemysteries.com',
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
    public function testGeneralSearch()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);
        self::$client = static::createClient();

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
        ($debug ? fwrite(STDERR, 'Results: ' . print_r($results, true) . "\n") : '');
        ($debug ? fwrite(STDERR, 'Result Count: ' . count($results['records']) . "\n") : '');
        // ($debug ? fwrite(STDERR, 'Result Count: ' . count($results) . "\n") : '');
        // Should have the user_email at least
        $this->assertTrue(count($results) > 0);
    }



//    /**
//     * Post File with CURL
//     */
//    public function testFilePost() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//
//
//        // initialise the curl request
//        // $request = curl_init(self::$base_url . '/file?XDEBUG_SESSION_START=phpstorm_xdebug');
//        $request = curl_init(self::$base_url . '/file');
//
//        // send a file
//        curl_setopt($request, CURLOPT_POST, true);
//
//        curl_setopt($request, CURLOPT_HTTPHEADER, array(
//            "Authorization: Bearer ". self::$token
//        ));
//
//        $file_name = '/home/nate/data-publisher/initial_setup.sql';
//        ($debug ? fwrite(STDERR, $file_name): '');
//
//        $curl_file = '@' . realpath($file_name);
//        if (function_exists('curl_file_create')) { // php 5.5+
//            $curl_file = curl_file_create($file_name);
//        }
//
//        curl_setopt(
//            $request,
//            CURLOPT_POSTFIELDS,
//            array(
//                'name' => 'My File Name',
//                // 'dataset_uuid' => 'dbee98e',
//                // 'template_field_uuid' => '4d5cfec',
//                // 'dataset_uuid' => '90eb084',
//                // 'template_field_uuid' => '3029d53eade509a7524253602811',
//                'dataset_uuid' => '97e16c2',
//                'record_uuid' => '9dbdd7233d347b02c8ed1f5c6ae1',
//                'template_field_uuid' => 'cc662d72c7b107bba341e0315a9d',
//                'user_email' => 'nate@opendatarepository.org',
//                'file' => $curl_file
//            ));
//
//        // output the response
//        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
//        $response = curl_exec($request);
//        ($debug ? fwrite(STDERR, print_r($response)):'');
//
//        $http_status = curl_getinfo($request, CURLINFO_HTTP_CODE);
//        ($debug ? fwrite(STDERR, $http_status):'');
//        $this->assertTrue($http_status == 302);
//
//        // close the session
//        curl_close($request);
//
//    }

//    /**
//     * Check user & databases (new random user)
//     */
//    public function testGetDataset() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting dataset.\n"):'');
//        self::$client->request(
//            'GET',
//            self::$base_url . '/dataset/dbee98e',
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $dataset = json_decode($content, true);
//
//        if(!is_array($dataset)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($dataset['database_uuid']));
//
//        if($debug && isset($dataset['database_uuid'])) {
//            // self::$dataset_data = $dataset;
//            fwrite(STDERR, print_r($dataset) . "\n");
//        }
//    }


//    /**
//     * Update database add options
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/search/template/' . self::$template_uuid,
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $template = json_decode($content, true);
//
//        if(!is_array($template)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($template['name']));
//
//        if($debug && isset($template['name'])) {
//            self::$template_data = $template;
//            fwrite(STDERR, $template['name'] . "\n");
//        }
//    }
//
//    /**
//     * Update database add child/linked record
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/search/template/' . self::$template_uuid,
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $template = json_decode($content, true);
//
//        if(!is_array($template)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($template['name']));
//
//        if($debug && isset($template['name'])) {
//            self::$template_data = $template;
//            fwrite(STDERR, $template['name'] . "\n");
//        }
//    }
//
//    /**
//     * Update database add file
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/search/template/' . self::$template_uuid,
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $template = json_decode($content, true);
//
//        if(!is_array($template)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($template['name']));
//
//        if($debug && isset($template['name'])) {
//            self::$template_data = $template;
//            fwrite(STDERR, $template['name'] . "\n");
//        }
//    }
//
//    /**
//     * Update database GET file
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/search/template/' . self::$template_uuid,
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $template = json_decode($content, true);
//
//        if(!is_array($template)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($template['name']));
//
//        if($debug && isset($template['name'])) {
//            self::$template_data = $template;
//            fwrite(STDERR, $template['name'] . "\n");
//        }
//    }
//
//    /**
//     * Get user & check for database
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/search/template/' . self::$template_uuid,
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $template = json_decode($content, true);
//
//        if(!is_array($template)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($template['name']));
//
//        if($debug && isset($template['name'])) {
//            self::$template_data = $template;
//            fwrite(STDERR, $template['name'] . "\n");
//        }
//    }
//
//    /**
//     * Get Dataset Non-public
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/search/template/' . self::$template_uuid,
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $template = json_decode($content, true);
//
//        if(!is_array($template)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($template['name']));
//
//        if($debug && isset($template['name'])) {
//            self::$template_data = $template;
//            fwrite(STDERR, $template['name'] . "\n");
//        }
//    }
//
//    /**
//     * Add file to related dataset
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/search/template/' . self::$template_uuid,
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $template = json_decode($content, true);
//
//        if(!is_array($template)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($template['name']));
//
//        if($debug && isset($template['name'])) {
//            self::$template_data = $template;
//            fwrite(STDERR, $template['name'] . "\n");
//        }
//    }
//
//    /**
//     * Publish database
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/search/template/' . self::$template_uuid,
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $template = json_decode($content, true);
//
//        if(!is_array($template)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($template['name']));
//
//        if($debug && isset($template['name'])) {
//            self::$template_data = $template;
//            fwrite(STDERR, $template['name'] . "\n");
//        }
//    }
//
//    /**
//     * Search for Dataset Public
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/search/template/' . self::$template_uuid,
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $template = json_decode($content, true);
//
//        if(!is_array($template)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($template['name']));
//
//        if($debug && isset($template['name'])) {
//            self::$template_data = $template;
//            fwrite(STDERR, $template['name'] . "\n");
//        }
//    }
//
//    /**
//     * Update database add file
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/search/template/' . self::$template_uuid,
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $template = json_decode($content, true);
//
//        if(!is_array($template)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($template['name']));
//
//        if($debug && isset($template['name'])) {
//            self::$template_data = $template;
//            fwrite(STDERR, $template['name'] . "\n");
//        }
//    }
//
//    /**
//     * Update database add file
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/search/template/' . self::$template_uuid,
//            [],
//            [],
//            self::$headers
//        );
//
//        $content = self::$client->getResponse()->getContent();
//
//        // Show the actual content if debug enabled.
//        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');
//
//        $template = json_decode($content, true);
//
//        if(!is_array($template)) {
//            ($debug ? fwrite(STDERR, $content) . "\n":'');
//        }
//
//        // Should redirect to login
//        $this->assertTrue(isset($template['name']));
//
//        if($debug && isset($template['name'])) {
//            self::$template_data = $template;
//            fwrite(STDERR, $template['name'] . "\n");
//        }
//    }
}
