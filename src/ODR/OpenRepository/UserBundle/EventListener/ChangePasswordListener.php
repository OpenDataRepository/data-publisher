<?php

/**
* Open Data Repository Data Publisher
* Change Password Listener
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* Overrides FoS to send the user to their profile page after
* sucessfully changing their password.
*/


namespace ODR\OpenRepository\UserBundle\EventListener;

use FOS\UserBundle\FOSUserEvents;
use FOS\UserBundle\Event\FormEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


class ChangePasswordListener implements EventSubscriberInterface
{
    private $router;
    private $site_baseurl;

    public function __construct(UrlGeneratorInterface $router, $site_baseurl)
    {
        $this->router = $router;
        $this->site_baseurl = $site_baseurl;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            FOSUserEvents::CHANGE_PASSWORD_SUCCESS => 'onChangePasswordSuccess',
        );
    }

    public function onChangePasswordSuccess(FormEvent $event)
    {
        $url = $this->router->generate('odr_admin_homepage').'#'.$this->router->generate('odr_self_profile_edit');

        $event->setResponse(new RedirectResponse($url));
    }
}
