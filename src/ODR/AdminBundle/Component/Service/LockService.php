<?php

/**
 * Open Data Repository Data Publisher
 * Lock Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service provides an interface to whichever lock handler is currently being used by ODR.
 * (currently RedisStore)
 */

namespace ODR\AdminBundle\Component\Service;

// Redis
use Predis;
// Symfony
use Symfony\Component\Lock\Factory;
use Symfony\Component\Lock\Store\RedisStore;
use Symfony\Component\Lock\Store\RetryTillSaveStore;

class LockService
{

    /**
     * @var string
     */
    private $cache_prefix;

    /**
     * @var Factory
     */
    private $lock_factory;


    /**
     * CacheService constructor.
     *
     * @param Predis\Client $redis_client
     * @param string $cache_prefix
     */
    public function __construct(
        Predis\Client $redis_client,
        $cache_prefix
    ) {
        $redis_store = new RedisStore($redis_client);
        $blocking_redis_store = new RetryTillSaveStore($redis_store);    // 100ms retry delay, unlimited times

        $this->lock_factory = new Factory($blocking_redis_store);
        $this->cache_prefix = $cache_prefix;
    }


    /**
     * Creates a lock for the given resource.
     *
     * @param string     $resource    The resource to lock
     * @param float|null $ttl         Maximum expected lock duration in seconds
     * @param bool       $autoRelease Whether to automatically release the lock or not when the lock instance is destroyed
     *
     * @return \Symfony\Component\Lock\Lock
     */
    public function createLock($resource, $ttl = 300.0, $autoRelease = true)
    {
        $key = $this->cache_prefix.'.'.$resource;
        $lock = $this->lock_factory->createLock($key, $ttl, $autoRelease);
        return $lock;
    }
}
