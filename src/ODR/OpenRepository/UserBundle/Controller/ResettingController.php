<?php

/**
 * Open Data Repository Data Publisher
 * Resetting Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Overrides FOSUserBundle's password reset controller so logged-in users can't request an email
 * to reset their password.  Instead, they get redirected to their profile page.
 */

namespace ODR\OpenRepository\UserBundle\Controller;

// FOS
use FOS\UserBundle\Controller\ResettingController as BaseController;
// Entities
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;


class ResettingController extends BaseController
{

    /**
     * Overrides FoSBundle:ResettingController:requestAction(), redirecting logged-in users to
     * their profile
     */
    public function requestAction()
    {
        // Determine if user is logged in
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if ($user == 'anon.')
            $user = null;

        // If user is logged in, redirect to their profile page
        if ( !is_null($user) ) {
            $site_baseurl = $this->container->getParameter('site_baseurl');
            return new RedirectResponse( $site_baseurl.'#'.$this->generateUrl('odr_self_profile_edit') );
        }

        // Otherwise, continue as usual
        return parent::requestAction();
    }


    /**
     * Overrides FoSBundle:ResettingController:sendEmailAction(), redirecting logged-in users to
     * their profile instead of sending out an email upon a password reset request
     */
    public function sendEmailAction(Request $request)
    {
        // Determine if user is logged in
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if ($user == 'anon.')
            $user = null;

        // If user is logged in, redirect to their profile page
        if ( !is_null($user) ) {
            $site_baseurl = $this->container->getParameter('site_baseurl');
            return new RedirectResponse( $site_baseurl.'#'.$this->generateUrl('odr_self_profile_edit') );
        }

        // ----------------------------------------
        // Copied from FoSBundle:ResettingController:sendEmailAction()
        $username = $request->request->get('username');

        /** @var ODRUser $user */
        $user = $this->get('fos_user.user_manager')->findUserByUsernameOrEmail($username);
        if (null === $user) {
            return $this->render('FOSUserBundle:Resetting:request.html.twig', array(
                'invalid_username' => $username
            ));
        }

        // If this user has already requested a password, pass their email address to the relevant
        //  twig file...required if they need to request another email for some reason
        if ($user->isPasswordRequestNonExpired($this->container->getParameter('fos_user.resetting.token_ttl'))) {
            return $this->render(
                'FOSUserBundle:Resetting:passwordAlreadyRequested.html.twig',
                array(
                    'username' => $username
                )
            );
        }


        // ----------------------------------------
        // Otherwise, continue as usual
        return parent::sendEmailAction($request);
    }


    /**
     * Overrides FoSBundle:ResettingController:checkEmailAction(), redirecting logged-in users to
     * their profile instead of telling them to check their email after a password reset request
     */
    public function checkEmailAction(Request $request)
    {
        // Determine if user is logged in
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if ($user == 'anon.')
            $user = null;

        // If user is logged in, redirect to their profile page
        if ( !is_null($user) ) {
            $site_baseurl = $this->container->getParameter('site_baseurl');
            return new RedirectResponse( $site_baseurl.'#'.$this->generateUrl('odr_self_profile_edit') );
        }

        // Otherwise, continue as usual
        return parent::checkEmailAction($request);
    }


    /**
     * Overrides FoSBundle:ResettingController:resetAction(), redirecting logged-in users to their
     * profile instead of resetting their password after a reset request
     */
    public function resetAction(Request $request, $token)
    {
        // Determine if user is logged in
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if ($user == 'anon.')
            $user = null;

        // If user is logged in, redirect to their profile page
        if ( !is_null($user) ) {
            $site_baseurl = $this->container->getParameter('site_baseurl');
            return new RedirectResponse( $site_baseurl.'#'.$this->generateUrl('odr_self_profile_edit') );
        }

        // Otherwise, continue as usual
        return parent::resetAction($request, $token);
    }


    /**
     * Allows the user to request another password reset email be sent to their email address.
     * TODO - time limitation on how often this can get called?  Or change config to allow more than once every 2 hours?
     */
    public function resendAction(Request $request)
    {
       // Determine if user is logged in
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if ($user == 'anon.')
            $user = null;

        // If user is logged in, redirect to their profile page
        if ( !is_null($user)  ) {
            $site_baseurl = $this->container->getParameter('site_baseurl');
            return new RedirectResponse( $site_baseurl.'#'.$this->generateUrl('odr_self_profile_edit') );
        }

        // Need to determine which user wants another password reset email
        $username = $request->request->get('username');
        $user_manager = $this->get('fos_user.user_manager');

        /** @var ODRUser $user */
        $user = $user_manager->findUserByUsernameOrEmail($username);
        if ( is_null($user) ) {
            return $this->render('FOSUserBundle:Resetting:request.html.twig', array(
                'invalid_username' => $username
            ));
        }

        // Reset the confirmation token and password request timestamp so sendEmailAction doesn't state "password already sent"
        $user->setConfirmationToken(null);
        $user->setPasswordRequestedAt(null);
        $user_manager->updateUser($user);

        return parent::sendEmailAction($request);
    }
}
