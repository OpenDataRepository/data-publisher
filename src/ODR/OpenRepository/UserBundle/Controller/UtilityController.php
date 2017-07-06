<?php

/**
 * Open Data Repository Data Publisher
 * Utility Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Due to HWIOAuthBundle effectively assuming it's the only bundle being used to log a user into a Symfony site, this
 * controller exists so users can be properly redirected back to their originally requested URLs after authentication.
 */


namespace ODR\OpenRepository\UserBundle\Controller;

// Symfony
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class UtilityController extends Controller
{

    /**
     * This action is called when the "Login" button in the upper-right corner of the screen is clicked...the page
     * provides the current baseurl for this action to save in the user's session.  Symfony won't automatically set the
     * session key because the user typically isn't directly accessing a "secured" area of the firewall.
     *
     * @param Request $request
     * @throws \Exception
     *
     * @return Response
     */
    public function saveurlAction(Request $request)
    {
        // Going to need these...
        $session = $request->getSession();
        $router = $this->get('router');

        // Ensure query was correctly formed
        if (!$request->query->has('url') )
            throw new \Exception('Invalid query string');

        // Ensure the requested url to save is of this domain
        $url = $request->query->get('url');
        $site_baseurl = $this->container->getParameter('site_baseurl');

        if ( strpos($url, $site_baseurl) !== 0 )
            throw new \Exception('Invalid query string');

        // Ensure all target paths are cleared before saving
        self::clearTargetPaths($request);

         // No issues, save the URL base
        $session->set('_security.main.target_path', $url);
        return new Response();
    }


    /**
     * This action is called by the login page to store any existing URL fragment, otherwise logins handled by the
     * HWIOAuthBundle can't be redirected back to the original URL.
     *
     * @param Request $request
     * @throws \Exception
     *
     * @return Response
     */
    public function savefragmentAction(Request $request)
    {
        // Going to need these...
        $session = $request->getSession();
        $router = $this->get('router');

        // Ensure query was correctly formed
        if (!$request->query->has('fragment') )
            throw new \Exception('Invalid query string');

        // If the fragment starts with  "/app_dev.php", temporarily get rid of it...route matching will fail otherwise
        $fragment = $request->query->get('fragment');
        $has_appdev = false;
        if ( strpos($fragment, '/app_dev.php') !== false ) {
            $has_appdev = true;
            $fragment = substr($fragment, 12);
        }

        // The fragment should be an actual route...not bothering to catch any exception that arises, would just rethrow it anyways
        $route = $router->match($fragment);

        // Ensure most target paths are cleared before saving
        // Don't want to clear "_security.main.target_path", because users will always get redirected to the dashboard in that case
        self::clearTargetPaths($request, false);

        // No issues, save the URL fragment
        if ($has_appdev)
            $fragment = '/app_dev.php'.$fragment;

        $session->set('_security.url_fragment', $fragment);
        return new Response();
    }


    /**
     * This action exists primarily to deal with HWIOAuthBundle redirecting to the homepage upon successful login.
     *
     * Fortunately, most of the Symfony firewalls store the desired target path in the user's session...this
     * action locates and redirects the user to those URLs after they successfully authenticate themselves.
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

        if ( $session->has('_security.oauth_connect.redirect_path') ) {
            // If linking an ODR account with an external OAuth provider, redirect back to finish the linking process
            $url = $session->get('_security.oauth_connect.redirect_path');
        }
        else if ( $session->has('_security.oauth_authorize.target_path') ) {
            // If the "oauth_authorize" firewall (apps using ODR as an OAuth provider) specified a redirect target, then preferentially redirect to that
            $url = $session->get('_security.oauth_authorize.target_path');
        }
        else if ( $session->has('_security.main.target_path') ) {
            // If the "main" firewall specified a redirect target, then redirect to that
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
        self::clearTargetPaths($request);

        if ($url !== '') {
            // The session specified some URL to redirect to, so send the user there
            return new RedirectResponse($url);
        }
        else {
            // Otherwise, no information about a desired redirect found...just redirect to the dashboard
            return new RedirectResponse($this->get('router')->generate('odr_admin_homepage'));
        }
    }


    /**
     * Utility function to ensure the user's session doesn't somehow redirect to a path when it shouldn't.
     *
     * @param Request $request
     * @param boolean $clear_all_paths
     */
    private function clearTargetPaths($request, $clear_all_paths = true)
    {
        $session = $request->getSession();

        // Remove the target path for linking an ODR account with an external OAuth provider
        if ( $session->has('_security.oauth_connect.redirect_path') )
            $session->remove('_security.oauth_connect.redirect_path');

        // Remove the target path for apps using ODR as an OAuth provider
        if ( $session->has('_security.oauth_authorize.target_path') )
            $session->remove('_security.oauth_authorize.target_path');

        if ($clear_all_paths) {
            // Remove the redirect path for conventional logins to ODR
            if ($session->has('_security.main.target_path')) {
                $session->remove('_security.main.target_path');

                if ($session->has('_security.url_fragment'))
                    $session->remove('_security.url_fragment');
            }
        }
    }
}
