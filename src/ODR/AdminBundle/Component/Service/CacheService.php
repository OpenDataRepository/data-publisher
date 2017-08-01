<?php

/**
 * Open Data Repository Data Publisher
 * Cache Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service provides an interface to whichever caching provider is currently being used by ODR. (currently redis)
 */

namespace ODR\AdminBundle\Component\Service;

use Predis;

class CacheService
{

    /**
     * @var Predis\Client
     */
    private $cache_service;

    /**
     * @var string
     */
    private $cache_prefix;


    /**
     * CacheService constructor.
     *
     * @param Predis\Client $cache_service
     * @param string $cache_prefix
     */
    public function __construct(Predis\Client $cache_service, $cache_prefix)
    {
        $this->cache_service = $cache_service;
        $this->cache_prefix = $cache_prefix;
    }


    /**
     * Deletes the specified key out of the cache.
     *
     * @param string $key
     */
    public function delete($key)
    {
        $this->cache_service->del( array($this->cache_prefix.'.'.$key) );
    }


    /**
     * Returns whether the provided key exists in the cache.
     *
     * @param string $key
     *
     * @return boolean
     */
    public function exists($key)
    {
        return $this->cache_service->exists($this->cache_prefix.'.'.$key);
    }


    /**
     * Marks the provided key as expiring after $duration seconds.
     *
     * @param string $key
     * @param integer $duration
     */
    public function expire($key, $duration)
    {
        $this->cache_service->expire($this->cache_prefix.'.'.$key, $duration);
    }


    /**
     * Attempts to fetch $key from the cache.
     *
     * @param string $key
     *
     * @return string|array|boolean
     */
    public function get($key)
    {
        // Attempt to get the value stored in this key
        $cache_value = $this->cache_service->get($this->cache_prefix.'.'.$key);

        // If there is a non-empty value, transform it back into an array
        if (strlen($cache_value) > 0)
            return unserialize(gzuncompress($cache_value));

        // Otherwise, return false
        return false;
    }


    /**
     * Attempts to store a value in the cache.
     *
     * @param string $key
     * @param string|array $value
     * @param integer|null $duration
     */
    public function set($key, $value, $duration = null)
    {
        $this->cache_service->set($this->cache_prefix.'.'.$key, gzcompress(serialize($value)));
    }
}
