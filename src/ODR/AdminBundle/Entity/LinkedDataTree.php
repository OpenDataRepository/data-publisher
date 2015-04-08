<?php

/**
* Open Data Repository Data Publisher
* LinkedDataTree Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The LinkedDataTree Entity is automatically generated from 
* ./Resources/config/doctrine/LinkedDataTree.orm.yml
*
*/


namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * LinkedDataTree
 */
class LinkedDataTree
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var boolean
     */
    private $multipleRecordsPerParent;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $updated;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
     */
    private $ancestor;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
     */
    private $descendant;


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
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return LinkedDataTree
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
     * Set multipleRecordsPerParent
     *
     * @param boolean $multipleRecordsPerParent
     * @return LinkedDataTree
     */
    public function setMultipleRecordsPerParent($multipleRecordsPerParent)
    {
        $this->multipleRecordsPerParent = $multipleRecordsPerParent;
    
        return $this;
    }

    /**
     * Get multipleRecordsPerParent
     *
     * @return boolean 
     */
    public function getMultipleRecordsPerParent()
    {
        return $this->multipleRecordsPerParent;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return LinkedDataTree
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
     * Set updated
     *
     * @param \DateTime $updated
     * @return LinkedDataTree
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
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return LinkedDataTree
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
     * Set updatedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $updatedBy
     * @return LinkedDataTree
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

    /**
     * Set ancestor
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $ancestor
     * @return LinkedDataTree
     */
    public function setAncestor(\ODR\AdminBundle\Entity\DataRecord $ancestor = null)
    {
        $this->ancestor = $ancestor;
    
        return $this;
    }

    /**
     * Get ancestor
     *
     * @return \ODR\AdminBundle\Entity\DataRecord 
     */
    public function getAncestor()
    {
        return $this->ancestor;
    }

    /**
     * Set descendant
     *
     * @param \ODR\AdminBundle\Entity\DataRecord $descendant
     * @return LinkedDataTree
     */
    public function setDescendant(\ODR\AdminBundle\Entity\DataRecord $descendant = null)
    {
        $this->descendant = $descendant;
    
        return $this;
    }

    /**
     * Get descendant
     *
     * @return \ODR\AdminBundle\Entity\DataRecord 
     */
    public function getDescendant()
    {
        return $this->descendant;
    }
}
