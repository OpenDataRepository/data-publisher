<?php

namespace ODR\AdminBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class APIControllerTest extends WebTestCase
{
    public static $client = "";

    public static $headers = array();

    public function testToken() {
        $debug = (getenv("DEBUG") == "APIController" ? true: false);
        self::$client = static::createClient();

        $post_data = '{"username":"test@opendatarepository.org","password":"12345asdF**"}';

        // Test that the outer frame loaded
        ($debug ? fwrite(STDERR, "Test token loaded.\n"):'');
        self::$client->request(
            'POST',
            '/api/v3/token',
            json_decode($post_data,true),
            array(),
            array()
        );

        $content = self::$client->getResponse()->getContent();

        // Show the actual content if debug enabled.
        ($debug ? fwrite(STDERR, 'Content pulled.') . "\n":'');

        $token = json_decode($content, true);

        if(is_array($token)) {
            ($debug ? fwrite(STDERR, $token['token']) . "\n":'');
        }
        else {
            ($debug ? fwrite(STDERR, $content) . "\n":'');
        }

        // Should redirect to login
        $this->assertTrue(isset($token['token']));
        self::$headers = array(
            'HTTP_AUTHORIZATION' => "Bearer {$token['token']}",
            'CONTENT_TYPE' => 'application/json',
        );

    }


}
