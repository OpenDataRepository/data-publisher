<?php

/**
 * Open Data Repository Data Publisher
 * ODR Client Manager
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service exists mostly so ODRUserController can easily detect what it should render on the user profile page.
 */

namespace ODR\OpenRepository\OAuthServerBundle\OAuth;

use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
use ODR\OpenRepository\OAuthServerBundle\Entity\Client;
use Doctrine\ORM\EntityManager;


class ClientManager
{
    /**
     * @var EntityManager
     */
    private $em;


    /**
     * ClientManager constructor.
     *
     * @param EntityManager $entity_manager
     */
    public function __construct(EntityManager $entity_manager)
    {
        $this->em = $entity_manager;
    }


    /**
     * Returns an array of clients the specified user owns
     *
     * @param ODRUser $user
     *
     * @return Client[]
     */
    public function getOwnedClients($user)
    {
        $owned_clients = $this->em->getRepository('ODROpenRepositoryOAuthServerBundle:Client')->findBy(array('owner' => $user->getId()));
        if ($owned_clients == null)
            return array();

        return $owned_clients;
    }
}
