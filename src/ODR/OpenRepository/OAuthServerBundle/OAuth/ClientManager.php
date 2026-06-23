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
     * ClientManager constructor.
     *
     * @param EntityManager $em
     */
    public function __construct(private readonly EntityManager $em)
    {
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
        $owned_clients = $this->em->getRepository('ODR\OpenRepository\OAuthServerBundle\Entity\Client')->findBy(['owner' => $user->getId()]);
        if ($owned_clients == null)
            return [];

        return $owned_clients;
    }
}
