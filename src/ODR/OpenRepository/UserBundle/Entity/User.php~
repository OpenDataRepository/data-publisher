<?php
// src/Acme/UserBundle/Entity/User.php

namespace ODR\OpenRepository\UserBundle\Entity;

use FOS\UserBundle\Model\User as BaseUser;
use Doctrine\ORM\Mapping as ORM;

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

    public function __construct()
    {
        parent::__construct();
        // your own logic
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
     * @ORM\OneToOne(targetEntity="\ODR\AdminBundle\Entity\ODRUserExtend")
     */
    private $user_extend;

    /**
     * Get UserExtend
     *
     * @return \ODR\AdminBundle\Entity\ODRUserExtend
     */
    public function getUserExtend()
    {
        return $this->user_extend;
    }

// override methods for username and tie them with email field

    /**
     * Sets the email.
     *
     * @param string $email
     * @return User
     */
    public function setEmail($email)
    {
        $this->setUsername($email);

        return parent::setEmail($email);
    }

    /**
     * Set the canonical email.
     *
     * @param string $emailCanonical
     * @return User
     */
    public function setEmailCanonical($emailCanonical)
    {
        $this->setUsernameCanonical($emailCanonical);

        return parent::setEmailCanonical($emailCanonical);
    }


    /**
     * Set user_extend
     *
     * @param \ODR\AdminBundle\Entity\ODRUserExtend $userExtend
     * @return User
     */
    public function setUserExtend(\ODR\AdminBundle\Entity\ODRUserExtend $userExtend = null)
    {
        $this->user_extend = $userExtend;
    
        return $this;
    }
}
