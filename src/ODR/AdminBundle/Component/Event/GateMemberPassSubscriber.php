<?php

/**
 * Open Data Repository Data Publisher
 * Gate Member Pass Subscriber
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Keeps a logged-in ODR user's member pass for the Apache gate up to date, so the gate lets them
 * through without the reCAPTCHA even when their address changes (iCloud Private Relay, phones).
 * See docs/server/bot-bans/gate/member_pass.php, which does the signing, and BAN_SYSTEM.md.
 *
 * Does nothing on a site that isn't behind the gate: Apache only names the gate's directory and
 * settings (odr_gate_dir, odr_gate_config) on sites that use it.
 */

namespace ODR\AdminBundle\Component\Event;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

class GateMemberPassSubscriber implements EventSubscriberInterface
{

    /**
     * GateMemberPassSubscriber constructor.
     *
     * @param TokenStorageInterface $token_storage
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TokenStorageInterface $token_storage,
        private readonly LoggerInterface $logger
    ) {
    }


    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
            // After the logout listeners that build the response (the redirect) have run
            LogoutEvent::class => ['onLogout', -100],
        ];
    }


    /**
     * Sets or renews the member pass on any page a logged-in user loads, when theirs is missing or
     * past half its life.
     *
     * @param ResponseEvent $event
     */
    public function onKernelResponse(ResponseEvent $event)
    {
        if ( !$event->isMainRequest() )
            return;

        $request = $event->getRequest();

        // API clients don't need it: the gate never asks the API for a reCAPTCHA
        if ( preg_match('#^/(?:[A-Za-z0-9_-]+/)?api/v[0-9]#', $request->getPathInfo())
            || preg_match('#^/(?:[A-Za-z0-9_-]+/)?api/v[0-9]#', $request->getRequestUri())
        ) {
            return;
        }

        $user = $this->token_storage->getToken()?->getUser();
        if ( !($user instanceof UserInterface) )
            return;

        $context = $this->getContext($request);
        if ( $context === null )
            return;

        if ( !odr_gate_member_needs_renewal($context, $request->cookies->all()) )
            return;

        [$name, $value, $options] = odr_gate_member_issue($context);
        $this->setCookie($event->getResponse(), $name, $value, $options);
        // So anything else answering this same request (WordPress, around an embedded ODR) sees it
        $_COOKIE[$name] = $value;
    }


    /**
     * Deletes the member pass when the user logs out of ODR.
     *
     * @param LogoutEvent $event
     */
    public function onLogout(LogoutEvent $event)
    {
        $response = $event->getResponse();
        if ( $response === null )
            return;

        $context = $this->getContext($event->getRequest());
        if ( $context === null )
            return;

        [$name, $options] = odr_gate_member_clear($context);
        $this->setCookie($response, $name, '', $options);
    }


    /**
     * The gate's member-pass settings for this request, or null when the site isn't behind the gate.
     *
     * @param Request $request
     * @return array|null
     */
    private function getContext(Request $request)
    {
        $server = $request->server->all();
        $dir = (string)($server['odr_gate_dir'] ?? $server['REDIRECT_odr_gate_dir'] ?? '');
        if ( $dir === '' )
            return null;

        $file = $dir.'/member_pass.php';
        if ( !function_exists('odr_gate_member_context') ) {
            if ( !is_file($file) ) {
                $this->logger->warning('GateMemberPassSubscriber: '.$file.' not found, member pass not set');
                return null;
            }
            require_once $file;
        }

        return odr_gate_member_context($server);
    }


    /**
     * Adds a cookie described in setcookie()'s option form to $response.
     */
    private function setCookie(Response $response, string $name, string $value, array $options)
    {
        $response->headers->setCookie(
            Cookie::create(
                $name,
                $value,
                $options['expires'],
                $options['path'],
                $options['domain'] ?? null,
                $options['secure'],
                $options['httponly'],
                false,
                Cookie::SAMESITE_LAX
            )
        );
    }
}
