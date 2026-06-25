<?php

/**
 * Open Data Repository Data Publisher
 * User Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Standalone user entity. Previously extended FOSUserBundle's BaseUser; FOSUserBundle is
 * incompatible with Symfony 5, so the model it provided is reimplemented here directly against the
 * existing "fos_user" table (column names unchanged so existing data / password hashes keep working).
 * Authentication uses Symfony Security with the sha512 encoder (see security.yml) -- getSalt()/
 * getPassword() expose the stored values so the existing password hashes still validate.
 */

namespace ODR\OpenRepository\UserBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\LegacyPasswordAuthenticatedUserInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @ORM\Entity(repositoryClass="ODR\OpenRepository\UserBundle\Entity\UserRepository")
 * @ORM\Table(name="fos_user")
 */
class User implements UserInterface, PasswordAuthenticatedUserInterface, LegacyPasswordAuthenticatedUserInterface
{
    const ROLE_DEFAULT = 'ROLE_USER';
    const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /** @ORM\Column(type="string", length=180) */
    protected $username;

    /** @ORM\Column(name="username_canonical", type="string", length=180) */
    protected $usernameCanonical;

    /** @ORM\Column(type="string", length=180) */
    protected $email;

    /** @ORM\Column(name="email_canonical", type="string", length=180) */
    protected $emailCanonical;

    /** @ORM\Column(type="boolean") */
    protected $enabled = false;

    /** @ORM\Column(type="string", nullable=true) */
    protected $salt;

    /** @ORM\Column(type="string") */
    protected $password;

    /** Transient -- never persisted */
    protected $plainPassword;

    /** @ORM\Column(name="last_login", type="datetime", nullable=true) */
    protected $lastLogin;

    /** @ORM\Column(name="confirmation_token", type="string", length=180, nullable=true) */
    protected $confirmationToken;

    /** @ORM\Column(name="password_requested_at", type="datetime", nullable=true) */
    protected $passwordRequestedAt;

    /** @ORM\Column(type="array") */
    protected $roles = [];

    /** @ORM\Column(type="string", length=64, nullable=true) */
    protected $firstName;

    /** @ORM\Column(type="string", length=64, nullable=true) */
    protected $lastName;

    /** @ORM\Column(type="string", length=64, nullable=true) */
    protected $institution;

    /** @ORM\Column(type="string", length=64, nullable=true) */
    protected $position;

    /** @ORM\Column(type="string", length=32, nullable=true) */
    protected $phoneNumber;

    /**
     * @var \Doctrine\Common\Collections\Collection
     * @ORM\OneToMany(targetEntity="\ODR\AdminBundle\Entity\UserGroup", mappedBy="user")
     * @ORM\JoinTable(name="odr_user_group")
     */
    private $userGroups;


    public function __construct()
    {
        $this->salt = base_convert(bin2hex(random_bytes(23)), 16, 36);
        $this->roles = [];
        $this->userGroups = new ArrayCollection();
    }


    // -------------------- Symfony Security UserInterface (Symfony 4.4) --------------------

