<?php

/**
 * Open Data Repository Data Publisher
 * Security Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * @see https://github.com/FriendsOfSymfony/FOSOAuthServerBundle/blob/master/Resources/doc/a_note_about_security.md
 * @see http://symfony.com/blog/new-in-symfony-2-6-security-component-improvements#added-a-security-error-helper
 */

namespace ODR\OpenRepository\OAuthServerBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;


class SecurityController extends \Symfony\Bundle\FrameworkBundle\Controller\AbstractController
{

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function oauthloginAction(Request $request)
    {
        $session = $request->getSession();

        // Add the following lines
        if ($session->has('_security.target_path')) {
            if (str_contains((string) $session->get('_security.target_path'), $this->generateUrl('fos_oauth_server_authorize'))) {
                $session->set('_fos_oauth_server.ensure_logout', true);
            }
        }

        $csrfToken = $this->has('security.csrf.token_manager')
            ? $this->container->get('security.csrf.token_manager')->getToken('authenticate')->getValue()
            : null;

        // TODO - this doesn't seem to actually do anything on authentication failure
        $helper = $this->container->get('security.authentication_utils');

        return $this->render('@ODROpenRepositoryOAuthServer/Security/oauth_login.html.twig', [
            'last_username' => $helper->getLastUsername(),
            'error'         => $helper->getLastAuthenticationError(),
            'csrf_token'    => $csrfToken
        ]);
    }


    /**
     * Apparently Symfony never calls this function.
     *
     * @param Request $request
     */
    public function oauthloginCheckAction(Request $request)
    {

    }
}
