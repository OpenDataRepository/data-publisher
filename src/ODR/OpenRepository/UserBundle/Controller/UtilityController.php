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
     * This action is called by the login page to store any existing URL fragment, otherwise logins handled by the
     * HWIOAuthBundle can't be redirected back to the original URL.
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

        // If the fragment starts with  "/app_dev.php", get rid of it...route matching will fail otherwise
        $fragment = $request->query->get('fragment');
        if ( strpos($fragment, '/app_dev.php') !== false )
            $fragment = substr($fragment, 12);

        // The fragment should be an actual route...not bothering to catch any exception that arises, would just rethrow it anyways
        $route = $router->match($fragment);

        // No issues, save the URL fragment
        $session->set('_security.url_fragment', $fragment);
        return new Response();
    }


    /**
     * This action exists primarily to deal with the HWIOAuthBundle's habit of always redirecting to the homepage.
     *
     * Fortunately, the various firewalls in Symfony store the desired target path in the user's session, which this
     * action locates and redirects the user to after they successfully authenticate themselves.
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function redirectAction(Request $request)
    {
        // Going to attempt to figure out the correct redirect URL from the user's session...
        $session = $request->getSession();

        // If the "oauth_authorize" firewall (apps using ODR as an OAuth provider) specified a redirect target, then preferentially redirect to that
        if ( $session->has('_security.oauth_authorize.target_path') ) {
            $url = $session->get('_security.oauth_authorize.target_path');
            $session->remove('_security.oauth_authorize.target_path');

            return new RedirectResponse($url);
        }

        // If the "main" firewall specified a redirect target, then redirect to that
        if ( $session->has('_security.main.target_path') ) {
            $url = $session->get('_security.main.target_path');
            $session->remove('_security.main.target_path');

            // If a URL fragment was specified, append that to the end of this url
            $fragment = '';
            if ( $session->has('_security.url_fragment') ) {
                $fragment = $session->get('_security.url_fragment');
                $session->remove('_security.url_fragment');
            }

            if ($fragment !== '')
                $url .= '#'.$fragment;

            return new RedirectResponse($url);
        }

        // Otherwise, no information about a desired redirect found...just redirect to the dashboard
        return new RedirectResponse( $this->get('router')->generate('odr_admin_homepage') );
    }
}
