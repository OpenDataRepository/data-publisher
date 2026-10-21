<?php

/**
 * Open Data Repository Data Publisher
 * Gmail API Transport
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Sends mail through the Gmail API using a Google Workspace service account with domain-wide
 * delegation, rather than SMTP.
 *
 * WHY THIS EXISTS
 *   Symfony's own google-mailer bridge only speaks SMTP with an app password -- its supported
 *   schemes are gmail, gmail+smtp and gmail+smtps.  There is no official OAuth2 transport, and
 *   the third-party bundle that offers one is pinned to symfony/mailer ^5.4|^6.0.  So this is
 *   roughly 150 lines of JWT signing and two HTTP calls instead of pulling google/apiclient and
 *   its dozen dependencies onto every server.
 *
 * HOW IT AUTHENTICATES
 *   Either of two ways, depending on which credentials are configured.  Both end up with a
 *   short-lived access token, and the send itself is identical.
 *
 *   OAuth client + refresh token (gmail_api_client_id / _client_secret / _refresh_token)
 *     Posts the refresh token to Google's token endpoint.  The refresh token comes from a
 *     one-time browser consent as the sending mailbox -- see odr:mailer:authorize.  No Workspace
 *     admin involvement, and no service-account key, which matters when the organization enforces
 *     iam.disableServiceAccountKeyCreation.  The trade-off is that it sends as exactly one
 *     mailbox, and that mailbox's owner can revoke it.
 *
 *   Service account + domain-wide delegation (gmail_api_service_account / _private_key)
 *     Signs a JWT asserting "service account X wants to act as user Y for the gmail.send scope",
 *     which only works because the Workspace admin console authorized that service account's
 *     numeric client id for the scope.  Cannot impersonate a consumer @gmail.com address.
 *
 *   Access tokens last an hour, so they're cached...otherwise every email would cost an extra
 *   round trip to Google.
 */

namespace ODR\AdminBundle\Component\Mailer;

use ODR\AdminBundle\Component\Service\CacheService;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;


class GmailApiTransport extends AbstractTransport
{

    /**
     * Where a signed JWT is exchanged for an access token.
     */
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Gmail's "send this message" endpoint.  {user} is the address being impersonated.
     */
    const SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/{user}/messages/send';

    /**
     * The only scope this needs.  Keep it narrow: it is also the scope that has to be authorized
     * in the Workspace admin console, and a broader one there grants more than sending.
     */
    const SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    /**
     * Google rejects assertions older than an hour; this is comfortably inside that.
     */
    const ASSERTION_TTL = 3600;

    /**
     * Shave a little off the cached token's life so one never expires mid-send.
     */
    const TOKEN_EXPIRY_SKEW = 60;

    /**
     * Seconds to wait on Google.
     */
    const TIMEOUT = 20;


    /**
     * @var string Service account address, e.g. odr-mailer@project.iam.gserviceaccount.com
     */
    private $service_account;

    /**
     * @var string The service account's RSA private key, PEM encoded
     */
    private $private_key;

    /**
     * @var string The Workspace user being impersonated; also the From address
     */
    private $impersonated_user;

    /**
     * @var string OAuth client id, when using the refresh-token flow
     */
    private $client_id;

    /**
     * @var string
     */
    private $client_secret;

    /**
     * @var string Obtained once via odr:mailer:authorize
     */
    private $refresh_token;

    /**
     * @var CacheService|null
     */
    private $cache_service;


    /**
     * @param string $service_account
     * @param string $private_key PEM
     * @param string $impersonated_user
     * @param CacheService|null $cache_service Caches the access token between sends
     * @param EventDispatcherInterface|null $dispatcher
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        $service_account,
        $private_key,
        $impersonated_user,
        ?CacheService $cache_service = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        $client_id = '',
        $client_secret = '',
        $refresh_token = ''
    ) {
        parent::__construct($dispatcher, $logger);

        $this->service_account = $service_account;
        $this->private_key = $private_key;
        $this->impersonated_user = $impersonated_user;
        $this->cache_service = $cache_service;
        $this->client_id = (string)$client_id;
        $this->client_secret = (string)$client_secret;
        $this->refresh_token = (string)$refresh_token;
    }

    /**
     * Whether this instance is configured for the OAuth refresh-token flow rather than a service
     * account.  Checked first, because an install that has both should use the one that doesn't
     * depend on a service-account key.
     *
     * @return bool
     */
    private function usesRefreshToken()
    {
        return ($this->client_id !== '' && $this->client_secret !== '' && $this->refresh_token !== '');
    }


    /**
     * @inheritdoc
     */
    protected function doSend(SentMessage $message): void
    {
        $token = $this->getAccessToken();

        // The Gmail API takes the whole RFC 2822 message, base64url encoded
        $raw = self::base64UrlEncode($message->toString());
        $url = str_replace('{user}', rawurlencode($this->impersonated_user), self::SEND_URL);

        list($status, $body) = $this->post(
            $url,
            json_encode(array('raw' => $raw)),
            array('Content-Type: application/json', 'Authorization: Bearer '.$token)
        );

        if ($status !== 200) {
            // A token that worked a moment ago can be revoked...drop it so the next attempt mints
            //  a fresh one rather than failing the same way
            if ($status === 401)
                $this->forgetAccessToken();

            throw new TransportException(
                'Gmail API refused the message (HTTP '.$status.'): '.self::describeError($body)
            );
        }
    }


