<?php

/**
* Open Data Repository Data Publisher
* ShortVarchar Entity
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The ShortVarchar Entity is automatically generated from 
* ./Resources/config/doctrine/ShortVarchar.orm.yml
*
*/


namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ODR\AdminBundle\Entity\ShortVarchar
 */
class ShortVarchar
{
    /**
     * @var integer $id
     */
    private $id;

    /**
     * @var string $value
     */
    private $value;

    /**
     * @var ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * @var ODR\AdminBundle\Entity\FieldType
     */
    private $fieldType;

    /**
     * @var ODR\AdminBundle\Entity\DataRecord
     */
    private $dataRecord;


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
     * Set value
     *
     * @param string $value
     * @return ShortVarchar
     */
    public function setValue($value)
    {
        $this->value = $value;
    
        return $this;
    }

    /**
     * Get value
     *
     * @return string 
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set dataField
     *
     * @param ODR\AdminBundle\Entity\DataFields $dataField
     * @return ShortVarchar
     */
    public function setDataField(\ODR\AdminBundle\Entity\DataFields $dataField = null)
    {
        $this->dataField = $dataField;
    
        return $this;
    }

    /**
     * Get dataField
     *
     * @return ODR\AdminBundle\Entity\DataFields 
     */
    public function getDataField()
    {
        return $this->dataField;
    }

    /**
     * Set fieldType
     *
     * @param ODR\AdminBundle\Entity\FieldType $fieldType
     * @return ShortVarchar
     */
    public function setFieldType(\ODR\AdminBundle\Entity\FieldType $fieldType = null)
    {
        $this->fieldType = $fieldType;
    
        return $this;
    }

    /**
     * Get fieldType
     *
     * @return ODR\AdminBundle\Entity\FieldType 
     */
    public function getFieldType()
    {
        return $this->fieldType;
    }

    /**
     * Set dataRecord
     *
     * @param ODR\AdminBundle\Entity\DataRecord $dataRecord
     * @return ShortVarchar
     */
    public function setDataRecord(\ODR\AdminBundle\Entity\DataRecord $dataRecord = null)
    {
        $this->dataRecord = $dataRecord;
    
        return $this;
    }

    /**
     * Get dataRecord
     *
     * @return ODR\AdminBundle\Entity\DataRecord 
     */
    public function getDataRecord()
    {
        return $this->dataRecord;
    }
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
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;


    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return ShortVarchar
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
     * Set created
     *
     * @param \DateTime $created
     * @return ShortVarchar
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
     * @return ShortVarchar
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
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return ShortVarchar
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
     * @return ShortVarchar
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
    /**
     * @var \ODR\AdminBundle\Entity\DataRecordFields
     */
    private $dataRecordFields;


    /**
     * Set dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     * @return ShortVarchar
     */
    public function setDataRecordFields(\ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields = null)
    {
        $this->dataRecordFields = $dataRecordFields;
    
        return $this;
    }

    /**
     * Get dataRecordFields
     *
     * @return \ODR\AdminBundle\Entity\DataRecordFields 
     */
    public function getDataRecordFields()
    {
        return $this->dataRecordFields;
    }
}
