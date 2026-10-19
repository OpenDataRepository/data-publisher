<?php

/**
 * Open Data Repository Data Publisher
 * Recaptcha Service
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Google reCAPTCHA for ODR's own pages, so the routes that are closed to anonymous visitors
 * (because bots were triggering a database search for every url they guessed at) can be reopened
 * to anyone willing to prove they're human.
 *
 * This is the ODR-side counterpart to the gate in the wordpress astra child theme.  Sites that
 * reach ODR through wordpress -- the odr.io subdomains, rruff.net -- are handled there; this only
 * covers requests that hit ODR directly, which is why it is limited to a list of hosts.
 *
 * Decisions, in order:
 *   1. Not one of the configured hosts, or no keys  -> not enabled, caller keeps its old behavior
 *   2. Verified search crawler                      -> allowed, if that's switched on
 *   3. Failed a challenge recently                  -> refuse, without spending a verification
 *   4. Passed a challenge recently                  -> allowed
 *   5. Otherwise                                    -> challenge
 *
 * Step 3 keeps usage inside reCAPTCHA's free tier: once a client fails, refusing them costs
 * nothing.
 *
 * Configuration lives in each instance's app/config/parameters.yml, which is gitignored and
 * per-instance -- so only the install that needs these keys carries them.  See
 * parameters.yml.dist for the key names.
 *
 * NOTE: the parameters are read through the parameter bag rather than injected, deliberately.  An
 * injected "%parameter%" that doesn't exist is a container COMPILE error, which would take down
 * every instance that has no reason to set these.  Reading them at runtime means a missing key is
 * simply a missing key, and this service reports itself disabled.
 *
 * NOTE: never identify a crawler by its user agent...that's a single forgeable header, and it's
 * what the bots being blocked are already doing.  See CrawlerVerificationService.
 */

namespace ODR\AdminBundle\Component\Service;

// Symfony
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use Psr\Log\LoggerInterface;


class RecaptchaService
{

    /**
     * reCAPTCHA v2 verification endpoint.
     */
    const SITEVERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * Hosts this is active for when the .env doesn't say otherwise.  Everything else -- notably
     * the wordpress-integrated odr.io subdomains -- is left alone.
     */
    const DEFAULT_HOSTS = 'www.odr.io,odr.io';

    /**
     * How long a client that fails is refused, and how long one that passes is trusted.
     */
    const DEFAULT_BLOCK_SECONDS = 7200;

    /**
     * Seconds to wait on Google.
     */
    const TIMEOUT = 10;


    /**
     * @var ParameterBagInterface
     */
    private $parameter_bag;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var CrawlerVerificationService
     */
    private $crawler_verification_service;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * RecaptchaService constructor.
     *
     * @param ParameterBagInterface $parameter_bag
     * @param CacheService $cache_service
     * @param CrawlerVerificationService $crawler_verification_service
     * @param LoggerInterface $logger
     */
    public function __construct(
        ParameterBagInterface $parameter_bag,
        CacheService $cache_service,
        CrawlerVerificationService $crawler_verification_service,
        LoggerInterface $logger
    ) {
        $this->parameter_bag = $parameter_bag;
        $this->cache_service = $cache_service;
        $this->crawler_verification_service = $crawler_verification_service;
        $this->logger = $logger;
    }


    /**
     * Whether this request should be challenged rather than simply refused.
     *
     * False means the caller keeps whatever it did before -- so a misconfiguration can only ever
     * leave a route as closed as it already was, never open it.
     *
     * @param string|null $host The request's host, without port
     *
     * @return bool
     */
    public function isEnabledForHost($host)
    {
        if ( $this->getSiteKey() === '' || $this->getSecret() === '' )
            return false;

        return $this->hostIsConfigured($host);
    }


    /**
     * Whether the given host is on the configured list.
     *
     * @param string|null $host
     *
     * @return bool
     */
    public function hostIsConfigured($host)
    {
        if ( $host === null || $host === '' )
            return false;

        // strip a port, and compare case-insensitively
        $host = strtolower(preg_replace('/:\d+$/', '', trim($host)));

        foreach ( $this->getHosts() as $configured ) {
            if ( $host === $configured )
                return true;
        }

        return false;
    }


    /**
     * The site key, which the challenge page hands to Google's widget.
     *
     * @return string
     */
    public function getSiteKey()
    {
        return $this->getConfigValue('odr_recaptcha_site_key', '');
    }


    /**
     * @return string
     */
    private function getSecret()
    {
        return $this->getConfigValue('odr_recaptcha_secret', '');
    }


    /**
     * Hosts this is active for, lowercased.
     *
     * @return string[]
     */
    public function getHosts()
    {
        $raw = $this->getConfigValue('odr_recaptcha_hosts', self::DEFAULT_HOSTS);

        $hosts = array();
        foreach ( explode(',', $raw) as $host ) {
            $host = strtolower(trim($host));
            if ( $host !== '' )
                $hosts[] = $host;
        }

        return $hosts;
    }