    /**
     * A usable access token, from cache when possible.
     *
     * @return string
     */
    private function getAccessToken()
    {
        $key = $this->tokenCacheKey();

        if ($this->cache_service !== null && $this->cache_service->exists($key)) {
            $cached = $this->cache_service->get($key);
            if (is_string($cached) && $cached !== '')
                return $cached;
        }

        $token = $this->requestAccessToken();

        if ($this->cache_service !== null) {
            $this->cache_service->set($key, $token);
            $this->cache_service->expire($key, self::ASSERTION_TTL - self::TOKEN_EXPIRY_SKEW);
        }

        return $token;
    }


    /**
     * Signs an assertion and trades it for an access token.
     *
     * @return string
     */
    private function requestAccessToken()
    {
        if ($this->usesRefreshToken())
            return $this->requestAccessTokenFromRefreshToken();

        if ($this->service_account === '' || $this->private_key === '' || $this->impersonated_user === '')
            throw new TransportException('The Gmail API transport is not configured.  Set either gmail_api_client_id / _client_secret / _refresh_token, or gmail_api_service_account / _private_key / _user.');

        $now = time();
        $claims = array(
            'iss' => $this->service_account,
            // "sub" is what makes this domain-wide delegation: act as this Workspace user
            'sub' => $this->impersonated_user,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + self::ASSERTION_TTL,
        );

        $assertion = $this->buildSignedJwt($claims);

        list($status, $body) = $this->post(
            self::TOKEN_URL,
            http_build_query(array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            )),
            array('Content-Type: application/x-www-form-urlencoded')
        );

        $decoded = json_decode($body, true);

        if ($status !== 200 || !isset($decoded['access_token'])) {
            // The two usual causes are worth naming, because the raw message is terse
            $hint = '';
            $error = is_array($decoded) && isset($decoded['error']) ? $decoded['error'] : '';
            if ($error === 'unauthorized_client')
                $hint = '  (the service account is probably not authorized for '.self::SCOPE.' in the Workspace admin console -- domain-wide delegation)';
            else if ($error === 'invalid_grant')
                $hint = '  (check that "'.$this->impersonated_user.'" is a real user in the Workspace domain, and that this server\'s clock is correct)';

            throw new TransportException(
                'Could not get a Gmail access token (HTTP '.$status.'): '.self::describeError($body).$hint
            );
        }

        return (string)$decoded['access_token'];
    }


    /**
     * Trades the stored refresh token for an access token.
     *
     * @return string
     */
    private function requestAccessTokenFromRefreshToken()
    {
        list($status, $body) = $this->post(
            self::TOKEN_URL,
            http_build_query(array(
                'grant_type' => 'refresh_token',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $this->refresh_token,
            )),
            array('Content-Type: application/x-www-form-urlencoded')
        );

        $decoded = json_decode($body, true);

        if ($status !== 200 || !isset($decoded['access_token'])) {
            $error = is_array($decoded) && isset($decoded['error']) ? $decoded['error'] : '';
            $hint = '';
            if ($error === 'invalid_grant')
                $hint = '  (the refresh token has been revoked or expired -- re-run "php app/console odr:mailer:authorize" to get a new one.  Changing the mailbox password revokes it, and an unpublished OAuth consent screen expires tokens after 7 days)';
            else if ($error === 'invalid_client')
                $hint = '  (check gmail_api_client_id and gmail_api_client_secret)';

            throw new TransportException(
                'Could not refresh the Gmail access token (HTTP '.$status.'): '.self::describeError($body).$hint
            );
        }

        return (string)$decoded['access_token'];
    }


    /**
     * Builds and RS256-signs a JWT.
     *
     * @param array $claims
     *
     * @return string
     */
    private function buildSignedJwt($claims)
    {
        $segments = array(
            self::base64UrlEncode(json_encode(array('alg' => 'RS256', 'typ' => 'JWT'))),
            self::base64UrlEncode(json_encode($claims)),
        );
        $unsigned = implode('.', $segments);

        $key = openssl_pkey_get_private($this->private_key);
        if ($key === false)
            throw new TransportException('The Gmail service account private key could not be read: '.openssl_error_string());

        $signature = '';
        $signed = openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$signed)
            throw new TransportException('Could not sign the Gmail assertion: '.openssl_error_string());

        return $unsigned.'.'.self::base64UrlEncode($signature);
    }


    /**
     * One POST, returning [status, body].
     *
     * @param string $url
     * @param string $payload
     * @param string[] $headers
     *
     * @return array
     */
    private function post($url, $payload, $headers)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false)
            throw new TransportException('Could not reach Google: '.$error);

        return array($status, (string)$body);
    }


    /**
     * Pulls something readable out of an error response.
     *
     * @param string $body
     *
     * @return string
     */
    private static function describeError($body)
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded))
            return trim(substr($body, 0, 300));

        if (isset($decoded['error']['message']))
            return $decoded['error']['message'];
        if (isset($decoded['error_description']))
            return $decoded['error_description'];
        if (isset($decoded['error']) && is_string($decoded['error']))
            return $decoded['error'];

        return trim(substr($body, 0, 300));
    }


    /**
     * @return string
     */
    private function tokenCacheKey()
    {
        return 'gmail_api_token_'.md5(
            $this->usesRefreshToken()
                ? 'oauth|'.$this->client_id.'|'.$this->impersonated_user
                : 'sa|'.$this->service_account.'|'.$this->impersonated_user
        );
    }


    /**
     * Drops the cached token.
     */
    private function forgetAccessToken()
    {
        if ($this->cache_service !== null)
            $this->cache_service->delete($this->tokenCacheKey());
    }


    /**
     * @param string $value
     *
     * @return string
     */
    private static function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }


    /**
     * @inheritdoc
     */
    public function __toString(): string
    {
        return 'gmail+api://'.$this->impersonated_user;
    }
}
