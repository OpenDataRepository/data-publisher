<?php

/**
 * Open Data Repository Data Publisher
 * Default Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Default controller handles the loading of the base template
 * and AJAX handlers that the rest of the site uses.  It also
 * handles the creation of the information displayed on the site's
 * dashboard.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;


class DefaultController extends ODRCustomController
{

    /**
     * Lifetime (seconds) of a JWT handed out by authTokenAction().  Short on
     * purpose -- the caller has a session and can ask for another one.
     */
    const SESSION_TOKEN_TTL = 3600;


    /**
     * Hands a logged-in user a JWT for the API, authenticated by their PHP
     * session.  Apps rendered inside ODR/WordPress can call this once and keep
     * the token in JS memory instead of asking the user for credentials again.
     *
     *   POST /auth/token
     *   200: { "token": "...", "expires_at": 1790000000, "expires_in": 3600,
     *          "username": "someone@example.org" }
     *   401: { "error": { "code": 401, "message": "..." } }
     *
     * Lives outside /api on purpose: the api firewall is stateless (JWT only)
     * and never looks at the session cookie, so this has to sit on the main
     * firewall alongside authStatusAction().
     *
     * Deliberately sends no CORS headers -- the response contains a bearer
     * token, so only same-origin callers may read it.  A cross-origin POST can
     * still be made, but the browser will not hand the attacker the body.
     *
     * The token is short-lived (see SESSION_TOKEN_TTL) rather than using the
     * global lexik token_ttl: it lives in a browser, and the caller can simply
     * ask for another one while their session is alive.
     *
     * @param Request $request
     * @return Response
     */
    public function authTokenAction(Request $request)
    {
        try {
            $token = $this->container->get('security.token_storage')->getToken();
            $user = ($token !== null) ? $token->getUser() : null;

            // "anon." is what an unauthenticated request looks like here
            if ( !is_object($user) || $user === 'anon.' ) {
                $response = new JsonResponse(
                    array('error' => array('code' => 401, 'message' => 'Not logged in.')),
                    401
                );
                $response->headers->set('Cache-Control', 'no-store, private');
                return $response;
            }

            /** @var JWTTokenManagerInterface $jwt_manager */
            $jwt_manager = $this->container->get('lexik_jwt_authentication.jwt_manager');

            // An explicit "exp" overrides the bundle's configured ttl
            $expires_at = time() + self::SESSION_TOKEN_TTL;
            $jwt = $jwt_manager->createFromPayload($user, array('exp' => $expires_at));

            $response = new JsonResponse(array(
                'token' => $jwt,
                'expires_at' => $expires_at,
                'expires_in' => self::SESSION_TOKEN_TTL,
                'username' => method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : (string)$user,
            ));
            // Never let a token sit in a shared or browser cache
            $response->headers->set('Cache-Control', 'no-store, private');
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x4a1c93b7;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Reports whether the current request comes from a logged-in user.
     * Used by cached static pages to decide whether to redirect the
     * visitor to the dynamic version (which can show non-public data).
     *
     * This deliberately lives outside /api: it answers from the session
     * cookie, and the api firewall is stateless (JWT only), so a browser
     * session would never be seen there.
     *
     *   GET /auth/status
     *   200: { "logged_in": true|false }
     *
     * CORS: the cached static files are typically served from a
     * different host than the dynamic backend (site_baseurl vs
     * wordpress_site_baseurl in WP-integrated mode). We allow a
     * credentialed cross-origin request from site_baseurl so the
     * cached page can call back to check session state.
     *
     * @param Request $request
     * @return Response
     */
    public function authStatusAction(Request $request)
    {
        $logged_in = false;
        try {
            $token = $this->container->get('security.token_storage')->getToken();
            if ($token !== null) {
                $user = $token->getUser();
                $logged_in = is_object($user) && $user !== 'anon.';
            }
        } catch (\Exception $e) {
            // Any failure → treat as anonymous; never error here, we want
            // the cached page's redirect script to keep working silently.
        }

        $response = new JsonResponse(array('logged_in' => $logged_in));

        // CORS: allow the cached-file origin (site_baseurl) to call us
        // with credentials. We only allow that one origin, never `*`,
        // because credentialed requests + wildcard origin is forbidden
        // by the spec anyway.
        $req_origin = $request->headers->get('Origin');
        if ($req_origin) {
            $allowed = self::normalizeOrigin($this->getParameter('site_baseurl'));
            if ($allowed !== '' && self::normalizeOrigin($req_origin) === $allowed) {
                $response->headers->set('Access-Control-Allow-Origin', $req_origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Vary', 'Origin');
            }
        }
        return $response;
    }

    /**
     * Strip protocol-relative `//` and trailing slashes; force https.
     * Used to compare an incoming Origin header against a configured
     * baseurl.
     */
    private static function normalizeOrigin($origin)
    {
        $origin = trim((string)$origin);
        $origin = preg_replace('#^https?:#', '', $origin);
        $origin = ltrim($origin, '/');
        $origin = rtrim($origin, '/');
        return $origin === '' ? '' : 'https://' . $origin;
    }


    /**
     * Triggers the loading of base.html.twig, and sets up session cookies.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function indexAction(Request $request)
    {
        try {
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->permissions_management_service;

            // Grab the current user
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()?->getUser() ?? 'anon.';
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            $site_baseurl = $this->getParameter('site_baseurl');
            $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
            $wordpress_site_baseurl = $this->getParameter('wordpress_site_baseurl');

            if ($is_wordpress_integrated) {
                // Render the base html for the page...$this->render() apparently creates and automatically returns a full Reponse object
                $html = $this->renderView(
                    '@ODRAdmin/Default/index.html.twig',
                    [
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'site_baseurl' => $site_baseurl,
                        'odr_wordpress_integrated' => $is_wordpress_integrated,
                        'wordpress_site_baseurl' => $wordpress_site_baseurl,
                    ]
                );
            }
            else {
                // Render the base html for the page...$this->render() apparently creates and automatically returns a full Reponse object
                $html = $this->renderView(
                    '@ODRAdmin/Default/default_full.html.twig',
                    [
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'site_baseurl' => $site_baseurl,
                        'odr_wordpress_integrated' => $is_wordpress_integrated,
                        'wordpress_site_baseurl' => $wordpress_site_baseurl,
                    ]
                );
            }

            $response = new Response($html);
            $response->headers->set('Content-Type', 'text/html');
            return $response;
        }
        catch (\Exception $e) {
            $source = 0xe75008d8;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