    /**
     * How long a failure is held against a client.
     *
     * @return int
     */
    public function getBlockSeconds()
    {
        $seconds = (int)$this->getConfigValue('odr_recaptcha_block_seconds', self::DEFAULT_BLOCK_SECONDS);
        return ( $seconds < 60 ) ? 60 : $seconds;
    }


    /**
     * Whether verified search crawlers skip the challenge.
     *
     * Off unless the .env says otherwise: a crawler guessing urls is still a bot spending a
     * search.  Turning it on is how these urls become indexable again.
     *
     * @return bool
     */
    public function allowVerifiedCrawlers()
    {
        $value = strtolower(trim($this->getConfigValue('odr_recaptcha_allow_verified_crawlers', '0')));
        return in_array($value, array('1', 'true', 'yes', 'on'), true);
    }


    /**
     * Whether this address belongs to a search crawler we're willing to let through.
     *
     * @param string|null $ip
     *
     * @return bool
     */
    public function isAllowedCrawler($ip)
    {
        if ( !$this->allowVerifiedCrawlers() )
            return false;

        return $this->crawler_verification_service->isVerifiedCrawler($ip);
    }


    /**
     * Whether this client failed a challenge recently.
     *
     * @param string|null $ip
     *
     * @return bool
     */
    public function isBlocked($ip)
    {
        return (bool)$this->cache_service->exists($this->cacheKey('block', $ip));
    }


    /**
     * Refuses this client for the configured window.
     *
     * @param string|null $ip
     */
    public function blockClient($ip)
    {
        $key = $this->cacheKey('block', $ip);
        $this->cache_service->set($key, time());
        $this->cache_service->expire($key, $this->getBlockSeconds());
    }


    /**
     * Whether this client has already passed recently.  Without this, every search would cost
     * another verification.
     *
     * @param string|null $ip
     *
     * @return bool
     */
    public function hasPassed($ip)
    {
        return (bool)$this->cache_service->exists($this->cacheKey('pass', $ip));
    }


    /**
     * Remembers that this client passed, for the same window a failure would have cost them.
     *
     * @param string|null $ip
     */
    public function markPassed($ip)
    {
        $key = $this->cacheKey('pass', $ip);
        $this->cache_service->set($key, time());
        $this->cache_service->expire($key, $this->getBlockSeconds());
    }


    /**
     * Asks Google whether a response token is good.
     *
     * Returns null when Google couldn't be reached -- that isn't the visitor's fault, so they
     * mustn't be banned for it.
     *
     * @param string $token
     * @param string|null $ip
     *
     * @return bool|null
     */
    public function verify($token, $ip)
    {
        if ( $token === '' || $token === null )
            return false;

        $post = array(
            'secret' => $this->getSecret(),
            'response' => $token,
        );
        if ( !empty($ip) )
            $post['remoteip'] = $ip;

        $ch = curl_init(self::SITEVERIFY_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);

        $body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ( $body === false ) {
            $this->logger->error('RecaptchaService: could not reach google: '.$curl_error);
            return null;
        }
        if ( $http_code !== 200 ) {
            $this->logger->error('RecaptchaService: google answered HTTP '.$http_code);
            return null;
        }

        $decoded = json_decode($body, true);
        if ( !is_array($decoded) || !isset($decoded['success']) ) {
            $this->logger->error('RecaptchaService: unexpected response from google');
            return null;
        }

        if ( $decoded['success'] !== true && isset($decoded['error-codes']) ) {
            // a bad secret is our problem, not the visitor's...worth seeing in the log
            $errors = implode(',', (array)$decoded['error-codes']);
            if ( strpos($errors, 'secret') !== false ) {
                $this->logger->error('RecaptchaService: google rejected our secret ('.$errors.')');
                return null;
            }
        }

        return ( $decoded['success'] === true );
    }


    /**
     * Cache key for one client.  The address is hashed so it isn't sitting in redis in the clear,
     * and so the key length is predictable.
     *
     * NOTE: CacheService prefixes keys per instance, so two instances sharing a redis can't tread
     * on each other.
     *
     * @param string $kind 'block' or 'pass'
     * @param string|null $ip
     *
     * @return string
     */
    private function cacheKey($kind, $ip)
    {
        return 'recaptcha_'.$kind.'_'.md5((string)$ip);
    }


    /**
     * Reads one parameter, falling back to a default when the instance hasn't set it.
     *
     * Everything funnels through here so that "not configured" is always a supported state.
     *
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    private function getConfigValue($key, $default)
    {
        if ( !$this->parameter_bag->has($key) )
            return $default;

        $value = $this->parameter_bag->get($key);

        // a parameter that exists but is null/empty counts as unset...an instance that commented
        // out a value shouldn't accidentally enable the feature with a blank key
        if ( $value === null || $value === '' )
            return $default;

        if ( is_bool($value) )
            return $value ? '1' : '0';
        if ( is_scalar($value) )
            return (string)$value;

        // anything else (an array, say) is a misconfiguration...don't let it turn into a cast error
        $this->logger->error('RecaptchaService: parameter "'.$key.'" is not a scalar, ignoring it');
        return $default;
    }
}
