<?php

/**
 * Open Data Repository Data Publisher
 * Security Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Renders the login form. Replaces FOSUserBundle's SecurityController (removed in the Symfony 5
 * upgrade). login_check and logout are handled by the firewall (form_login / logout), so only the
 * GET login form needs a controller. Reuses the existing @ODROpenRepositoryUser/Security/login.html.twig.
 */

namespace ODR\OpenRepository\UserBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;


class SecurityController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly AuthenticationUtils $authenticationUtils,
        private readonly CsrfTokenManagerInterface $csrfTokenManager
    ) {
    }

    /**
     * Renders the login form (route: fos_user_security_login).
     *
     * @return Response
     */
    public function loginAction()
    {
        return new Response($this->twig->render('@ODROpenRepositoryUser/Security/login.html.twig', [
            'error' => $this->authenticationUtils->getLastAuthenticationError(),
            // matches the form_login default csrf_token_id of "authenticate"
            'csrf_token' => $this->csrfTokenManager->getToken('authenticate')->getValue(),
        ]));
    }

    /**
     * The firewall (form_login) intercepts this path before the controller runs; route exists only so
     * the form action / check_path can be generated.
     */
    public function checkAction()
    {
        throw new \LogicException('This should be intercepted by the form_login firewall.');
    }

    /**
     * The firewall (logout) intercepts this path before the controller runs.
     */
    public function logoutAction()
    {
        throw new \LogicException('This should be intercepted by the logout firewall.');
    }
}
