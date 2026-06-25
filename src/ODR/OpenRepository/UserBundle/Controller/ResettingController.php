<?php

/**
 * Open Data Repository Data Publisher
 * Resetting Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Standalone password-reset flow (request -> email link -> set new password). Replaces FOSUserBundle's
 * ResettingController (removed in the Symfony 5 upgrade) using the existing fos_user columns
 * (confirmation_token, password_requested_at) and the existing @ODROpenRepositoryUser/Resetting/*
 * templates. Logged-in users are redirected to their profile instead of resetting.
 */

namespace ODR\OpenRepository\UserBundle\Controller;

use ODR\OpenRepository\UserBundle\Component\Service\ODRTokenGenerator;
use ODR\OpenRepository\UserBundle\Component\Service\ODRUserManager;
use ODR\OpenRepository\UserBundle\Entity\User;
use ODR\OpenRepository\UserBundle\Form\ResetPasswordForm;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;


class ResettingController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ODRUserManager $user_manager,
        private readonly ODRTokenGenerator $token_generator,
        private readonly \Swift_Mailer $mailer,
        private readonly RouterInterface $router,
        private readonly FormFactoryInterface $form_factory,
        private readonly TokenStorageInterface $token_storage,
        private readonly string $site_baseurl,
        private readonly ?string $from_email = null,
        private readonly int $reset_token_ttl = 86400
    ) {
    }

    /**
     * If a real user is logged in, return a redirect to their profile page; otherwise null.
     */
    private function redirectLoggedInUser(): ?RedirectResponse
    {
        $token = $this->token_storage->getToken();
        $user = $token ? $token->getUser() : null;
        if ($user instanceof User)
            return new RedirectResponse($this->site_baseurl.'#'.$this->router->generate('odr_self_profile_edit'));

        return null;
    }

    /**
     * Shows the "enter your email" form (route: fos_user_resetting_request).
     */
    public function requestAction()
    {
        if ($redirect = $this->redirectLoggedInUser())
            return $redirect;

        return new Response($this->twig->render('@ODROpenRepositoryUser/Resetting/request.html.twig', []));
    }

    /**
     * Handles the reset request: emails a reset link if the account exists (route: fos_user_resetting_send_email).
     */
    public function sendEmailAction(Request $request)
    {
        if ($redirect = $this->redirectLoggedInUser())
            return $redirect;

        $username = (string)$request->request->get('username');

        /** @var User|null $user */
        $user = $this->user_manager->findUserByUsernameOrEmail($username);
        if (null === $user) {
            return new Response($this->twig->render('@ODROpenRepositoryUser/Resetting/request.html.twig', [
                'invalid_username' => $username,
            ]));
        }

        // If a reset was already requested recently, don't send another (offer a resend instead)
        if ($user->isPasswordRequestNonExpired($this->reset_token_ttl)) {
            return new Response($this->twig->render('@ODROpenRepositoryUser/Resetting/passwordAlreadyRequested.html.twig', [
                'username' => $username,
            ]));
        }

        $user->setConfirmationToken($this->token_generator->generateToken());
        $user->setPasswordRequestedAt(new \DateTime());
        $this->user_manager->updateUser($user);

        $this->sendResettingEmail($user);

        return new RedirectResponse($this->router->generate('fos_user_resetting_check_email'));
    }

    /**
     * "Check your email" confirmation page (route: fos_user_resetting_check_email).
     */
    public function checkEmailAction()
    {
        if ($redirect = $this->redirectLoggedInUser())
            return $redirect;

        return new Response($this->twig->render('@ODROpenRepositoryUser/Resetting/checkEmail.html.twig', []));
    }

    /**
     * Validates the token and lets the user set a new password (route: fos_user_resetting_reset).
     */
    public function resetAction(Request $request, $token)
    {
        if ($redirect = $this->redirectLoggedInUser())
            return $redirect;

        /** @var User|null $user */
        $user = $this->user_manager->findUserByConfirmationToken($token);

        // Invalid or expired token -> bounce back to the request form
        if (null === $user || !$user->isPasswordRequestNonExpired($this->reset_token_ttl))
            return new RedirectResponse($this->router->generate('fos_user_resetting_request'));

        $form = $this->form_factory->create(ResetPasswordForm::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Clear the reset token and persist (ODRUserManager hashes the new plainPassword)
            $user->setConfirmationToken(null);
            $user->setPasswordRequestedAt(null);
            $user->setEnabled(true);
            $this->user_manager->updateUser($user);

            return new RedirectResponse($this->router->generate('fos_user_security_login'));
        }

        return new Response($this->twig->render('@ODROpenRepositoryUser/Resetting/reset.html.twig', [
            'token' => $token,
            'form' => $form->createView(),
        ]));
    }

    /**
     * Clears any pending request and re-sends a reset email (route: fos_user_resetting_resend_email).
     */
    public function resendAction(Request $request)
    {
        if ($redirect = $this->redirectLoggedInUser())
            return $redirect;

        $username = (string)$request->request->get('username');

        /** @var User|null $user */
        $user = $this->user_manager->findUserByUsernameOrEmail($username);
        if (null === $user) {
            return new Response($this->twig->render('@ODROpenRepositoryUser/Resetting/request.html.twig', [
                'invalid_username' => $username,
            ]));
        }

        // Reset the request state so sendEmailAction will send a fresh email
        $user->setConfirmationToken(null);
        $user->setPasswordRequestedAt(null);
        $this->user_manager->updateUser($user);

        return $this->sendEmailAction($request);
    }

    /**
     * Sends the password-reset email containing the tokenized reset link.
     */
    private function sendResettingEmail(User $user): void
    {
        $reset_url = $this->router->generate(
            'fos_user_resetting_reset',
            ['token' => $user->getConfirmationToken()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $body = "A password reset was requested for your Open Data Repository account.\n\n"
            ."To choose a new password, open the following link:\n".$reset_url."\n\n"
            ."If you did not request this, you can safely ignore this email.";

        // fall back to a noreply@<host> address when no mailer "from" is configured
        $from = $this->from_email;
        if (empty($from)) {
            $host = parse_url($this->site_baseurl, PHP_URL_HOST) ?: 'localhost';
            $from = 'noreply@'.$host;
        }

        $message = (new \Swift_Message('Password Reset Request'))
            ->setFrom($from)
            ->setTo($user->getEmail())
            ->setBody($body, 'text/plain');

        $this->mailer->send($message);
    }
}
