<?php

namespace ODR\AdminBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use ODR\AdminBundle\Component\Utility\CurlUtility;

class APIControllerPublshTest extends WebTestCase
{
    public static $client = "";

    public static $token = "";
    public static $headers = array();

    // public static $base_url = "https://ahed-dev.nasawestprime.com/ahed-api/api/v3";
    // public static $base_url = "http://office_dev/app_dev.php/api/v3";
    // public static $base_url = "http://localhost:8000/app_dev.php/api/v3";
    public static $base_url = "http://eta.odr.io/api/v3";

    public static $template_uuid = "2ea627b";

    public static $created_dataset = [];
    public static $created_datarecord = [];

    public static $template_data = [];
    // public static $public_date = new \DateTime();
    public static $public_date = '2010-01-01 01:02:03';
    // public static $public_date = '2179-01-01 01:01:01';

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

    public function testPublish1()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;

        $post_data = array(
            'user_email' => 'kevin.boydstun@nasa.gov',
            'dataset_uuid' => 'cedd8b4dfafc2ae1791e379b1a7f',
            'public_date' => self::$public_date
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

    public function testPublish2()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;

        $post_data = array(
            'user_email' => 'marybeth.wilhelm@nasa.gov',
            'dataset_uuid' => '72099f5',
            'public_date' => self::$public_date
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

    public function testPublish3()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;

        $post_data = array(
            'user_email' => 'barbara.lafuentevalverde@nasa.gov',
            'dataset_uuid' => '78afeb8687f2df684c4d96f16593',
            'public_date' => self::$public_date
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

    public function testPublish4()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;

        $post_data = array(
            'user_email' => 'barbara.lafuentevalverde@nasa.gov',
            'dataset_uuid' => '0fa9713',
            'public_date' => self::$public_date
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

    public function testPublish5()
    {
        $debug = ((getenv("DEBUG") == "APIController" || getenv("DEBUG") == __FUNCTION__) ? true : false);

        $headers[] = 'Authorization: Bearer ' . self::$token;

        $post_data = array(
            'user_email' => 'barbara.lafuentevalverde@nasa.gov',
            'dataset_uuid' => 'a62bba8',
            'public_date' => self::$public_date
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

}

