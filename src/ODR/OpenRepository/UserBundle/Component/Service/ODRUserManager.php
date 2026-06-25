<?php

/**
 * Open Data Repository Data Publisher
 * ODR User Manager
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Drop-in replacement for FOSUserBundle's fos_user.user_manager (which is incompatible with
 * Symfony 5). It is aliased to the "fos_user.user_manager" service id so the ~50 existing call sites
 * keep working unchanged. Covers exactly the methods ODR uses: findUserBy / findUserByEmail /
 * findUserByUsernameOrEmail / findUsers / createUser / updateUser (+ a couple of helpers used by the
 * password-reset flow). updateUser canonicalizes the username/email and hashes a pending plainPassword
 * with the configured (sha512) encoder so existing hashes stay compatible.
 */

namespace ODR\OpenRepository\UserBundle\Component\Service;

use Doctrine\ORM\EntityManagerInterface;
use ODR\OpenRepository\UserBundle\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ODRUserManager
{
    /** @var EntityManagerInterface */
    private $em;

    /** @var UserPasswordHasherInterface */
    private $password_encoder;

    public function __construct(EntityManagerInterface $em, UserPasswordHasherInterface $password_encoder)
    {
        $this->em = $em;
        $this->password_encoder = $password_encoder;
    }

    /**
     * @return string
     */
    public function getClass()
    {
        return User::class;
    }

    /**
     * @return User
     */
    public function createUser()
    {
        return new User();
    }

    private function getRepository()
    {
        return $this->em->getRepository(User::class);
    }

    private function canonicalize($value)
    {
        return null === $value ? null : mb_strtolower(trim($value));
    }

    /**
     * @param array $criteria
     * @return User|null
     */
    public function findUserBy(array $criteria)
    {
        return $this->getRepository()->findOneBy($criteria);
    }

    /**
     * @param string $email
     * @return User|null
     */
    public function findUserByEmail($email)
    {
        return $this->getRepository()->findOneBy(['emailCanonical' => $this->canonicalize($email)]);
    }

    /**
     * @param string $username
     * @return User|null
     */
    public function findUserByUsername($username)
    {
        return $this->getRepository()->findOneBy(['usernameCanonical' => $this->canonicalize($username)]);
    }

    /**
     * @param string $usernameOrEmail
     * @return User|null
     */
    public function findUserByUsernameOrEmail($usernameOrEmail)
    {
        return $this->getRepository()->loadUserByUsername($usernameOrEmail);
    }

    /**
     * @param string $token
     * @return User|null
     */
    public function findUserByConfirmationToken($token)
    {
        return $this->getRepository()->findOneBy(['confirmationToken' => $token]);
    }

    /**
     * @return User[]
     */
    public function findUsers()
    {
        return $this->getRepository()->findAll();
    }

    /**
     * Keeps the canonical fields in sync and hashes a pending plain password, then (by default)
     * persists+flushes -- matching FOSUserBundle's updateUser() contract.
     *
     * @param User $user
     * @param bool $andFlush
     */
    public function updateUser(User $user, $andFlush = true)
    {
        // ODR keeps username identical to email; canonical fields are the lowercased forms
        $user->setUsernameCanonical($this->canonicalize($user->getUsername()));
        $user->setEmailCanonical($this->canonicalize($user->getEmail()));

        if (null !== $user->getPlainPassword() && '' !== $user->getPlainPassword()) {
            $user->setPassword($this->password_encoder->hashPassword($user, $user->getPlainPassword()));
            $user->eraseCredentials();
        }

        $this->em->persist($user);
        if ($andFlush)
            $this->em->flush();
    }

    /**
     * @param User $user
     */
    public function deleteUser(User $user)
    {
        $this->em->remove($user);
        $this->em->flush();
    }
}
