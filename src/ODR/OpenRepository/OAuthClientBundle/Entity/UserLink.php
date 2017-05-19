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
     */
    private $odrAccessToken;

    /**
     * @var string
     * @ORM\Column(name="github_id", type="string", length=255, nullable=true)
     */
    private $githubId;

    /**
     * @var string
     */
    private $githubAccessToken;


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
     * Set ODRAccessToken
     *
     * @param $odrAccessToken
     * @return UserLink
     */
    public function setODRAccessToken($odrAccessToken)
    {
        $this->odrAccessToken = $odrAccessToken;

        return $this;
    }

    /**
     * Get ODRAccessToken
     * 
     * @return string
     */
    public function getODRAccessToken()
    {
        return $this->odrAccessToken;
    }

    /**
     * Set GithubId
     *
     * @param $githubId
     * @return UserLink
     */
    public function setGithubId($githubId)
    {
        $this->githubId = $githubId;

        return $this;
    }

    /**
     * Get GithubId
     *
     * @return string
     */
    public function getGithubId()
    {
        return $this->githubId;
    }

    /**
     * Set GithubAccessToken
     *
     * @param $githubAccessToken
     * @return UserLink
     */
    public function setGithubAccessToken($githubAccessToken)
    {
        $this->githubAccessToken = $githubAccessToken;

        return $this;
    }

    /**
     * Get GithubAccessToken
     *
     * @return string
     */
    public function getGithubAccessToken()
    {
        return $this->githubAccessToken;
    }
}
