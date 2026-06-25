<?php

/**
 * Open Data Repository Data Publisher
 * User Repository
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Implements UserLoaderInterface so the Symfony Security entity provider loads users the way
 * FOSUserBundle did: a single case-insensitive lookup against the canonical username/email columns,
 * so an existing user can still log in regardless of the case they type their email/username in.
 */

namespace ODR\OpenRepository\UserBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserRepository extends EntityRepository implements UserLoaderInterface
{
    /**
     * Loads a user for authentication by username or email (case-insensitive, via the canonical columns).
     * Symfony 6 renamed UserLoaderInterface::loadUserByUsername() to loadUserByIdentifier().
     */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        $canonical = mb_strtolower(trim($identifier));

        return $this->createQueryBuilder('u')
            ->where('u.usernameCanonical = :c OR u.emailCanonical = :c')
            ->setParameter('c', $canonical)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
