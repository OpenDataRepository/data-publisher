<?php

/**
* Open Data Repository Data Publisher
* Resetting Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* Overrides FOSUserBundle's password reset controller so logged-in
* users get redirected to their profile page instead of getting an
* email to reset their password.
*/


namespace ODR\OpenRepository\UserBundle\Controller;

// Symfony
use FOS\UserBundle\Controller\ResettingController as BaseController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ResettingController extends BaseController
{

    /**
     * Override to default FoSBundle:requestAction(), where logged in users are redirected to their profile
     * instead of performing the requested action
     */
    public function requestAction(Request $request)
    {
        // Determine if user is logged in
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if ($user == 'anon.')
            $user = null;

        // If user is logged in, redirect to their profile page
        if ($user !== null) {
            $site_baseurl = $this->container->getParameter('site_baseurl');
            return new RedirectResponse( $site_baseurl.'#'.$this->generateUrl('odr_self_profile_edit') );
        }

        // Otherwise, continue as usual
        return parent::requestAction();
    }


    /**
     * Override to default FoSBundle:sendEmailAction(), where logged in users are redirected to their profile
     * instead of performing the requested action
     */
    public function sendEmailAction(Request $request)
    {
        // Determine if user is logged in
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if ($user == 'anon.')
            $user = null;

        // If user is logged in, redirect to their profile page
        if ($user !== null) {
            $site_baseurl = $this->container->getParameter('site_baseurl');
            return new RedirectResponse( $site_baseurl.'#'.$this->generateUrl('odr_self_profile_edit') );
        }

        // ----------------------------------------
        // Copied from FoSBundle:sendEmailAction()...
        $username = $request->request->get('username');

        /** @var $user UserInterface */
        $user = $this->get('fos_user.user_manager')->findUserByUsernameOrEmail($username);
        if (null === $user) {
            return $this->render('FOSUserBundle:Resetting:request.html.twig', array(
                'invalid_username' => $username
            ));
        }

        // If this user has already requested a password, pass their email address to the relevant twig file...required if they need to request another email for some reason
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
     * Override to default FoSBundle:checkEmailAction(), where logged in users are redirected to their profile
     * instead of performing the requested action
     */
    public function checkEmailAction(Request $request)
    {
        // Determine if user is logged in
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if ($user == 'anon.')
            $user = null;

        // If user is logged in, redirect to their profile page
        if ($user !== null) {
            $site_baseurl = $this->container->getParameter('site_baseurl');
            return new RedirectResponse( $site_baseurl.'#'.$this->generateUrl('odr_self_profile_edit') );
        }

        // Otherwise, continue as usual
        return parent::checkEmailAction($request);
    }


    /**
     * Override to default FoSBundle:resetAction(), where logged in users are redirected to their profile
     * instead of performing the requested action
     */
    public function resetAction(Request $request, $token)
    {
        // Determine if user is logged in
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if ($user == 'anon.')
            $user = null;

        // If user is logged in, redirect to their profile page
        if ($user !== null) {
            $site_baseurl = $this->container->getParameter('site_baseurl');
            return new RedirectResponse( $site_baseurl.'#'.$this->generateUrl('odr_self_profile_edit') );
        }

        // Otherwise, continue as usual
        return parent::resetAction($request, $token);
    }


    /**
     * Enables the user to request another password reset email be sent to their email address
     */
    public function resendAction(Request $request)
    {
       // Determine if user is logged in
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if ($user == 'anon.')
            $user = null;

        // If user is logged in, redirect to their profile page
        if ($user !== null) {
            $site_baseurl = $this->container->getParameter('site_baseurl');
            return new RedirectResponse( $site_baseurl.'#'.$this->generateUrl('odr_self_profile_edit') );
        }

        // Need to determine which user wants another password reset email
        $username = $request->request->get('username');
        $user_manager = $this->get('fos_user.user_manager');
        $user = $user_manager->findUserByUsernameOrEmail($username);

        if (null === $user) {
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

