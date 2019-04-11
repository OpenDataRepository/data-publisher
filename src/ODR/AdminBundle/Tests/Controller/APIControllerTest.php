<?php

namespace ODR\AdminBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class APIControllerTest extends WebTestCase
{
    public static $client = "";


    public function testToken() {
        $debug = (getenv("DEBUG") == "APIController" ? true: false);
        self::$client = static::createClient();

        // Test that the outer frame loaded
        ($debug ? fwrite(STDERR, "Test the outer frame loaded.\n"):'');
        $crawler = self::$client->request('GET', 'http://odr.localhost/admin');



        // Show the actual content if debug enabled.
        ($debug ? fwrite(STDERR, self::$client->getResponse()->getContent()) . "\n":'');


        // Should redirect to login
        ($debug ? fwrite(STDERR, "Should redirect to login.\n"):'');
        $this->assertTrue($crawler->filter('html:contains("Redirecting to")')->count() > 0);
    }


}
