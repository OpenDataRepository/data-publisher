<?php

namespace ODR\AdminBundle\Entity;

/**
 * SidebarLayoutPreferences
 */
class SidebarLayoutPreferences
{
    /**
     * @var int
     */
    private $id;

    /**
     * @var int
     */
    private $defaultFor;

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
     * Set defaultFor.
     *
     * @param int $defaultFor
     *
     * @return SidebarLayoutPreferences
     */
    public function setDefaultFor($defaultFor)
    {
        $this->defaultFor = $defaultFor;

        return $this;
    }

    /**
     * Get defaultFor.
     *
     * @return int
     */
    public function getDefaultFor()
    {
        return $this->defaultFor;
    }

    /**
     * Set created.
     *
     * @param \DateTime $created
     *
     * @return SidebarLayoutPreferences
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
     * @return SidebarLayoutPreferences
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
     * @return SidebarLayoutPreferences
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
     * @return SidebarLayoutPreferences
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
     * Set createdBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $createdBy
     *
     * @return SidebarLayoutPreferences
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
     * @return SidebarLayoutPreferences
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
