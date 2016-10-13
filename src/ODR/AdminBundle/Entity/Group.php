<?php

/**
 * Open Data Repository Data Publisher
 * Group Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Group Entity is automatically generated from
 * ./Resources/config/doctrine/Group.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

/**
 * Group
 */
class Group
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $purpose;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $groupMeta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $userGroups;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $groupDatatypePermissions;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $groupDatafieldPermissions;

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
        $this->groupMeta = new \Doctrine\Common\Collections\ArrayCollection();
        $this->userGroups = new \Doctrine\Common\Collections\ArrayCollection();
        $this->groupDatatypePermissions = new \Doctrine\Common\Collections\ArrayCollection();
        $this->groupDatafieldPermissions = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set purpose
     *
     * @param string $purpose
     *
     * @return Group
     */
    public function setPurpose($purpose)
    {
        $this->purpose = $purpose;

        return $this;
    }

    /**
     * Get purpose
     *
     * @return string
     */
    public function getPurpose()
    {
        return $this->purpose;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Group
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
     * @return Group
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
     * Add groupMetum
     *
     * @param \ODR\AdminBundle\Entity\GroupMeta $groupMetum
     *
     * @return Group
     */
    public function addGroupMetum(\ODR\AdminBundle\Entity\GroupMeta $groupMetum)
    {
        $this->groupMeta[] = $groupMetum;

        return $this;
    }

    /**
     * Remove groupMetum
     *
     * @param \ODR\AdminBundle\Entity\GroupMeta $groupMetum
     */
    public function removeGroupMetum(\ODR\AdminBundle\Entity\GroupMeta $groupMetum)
    {
        $this->groupMeta->removeElement($groupMetum);
    }

    /**
     * Get groupMeta
     *
     * @return GroupMeta
     */
    public function getGroupMeta()
    {
        return $this->groupMeta->first();
    }

    /**
     * Add userGroup
     *
     * @param \ODR\AdminBundle\Entity\UserGroup $userGroup
     *
     * @return Group
     */
    public function addUserGroup(\ODR\AdminBundle\Entity\UserGroup $userGroup)
    {
        $this->userGroups[] = $userGroup;

        return $this;
    }

    /**
     * Remove userGroup
     *
     * @param \ODR\AdminBundle\Entity\UserGroup $userGroup
     */
    public function removeUserGroup(\ODR\AdminBundle\Entity\UserGroup $userGroup)
    {
        $this->userGroups->removeElement($userGroup);
    }

    /**
     * Get userGroups
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getUserGroups()
    {
        return $this->userGroups;
    }

    /**
     * Add groupDatatypePermission
     *
     * @param \ODR\AdminBundle\Entity\GroupDatatypePermissions $groupDatatypePermission
     *
     * @return Group
     */
    public function addGroupDatatypePermission(\ODR\AdminBundle\Entity\GroupDatatypePermissions $groupDatatypePermission)
    {
        $this->groupDatatypePermissions[] = $groupDatatypePermission;

        return $this;
    }

    /**
     * Remove groupDatatypePermission
     *
     * @param \ODR\AdminBundle\Entity\GroupDatatypePermissions $groupDatatypePermission
     */
    public function removeGroupDatatypePermission(\ODR\AdminBundle\Entity\GroupDatatypePermissions $groupDatatypePermission)
    {
        $this->groupDatatypePermissions->removeElement($groupDatatypePermission);
    }

    /**
     * Get groupDatatypePermissions
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getGroupDatatypePermissions()
    {
        return $this->groupDatatypePermissions;
    }

    /**
     * Add groupDatafieldPermission
     *
     * @param \ODR\AdminBundle\Entity\GroupDatafieldPermissions $groupDatafieldPermission
     *
     * @return Group
     */
    public function addGroupDatafieldPermission(\ODR\AdminBundle\Entity\GroupDatafieldPermissions $groupDatafieldPermission)
    {
        $this->groupDatafieldPermissions[] = $groupDatafieldPermission;

        return $this;
    }

    /**
     * Remove groupDatafieldPermission
     *
     * @param \ODR\AdminBundle\Entity\GroupDatafieldPermissions $groupDatafieldPermission
     */
    public function removeGroupDatafieldPermission(\ODR\AdminBundle\Entity\GroupDatafieldPermissions $groupDatafieldPermission)
    {
        $this->groupDatafieldPermissions->removeElement($groupDatafieldPermission);
    }

    /**
     * Get groupDatafieldPermissions
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getGroupDatafieldPermissions()
    {
        return $this->groupDatafieldPermissions;
    }

    /**
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     *
     * @return Group
     */
    public function setDataType(\ODR\AdminBundle\Entity\DataType $dataType = null)
    {
        $this->dataType = $dataType;

        return $this;
    }

    /**
     * Get dataType
     *
     * @return \ODR\AdminBundle\Entity\DataType
     */
    public function getDataType()
    {
        return $this->dataType;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     *
     * @return Group
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
     * @return Group
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
     * Get groupName
     *
     * @return string
     */
    public function getGroupName()
    {
        return $this->getGroupMeta()->getGroupName();
    }

    /**
     * Get groupDescription
     *
     * @return string
     */
    public function getGroupDescription()
    {
        return $this->getGroupMeta()->getGroupDescription();
    }

    /**
     * Get datarecordRestriction
     *
     * @return string
     */
    public function getDatarecordRestriction()
    {
        return $this->getGroupMeta()->getDatarecordRestriction();
    }
}
