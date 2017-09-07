<?php

/**
 * Open Data Repository Data Publisher
 * Client Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Handles creation/deletion of OAuth Clients for a given user.
 */

namespace ODR\OpenRepository\OAuthServerBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// ODR
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
use ODR\OpenRepository\OAuthServerBundle\Entity\Client;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ClientController extends \Symfony\Bundle\FrameworkBundle\Controller\Controller
{

    /**
     * Creates a new OAuth Client for the currently logged-in user.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function createOAuthClientAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // TODO - require some sort of permissions for this?
            if ($user === 'anon.')
                throw new ODRForbiddenException();

            /** @var Client[] $owned_clients */
            $owned_clients = $em->getRepository('ODROpenRepositoryOAuthServerBundle:Client')->findBy( array('owner' => $user->getId()) );

            // Don't allow if the user already has a client
            if (count($owned_clients) != 0)
                throw new ODRException('Conflict', 409);
            // --------------------

            $site_baseurl = $this->getParameter('site_baseurl');
            $clientManager = $this->container->get('fos_oauth_server.client_manager.default');

            // Define the grant types this oauth client is allowed to use
            $grant_types = array(
                'http://odr.io/grants/owned_client',    // required to use the grant extension
                'token',                                // allow the user to get an access token
                'refresh_token',                        // allow the user to use refresh tokens
            );

            /** @var Client $client */
            $client = $clientManager->createClient();
            $client->setRedirectUris( array($site_baseurl) );     // this is required, but it won't be used
            $client->setAllowedGrantTypes($grant_types);
            $client->setOwner($user);
            $clientManager->updateClient($client);
        }
        catch (\Exception $e) {
            $source = 0x9ee51286;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes the currently logged-in user's OAuth Client, if they have one.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function deleteOAuthClientAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // --------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // TODO - require some sort of permissions for this?
            if ($user === 'anon.')
                throw new ODRForbiddenException();

            /** @var Client[] $owned_clients */
            $owned_clients = $em->getRepository('ODROpenRepositoryOAuthServerBundle:Client')->findBy( array('owner' => $user->getId()) );

            // Don't allow if the user already has a client
            if (count($owned_clients) == 0)
                throw new ODRNotFoundException('OAuth Client');
            // --------------------

            $client = $owned_clients[0];
            $client_id = $client->getId();

            // ----------------------------------------
            // Due to foreign key constraints, going to need to ensure entries referencing this client are deleted prior to deleting the client itself
            $query = $em->createQuery(
                'DELETE FROM ODROpenRepositoryOAuthServerBundle:RefreshToken AS e
                WHERE e.client = :client_id'
            )->setParameters( array('client_id' => $client_id) );
            $rows = $query->execute();

            $query = $em->createQuery(
                'DELETE FROM ODROpenRepositoryOAuthServerBundle:AccessToken AS e
                WHERE e.client = :client_id'
            )->setParameters( array('client_id' => $client_id) );
            $rows = $query->execute();

            // There should never be any entries in the AuthCode or AuthorizedClient entities that reference this client


            // ----------------------------------------
            // Finally, delete the client itself
            $em->remove($client);
            $em->flush();
        }
        catch (\Exception $e) {
            $source = 0xb1a0c8ed;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
