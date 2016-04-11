<?php

/**
 * Open Data Repository Data Publisher
 * DataTree Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The DataTree Entity is automatically generated from
 * ./Resources/config/doctrine/DataTree.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * DataTree
 */
class DataTree
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
    private $updated;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $DataTreeMeta;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $ancestor;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $descendant;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $deletedBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->DataTreeMeta = new \Doctrine\Common\Collections\ArrayCollection();
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
     * @return DataTree
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
     * @return DataTree
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
     * Set is_link
     *
     * @param boolean $isLink
     * @return DataTree
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
        return $this->getDataTreeMeta()->getIsLink();
    }

    /**
     * Set multiple_allowed
     *
     * @param boolean $multipleAllowed
     * @return DataTree
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
        return $this->getDataTreeMeta()->getMultipleAllowed();
    }

    /**
     * Set updated
     *
     * @param \DateTime $updated
     * @return DataTree
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;

        return $this;
    }

    /**
     * Get updated
     *
     * @return \DateTime 
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Add DataTreeMeta
     *
     * @param \ODR\AdminBundle\Entity\DataTreeMeta $dataTreeMeta
     * @return DataTree
     */
    public function addDataTreeMetum(\ODR\AdminBundle\Entity\DataTreeMeta $dataTreeMeta)
    {
        $this->DataTreeMeta[] = $dataTreeMeta;

        return $this;
    }

    /**
     * Remove DataTreeMeta
     *
     * @param \ODR\AdminBundle\Entity\DataTreeMeta $dataTreeMeta
     */
    public function removeDataTreeMetum(\ODR\AdminBundle\Entity\DataTreeMeta $dataTreeMeta)
    {
        $this->DataTreeMeta->removeElement($dataTreeMeta);
    }

    /**
     * Get DataTreeMeta
     *
     * @return \ODR\AdminBundle\Entity\DataTreeMeta
     */
    public function getDataTreeMeta()
    {
        return $this->DataTreeMeta->first();
    }

    /**
     * Set ancestor
     *
     * @param \ODR\AdminBundle\Entity\DataType $ancestor
     * @return DataTree
     */
    public function setAncestor(\ODR\AdminBundle\Entity\DataType $ancestor = null)
    {
        $this->ancestor = $ancestor;

        return $this;
    }

    /**
     * Get ancestor
     *
     * @return \ODR\AdminBundle\Entity\DataType 
     */
    public function getAncestor()
    {
        return $this->ancestor;
    }

    /**
     * Set descendant
     *
     * @param \ODR\AdminBundle\Entity\DataType $descendant
     * @return DataTree
     */
    public function setDescendant(\ODR\AdminBundle\Entity\DataType $descendant = null)
    {
        $this->descendant = $descendant;

        return $this;
    }

    /**
     * Get descendant
     *
     * @return \ODR\AdminBundle\Entity\DataType 
     */
    public function getDescendant()
    {
        return $this->descendant;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return DataTree
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
     * @return DataTree
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
     * Set updatedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $updatedBy
     * @return DataTree
     */
    public function setUpdatedBy(\ODR\OpenRepository\UserBundle\Entity\User $updatedBy = null)
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    /**
     * Get updatedBy
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User 
     */
    public function getUpdatedBy()
    {
        return $this->updatedBy;
    }

    // ----------------------------------------
    // TODO - delete these two functions
    /**
     * Get is_link original
     *
     * @return bool
     */
    public function getIsLinkOriginal()
    {
        return $this->is_link;
    }

    /**
     * Get multiple_allowed original
     *
     * @return bool
     */
    public function getMultipleAllowedOriginal()
    {
        return $this->multiple_allowed;
    }
}
