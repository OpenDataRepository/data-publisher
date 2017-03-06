<?php

/**
 * Open Data Repository Data Publisher
 * Client Entity (override)
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Extends the default FOS OAuth Client Entity to work with the
 * existing overridden FOS User Entity.
 */

namespace ODR\OpenRepository\OAuthBundle\Entity;

use FOS\OAuthServerBundle\Entity\Client as BaseClient;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="fos_client")
 */
class Client extends BaseClient
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\ManyToMany(targetEntity="ODR\OpenRepository\UserBundle\Entity\User", mappedBy="clients")
     */
    private $users;

    /**
     * @inheritdoc
     */
    public function __construct()
    {
        parent::__construct();

        $this->users = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Add user
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $user
     *
     * @return Client
     */
    public function addUser(\ODR\OpenRepository\UserBundle\Entity\User $user)
    {
        $this->users[] = $user;

        return $this;
    }

    /**
     * Remove user
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $user
     */
    public function removeUser(\ODR\OpenRepository\UserBundle\Entity\User $user)
    {
        $this->users->removeElement($user);
    }

    /**
     * Get users
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getUsers()
    {
        return $this->users;
    }
}
