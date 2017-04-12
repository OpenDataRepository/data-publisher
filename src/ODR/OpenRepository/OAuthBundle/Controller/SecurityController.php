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

namespace ODR\OpenRepository\OAuthBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;


class SecurityController extends Controller
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
            if (false !== strpos($session->get('_security.target_path'), $this->generateUrl('fos_oauth_server_authorize'))) {
                $session->set('_fos_oauth_server.ensure_logout', true);
            }
        }

        // TODO - this doesn't seem to actually do anything on authentication failure
        $helper = $this->get('security.authentication_utils');

        return $this->render('ODROpenRepositoryOAuthBundle:Security:oauth_login.html.twig', array(
            'last_username' => $helper->getLastUsername(),
            'error'         => $helper->getLastAuthenticationError(),
        ));
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
