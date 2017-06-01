<?php

/**
 * Open Data Repository Data Publisher
 * User Entity (override)
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Extends the default FOS User Entity to add some additional data,
 * and adds a password validation function.
 */

namespace ODR\OpenRepository\UserBundle\Entity;

use FOS\UserBundle\Model\User as BaseUser;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @ORM\Entity
 * @ORM\Table(name="fos_user")
 */
class User extends BaseUser
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @inheritdoc
     */
    public function __construct()
    {
        parent::__construct();

        $this->clients = new \Doctrine\Common\Collections\ArrayCollection();
        $this->userLink = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set email
     * Also ensure username is identical to email field.
     *
     * @param string $email
     * @return User
     */
    public function setEmail($email)
    {
        $this->username = $email;
        $this->email = $email;

        return parent::setEmail($email);
    }

    /**
     * Set emailCanonical
     * Also ensure username is identical to email field.
     *
     * @param string $emailCanonical
     * @return User
     */
    public function setEmailCanonical($emailCanonical)
    {
        $this->usernameCanonical = $emailCanonical;
        $this->emailCanonical = $emailCanonical;

        return parent::setEmailCanonical($emailCanonical);
    }

    /**
     * @ORM\Column(type="string", length=64, nullable=true)
     */
    protected $firstName;

    /**
     * @ORM\Column(type="string", length=64, nullable=true)
     */
    protected $lastName;

    /**
     * @ORM\Column(type="string", length=64, nullable=true)
     */
    protected $institution;

    /**
     * @ORM\Column(type="string", length=64, nullable=true)
     */
    protected $position;

    /**
     * @ORM\Column(type="string", length=32, nullable=true)
     */
    protected $phoneNumber;


    /**
     * Set firstName
     *
     * @param string $firstName
     * @return User
     */
    public function setFirstName($firstName)
    {
        $this->firstName = $firstName;

        return $this;
    }

    /**
     * Get firstName
     *
     * @return string 
     */
    public function getFirstName()
    {
        return $this->firstName;
    }

    /**
     * Set lastName
     *
     * @param string $lastName
     * @return User
     */
    public function setLastName($lastName)
    {
        $this->lastName = $lastName;

        return $this;
    }

    /**
     * Get lastName
     *
     * @return string 
     */
    public function getLastName()
    {
        return $this->lastName;
    }

    /**
     * Get userString
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

    /**
     * Set institution
     *
     * @param string $institution
     * @return User
     */
    public function setInstitution($institution)
    {
        $this->institution = $institution;

        return $this;
    }

    /**
     * Get institution
     *
     * @return string 
     */
    public function getInstitution()
    {
        return $this->institution;
    }

    /**
     * Set position
     *
     * @param string $position
     * @return User
     */
    public function setPosition($position)
    {
        $this->position = $position;

        return $this;
    }

    /**
     * Get position
     *
     * @return string 
     */
    public function getPosition()
    {
        return $this->position;
    }

    /**
     * Set phoneNumber
     *
     * @param string $phoneNumber
     * @return User
     */
    public function setPhoneNumber($phoneNumber)
    {
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    /**
     * Get phoneNumber
     *
     * @return string 
     */
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


    // -------------------- OAuth Server --------------------
    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\ManyToMany(targetEntity="\ODR\OpenRepository\OAuthServerBundle\Entity\Client", inversedBy="users")
     * @ORM\JoinTable(name="fos_authorized_clients")
     */
    private $clients;

    /**
     * Add authorizedClient
     *
     * @param \ODR\OpenRepository\OAuthServerBundle\Entity\Client $client
     * @return User
     */
    public function addClient(\ODR\OpenRepository\OAuthServerBundle\Entity\Client $client)
    {
        $this->clients[] = $client;

        return $this;
    }

    /**
     * Remove authorizedClient
     *
     * @param \ODR\OpenRepository\OAuthServerBundle\Entity\Client $client
     */
    public function removeClient(\ODR\OpenRepository\OAuthServerBundle\Entity\Client $client)
    {
        $this->clients->removeElement($client);
    }

    /**
     * Get clients
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getClients()
    {
        return $this->clients;
    }

    /**
     * is authorizedClient
     *
     * @param \ODR\OpenRepository\OAuthServerBundle\Entity\Client $client
     * @return boolean
     */
    public function isAuthorizedClient(\ODR\OpenRepository\OAuthServerBundle\Entity\Client $client)
    {
        $authorized_clients = self::getClients();
        foreach ($authorized_clients as $authorized_client) {
            if ($client->getId() == $authorized_client->getId())
                return true;
        }

        return false;
    }

    // -------------------- OAuth Client --------------------
    /**
     * @var \ODR\OpenRepository\OAuthClientBundle\Entity\UserLink
     *
     * @ORM\OneToMany(targetEntity="\ODR\OpenRepository\OAuthClientBundle\Entity\UserLink", mappedBy="user")
     * @ORM\JoinTable(name="fos_user_link_oauth")
     */
    private $userLink;

    /**
     * Set UserLink
     *
     * @param \ODR\OpenRepository\OAuthClientBundle\Entity\UserLink $userLink
     * @return User
     */
    public function setUserLink(\ODR\OpenRepository\OAuthClientBundle\Entity\UserLink $userLink)
    {
        $this->userLink = $userLink;

        return $this;
    }

    /**
     * Get UserLink
     *
     * @return \ODR\OpenRepository\OAuthClientBundle\Entity\UserLink
     */
    public function getUserLink()
    {
        return $this->userLink;
    }

    /**
     * Add userLink
     *
     * @param \ODR\OpenRepository\OAuthClientBundle\Entity\UserLink $userLink
     *
     * @return User
     */
    public function addUserLink(\ODR\OpenRepository\OAuthClientBundle\Entity\UserLink $userLink)
    {
        $this->userLink[] = $userLink;

        return $this;
    }

    /**
     * Remove userLink
     *
     * @param \ODR\OpenRepository\OAuthClientBundle\Entity\UserLink $userLink
     */
    public function removeUserLink(\ODR\OpenRepository\OAuthClientBundle\Entity\UserLink $userLink)
    {
        $this->userLink->removeElement($userLink);
    }
}
