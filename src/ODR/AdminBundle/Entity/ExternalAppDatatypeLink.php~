<?php

/**
 * Open Data Repository Data Publisher
 * ExternalApp Datatype Link Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The ExternalAppDatatypeLink Entity is automatically generated from
 * ./Resources/config/doctrine/ExternalAppDatatypeLink.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ExternalAppDatatypeLink
 */
class ExternalAppDatatypeLink
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
     * @var \ODR\AdminBundle\Entity\ExternalApp
     */
    private $externalApp;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

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
     * @return ExternalAppDatatypeLink
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
     * @return ExternalAppDatatypeLink
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
     * Set externalApp.
     *
     * @param \ODR\AdminBundle\Entity\ExternalApp|null $externalApp
     *
     * @return ExternalAppDatatypeLink
     */
    public function setExternalApp(\ODR\AdminBundle\Entity\ExternalApp $externalApp = null)
    {
        $this->externalApp = $externalApp;

        return $this;
    }

    /**
     * Get externalApp.
     *
     * @return \ODR\AdminBundle\Entity\ExternalApp|null
     */
    public function getExternalApp()
    {
        return $this->externalApp;
    }

    /**
     * Set dataType.
     *
     * @param \ODR\AdminBundle\Entity\DataType|null $dataType
     *
     * @return ExternalAppDatatypeLink
     */
    public function setDatatype(\ODR\AdminBundle\Entity\DataType $dataType = null)
    {
        $this->dataType = $dataType;

        return $this;
    }

    /**
     * Get dataType.
     *
     * @return \ODR\AdminBundle\Entity\DataType|null
     */
    public function getDatatype()
    {
        return $this->dataType;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return ExternalAppDatatypeLink
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
     * @return ExternalAppDatatypeLink
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
}
