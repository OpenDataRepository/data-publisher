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
     * Edit mode won't make any effort to block editing of descendant records.  This is the default.
     */
    const ALWAYS_EDIT = 0;

    /**
     * Edit mode will attempt to protect descendant records from being directly edited, but instead
     * open their edit pages in a new tab.
     */
    const LINK_EDIT = 1;

    /**
     * Edit mode will attempt to protect descendant records from being directly edited, but provide
     * a button/popup combo to allow users to toggle the abiilty to directly edit the record.
     */
    const TOGGLE_EDIT_INACTIVE = 2;

    /**
     * Edit mode will attempt to protect descendant records from being directly edited, but the
     * button/popup combo to allow users to toggle the abiilty to directly edit the record has been
     * triggered in this case.  This is a temporary state, and should not be saved to the database.
     */
    const TOGGLE_EDIT_ACTIVE = 3;


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
     * @var integer
     */
    private $edit_behavior;

    /**
     * @var \ODR\AdminBundle\Entity\DataTree
     */
    private $secondaryDataTree;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $updated;

    /**
     * @var \ODR\AdminBundle\Entity\DataTree
     */
    private $dataTree;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;


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
     * Set editBehavior.
     *
     * @param integer $editBehavior
     *
     * @return DataTreeMeta
     */
    public function setEditBehavior($editBehavior)
    {
        $this->edit_behavior = $editBehavior;

        return $this;
    }

    /**
     * Get editBehavior.
     *
     * @return integer
     */
    public function getEditBehavior()
    {
        return $this->edit_behavior;
    }

    /**
     * Set secondaryDataTree.
     *
     * @param \ODR\AdminBundle\Entity\DataTree|null $secondaryDataTree
     *
     * @return DataTreeMeta
     */
    public function setSecondaryDataTree(\ODR\AdminBundle\Entity\DataTree $secondaryDataTree = null)
    {
        $this->secondaryDataTree = $secondaryDataTree;

        return $this;
    }

    /**
     * Get secondaryDataTree.
     *
     * @return \ODR\AdminBundle\Entity\DataTree|null
     */
    public function getSecondaryDataTree()
    {
        return $this->secondaryDataTree;
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
     * Set updated
     *
     * @param \DateTime $updated
     * @return DataTreeMeta
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
     * Set dataTree
     *
     * @param \ODR\AdminBundle\Entity\DataTree $dataTree
     * @return DataTreeMeta
     */
    public function setDataTree(\ODR\AdminBundle\Entity\DataTree $dataTree = null)
    {
        $this->dataTree = $dataTree;

        return $this;
    }

    /**
     * Get dataTree
     *
     * @return \ODR\AdminBundle\Entity\DataTree 
     */
    public function getDataTree()
    {
        return $this->dataTree;
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

    /**
     * Set updatedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $updatedBy
     * @return DataTreeMeta
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
}
