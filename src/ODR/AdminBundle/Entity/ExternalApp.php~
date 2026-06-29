<?php

/**
 * Open Data Repository Data Publisher
 * ExternalApp Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The ExternalApp Entity is automatically generated from
 * ./Resources/config/doctrine/ExternalApp.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

/**
 * ExternalApp
 */
class ExternalApp
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $externalAppMeta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $externalAppDatatypeLinks;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $deletedBy;


    /**
     * Constructor
     */
    public function __construct()
    {
        $this->externalAppMeta = new \Doctrine\Common\Collections\ArrayCollection();
        $this->externalAppDatatypeLinks = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set created
     *
     * @param \DateTime $created
     *
     * @return ExternalApp
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     *
     * @return ExternalApp
     */
    public function setDeletedAt($deletedAt)
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    /**
     * Get deletedAt
     *
     * @return \DateTime
     */
    public function getDeletedAt()
    {
        return $this->deletedAt;
    }

    /**
     * Add ExternalAppMetum
     *
     * @param \ODR\AdminBundle\Entity\ExternalAppMeta $externalAppMetum
     *
     * @return ExternalApp
     */
    public function addExternalAppMetum(\ODR\AdminBundle\Entity\ExternalAppMeta $externalAppMetum)
    {
        $this->externalAppMeta[] = $externalAppMetum;

        return $this;
    }

    /**
     * Remove ExternalAppMetum
     *
     * @param \ODR\AdminBundle\Entity\ExternalAppMeta $externalAppMetum
     */
    public function removeExternalAppMetum(\ODR\AdminBundle\Entity\ExternalAppMeta $externalAppMetum)
    {
        $this->externalAppMeta->removeElement($externalAppMetum);
    }

    /**
     * Get ExternalAppMeta
     *
     * @return ExternalAppMeta
     */
    public function getExternalAppMeta()
    {
        return $this->externalAppMeta->first();
    }

    /**
     * Add externalAppDatatypeLink
     *
     * @param \ODR\AdminBundle\Entity\ExternalAppDatatypeLink $externalAppDatatypeLink
     *
     * @return ExternalApp
     */
    public function addExternalAppDatatypeLink(\ODR\AdminBundle\Entity\ExternalAppDatatypeLink $externalAppDatatypeLink)
    {
        $this->externalAppDatatypeLinks[] = $externalAppDatatypeLink;

        return $this;
    }

    /**
     * Remove externalAppDatatypeLink
     *
     * @param \ODR\AdminBundle\Entity\ExternalAppDatatypeLink $externalAppDatatypeLink
     */
    public function removeExternalAppDatatypeLink(\ODR\AdminBundle\Entity\ExternalAppDatatypeLink $externalAppDatatypeLink)
    {
        $this->externalAppDatatypeLinks->removeElement($externalAppDatatypeLink);
    }

    /**
     * Get externalAppDatatypeLinks
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getExternalAppDatatypeLinks()
    {
        return $this->externalAppDatatypeLinks;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     *
     * @return ExternalApp
     */
    public function setCreatedBy(\ODR\OpenRepository\UserBundle\Entity\User $createdBy = null)
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * Get createdBy
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User
     */
    public function getCreatedBy()
    {
        return $this->createdBy;
    }

    /**
     * Set deletedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $deletedBy
     *
     * @return ExternalApp
     */
    public function setDeletedBy(\ODR\OpenRepository\UserBundle\Entity\User $deletedBy = null)
    {
        $this->deletedBy = $deletedBy;

        return $this;
    }

    /**
     * Get deletedBy
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User
     */
    public function getDeletedBy()
    {
        return $this->deletedBy;
    }


    /**
     * Get appName
     *
     * @return string
     */
    public function getAppName()
    {
        return $this->getExternalAppMeta()->getAppName();
    }

    /**
     * Get appDescription
     *
     * @return string
     */
    public function getAppDescription()
    {
        return $this->getExternalAppMeta()->getAppDescription();
    }

    /**
     * Get appUrl
     *
     * @return string
     */
    public function getAppUrl()
    {
        return $this->getExternalAppMeta()->getAppUrl();
    }
}
