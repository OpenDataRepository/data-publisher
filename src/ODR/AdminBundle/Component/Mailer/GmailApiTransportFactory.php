<?php

/**
 * Open Data Repository Data Publisher
 * Gmail API Transport Factory
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Makes "gmail+api://default" usable as a mailer_dsn.
 *
 * The credentials deliberately do NOT travel in the DSN: a service account's RSA key is far too
 * long to sit in a url, and the DSN ends up in logs and debug output.  They are read from this
 * instance's parameters instead -- through the parameter bag, so an instance that doesn't send
 * mail this way simply has no such parameters rather than a container compile error.
 */

namespace ODR\AdminBundle\Component\Mailer;

use ODR\AdminBundle\Component\Service\CacheService;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;


final class GmailApiTransportFactory extends AbstractTransportFactory
{

    /**
     * @var ParameterBagInterface
     */
    private $parameter_bag;

    /**
     * @var CacheService|null
     */
    private $cache_service;


    public function __construct(
        ParameterBagInterface $parameter_bag,
        ?CacheService $cache_service = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($dispatcher, null, $logger);

        $this->parameter_bag = $parameter_bag;
        $this->cache_service = $cache_service;
    }


    /**
     * @inheritdoc
     */
    public function create(Dsn $dsn): TransportInterface
    {
        if (!\in_array($dsn->getScheme(), $this->getSupportedSchemes(), true))
            throw new UnsupportedSchemeException($dsn, 'gmail+api', $this->getSupportedSchemes());

        // the DSN may name the user to impersonate; otherwise the parameter is used
        $user = $dsn->getUser();
        if ($user === null || $user === '')
            $user = $this->parameter('gmail_api_user');

        return new GmailApiTransport(
            $this->parameter('gmail_api_service_account'),
            $this->privateKey(),
            $user,
            $this->cache_service,
            $this->dispatcher,
            $this->logger,
            $this->parameter('gmail_api_client_id'),
            $this->parameter('gmail_api_client_secret'),
            $this->parameter('gmail_api_refresh_token')
        );
    }


    /**
     * @inheritdoc
     */
    protected function getSupportedSchemes(): array
    {
        return array('gmail+api');
    }


    /**
     * The service account key, stored base64-encoded so the PEM fits on one line in
     * parameters.yml.  A key pasted as a raw PEM is accepted too.
     *
     * @return string
     */
    private function privateKey(): string
    {
        $value = $this->parameter('gmail_api_private_key');
        if ($value === '')
            return '';

        if (str_contains($value, 'BEGIN'))
            return $value;

        $decoded = base64_decode($value, true);

        return ($decoded === false) ? '' : $decoded;
    }


    /**
     * @param string $key
     *
     * @return string
     */
    private function parameter(string $key): string
    {
        if (!$this->parameter_bag->has($key))
            return '';

        $value = $this->parameter_bag->get($key);

        return is_scalar($value) ? (string)$value : '';
    }
}
