<?php

/**
 * Open Data Repository Data Publisher
 * UserFieldPermissions Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The UserFieldPermissions Entity is automatically generated from
 * ./Resources/config/doctrine/UserFieldPermissions.orm.yml
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * UserFieldPermissions
 */
class UserFieldPermissions
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
    private $can_view_field;

    /**
     * @var integer
     */
    private $can_edit_field;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $user_id;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataFields;

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
     * @return UserFieldPermissions
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
     * Set can_view_field
     *
     * @param integer $canViewField
     * @return UserFieldPermissions
     */
    public function setCanViewField($canViewField)
    {
        $this->can_view_field = $canViewField;

        return $this;
    }

    /**
     * Get can_view_field
     *
     * @return integer 
     */
    public function getCanViewField()
    {
        return $this->can_view_field;
    }

    /**
     * Set can_edit_field
     *
     * @param integer $canEditField
     * @return UserFieldPermissions
     */
    public function setCanEditField($canEditField)
    {
        $this->can_edit_field = $canEditField;

        return $this;
    }

    /**
     * Get can_edit_field
     *
     * @return integer 
     */
    public function getCanEditField()
    {
        return $this->can_edit_field;
    }

    /**
     * Set user_id
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $userId
     * @return UserFieldPermissions
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
     * Set dataFields
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataFields
     * @return UserFieldPermissions
     */
    public function setDataFields(\ODR\AdminBundle\Entity\DataFields $dataFields = null)
    {
        $this->dataFields = $dataFields;

        return $this;
    }

    /**
     * Get dataFields
     *
     * @return \ODR\AdminBundle\Entity\DataFields 
     */
    public function getDataFields()
    {
        return $this->dataFields;
    }

    /**
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return UserFieldPermissions
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
     * @return UserFieldPermissions
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
