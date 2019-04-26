<?php

namespace ODR\AdminBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class APIControllerTest extends WebTestCase
{
    public static $client = "";

    public static $token = "";
    public static $headers = array();

    public static $template_uuid = "2ea627b";

    public static $template_data = [];

    public function testToken() {
        $debug = (getenv("DEBUG") == "APIController" ? true: false);
        self::$client = static::createClient();

        // Test that the outer frame loaded
        ($debug ? fwrite(STDERR, "Test token loaded.\n"):'');
        self::$client->request(
            'POST',
            'http://eta.odr.io/api/v3/token',
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
        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');

        $token = json_decode($content, true);

        if(!is_array($token)) {
            ($debug ? fwrite(STDERR, $content) . "\n":'');
        }

        // Should redirect to login
        $this->assertTrue(isset($token['token']));

        self::$token = $token['token'];
        self::$headers = array(
            'HTTP_AUTHORIZATION' => "Bearer {$token['token']}",
            'CONTENT_TYPE' => 'application/json',
        );

        ($debug ? fwrite(STDERR, print_r(self::$headers) . "\n"):'');
    }

    public function testTemplate() {
        $debug = (getenv("DEBUG") == "APIController" ? true: false);
        self::$client = static::createClient();

        // Test that the outer frame loaded
        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
        self::$client->request(
            'GET',
            'http://eta.odr.io/api/v3/search/template/' . self::$template_uuid,
            [],
            [],
            self::$headers
        );

        $content = self::$client->getResponse()->getContent();

        // Show the actual content if debug enabled.
        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');

        $template = json_decode($content, true);

        if(!is_array($template)) {
            ($debug ? fwrite(STDERR, $content) . "\n":'');
        }

        // Should redirect to login
        $this->assertTrue(isset($template['name']));

        if($debug && isset($template['name'])) {
            self::$template_data = $template;
            fwrite(STDERR, $template['name'] . "\n");
        }
    }

    /**
     * Post File with CURL
     */
    public function testFilePost() {
        $debug = (getenv("DEBUG") == "APIController" ? true: false);


        // initialise the curl request
        $request = curl_init('http://eta.odr.io/api/v3/file?XDEBUG_SESSION_START=phpstorm_xdebug');

        // send a file
        curl_setopt($request, CURLOPT_POST, true);

        curl_setopt($request, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer ". self::$token
        ));

        $file_name = '/home/nate/data-publisher/initial_setup.sql';
        ($debug ? fwrite(STDERR, $file_name): '');

        $curl_file = '@' . realpath($file_name);
        if (function_exists('curl_file_create')) { // php 5.5+
            $curl_file = curl_file_create($file_name);
        }

        curl_setopt(
            $request,
            CURLOPT_POSTFIELDS,
            array(
                'name' => 'My File Name',
                'dataset_uuid' => '90eb084',
                // 'dataset_uuid' => 'dbee98e',
                // 'template_field_uuid' => '4d5cfec',
                'template_field_uuid' => '3029d53eade509a7524253602811',
                'user_email' => 'nate@opendatarepository.org',
                'file' => $curl_file
            ));

        // output the response
        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($request);
        ($debug ? fwrite(STDERR, print_r($response)):'');

        $http_status = curl_getinfo($request, CURLINFO_HTTP_CODE);
        ($debug ? fwrite(STDERR, $http_status):'');
        $this->assertTrue($http_status == 302);

        // close the session
        curl_close($request);

    }

    /**
     * Check user & databases (new random user)
     */
    public function testGetDataset() {
        $debug = (getenv("DEBUG") == "APIController" ? true: false);
        self::$client = static::createClient();

        // Test that the outer frame loaded
        ($debug ? fwrite(STDERR, "Getting dataset.\n"):'');
        self::$client->request(
            'GET',
            'http://eta.odr.io/api/v3/dataset/dbee98e',
            [],
            [],
            self::$headers
        );

        $content = self::$client->getResponse()->getContent();

        // Show the actual content if debug enabled.
        ($debug ? fwrite(STDERR, 'Content pulled.' . "\n"):'');

        $dataset = json_decode($content, true);

        if(!is_array($dataset)) {
            ($debug ? fwrite(STDERR, $content) . "\n":'');
        }

        // Should redirect to login
        $this->assertTrue(isset($dataset['database_uuid']));

        if($debug && isset($dataset['database_uuid'])) {
            // self::$dataset_data = $dataset;
            fwrite(STDERR, print_r($dataset) . "\n");
        }
    }

//    /**
//     * Create a database from template
//     */
//    public function testCreate() {
//        $debug = (getenv("DEBUG") == "APIController" ? true: false);
//        self::$client = static::createClient();
//
//        // Test that the outer frame loaded
//        ($debug ? fwrite(STDERR, "Getting template.\n"):'');
//        self::$client->request(
//            'GET',
//            '/api/v3/search/template/' . self::$template_uuid,
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
//            '/api/v3/search/template/' . self::$template_uuid,
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
//            '/api/v3/search/template/' . self::$template_uuid,
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
//            '/api/v3/search/template/' . self::$template_uuid,
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
//            '/api/v3/search/template/' . self::$template_uuid,
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
//            '/api/v3/search/template/' . self::$template_uuid,
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
//            '/api/v3/search/template/' . self::$template_uuid,
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
//            '/api/v3/search/template/' . self::$template_uuid,
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
//            '/api/v3/search/template/' . self::$template_uuid,
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
//            '/api/v3/search/template/' . self::$template_uuid,
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
//            '/api/v3/search/template/' . self::$template_uuid,
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
//            '/api/v3/search/template/' . self::$template_uuid,
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
