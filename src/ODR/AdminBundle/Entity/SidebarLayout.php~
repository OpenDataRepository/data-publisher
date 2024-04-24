<?php

namespace ODR\AdminBundle\Entity;

/**
 * SidebarLayout
 */
class SidebarLayout
{
    /**
     * @var int
     */
    private $id;

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
     * @var \Doctrine\Common\Collections\Collection
     */
    private $sidebarLayoutMeta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $sidebarLayoutPreferences;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $sidebarLayoutMap;

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
    private $updatedBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $deletedBy;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->sidebarLayoutMeta = new \Doctrine\Common\Collections\ArrayCollection();
        $this->sidebarLayoutPreferences = new \Doctrine\Common\Collections\ArrayCollection();
        $this->sidebarLayoutMap = new \Doctrine\Common\Collections\ArrayCollection();
    }

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
     * Set created.
     *
     * @param \DateTime $created
     *
     * @return SidebarLayout
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
     * @return SidebarLayout
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
     * @return SidebarLayout
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
     * Add sidebarLayoutMetum.
     *
     * @param \ODR\AdminBundle\Entity\SidebarLayoutMeta $sidebarLayoutMetum
     *
     * @return SidebarLayout
     */
    public function addSidebarLayoutMetum(\ODR\AdminBundle\Entity\SidebarLayoutMeta $sidebarLayoutMetum)
    {
        $this->sidebarLayoutMeta[] = $sidebarLayoutMetum;

        return $this;
    }

    /**
     * Remove sidebarLayoutMetum.
     *
     * @param \ODR\AdminBundle\Entity\SidebarLayoutMeta $sidebarLayoutMetum
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeSidebarLayoutMetum(\ODR\AdminBundle\Entity\SidebarLayoutMeta $sidebarLayoutMetum)
    {
        return $this->sidebarLayoutMeta->removeElement($sidebarLayoutMetum);
    }

    /**
     * Get sidebarLayoutMeta.
     *
     * @return SidebarLayoutMeta
     */
    public function getSidebarLayoutMeta()
    {
        return $this->sidebarLayoutMeta->first();
    }

    /**
     * Add sidebarLayoutPreference.
     *
     * @param \ODR\AdminBundle\Entity\SidebarLayoutPreferences $sidebarLayoutPreference
     *
     * @return SidebarLayout
     */
    public function addSidebarLayoutPreference(\ODR\AdminBundle\Entity\SidebarLayoutPreferences $sidebarLayoutPreference)
    {
        $this->sidebarLayoutPreferences[] = $sidebarLayoutPreference;

        return $this;
    }

    /**
     * Remove sidebarLayoutPreference.
     *
     * @param \ODR\AdminBundle\Entity\SidebarLayoutPreferences $sidebarLayoutPreference
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeSidebarLayoutPreference(\ODR\AdminBundle\Entity\SidebarLayoutPreferences $sidebarLayoutPreference)
    {
        return $this->sidebarLayoutPreferences->removeElement($sidebarLayoutPreference);
    }

    /**
     * Get sidebarLayoutPreferences.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getSidebarLayoutPreferences()
    {
        return $this->sidebarLayoutPreferences;
    }

    /**
     * Add sidebarLayoutMap.
     *
     * @param \ODR\AdminBundle\Entity\SidebarLayoutMap $sidebarLayoutMap
     *
     * @return SidebarLayout
     */
    public function addSidebarLayoutMap(\ODR\AdminBundle\Entity\SidebarLayoutMap $sidebarLayoutMap)
    {
        $this->sidebarLayoutMap[] = $sidebarLayoutMap;

        return $this;
    }

    /**
     * Remove sidebarLayoutMap.
     *
     * @param \ODR\AdminBundle\Entity\SidebarLayoutMap $sidebarLayoutMap
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeSidebarLayoutMap(\ODR\AdminBundle\Entity\SidebarLayoutMap $sidebarLayoutMap)
    {
        return $this->sidebarLayoutMap->removeElement($sidebarLayoutMap);
    }

    /**
     * Get sidebarLayoutMap.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getSidebarLayoutMap()
    {
        return $this->sidebarLayoutMap;
    }

    /**
     * Set dataType.
     *
     * @param \ODR\AdminBundle\Entity\DataType|null $dataType
     *
     * @return SidebarLayout
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
     * Set createdBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $createdBy
     *
     * @return SidebarLayout
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
     * @return SidebarLayout
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

    /**
     * Set deletedBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $deletedBy
     *
     * @return SidebarLayout
     */
    public function setDeletedBy(\ODR\OpenRepository\UserBundle\Entity\User $deletedBy = null)
    {
        $this->deletedBy = $deletedBy;

        return $this;
    }

    /**
     * Get deletedBy.
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User|null
     */
    public function getDeletedBy()
    {
        return $this->deletedBy;
    }

    /**
     * Get layoutName
     *
     * @return string
     */
    public function getLayoutName()
    {
        return $this->getSidebarLayoutMeta()->getLayoutName();
    }

    /**
     * Get layoutDescription
     *
     * @return string
     */
    public function getLayoutDescription()
    {
        return $this->getSidebarLayoutMeta()->getLayoutDescription();
    }

    /**
     * Get shared
     *
     * @return bool
     */
    public function getShared()
    {
        return $this->getSidebarLayoutMeta()->getShared();
    }

    /**
     * Get layoutDescription
     *
     * @return int
     */
    public function getDefaultFor()
    {
        return $this->getSidebarLayoutMeta()->getDefaultFor();
    }

    /**
     * Get shared
     *
     * @return int
     */
    public function getDisplayOrder()
    {
        return $this->getSidebarLayoutMeta()->getDisplayOrder();
    }
}
