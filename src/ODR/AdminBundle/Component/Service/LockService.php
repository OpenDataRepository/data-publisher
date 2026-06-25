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
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

class LockService
{

    /**
     * @var LockFactory
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
        private $cache_prefix
    ) {
        // Symfony 6 removed RetryTillSaveStore; the retry-till-save behaviour is now built into the
        // Lock itself (acquire(true) blocks/retries for non-blocking stores like RedisStore).
        $redis_store = new RedisStore($redis_client);

        $this->lock_factory = new LockFactory($redis_store);
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
