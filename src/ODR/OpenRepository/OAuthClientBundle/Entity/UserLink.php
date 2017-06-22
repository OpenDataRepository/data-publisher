<?php

/**
 * Open Data Repository Data Publisher
 * User Link
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 */

namespace ODR\OpenRepository\OAuthClientBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use ODR\OpenRepository\UserBundle\Entity\User;

/**
 * @ORM\Entity
 * @ORM\Table(name="fos_user_link_oauth")
 */
class UserLink
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     * @ORM\ManyToOne(targetEntity="ODR\OpenRepository\UserBundle\Entity\User", inversedBy="userLink")
     */
    private $user;

    /**
     * @var string
     * @ORM\Column(name="provider_name", type="string", length=255, nullable=true)
     */
    private $providerName;

    /**
     * @var string
     * @ORM\Column(name="provider_id", type="string", length=255, nullable=true)
     */
    private $providerId;


    /**
     * @inheritdoc
     */
    public function __construct()
    {
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
     * Set user
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $user
     * @return UserLink
     */
    public function setUser(\ODR\OpenRepository\UserBundle\Entity\User $user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user
     *
     * @return User
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set ProviderName
     *
     * @param $providerName
     * @return UserLink
     */
    public function setProviderName($providerName)
    {
        $this->providerName = $providerName;

        return $this;
    }

    /**
     * Get ProviderName
     *
     * @return string
     */
    public function getProviderName()
    {
        return $this->providerName;
    }

    /**
     * Set ProviderId
     *
     * @param $providerId
     * @return UserLink
     */
    public function setProviderId($providerId)
    {
        $this->providerId = $providerId;

        return $this;
    }

    /**
     * Get ProviderId
     *
     * @return string
     */
    public function getProviderId()
    {
        return $this->providerId;
    }
}
