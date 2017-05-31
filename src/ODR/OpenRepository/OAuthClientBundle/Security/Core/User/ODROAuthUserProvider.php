<?php

/**
 * Open Data Repository Data Publisher
 * ODR OAuth Client User Provider
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Custom user provider for use by the HWIOAuthBundle oauth client.
 */

namespace ODR\OpenRepository\OAuthClientBundle\Security\Core\User;

use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManager;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;


class ODROAuthUserProvider implements UserProviderInterface, OAuthAwareUserProviderInterface
{
    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var string
     */
    protected $class;

    /**
     * @var ObjectRepository
     */
    protected $repository;

    /**
     * @var array
     */
    protected $properties = array(
        'identifier' => 'id',
    );

    /**
     * Constructor.
     *
     * @param EntityManager $em
     * @param string        $class       user entity class to load
     * @param array         $properties  Mapping of resource owners to properties
     */
    public function __construct(EntityManager $em, $class, array $properties)
    {
        $this->em = $em;
        $this->class = $class;
        $this->properties = array_merge($this->properties, $properties);
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByUsername($username)
    {
        exit( 'THIS SHOULD NEVER BE CALLED' );

        $user = $this->findUser(array('username' => $username));
        if (!$user) {
            throw new UsernameNotFoundException(sprintf("User '%s' not found.", $username));
        }

        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByOAuthUserResponse(UserResponseInterface $response)
    {
        $resourceOwnerName = $response->getResourceOwner()->getName();

        if (!isset($this->properties[$resourceOwnerName])) {
            throw new \RuntimeException(sprintf("No property defined for entity for resource owner '%s'.", $resourceOwnerName));
        }

        $username = $response->getUsername();
        if (null === $user = $this->findUser(array($this->properties[$resourceOwnerName] => $username))) {
            throw new UsernameNotFoundException(sprintf("User '%s' not found.", $username));
        }

        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function refreshUser(UserInterface $user)
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        $identifier = $this->properties['identifier'];
        if (!$this->supportsClass(get_class($user)) || !$accessor->isReadable($user, $identifier)) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        $userId = $accessor->getValue($user, $identifier);
        if (null === $user = $this->findUser(array($identifier => $userId))) {
            throw new UsernameNotFoundException(sprintf('User with ID "%d" could not be reloaded.', $userId));
        }

        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsClass($class)
    {
        return $class === $this->class || is_subclass_of($class, $this->class);
    }

    /**
     * @param array $criteria
     *
     * @return object
     */
    protected function findUser(array $criteria)
    {
        if (null === $this->repository) {
            $this->repository = $this->em->getRepository($this->class);
        }

//        exit ( '<pre>criteria: '.print_r($criteria, true).'</pre>' );

        $criteria_key = $criteria_value = null;
        foreach ($criteria as $key => $value) {
            $criteria_key = $key;
            $criteria_value = $value;
        }

        $query = $this->em->createQuery(
           'SELECT u
            FROM ODROpenRepositoryUserBundle:User AS u
            JOIN ODROpenRepositoryOAuthClientBundle:UserLink AS ul WITH ul.user = u
            WHERE ul.'.$criteria_key.' = :criteria
            AND u.enabled = 1'
        )->setParameters( array('criteria' => $criteria_value) );
        $result = $query->getSingleResult();

        return $result;
    }
}
