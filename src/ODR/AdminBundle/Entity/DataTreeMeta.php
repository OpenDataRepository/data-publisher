<?php

/**
 * Open Data Repository Data Publisher
 * DataTreeMeta Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The DataTreeMeta Entity is responsible for storing the properties
 * of the DataTree Entity that are subject to change, and is
 * automatically generated from ./Resources/config/doctrine/DataTreeMeta.orm.yml
 * 
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * DataTreeMeta
 */
class DataTreeMeta
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var boolean
     */
    private $is_link;

    /**
     * @var boolean
     */
    private $multiple_allowed;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \ODR\AdminBundle\Entity\DataTree
     */
    private $DataTree;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;


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
     * Set is_link
     *
     * @param boolean $isLink
     * @return DataTreeMeta
     */
    public function setIsLink($isLink)
    {
        $this->is_link = $isLink;

        return $this;
    }

    /**
     * Get is_link
     *
     * @return boolean 
     */
    public function getIsLink()
    {
        return $this->is_link;
    }

    /**
     * Set multiple_allowed
     *
     * @param boolean $multipleAllowed
     * @return DataTreeMeta
     */
    public function setMultipleAllowed($multipleAllowed)
    {
        $this->multiple_allowed = $multipleAllowed;

        return $this;
    }

    /**
     * Get multiple_allowed
     *
     * @return boolean 
     */
    public function getMultipleAllowed()
    {
        return $this->multiple_allowed;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return DataTreeMeta
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
     * Set created
     *
     * @param \DateTime $created
     * @return DataTreeMeta
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
     * Set DataTree
     *
     * @param \ODR\AdminBundle\Entity\DataTree $dataTree
     * @return DataTreeMeta
     */
    public function setDataTree(\ODR\AdminBundle\Entity\DataTree $dataTree = null)
    {
        $this->DataTree = $dataTree;

        return $this;
    }

    /**
     * Get DataTree
     *
     * @return \ODR\AdminBundle\Entity\DataTree 
     */
    public function getDataTree()
    {
        return $this->DataTree;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return DataTreeMeta
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
}
