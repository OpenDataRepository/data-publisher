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
     * @param array         $properties  Mapping of resource owners to properties
     */
    public function __construct(EntityManager $em, Session $session, array $properties)
    {
        $this->em = $em;
        $this->session = $session;
        $this->properties = array_merge($this->properties, $properties);
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByUsername($username)
    {
        throw new \Exception("ODROAuthUserProvider is not usable as a regular user provider", 0xd92a78ec);

//        $user = $this->findUser(array('username' => $username));
//        if (!$user) {
//            throw new UsernameNotFoundException(sprintf("User '%s' not found.", $username));
//        }
//
//        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByOAuthUserResponse(UserResponseInterface $response)
    {
        // Locate the OAuth Resource owner behind this login request
        $resourceOwnerName = $response->getResourceOwner()->getName();
        if (!isset($this->properties[$resourceOwnerName])) {
            throw new \RuntimeException(sprintf("No property defined for entity for resource owner '%s'.", $resourceOwnerName), 0xd92a78ec);
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
            throw new UsernameNotFoundException(sprintf("User '%s' not found.", $username), 0xd92a78ec);
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
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)), 0xd92a78ec);
        }

        $userId = $accessor->getValue($user, $identifier);
        if (null === $user = $this->findUser(array($identifier => $userId))) {
            throw new UsernameNotFoundException(sprintf('User with ID "%d" could not be reloaded.', $userId), 0xd92a78ec);
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
        $provider_name = $provider_id = null;
        foreach ($criteria as $key => $value) {
            $provider_name = substr($key, 0, -2);
            $provider_id = $value;
        }

        // Attempt to locate the correct user based off the provided criteria
        $query = $this->em->createQuery(
           'SELECT u
            FROM ODROpenRepositoryUserBundle:User AS u
            JOIN ODROpenRepositoryOAuthClientBundle:UserLink AS ul WITH ul.user = u
            WHERE ul.providerName = :provider_name AND ul.providerId = :provider_id
            AND u.enabled = 1'
        )->setParameters( array('provider_name' => $provider_name, 'provider_id' => $provider_id) );
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
        // Tweak the provided criteria
        $provider_name = $provider_id = null;
        foreach ($criteria as $key => $value) {
            $provider_name = substr($key, 0, -2);
            $provider_id = $value;
        }

        // Don't continue if a user is already connected to this criteria
        // TODO - do something more comphrehensive than just refusing to connect?
        $user_link = $this->em->getRepository("ODROpenRepositoryOAuthClientBundle:UserLink")->findOneBy( array('providerName' => $provider_name, 'providerId' => $provider_id) );
        if ($user_link)
            throw new \RuntimeException("Unable to connect account", 0xd92a78ec);

        /** @var ODRUser $user */
        // Load the user and its associated UserLink entry
        $user = $this->em->getRepository("ODROpenRepositoryUserBundle:User")->find($user_id);
        if (!$user)
            throw new \RuntimeException("Invalid User", 0xd92a78ec);


        // Locate an unused UserLink entity for this user if possible
        $user_link = null;
        foreach ($user->getUserLink() as $ul) {
            /** @var UserLink $ul */
            if ($ul->getProviderName() == null) {
                $user_link = $ul;
                break;
            }
        }

        // Ensure UserLink entity exists
        if (!$user_link) {
            $user_link = new UserLink();
            $user_link->setUser( $user );
        }

        // Set this UserLink entity to have the correct provider name/id
        $user_link->setProviderName($provider_name);
        $user_link->setProviderId($provider_id);

        // Done here, persist, flush, and continue
        $this->em->persist($user_link);
        $this->em->flush();
    }
}
