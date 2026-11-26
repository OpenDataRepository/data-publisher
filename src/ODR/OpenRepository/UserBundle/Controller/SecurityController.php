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

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;


class SecurityController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly AuthenticationUtils $authenticationUtils,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $router
    ) {
    }

    /**
     * Renders the login form (route: fos_user_security_login).
     *
     * @param Request $request
     * @return Response
     */
    public function loginAction(Request $request)
    {
        return new Response($this->twig->render('@ODROpenRepositoryUser/Security/login.html.twig', [
            'error' => $this->authenticationUtils->getLastAuthenticationError(),
            // matches the form_login default csrf_token_id of "authenticate"
            'csrf_token' => $this->csrfTokenManager->getToken('authenticate')->getValue(),
            // set by checkInterruptedAction(): the last attempt never reached ODR
            'login_interrupted' => $request->query->has('interrupted'),
        ]));
    }

    /**
     * A GET of login_check (route: odr_security_check_interrupted).  A login form only ever POSTs
     * there, so this means the POST never reached ODR: the Apache gate stopped it for a reCAPTCHA
     * (the visitor's address changed since they loaded the form, e.g. iCloud Private Relay) and,
     * once solved, sent the browser back to this url.  Their password went nowhere -- the gate never
     * passes a form on -- so all that's left is to send them back to the form, saying why.
     *
     * The gate normally sends them back to the form page itself (challenge.php); this is for when
     * the browser didn't say which page that was.
     *
     * @return RedirectResponse
     */
    public function checkInterruptedAction()
    {
        return new RedirectResponse(
            $this->router->generate('fos_user_security_login', ['interrupted' => 1]),
            303
        );
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
