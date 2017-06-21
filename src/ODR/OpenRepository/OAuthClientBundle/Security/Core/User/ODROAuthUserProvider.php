<?php

/**
 * Open Data Repository Data Publisher
 * ODR OAuth Client User Provider
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Custom user provider for use by the HWIOAuthBundle oauth client...instead of being in fos_user, the external
 * OAuth provider account ids are in the fos_user_link_oauth table.  Also, handles connecting an ODR user account to
 * an external OAuth account.
 *
 * Based off HWIOAuthBundle\Security\Core\User\EntityUserProvider
 */

namespace ODR\OpenRepository\OAuthClientBundle\Security\Core\User;

// ODR
use ODR\OpenRepository\OAuthClientBundle\Entity\UserLink;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Doctrine
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManager;
// HWIOAuthBundle
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;
// Symfony
use Symfony\Component\HttpFoundation\Session\Session;
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
     * @var Session
     */
    protected $session;

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
     * @param Session       $session
     * @param string        $class       user entity class to load
     * @param array         $properties  Mapping of resource owners to properties
     */
    public function __construct(EntityManager $em, Session $session, $class, array $properties)
    {
        $this->em = $em;
        $this->session = $session;
        $this->class = $class;
        $this->properties = array_merge($this->properties, $properties);
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByUsername($username)
    {
        throw new \Exception("ODROAuthUserProvider is not usable as a regular user provider");

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
        // Locate the OAuth Resource owner behind this login request
        $resourceOwnerName = $response->getResourceOwner()->getName();
        if (!isset($this->properties[$resourceOwnerName])) {
            throw new \RuntimeException(sprintf("No property defined for entity for resource owner '%s'.", $resourceOwnerName));
        }

        $username = $response->getUsername();
        $criteria = array($this->properties[$resourceOwnerName] => $username);

        // This session key only exists when ODR\OpenRepository\OAuthClientBundle\Security\Http\Firewall\ODROAuthListener
        //  is convinced this is a ODR <-> OAuth account connection attempt
        $session = $this->session;
        if ( $session->has('_security.oauth_connect.target_user') ) {
            // Connect the provided ODR user id with the available OAuth criteria
            $this->connectUser( $session->get('_security.oauth_connect.target_user'), $criteria );

            // No longer need these session keys...the redirect path will still be used later on though
            $session->remove('_security.oauth_connect.csrf_state');
            $session->remove('_security.oauth_connect.target_user');
            $session->remove('_security.oauth_connect.target_resource');
        }

        // Attempt to load an ODR user using the available OAuth criteria
        if (null === $user = $this->findUser($criteria)) {
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
     * Attempts to load an ODR user from the database given the OAuth provider's criteria and the user's OAuth id
     *
     * @param array $criteria
     *
     * @return ODRUser|null
     */
    private function findUser(array $criteria)
    {
        // Tweak the provided criteria to make it easier for DQL to locate the correct user
        $criteria_key = $criteria_value = null;
        foreach ($criteria as $key => $value) {
            $criteria_key = $key;
            $criteria_value = $value;
        }

        // Attempt to locate the correct user based off the provided criteria
        $query = $this->em->createQuery(
           'SELECT u
            FROM ODROpenRepositoryUserBundle:User AS u
            JOIN ODROpenRepositoryOAuthClientBundle:UserLink AS ul WITH ul.user = u
            WHERE ul.'.$criteria_key.' = :criteria
            AND u.enabled = 1'
        )->setParameters( array('criteria' => $criteria_value) );
        $result = $query->getResult();

        // Would like to use $query->getSingleResult(), but apparently that throws an exception when nothing found...
        if ( count($result) == 0 )
            return null;

        // TODO - is this safe?  In theory, a user account is owned by one person...so theoretically only one person knows the login info?
        return $result[0];
    }

    /**
     * Given an ODR user id and some OAuth provider criteria, connects the two in the database so $this->findUser()
     * will correctly locate an ODR user.
     *
     * @param integer $user_id
     * @param array $criteria
     *
     * @throws \RuntimeException
     */
    private function connectUser($user_id, $criteria)
    {
        if (null === $this->repository)
            $this->repository = $this->em->getRepository($this->class);

        // Tweak the provided criteria
        $criteria_key = $criteria_value = null;
        foreach ($criteria as $key => $value) {
            $criteria_key = $key;
            $criteria_value = $value;
        }

        /** @var ODRUser $user */
        // Load the user and its associated UserLink entry
        $user = $this->repository->find($user_id);
        if (!$user)
            throw new \RuntimeException("Invalid User");

        $user_link = $user->getUserLink();
        if (!$user_link) {
            // UserLink entry doesn't exist, create it
            $user_link = new UserLink();
            $user_link->setUser( $user );
        }

        $accessor = PropertyAccess::createPropertyAccessor();
        if ( $accessor->isWritable($user_link, $criteria_key) ) {
            // Set the id for this OAuth provider to null
            $accessor->setValue($user_link, $criteria_key, $criteria_value);

            // Done here, persist, flush, and continue
            $this->em->persist($user_link);
            $this->em->flush();
        }
        else {
            throw new \RuntimeException('Unable to connect account');
        }
    }
}
