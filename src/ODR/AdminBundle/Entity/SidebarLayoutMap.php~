<?php

namespace ODR\AdminBundle\Entity;

/**
 * SidebarLayoutMap
 */
class SidebarLayoutMap
{

    // Entries with this value never show up in a layout
    public const NEVER_DISPLAY = 0;
    // Entries with this value will always show up in a layout
    public const ALWAYS_DISPLAY = 1;
    // Entries with this value
    public const EXTENDED_DISPLAY = 2;

    // Not all datafields of a datatype are guaranteed to have a SidebarLayoutMap entry...if they
    //  don't, then it's assumed that they'll only show up when TODO

    /**
     * @var int
     */
    private $id;

    /**
     * @var int
     */
    private $category;

    /**
     * @var int
     */
    private $displayOrder;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $updated;

    /**
     * @var \DateTime|null
     */
    private $deletedAt;

    /**
     * @var \ODR\AdminBundle\Entity\SidebarLayout
     */
    private $sidebarLayout;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;


    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set category.
     *
     * @param int $category
     *
     * @return SidebarLayoutMap
     */
    public function setCategory($category)
    {
        $this->category = $category;

        return $this;
    }

    /**
     * Get category.
     *
     * @return int
     */
    public function getCategory()
    {
        return $this->category;
    }

    /**
     * Set displayOrder.
     *
     * @param int $displayOrder
     *
     * @return SidebarLayoutMap
     */
    public function setDisplayOrder($displayOrder)
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    /**
     * Get displayOrder.
     *
     * @return int
     */
    public function getDisplayOrder()
    {
        return $this->displayOrder;
    }

    /**
     * Set created.
     *
     * @param \DateTime $created
     *
     * @return SidebarLayoutMap
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created.
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set updated.
     *
     * @param \DateTime $updated
     *
     * @return SidebarLayoutMap
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;

        return $this;
    }

    /**
     * Get updated.
     *
     * @return \DateTime
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Set deletedAt.
     *
     * @param \DateTime|null $deletedAt
     *
     * @return SidebarLayoutMap
     */
    public function setDeletedAt($deletedAt = null)
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    /**
     * Get deletedAt.
     *
     * @return \DateTime|null
     */
    public function getDeletedAt()
    {
        return $this->deletedAt;
    }

    /**
     * Set sidebarLayout.
     *
     * @param \ODR\AdminBundle\Entity\SidebarLayout|null $sidebarLayout
     *
     * @return SidebarLayoutMap
     */
    public function setSidebarLayout(\ODR\AdminBundle\Entity\SidebarLayout $sidebarLayout = null)
    {
        $this->sidebarLayout = $sidebarLayout;

        return $this;
    }

    /**
     * Get sidebarLayout.
     *
     * @return \ODR\AdminBundle\Entity\SidebarLayout|null
     */
    public function getSidebarLayout()
    {
        return $this->sidebarLayout;
    }

    /**
     * Set dataType.
     *
     * @param \ODR\AdminBundle\Entity\DataType|null $dataType
     *
     * @return SidebarLayoutMap
     */
    public function setDataType(\ODR\AdminBundle\Entity\DataType $dataType = null)
    {
        $this->dataType = $dataType;

        return $this;
    }

    /**
     * Get dataType.
     *
     * @return \ODR\AdminBundle\Entity\DataType|null
     */
    public function getDataType()
    {
        return $this->dataType;
    }

    /**
     * Set dataField.
     *
     * @param \ODR\AdminBundle\Entity\DataFields|null $dataField
     *
     * @return SidebarLayoutMap
     */
    public function setDataField(\ODR\AdminBundle\Entity\DataFields $dataField = null)
    {
        $this->dataField = $dataField;

        return $this;
    }

    /**
     * Get dataField.
     *
     * @return \ODR\AdminBundle\Entity\DataFields|null
     */
    public function getDataField()
    {
        return $this->dataField;
    }

    /**
     * Set createdBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $createdBy
     *
     * @return SidebarLayoutMap
     */
    public function setCreatedBy(\ODR\OpenRepository\UserBundle\Entity\User $createdBy = null)
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * Get createdBy.
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User|null
     */
    public function getCreatedBy()
    {
        return $this->createdBy;
    }

    /**
     * Set updatedBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $updatedBy
     *
     * @return SidebarLayoutMap
     */
    public function setUpdatedBy(\ODR\OpenRepository\UserBundle\Entity\User $updatedBy = null)
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    /**
     * Get updatedBy.
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User|null
     */
    public function getUpdatedBy()
    {
        return $this->updatedBy;
    }
}
