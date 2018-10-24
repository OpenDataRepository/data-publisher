<?php

/**
 * Open Data Repository Data Publisher
 * Utility Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Due to HWIOAuthBundle effectively assuming it's the only bundle being used to log a user into
 * a Symfony site, this controller exists so users can be properly redirected back to their
 * originally requested URLs after authentication.
 */

namespace ODR\OpenRepository\UserBundle\Controller;

// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
// Services
use ODR\OpenRepository\UserBundle\Component\Service\TrackedPathService;
// Symfony
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;


class UtilityController extends Controller
{

    /**
     * This action is called when the "Login" button in the upper-right corner of the screen is
     * clicked...the page provides the current baseurl for this action to save in the user's
     * session.  Symfony won't automatically set the session key because the user typically isn't
     * directly accessing a "secured" area of the firewall.
     *
     * @param Request $request
     *
     * @throws ODRBadRequestException
     *
     * @return JsonResponse
     */
    public function saveurlAction(Request $request)
    {
        // Going to need these...
        $session = $request->getSession();
//        $router = $this->get('router');

        // Ensure query was correctly formed
        if (!$request->query->has('url') )
            throw new ODRBadRequestException('Invalid query string', 0xf48d5eba);

        // Ensure the requested url to save is of this domain
        $url = $request->query->get('url');
        $site_baseurl = $this->container->getParameter('site_baseurl');

        if ( strpos($url, $site_baseurl) !== 0 )
            throw new ODRBadRequestException('Invalid query string', 0xed466573);

        // Ensure all target paths are cleared before saving
        /** @var TrackedPathService $tracked_path_service */
        $tracked_path_service = $this->container->get('odr.tracked_path_service');
        $tracked_path_service->clearTargetPaths();

         // No issues, save the URL base
        $session->set('_security.main.target_path', $url);
        return new JsonResponse();
    }


    /**
     * This action is called by the login page to store any existing URL fragment, otherwise
     * logins handled by the HWIOAuthBundle can't be redirected back to the original URL.
     *
     * @param Request $request
     *
     * @throws ODRBadRequestException
     *
     * @return JsonResponse
     */
    public function savefragmentAction(Request $request)
    {
        // Going to need these...
        $session = $request->getSession();
        $router = $this->get('router');

        // Ensure query was correctly formed
        if (!$request->query->has('fragment') )
            throw new ODRBadRequestException('Invalid query string', 0x3cf17023);

        // If the fragment starts with  "/app_dev.php", temporarily get rid of it...
        //  route matching will fail otherwise
        $fragment = $request->query->get('fragment');
        $has_appdev = false;
        if ( strpos($fragment, '/app_dev.php') !== false ) {
            $has_appdev = true;
            $fragment = substr($fragment, 12);
        }

        // The fragment should be an actual route...
        // Not bothering to catch any exception that arises, would just rethrow it anyways
        $route = $router->match($fragment);

        // Ensure target paths except for "_security.main.target_path" are cleared before saving
        // If that path was cleared, users would always get redirected to the dashboard
        /** @var TrackedPathService $tracked_path_service */
        $tracked_path_service = $this->container->get('odr.tracked_path_service');
        $tracked_path_service->clearTargetPaths(false);

        // No issues, save the URL fragment
        if ($has_appdev)
            $fragment = '/app_dev.php'.$fragment;

        $session->set('_security.url_fragment', $fragment);
        return new JsonResponse();
    }


    /**
     * This action exists primarily to deal with HWIOAuthBundle redirecting to the homepage upon
     * successful login.
     *
     * Fortunately, most of the Symfony firewalls store the desired target path in the user's
     * session...this action locates and redirects the user to those URLs after they successfully
     * authenticate themselves.
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function redirectAction(Request $request)
    {
        // Going to attempt to figure out the correct redirect URL from the user's session...
        $session = $request->getSession();
//        exit( '<pre>'.print_r($session, true).'</pre>' );
        $url = '';

        // If linking an ODR account with an external OAuth provider...
        if ( $session->has('_security.oauth_connect.redirect_path') ) {
            // ...redirect back to finish the linking process
            $url = $session->get('_security.oauth_connect.redirect_path');
        }
        // If the "oauth_authorize" firewall (apps using ODR as an OAuth provider) is active...
        else if ( $session->has('_security.oauth_authorize.target_path') ) {
            // ...then preferentially redirect to the specified target
            $url = $session->get('_security.oauth_authorize.target_path');
        }
        // If the "main" firewall specified a redirect target...
        else if ( $session->has('_security.main.target_path') ) {
            // ...then redirect to that
            $url = $session->get('_security.main.target_path');

            // If a URL fragment was specified, append that to the end of this url
            $fragment = '';
            if ( $session->has('_security.url_fragment') ) {
                $fragment = $session->get('_security.url_fragment');
            }

            if ($fragment !== '')
                $url .= '#'.$fragment;
        }

        // Ensure all target paths in the user's session are deleted prior to redirecting
        /** @var TrackedPathService $tracked_path_service */
        $tracked_path_service = $this->container->get('odr.tracked_path_service');
        $tracked_path_service->clearTargetPaths();

        if ($url !== '') {
            // The session specified some URL to redirect to, so send the user there
            return new RedirectResponse($url);
        }
        else {
            // Otherwise, no desired redirect found...just redirect to the dashboard
            return new RedirectResponse($this->get('router')->generate('odr_admin_homepage'));
        }
    }
}
