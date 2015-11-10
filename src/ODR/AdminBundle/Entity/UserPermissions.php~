<?php

/**
 * Open Data Repository Data Publisher
 * UserPermissions Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The UserPermissions Entity is automatically generated from
 * ./Resources/config/doctrine/UserPermissions.orm.yml
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * UserPermissions
 */
class UserPermissions
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
     * @var integer
     */
    private $can_view_type;

    /**
     * @var integer
     */
    private $can_edit_record;

    /**
     * @var integer
     */
    private $can_add_record;

    /**
     * @var integer
     */
    private $can_delete_record;

    /**
     * @var integer
     */
    private $can_design_type;

    /**
     * @var integer
     */
    private $is_type_admin;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $user_id;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;


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
     * @return UserPermissions
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
     * Set can_view_type
     *
     * @param integer $canViewType
     * @return UserPermissions
     */
    public function setCanViewType($canViewType)
    {
        $this->can_view_type = $canViewType;

        return $this;
    }

    /**
     * Get can_view_type
     *
     * @return integer 
     */
    public function getCanViewType()
    {
        return $this->can_view_type;
    }

    /**
     * Set can_edit_record
     *
     * @param integer $canEditRecord
     * @return UserPermissions
     */
    public function setCanEditRecord($canEditRecord)
    {
        $this->can_edit_record = $canEditRecord;

        return $this;
    }

    /**
     * Get can_edit_record
     *
     * @return integer 
     */
    public function getCanEditRecord()
    {
        return $this->can_edit_record;
    }

    /**
     * Set can_add_record
     *
     * @param integer $canAddRecord
     * @return UserPermissions
     */
    public function setCanAddRecord($canAddRecord)
    {
        $this->can_add_record = $canAddRecord;

        return $this;
    }

    /**
     * Get can_add_record
     *
     * @return integer 
     */
    public function getCanAddRecord()
    {
        return $this->can_add_record;
    }

    /**
     * Set can_delete_record
     *
     * @param integer $canDeleteRecord
     * @return UserPermissions
     */
    public function setCanDeleteRecord($canDeleteRecord)
    {
        $this->can_delete_record = $canDeleteRecord;

        return $this;
    }

    /**
     * Get can_delete_record
     *
     * @return integer 
     */
    public function getCanDeleteRecord()
    {
        return $this->can_delete_record;
    }

    /**
     * Set can_design_type
     *
     * @param integer $canDesignType
     * @return UserPermissions
     */
    public function setCanDesignType($canDesignType)
    {
        $this->can_design_type = $canDesignType;

        return $this;
    }

    /**
     * Get can_design_type
     *
     * @return integer 
     */
    public function getCanDesignType()
    {
        return $this->can_design_type;
    }

    /**
     * Set is_type_admin
     *
     * @param integer $isTypeAdmin
     * @return UserPermissions
     */
    public function setIsTypeAdmin($isTypeAdmin)
    {
        $this->is_type_admin = $isTypeAdmin;

        return $this;
    }

    /**
     * Get is_type_admin
     *
     * @return integer 
     */
    public function getIsTypeAdmin()
    {
        return $this->is_type_admin;
    }

    /**
     * Set user_id
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $userId
     * @return UserPermissions
     */
    public function setUserId(\ODR\OpenRepository\UserBundle\Entity\User $userId = null)
    {
        $this->user_id = $userId;

        return $this;
    }

    /**
     * Get user_id
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User 
     */
    public function getUserId()
    {
        return $this->user_id;
    }

    /**
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return UserPermissions
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
     * @return UserPermissions
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
}
