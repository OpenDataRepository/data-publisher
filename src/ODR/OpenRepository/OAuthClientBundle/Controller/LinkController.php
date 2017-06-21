<?php

/**
 * Open Data Repository Data Publisher
 * Link Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Handles (dis)connecting ODR user accounts and external OAuth provider accounts.
 */

namespace ODR\OpenRepository\OAuthClientBundle\Controller;

// ODR
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
use ODR\AdminBundle\Controller\ODRCustomController;
// HWI
use HWI\Bundle\OAuthBundle\OAuth\ResourceOwner\AbstractResourceOwner;
use HWI\Bundle\OAuthBundle\Security\Http\ResourceOwnerMap;
use HWI\Bundle\OAuthBundle\Security\OAuthUtils;
// Symfony
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;


class LinkController extends ODRCustomController
{

    /**
     * Connects an ODR account with an OAuth account.
     *
     * @param string $resource
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function connectAction($resource, Request $request)
    {
        try {
            // Going to need these
            $session = $request->getSession();
            $site_baseurl = $this->container->getParameter('site_baseurl');


            // ----------------------------------------
            // Shouldn't happen, but don't procede if nobody logged in
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user === 'anon.')
                return parent::permissionDeniedError();

            // Ensure the requested OAuth resource owner exists
            /** @var OAuthUtils $oauth_utils */
            $oauth_utils = $this->get('hwi_oauth.security.oauth_utils');
            $resource_owners = $oauth_utils->getResourceOwners();
            if ( !in_array($resource, $resource_owners) )
                throw new \Exception('Invalid resource');

            /** @var AbstractResourceOwner $resource_owner */
            $resource_owner = $this->get('hwi_oauth.resource_owner.'.$resource);

            // Store whether any OAuth providers have been configured
            $user_link = $user->getUserLink();
            if ($user_link) {
                $accessor = PropertyAccess::createPropertyAccessor();

                if ($accessor->isReadable($user_link, $resource.'Id') && $accessor->getValue($user_link, $resource.'Id') !== null)
                    throw new \Exception('Already connected to resource');
            }


            // ----------------------------------------
            // Generate the correct route for the external OAuth provider to redirect back to
            /** @var ResourceOwnerMap $resource_ownermap */
            $resource_ownermap = $this->get('hwi_oauth.resource_ownermap.main');
            $oauth_redirect_url_fragment = $resource_ownermap->getResourceOwnerCheckPath($resource);
            $auth_url = $resource_owner->getAuthorizationUrl($site_baseurl.$oauth_redirect_url_fragment);
//            exit( '<pre>'.$auth_url.'</pre>' );

            // Determine the name of the route from the redirection fragment...don't bother catching any exception thrown by match()
            /** @var UrlMatcherInterface $matcher */
            $matcher = $this->get('router')->getMatcher();
            $params = $matcher->match($oauth_redirect_url_fragment);
            $route_name = $params['_route'];

//            exit( '<pre>'.print_r($params, true).'</pre>' );

            // ----------------------------------------
            // Need to split $auth_url apart to extract the csrf state token to store it in the user's session
            // Without this, the user could potentially do a weird sequence of events and connect their id for provider A's to provider B in the database
            $start = strpos($auth_url, '&state=') + strlen('&state=');

            $state = null;
            if ( strpos($auth_url, '&', $start) !== false ) {
                // The &state parameter is not at the end of $auth_url
                $length = strpos($auth_url, '&', $start) - $start;
                $state = substr($auth_url, $start, $length);
            }
            else {
                // The &state parameter is at the end of the $auth_url
                $state = substr($auth_url, $start);
            }

//            exit('<pre>'.$state.'</pre>' );

            // Set up the variables in the session so ODR can properly connect the OAuth user account to an ODR account
            $odr_redirect_url = $site_baseurl.'/admin#'.$this->generateUrl('odr_self_profile_edit');
            $session->set('_security.oauth_connect.redirect_path', $odr_redirect_url);
            $session->set('_security.oauth_connect.csrf_state', $state);
            $session->set('_security.oauth_connect.target_user', $user->getId());
            $session->set('_security.oauth_connect.target_resource', $route_name);


            // ----------------------------------------
            // Redirect the user to the OAuth resource's login page
            return new RedirectResponse($auth_url);

            // Assuming the user logs in to the OAuth provider, the program control flow will eventually get back to
            // ODR\OpenRepository\OAuthClientBundle\Security\Http\Firewall\ODROAuthListener.php
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2650903: ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Disconnects the logged-in user from the specified resource.
     *
     * TODO - determine whether 1) logging in with OAuth provider X, then 2) disconnecting from OAuth provider X...can ever create issues
     *
     * @param string $resource
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function disconnectAction($resource, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // ----------------------------------------
            // Shouldn't happen, but don't procede if nobody logged in
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user === 'anon.')
                return new Response('Unathorized', 401);

            // Ensure the requested OAuth resource owner exists
            /** @var OAuthUtils $oauth_utils */
            $oauth_utils = $this->get('hwi_oauth.security.oauth_utils');
            $resource_owners = $oauth_utils->getResourceOwners();
            if ( !in_array($resource, $resource_owners) )
                return new Response('Invalid resource', 404);


            // ----------------------------------------
            // If the user has a link to the specified OAuth resource, remove it
            $user_link = $user->getUserLink();
            if ($user_link) {
                $accessor = PropertyAccess::createPropertyAccessor();

                if ($accessor->isWritable($user_link, $resource.'Id') && $accessor->getValue($user_link, $resource.'Id') !== null) {
                    // Set the id for this OAuth provider to null
                    $accessor->setValue($user_link, $resource.'Id', null);

                    // Persist/flush the entity
                    $em->persist($user_link);
                    $em->flush();
                }
                else {
                    // Is this needed?
                    throw new \Exception('Not connected to resource');
                }
            }
            else {
                // Is this needed?
                throw new \Exception('Not connected to resource');
            }


            // ----------------------------------------
            // Done with the request...force the user to reload the page, for now
            $site_baseurl = $this->container->getParameter('site_baseurl');
            $odr_redirect_url = $site_baseurl.'/admin#'.$this->generateUrl('odr_self_profile_edit');

            return new RedirectResponse($odr_redirect_url);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x3090439: ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
