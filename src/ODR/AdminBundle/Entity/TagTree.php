<?php

/**
 * Open Data Repository Data Publisher
 * TagTree Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The TagTree Entity is automatically generated from
 * ./Resources/config/doctrine/TagTree.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

/**
 * TagTree
 */
class TagTree
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
     * @var \ODR\AdminBundle\Entity\Tags
     */
    private $parent;

    /**
     * @var \ODR\AdminBundle\Entity\Tags
     */
    private $child;

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
     *
     * @return TagTree
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
     * @return TagTree
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
     * Set parent
     *
     * @param \ODR\AdminBundle\Entity\Tags $parent
     *
     * @return TagTree
     */
    public function setParent(\ODR\AdminBundle\Entity\Tags $parent = null)
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * Get parent
     *
     * @return \ODR\AdminBundle\Entity\Tags
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Set child
     *
     * @param \ODR\AdminBundle\Entity\Tags $child
     *
     * @return TagTree
     */
    public function setChild(\ODR\AdminBundle\Entity\Tags $child = null)
    {
        $this->child = $child;

        return $this;
    }

    /**
     * Get child
     *
     * @return \ODR\AdminBundle\Entity\Tags
     */
    public function getChild()
    {
        return $this->child;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     *
     * @return TagTree
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
     * @return TagTree
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
