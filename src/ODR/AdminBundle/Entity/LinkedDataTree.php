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
    private $created;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
     */
    private $ancestor;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
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
     * Set deletedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $deletedBy
     * @return LinkedDataTree
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
     * @var \Doctrine\Common\Collections\Collection
     */
    private $ancestors;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->ancestors = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Add ancestor.
     *
     * @param \ODR\AdminBundle\Entity\linkedDataTree $ancestor
     *
     * @return LinkedDataTree
     */
    public function addAncestor(\ODR\AdminBundle\Entity\linkedDataTree $ancestor)
    {
        $this->ancestors[] = $ancestor;

        return $this;
    }

    /**
     * Remove ancestor.
     *
     * @param \ODR\AdminBundle\Entity\linkedDataTree $ancestor
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeAncestor(\ODR\AdminBundle\Entity\linkedDataTree $ancestor)
    {
        return $this->ancestors->removeElement($ancestor);
    }

    /**
     * Get ancestors.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getAncestors()
    {
        return $this->ancestors;
    }
    /**
     * @var \ODR\AdminBundle\Entity\linkedDataTree
     */
    private $descendants;


    /**
     * Set descendants.
     *
     * @param \ODR\AdminBundle\Entity\linkedDataTree|null $descendants
     *
     * @return LinkedDataTree
     */
    public function setDescendants(\ODR\AdminBundle\Entity\linkedDataTree $descendants = null)
    {
        $this->descendants = $descendants;

        return $this;
    }

    /**
     * Get descendants.
     *
     * @return \ODR\AdminBundle\Entity\linkedDataTree|null
     */
    public function getDescendants()
    {
        return $this->descendants;
    }
}