    public function getId()
    {
        return $this->id;
    }

    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Symfony 6 UserInterface identifier (replaces getUsername() for authentication).
     */
    public function getUserIdentifier(): string
    {
        return (string)$this->username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getSalt(): ?string
    {
        return $this->salt;
    }

    /**
     * Always includes ROLE_USER, mirroring FOSUserBundle's behaviour.
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = static::ROLE_DEFAULT;

        return array_values(array_unique($roles));
    }

    public function eraseCredentials()
    {
        $this->plainPassword = null;
    }


    // -------------------- setters / role helpers (FOSUser-compatible API) --------------------

    public function setUsername($username)
    {
        $this->username = $username;
        return $this;
    }

    public function getUsernameCanonical()
    {
        return $this->usernameCanonical;
    }

    public function setUsernameCanonical($usernameCanonical)
    {
        $this->usernameCanonical = $usernameCanonical;
        return $this;
    }

    public function getEmail()
    {
        return $this->email;
    }

    /**
     * Setting the email also keeps username identical to it (ODR logs in by email).
     */
    public function setEmail($email)
    {
        $this->username = $email;
        $this->email = $email;
        return $this;
    }

    public function getEmailCanonical()
    {
        return $this->emailCanonical;
    }

    public function setEmailCanonical($emailCanonical)
    {
        $this->usernameCanonical = $emailCanonical;
        $this->emailCanonical = $emailCanonical;
        return $this;
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    public function setEnabled($enabled)
    {
        $this->enabled = (bool)$enabled;
        return $this;
    }

    public function setPassword($password)
    {
        $this->password = $password;
        return $this;
    }

    public function setSalt($salt)
    {
        $this->salt = $salt;
        return $this;
    }

    public function getPlainPassword()
    {
        return $this->plainPassword;
    }

    public function setPlainPassword($plainPassword)
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }

    public function getLastLogin()
    {
        return $this->lastLogin;
    }

    public function setLastLogin(?\DateTime $time = null)
    {
        $this->lastLogin = $time;
        return $this;
    }

    public function getConfirmationToken()
    {
        return $this->confirmationToken;
    }

    public function setConfirmationToken($confirmationToken)
    {
        $this->confirmationToken = $confirmationToken;
        return $this;
    }

    public function getPasswordRequestedAt()
    {
        return $this->passwordRequestedAt;
    }

    public function setPasswordRequestedAt(?\DateTime $date = null)
    {
        $this->passwordRequestedAt = $date;
        return $this;
    }

    /**
     * Whether the password-reset request is still within the given TTL (seconds).
     */
    public function isPasswordRequestNonExpired($ttl)
    {
        return $this->passwordRequestedAt instanceof \DateTime
            && $this->passwordRequestedAt->getTimestamp() + $ttl > time();
    }

    public function setRoles(array $roles)
    {
        $this->roles = [];
        foreach ($roles as $role)
            $this->addRole($role);

        return $this;
    }

    public function addRole($role)
    {
        $role = strtoupper($role);
        if ($role === static::ROLE_DEFAULT)
            return $this;

        if (!in_array($role, $this->roles, true))
            $this->roles[] = $role;

        return $this;
    }

    public function removeRole($role)
    {
        if (false !== $key = array_search(strtoupper($role), $this->roles, true)) {
            unset($this->roles[$key]);
            $this->roles = array_values($this->roles);
        }

        return $this;
    }

    public function hasRole($role)
    {
        return in_array(strtoupper($role), $this->getRoles(), true);
    }

    public function isSuperAdmin()
    {
        return $this->hasRole(static::ROLE_SUPER_ADMIN);
    }


    // -------------------- ODR custom fields --------------------

    public function setFirstName($firstName)
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getFirstName()
    {
        return $this->firstName;
    }

    public function setLastName($lastName)
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getLastName()
    {
        return $this->lastName;
    }

    /**
     * Get userString -- a friendly display name, falling back to the email.
     *
     * @return string
     */
    public function getUserString()
    {
        if ($this->firstName == null || $this->firstName == '')
            return $this->email;
        else
            return $this->firstName.' '.$this->lastName;
    }

    public function setInstitution($institution)
    {
        $this->institution = $institution;
        return $this;
    }

    public function getInstitution()
    {
        return $this->institution;
    }

    public function setPosition($position)
    {
        $this->position = $position;
        return $this;
    }

    public function getPosition()
    {
        return $this->position;
    }

    public function setPhoneNumber($phoneNumber)
    {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    public function getPhoneNumber()
    {
        return $this->phoneNumber;
    }


    /**
     * Custom callback to validate the plainPassword
     * @see http://symfony.com/doc/2.8/reference/constraints/Callback.html
     *
     * Any change to the password rules also needs to be made in ODR\AdminBundle\Resources\views\ODRUser\change_password.html.twig
     */
    public function isPasswordValid(ExecutionContextInterface $context)
    {
        // Prevent profile edit form from complaining about bad password when nothing was actually submitted as a password
        if ($this->plainPassword == null)
            return;

        if ( preg_match('/[a-z]/', $this->plainPassword) !== 1 )
            $context->buildViolation('Password must contain at least one lowercase letter')->atPath('plainPassword')->addViolation();

        if ( preg_match('/[A-Z]/', $this->plainPassword) !== 1 )
            $context->buildViolation('Password must contain at least one uppercase letter')->atPath('plainPassword')->addViolation();

        if ( preg_match('/[0-9]/', $this->plainPassword) !== 1 )
            $context->buildViolation('Password must contain at least one numerical letter')->atPath('plainPassword')->addViolation();

        if ( preg_match('/[\`\~\!\@\#\$\%\^\&\*\(\)\-\_\=\+\[\{\]\}\\\|\;\:\'\"\,\<\.\>\/\?]/', $this->plainPassword) !== 1 )
            $context->buildViolation('Password must contain at least one symbol')->atPath('plainPassword')->addViolation();

        if ( strlen($this->plainPassword) < 8 )
            $context->buildViolation('Password must be at least 8 characters long')->atPath('plainPassword')->addViolation();
    }


    // -------------------- Groups --------------------

    public function addUserGroup(\ODR\AdminBundle\Entity\UserGroup $userGroup)
    {
        $this->userGroups[] = $userGroup;
        return $this;
    }

    public function removeUserGroup(\ODR\AdminBundle\Entity\UserGroup $userGroup)
    {
        $this->userGroups->removeElement($userGroup);
    }

    public function getUserGroups()
    {
        return $this->userGroups;
    }


    // -------------------- Session serialization (minimal; user is refreshed from DB each request) --------------------

    public function __serialize(): array
    {
        return [
            'id'       => $this->id,
            'username' => $this->username,
            'email'    => $this->email,
            'salt'     => $this->salt,
            'password' => $this->password,
            'enabled'  => $this->enabled,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id       = $data['id'] ?? null;
        $this->username = $data['username'] ?? null;
        $this->email    = $data['email'] ?? null;
        $this->salt     = $data['salt'] ?? null;
        $this->password = $data['password'] ?? null;
        $this->enabled  = $data['enabled'] ?? false;
    }
}
