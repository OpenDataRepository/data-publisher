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


class DefaultController extends ODRCustomController
{

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
