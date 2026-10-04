<?php

/**
 * Open Data Repository Data Publisher
 * Crawler Verification Service
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Determines whether a given IP address belongs to a search engine crawler, by matching it
 * against the IP ranges that Google, Bing, and DuckDuckGo publish for their crawlers.
 *
 * This exists so the parts of ODR that are closed to anonymous visitors (because bots were
 * triggering a database search for every url they felt like guessing at) can stay open to the
 * search engines that are supposed to be indexing the site.
 *
 * The ranges themselves live in a generated file, written by the console command
 * odr:crawler_ips:update...they are NOT downloaded at runtime.
 *
 * NOTE: never identify a crawler by its user agent.  That's a single header and trivially
 * forged...it's exactly what the bots being blocked are doing.
 */

namespace ODR\AdminBundle\Component\Service;

// Symfony
use Psr\Log\LoggerInterface;


class CrawlerVerificationService
{

    /**
     * @var string
     */
    private $data_file;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Lazily loaded contents of $data_file.
     *
     * @var array|null
     */
    private $data = null;


    /**
     * CrawlerVerificationService constructor.
     *
     * @param string $data_file
     * @param LoggerInterface $logger
     */
    public function __construct($data_file, LoggerInterface $logger)
    {
        $this->data_file = $data_file;
        $this->logger = $logger;
    }


    /**
     * Returns the name of the search engine that the given IP address belongs to ('google',
     * 'bing', or 'duckduckgo'), or null when the address doesn't belong to any of them.
     *
     * @param string|null $ip
     *
     * @return string|null
     */
    public function identifyCrawler($ip)
    {
        if ( $ip === null || $ip === '' )
            return null;

        // inet_pton() gives 4 packed bytes for IPv4 and 16 for IPv6, which is the form the
        //  generated file stores its prefixes in
        $packed = @inet_pton($ip);
        if ( $packed === false )
            return null;

        $data = $this->getData();

        if ( strlen($packed) === 4 ) {
            // IPv4 prefixes are pre-reduced to a network/mask pair of integers when the file is
            //  generated, so matching is a couple of integer ops per prefix
            $needle = unpack('N', $packed)[1];
            foreach ($data['ipv4'] as $entry) {
                if ( ($needle & $entry[1]) === $entry[0] )
                    return $entry[2];
            }

            return null;
        }

        foreach ($data['ipv6'] as $entry) {
            if ( self::packedAddressInPrefix($packed, $entry[0], $entry[1]) )
                return $entry[2];
        }

        return null;
    }


    /**
     * Returns whether the given IP address belongs to one of the search engine crawlers.
     *
     * @param string|null $ip
     *
     * @return bool
     */
    public function isVerifiedCrawler($ip)
    {
        return ( $this->identifyCrawler($ip) !== null );
    }


    /**
     * Returns when the stored ranges were generated and how many prefixes came from each
     * source...useful for verifying that a server actually has current data.
     *
     * @return array
     */
    public function getMetadata()
    {
        $data = $this->getData();

        return array(
            'generated' => $data['generated'],
            'sources' => $data['sources'],
            'ipv4_prefixes' => count($data['ipv4']),
            'ipv6_prefixes' => count($data['ipv6']),
        );
    }


    /**
     * Loads the generated file, which is a plain php array so that opcache holds it and there's
     * no parsing cost per request.
     *
     * A missing or malformed file means "no crawler matches anything"...which is deliberately the
     * safe direction for the server (anonymous visitors get blocked) but the dangerous direction
     * for indexing, so it gets logged.
     *
     * @return array
     */
    private function getData()
    {
        if ( $this->data !== null )
            return $this->data;

        $this->data = self::emptyData();

        if ( !file_exists($this->data_file) ) {
            $this->logger->warning('CrawlerVerificationService: no crawler ip file at "'.$this->data_file.'", so no crawler will be recognized...run "php app/console odr:crawler_ips:update"');
            return $this->data;
        }

        $loaded = @include $this->data_file;
        if ( !is_array($loaded) || !isset($loaded['ipv4']) || !isset($loaded['ipv6']) ) {
            $this->logger->error('CrawlerVerificationService: the crawler ip file at "'.$this->data_file.'" is malformed, so no crawler will be recognized');
            return $this->data;
        }

        $this->data = $loaded;
        return $this->data;
    }


    /**
     * Returns the structure that the generated file is expected to have.
     *
     * @return array
     */
    private static function emptyData()
    {
        return array(
            'generated' => null,
            'sources' => array(),
            'ipv4' => array(),
            'ipv6' => array(),
        );
    }


    /**
     * Returns whether a packed address falls inside the given prefix, where the prefix is a hex
     * encoding of its packed network address plus a length in bits.
     *
     * Handles prefixes that don't land on a byte boundary, though in practice the published lists
     * are all /32s, /24s, and /64s.
     *
     * @param string $packed Packed address, from inet_pton()
     * @param string $network_hex Hex encoding of the packed network address
     * @param int $bits
     *
     * @return bool
     */
    private static function packedAddressInPrefix($packed, $network_hex, $bits)
    {
        $network = @hex2bin($network_hex);
        if ( $network === false || strlen($network) !== strlen($packed) )
            return false;

        $whole_bytes = intdiv($bits, 8);
        if ( $whole_bytes > 0 && strncmp($packed, $network, $whole_bytes) !== 0 )
            return false;

        $leftover_bits = $bits % 8;
        if ( $leftover_bits === 0 )
            return true;

        $mask = (0xFF << (8 - $leftover_bits)) & 0xFF;
        return ( (ord($packed[$whole_bytes]) & $mask) === (ord($network[$whole_bytes]) & $mask) );
    }
}
