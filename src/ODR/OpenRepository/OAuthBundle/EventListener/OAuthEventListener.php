<?php

namespace ODR\OpenRepository\OAuthBundle\EventListener;

/*
use FOS\UserBundle\FOSUserEvents;
use FOS\UserBundle\Event\FormEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
*/

use FOS\OAuthServerBundle\Event\OAuthEvent;
use OAuth2\OAuth2;
use OAuth2\OAuth2AuthenticateException;
use OAuth2\OAuth2ServerException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


class OAuthEventListener implements EventSubscriberInterface
{
/*
    private $router;
    private $site_baseurl;

    public function __construct(UrlGeneratorInterface $router, $site_baseurl)
    {
        $this->router = $router;
        $this->site_baseurl = $site_baseurl;
    }
*/

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

    public function onPreAuthorizationProcess(OAuthEvent $event)
    {

        $user = $event->getUser();
        $roles = $user->getRoles();
/*
        throw new \Exception('do not continue');

        if (in_array('ROLE_JUPYTERHUB_USER', $roles))
//            $event->setAuthorizedClient(true);
//            throw new OAuth2AuthenticateException(OAuth2::HTTP_UNAUTHORIZED, OAuth2::TOKEN_TYPE_BEARER, '', OAuth2::ERROR_USER_DENIED, 'user has role' );
        throw new OAuth2ServerException(OAuth2::HTTP_UNAUTHORIZED, OAuth2::ERROR_USER_DENIED, 'user has role');
        else
//            $event->setAuthorizedClient(false);
//            throw new OAuth2AuthenticateException(OAuth2::HTTP_UNAUTHORIZED, OAuth2::TOKEN_TYPE_BEARER, '', OAuth2::ERROR_USER_DENIED, 'user does not have role' );
            throw new OAuth2ServerException(OAuth2::HTTP_UNAUTHORIZED, OAuth2::ERROR_USER_DENIED, 'user does not have role');
*/
    }

    public function onPostAuthorizationProcess(OAuthEvent $event)
    {

    }
}
