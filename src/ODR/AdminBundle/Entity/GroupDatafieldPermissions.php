<?php

/**
 * Open Data Repository Data Publisher
 * Group DatafieldPermissions Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Group DatafieldPermissions Entity is automatically generated from
 * ./Resources/config/doctrine/GroupDatafieldPermissions.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

/**
 * GroupDatafieldPermissions
 */
class GroupDatafieldPermissions
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var boolean
     */
    private $can_view_datafield;

    /**
     * @var boolean
     */
    private $can_edit_datafield;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $updated;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \ODR\AdminBundle\Entity\Group
     */
    private $group;

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
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set canViewDatafield
     *
     * @param boolean $canViewDatafield
     *
     * @return GroupDatafieldPermissions
     */
    public function setCanViewDatafield($canViewDatafield)
    {
        $this->can_view_datafield = $canViewDatafield;

        return $this;
    }

    /**
     * Get canViewDatafield
     *
     * @return boolean
     */
    public function getCanViewDatafield()
    {
        return $this->can_view_datafield;
    }

    /**
     * Set canEditDatafield
     *
     * @param boolean $canEditDatafield
     *
     * @return GroupDatafieldPermissions
     */
    public function setCanEditDatafield($canEditDatafield)
    {
        $this->can_edit_datafield = $canEditDatafield;

        return $this;
    }

    /**
     * Get canEditDatafield
     *
     * @return boolean
     */
    public function getCanEditDatafield()
    {
        return $this->can_edit_datafield;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     *
     * @return GroupDatafieldPermissions
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
     *
     * @return GroupDatafieldPermissions
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
     *
     * @return GroupDatafieldPermissions
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
     * Set group
     *
     * @param \ODR\AdminBundle\Entity\Group $group
     *
     * @return GroupDatafieldPermissions
     */
    public function setGroup(\ODR\AdminBundle\Entity\Group $group = null)
    {
        $this->group = $group;

        return $this;
    }

    /**
     * Get group
     *
     * @return \ODR\AdminBundle\Entity\Group
     */
    public function getGroup()
    {
        return $this->group;
    }

    /**
     * Set dataField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataField
     *
     * @return GroupDatafieldPermissions
     */
    public function setDataField(\ODR\AdminBundle\Entity\DataFields $dataField = null)
    {
        $this->dataField = $dataField;

        return $this;
    }

    /**
     * Get dataField
     *
     * @return \ODR\AdminBundle\Entity\DataFields
     */
    public function getDataField()
    {
        return $this->dataField;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     *
     * @return GroupDatafieldPermissions
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
     *
     * @return GroupDatafieldPermissions
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
