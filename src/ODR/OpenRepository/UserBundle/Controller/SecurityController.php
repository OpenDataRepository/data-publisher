<?php

/**
 * Open Data Repository Data Publisher
 * Security Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Overrides FOSUserBundle's default SecurityController, to allow for
 * separating a regular login and an OAuth login.
 */

namespace ODR\OpenRepository\UserBundle\Controller;

use FOS\UserBundle\Controller\SecurityController as BaseController;
use Symfony\Component\HttpFoundation\Request;


class SecurityController extends BaseController
{

    /**
     * This function is needed to separate the OAuth login from the regular login.
     *
     * @inheritdoc
     */
    public function loginAction(Request $request)
    {
        return parent::loginAction($request);
    }


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

        // @see http://symfony.com/blog/new-in-symfony-2-6-security-component-improvements#added-a-security-error-helper
        $helper = $this->get('security.authentication_utils');

        return $this->render('ODROpenRepositoryUserBundle:Security:oauth_login.html.twig', array(
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
