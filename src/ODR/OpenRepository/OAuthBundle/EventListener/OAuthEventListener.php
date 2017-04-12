<?php

/**
 * Open Data Repository Data Publisher
 * OAuth Event Listener
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Listens to the FOSOAuthServer's pre/post authorization events so users only have to authorize a client once.
 *
 * @see https://github.com/FriendsOfSymfony/FOSOAuthServerBundle/blob/master/Resources/doc/the_oauth_event_class.md
 *
 */

namespace ODR\OpenRepository\OAuthBundle\EventListener;

// Entities
use ODR\OpenRepository\OAuthBundle\Entity\Client;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use FOS\OAuthServerBundle\Event\OAuthEvent;
// Symfony
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


class OAuthEventListener implements EventSubscriberInterface
{

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            OAuthEvent::PRE_AUTHORIZATION_PROCESS => 'onPreAuthorizationProcess',
            OAuthEvent::POST_AUTHORIZATION_PROCESS => 'onPostAuthorizationProcess',
        );
    }


    /**
     * Called during @see FOS/OAuthServerBundle/Controller/AuthorizeController::authorizeAction()
     *
     * By setting $event->isAuthorizedClient() to true, the authorization dialog of "Do you want to allow <CLIENT> to
     * access your data" dialog can be skipped.
     *
     * @param OAuthEvent $event
     */
    public function onPreAuthorizationProcess(OAuthEvent $event)
    {
        /** @var ODRUser $user */
        $user = $event->getUser();
        /** @var Client $client */
        $client = $event->getClient();

        // If the user has previously authorized this client, then allow the OAuth login flow to bypass the authorization dialog
        if ($user && $client)
            $event->setAuthorizedClient( $user->isAuthorizedClient($client) );
    }


    /**
     * Called during @see FOS/OAuthServerBundle/Controller/AuthorizeController::processSuccess()
     *
     * At that point in the OAuth flow, the user has authorized <CLIENT> to do what it wants...to prevent them from
     * having to authorize the <CLIENT> repeatedly.
     *
     * @param OAuthEvent $event
     */
    public function onPostAuthorizationProcess(OAuthEvent $event)
    {
        /** @var ODRUser $user */
        $user = $event->getUser();
        /** @var Client $client */
        $client = $event->getClient();

        // Store that this user authorized this client so they don't have to authorize it again later
        if ($user && $client && $event->isAuthorizedClient() && !$user->isAuthorizedClient($client))
            $user->addClient($client);
    }
}
