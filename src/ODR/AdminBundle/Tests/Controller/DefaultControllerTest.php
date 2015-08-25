<?php

namespace ODR\AdminBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DefaultControllerTest extends WebTestCase
{
    public static $client = "";
    public function testIndex()
    {
        $debug = (getenv("DEBUG") == "DefaultController" ? true: false);
        self::$client = static::createClient();

        // Test that the outer frame loaded
        ($debug ? fwrite(STDERR, "Test the outer frame loaded.\n"):'');
        $crawler = self::$client->request('GET', 'http://odr.localhost/admin');
        ($debug ? fwrite(STDERR, self::$client->getResponse()->getContent()) . "\n":'');

        // Should redirect to login
        ($debug ? fwrite(STDERR, "Should redirect to login.\n"):'');
        $this->assertTrue($crawler->filter('html:contains("Redirecting to")')->count() > 0);
    }
    public function testIndex2()
    {
        $debug = (getenv("DEBUG") == "DefaultController" ? true: false);

        // Test that the outer frame loaded
        ($debug ? fwrite(STDERR, "Test the outer frame loaded.\n"):'');
        $crawler = self::$client->request('GET', 'http://odr.localhost/admin');
        ($debug ? fwrite(STDERR, self::$client->getResponse()->getContent()) . "\n":'');

        // Should redirect to login
        ($debug ? fwrite(STDERR, "222Should redirect to login.\n"):'');
        $this->assertTrue($crawler->filter('html:contains("Redirecting to")')->count() > 0);
    }
}
